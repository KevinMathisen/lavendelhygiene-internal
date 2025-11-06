<?php
/**
 * Lavendel Hygiene Tripletex — API (single-file)
 *
 * Implements a tiny, resilient HTTP layer + endpoint helpers.
 * All services should call ONLY these functions.
 */

if (!defined('ABSPATH')) exit;

/** -------------------------------------------------------------------------
 * Utilities (masking, array helpers)
 * -------------------------------------------------------------------------- */
if (!function_exists('lh_ttx_get_base_url')) {
    // Soft fallback if file is loaded standalone (tests).
    function lh_ttx_get_base_url() { return 'https://tripletex.no/v2'; }
}
if (!class_exists('LH_Ttx_Logger')) {
    final class LH_Ttx_Logger { public static function info($m,$c=[]){ } public static function error($m,$c=[]){ } }
}

/** Mask secrets in logs */
function ttx_mask(?string $s, int $keepTail = 4): string {
    if (!$s) return '';
    $len = strlen($s);
    if ($len <= $keepTail) return str_repeat('*', $len);
    return str_repeat('*', max(0, $len - $keepTail)) . substr($s, -$keepTail);
}

/** Join fields param (array|string) into Tripletex format */
function ttx_normalize_fields($fields) {
    if (is_array($fields)) return implode(',', array_filter(array_map('trim', $fields)));
    return is_string($fields) ? $fields : '*';
}

/** Build query array (skip nulls/empties) */
function ttx_clean_query(array $q): array {
    $out = [];
    foreach ($q as $k => $v) {
        if ($v === null) continue;
        if (is_array($v) && $k === 'fields') {
            $v = ttx_normalize_fields($v);
        }
        if (is_array($v) && $v === []) continue;
        if ($v === '') continue;
        $out[$k] = $v;
    }
    return $out;
}

/** Normalize Tripletex envelopes: return plain array */
function ttx_unwrap($decoded) {
    if (!is_array($decoded)) return $decoded;
    if (array_key_exists('value', $decoded) && count($decoded) === 1) {
        return $decoded['value'];
    }
    if (array_key_exists('values', $decoded) && count($decoded) >= 1) {
        return $decoded['values'];
    }
    // some endpoints might return raw object/array
    return $decoded;
}

/** Create WP_Error with Tripletex details */
function ttx_error(string $code, string $message, array $ctx = []): WP_Error {
    return new WP_Error($code, $message, $ctx);
}

/** -------------------------------------------------------------------------
 * URL builder
 * -------------------------------------------------------------------------- */
/**
 * Build a full URL from base + path + query.
 */
function ttx_build_url(string $path, array $query = []): string {
    $base = rtrim(lh_ttx_get_base_url(), '/');
    $path = '/' . ltrim($path, '/');
    $query = ttx_clean_query($query);
    $qs = $query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
    return $base . $path . $qs;
}

/** -------------------------------------------------------------------------
 * Session token (create/cache/clear)
 * -------------------------------------------------------------------------- */
/**
 * Create/fetch a cached Tripletex session token using consumer+employee tokens.
 * Calls /token/session/:create without Authorization header.
 *
 * @return string|\WP_Error
 */
function ttx_get_session_token() {
    if (function_exists('lh_ttx_get_cached_session')) {
        $cached = lh_ttx_get_cached_session();
        $now = time() + 15; // small safety skew
        if (!empty($cached['token']) && (int)$cached['expires'] > $now) {
            return $cached['token'];
        }
    }

    if (!function_exists('lh_ttx_get_consumer_token') || !function_exists('lh_ttx_get_employee_token')) {
        return ttx_error('ttx_tokens_missing', __('Tripletex API tokens are not configured.', 'lh-ttx'));
    }
    $consumer = (string) lh_ttx_get_consumer_token();
    $employee = (string) lh_ttx_get_employee_token();

    if ($consumer === '' || $employee === '') {
        return ttx_error('ttx_tokens_missing', __('Tripletex API tokens are not configured.', 'lh-ttx'));
    }

    // Build request — Tripletex accepts tokens for session create (no Authorization header).
    $url = ttx_build_url('/token/session/:create', [
        'consumerToken' => $consumer,
        'employeeToken' => $employee,
    ]);

    $args = [
        'method'  => 'POST',
        'timeout' => 20,
        'headers' => [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent'   => 'LavendelhygieneTripletex/' . (defined('LH_TTX_VERSION') ? LH_TTX_VERSION : 'dev'),
        ],
        'body'    => wp_json_encode(new stdClass()), // body required by some stacks; empty JSON
    ];

    $res = wp_remote_request($url, $args);
    if (is_wp_error($res)) {
        LH_Ttx_Logger::error('Tripletex session create failed (transport)', [
            'url' => $url,
            'error' => $res->get_error_message(),
        ]);
        return ttx_error('ttx_session_transport', __('Kunne ikke opprette sesjon.', 'lh-ttx'), ['reason' => $res->get_error_message()]);
    }

    $status = (int) wp_remote_retrieve_response_code($res);
    $body   = wp_remote_retrieve_body($res);
    $hdrs   = wp_remote_retrieve_headers($res);
    $rid    = is_array($hdrs) ? ($hdrs['x-tlx-request-id'] ?? null) : (method_exists($hdrs, 'getArrayCopy') ? ($hdrs->getArrayCopy()['x-tlx-request-id'] ?? null) : null);

    if ($status < 200 || $status >= 300) {
        $decoded = json_decode($body, true);
        $msg = $decoded['message'] ?? __('Feil ved opprettelse av sesjon.', 'lh-ttx');
        LH_Ttx_Logger::error('Tripletex session create failed (HTTP)', [
            'status' => $status,
            'req_id' => $rid,
            'message'=> $msg,
        ]);
        return ttx_error('ttx_session_http', $msg, [
            'status' => $status,
            'requestId' => $rid,
            'body' => $decoded,
        ]);
    }

    $data = json_decode($body, true);
    $data = ttx_unwrap($data);

    // Common shapes: { token: "...", expirationDate: "YYYY-MM-DDThh:mm:ss" }
    $token = (string) ($data['token'] ?? '');
    $expiresEpoch = 0;
    if (!empty($data['expirationDate'])) {
        $ts = strtotime($data['expirationDate']);
        if ($ts !== false) $expiresEpoch = $ts;
    }

    if ($token === '') {
        LH_Ttx_Logger::error('Tripletex session create: token missing', ['req_id' => $rid]);
        return ttx_error('ttx_session_missing', __('Mangler session token fra Tripletex.', 'lh-ttx'));
    }

    if (function_exists('lh_ttx_set_cached_session')) {
        // Default to +55 minutes if expiration missing (a typical session lifetime)
        if ($expiresEpoch <= time()) $expiresEpoch = time() + 55 * 60;
        lh_ttx_set_cached_session($token, $expiresEpoch);
    }

    LH_Ttx_Logger::info('Tripletex session created', [
        'req_id' => $rid,
        'exp'    => $expiresEpoch,
    ]);

    return $token;
}

/** Clear cached session (force refresh) */
function ttx_clear_session_token(): void {
    if (function_exists('lh_ttx_clear_cached_session')) {
        lh_ttx_clear_cached_session();
    }
}

/** -------------------------------------------------------------------------
 * Headers & HTTP
 * -------------------------------------------------------------------------- */
/**
 * Build Authorization header (Basic base64("<companyId>:<sessionToken>")).
 */
function ttx_get_auth_header(?string $session_token = null, ?int $company_id = null): string {
    if ($session_token === null) {
        $token = ttx_get_session_token();
        if (is_wp_error($token)) return ''; // caller should handle missing header on error
        $session_token = $token;
    }
    if ($company_id === null) {
        $company_id = function_exists('lh_ttx_get_company_id') ? (int) lh_ttx_get_company_id() : 0;
    }
    $encoded = base64_encode("{$company_id}:{$session_token}");
    return 'Basic ' . $encoded;
}

/** Default JSON headers (Authorization is added unless explicitly disabled) */
function ttx_default_headers(array $extra = [], bool $no_auth = false): array {
    $headers = [
        'Accept'       => 'application/json',
        'Content-Type' => 'application/json; charset=utf-8',
        'User-Agent'   => 'LavendelhygieneTripletex/' . (defined('LH_TTX_VERSION') ? LH_TTX_VERSION : 'dev'),
    ];
    if (!$no_auth) {
        $auth = ttx_get_auth_header();
        if ($auth) $headers['Authorization'] = $auth;
    }
    return array_merge($headers, $extra);
}

/**
 * HTTP request with retries/backoff and envelope normalization.
 *
 * Args:
 *  - query   array
 *  - body    array
 *  - headers array
 *  - timeout int
 *  - no_auth bool  (skip Authorization header)
 *
 * @return array|\WP_Error normalized decoded body (plain array/object) or error
 */
function ttx_request(string $method, string $path, array $args = []) {
    $maxAttempts = 3;
    $attempt     = 0;
    $refreshed   = false;

    $no_auth     = (bool) ($args['no_auth'] ?? false);
    $query       = isset($args['query']) ? ttx_clean_query((array) $args['query']) : [];
    $headers     = isset($args['headers']) ? (array) $args['headers'] : [];
    $timeout     = isset($args['timeout']) ? (int) $args['timeout'] : 20;
    $bodyArr     = isset($args['body']) ? (array) $args['body'] : null;

    $url = ttx_build_url($path, $query);

    do {
        $attempt++;

        // Build headers per attempt (so re-auth after 401 gets fresh token)
        $h = ttx_default_headers($headers, $no_auth);

        $request_args = [
            'method'  => strtoupper($method),
            'timeout' => $timeout,
            'headers' => $h,
        ];

        if ($bodyArr !== null) {
            $request_args['body'] = wp_json_encode($bodyArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $res = wp_remote_request($url, $request_args);

        if (is_wp_error($res)) {
            // Transport-level error; retry a couple of times with jitter
            LH_Ttx_Logger::error('Tripletex transport error', [
                'url'     => $url,
                'attempt' => $attempt,
                'error'   => $res->get_error_message(),
            ]);
            if ($attempt < $maxAttempts) {
                usleep(200000 + random_int(0, 300000)); // 0.2–0.5s
                continue;
            }
            return ttx_error('ttx_transport', __('Nettverksfeil mot Tripletex.', 'lh-ttx'), ['reason' => $res->get_error_message()]);
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $body   = wp_remote_retrieve_body($res);
        $hdrs   = wp_remote_retrieve_headers($res);
        $rid    = is_array($hdrs) ? ($hdrs['x-tlx-request-id'] ?? null) : (method_exists($hdrs, 'getArrayCopy') ? ($hdrs->getArrayCopy()['x-tlx-request-id'] ?? null) : null);
        $retryAfterHeader = is_array($hdrs) ? ($hdrs['retry-after'] ?? null) : (method_exists($hdrs, 'getArrayCopy') ? ($hdrs->getArrayCopy()['retry-after'] ?? null) : null);

        // Success 2xx
        if ($status >= 200 && $status < 300) {
            if ($body === '' || $status === 204) return []; // no content
            $decoded = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                LH_Ttx_Logger::error('Tripletex JSON decode failed', [
                    'status' => $status,
                    'req_id' => $rid,
                    'error'  => json_last_error_msg(),
                ]);
                return ttx_error('ttx_json', __('Ugyldig JSON fra Tripletex.', 'lh-ttx'), ['body' => $body, 'requestId' => $rid]);
            }
            return ttx_unwrap($decoded);
        }

        // Handle 401 once: refresh session and retry
        if ($status === 401 && !$no_auth && !$refreshed) {
            LH_Ttx_Logger::info('Tripletex 401 received; refreshing session', ['req_id' => $rid, 'attempt' => $attempt]);
            ttx_clear_session_token();
            $refreshed = true;
            // try again immediately
            continue;
        }

        // Handle 429 (respect Retry-After)
        if ($status === 429 && $attempt < $maxAttempts) {
            $delay = 1.0;
            if ($retryAfterHeader && is_numeric($retryAfterHeader)) {
                $delay = min(8.0, max(1.0, (float) $retryAfterHeader));
            }
            $delay += (random_int(0, 250) / 1000.0); // jitter
            LH_Ttx_Logger::info('Tripletex 429 rate limited; backing off', [
                'req_id'  => $rid,
                'wait_s'  => $delay,
                'attempt' => $attempt,
            ]);
            usleep((int) round($delay * 1_000_000));
            continue;
        }

        // Handle 5xx with tiny backoff
        if ($status >= 500 && $status <= 599 && $attempt < $maxAttempts) {
            LH_Ttx_Logger::info('Tripletex 5xx; retrying', ['status' => $status, 'req_id' => $rid, 'attempt' => $attempt]);
            usleep(300000 + random_int(0, 400000)); // 0.3–0.7s
            continue;
        }

        // Build structured error
        $decoded = json_decode($body, true) ?: [];
        $msg  = $decoded['message'] ?? (wp_remote_retrieve_response_message($res) ?: __('Ukjent feil fra Tripletex.', 'lh-ttx'));
        $code = $decoded['code'] ?? $status;

        LH_Ttx_Logger::error('Tripletex HTTP error', [
            'status' => $status,
            'code'   => $code,
            'req_id' => $rid,
            'message'=> $msg,
        ]);

        return ttx_error('ttx_http', $msg, [
            'status'            => $status,
            'code'              => $code,
            'developerMessage'  => $decoded['developerMessage'] ?? null,
            'validationMessages'=> $decoded['validationMessages'] ?? null,
            'requestId'         => $rid,
            'body'              => $decoded,
        ]);

    } while ($attempt < $maxAttempts);

    // Should not reach here; safety:
    return ttx_error('ttx_unknown', __('Ukjent feil mot Tripletex.', 'lh-ttx'));
}

/** Convenience wrappers */
function ttx_get(string $path, array $query = [], array $headers = []) { return ttx_request('GET', $path, ['query' => $query, 'headers' => $headers]); }
function ttx_post(string $path, array $body = [], array $query = [], array $headers = []) { return ttx_request('POST', $path, ['body' => $body, 'query' => $query, 'headers' => $headers]); }
function ttx_put(string $path, array $body = [], array $query = [], array $headers = []) { return ttx_request('PUT', $path, ['body' => $body, 'query' => $query, 'headers' => $headers]); }
function ttx_delete(string $path, array $query = [], array $headers = []) { return ttx_request('DELETE', $path, ['query' => $query, 'headers' => $headers]); }

/** -------------------------------------------------------------------------
 * Customers
 * -------------------------------------------------------------------------- */

/**
 * Create customer, returning new id.
 *
 * @return int|\WP_Error
 */
function ttx_customers_create(array $payload) {
    // OpenAPI: POST /customer expects a Customer object in the body
    $res = ttx_post('/customer', $payload);
    if (is_wp_error($res)) return $res;

    $id = (int) ($res['id'] ?? 0);
    if ($id <= 0) {
        return ttx_error('ttx_create_missing_id', __('Tripletex returnerte ikke en gyldig ID.', 'lh-ttx'), ['response' => $res]);
    }
    return $id;
}

/**
 * Update customer by id (PUT partial).
 *
 * @return bool|\WP_Error
 */
function ttx_customers_update(int $id, array $payload, ?int $version = null) {
    if ($id <= 0) return ttx_error('ttx_id_invalid', __('Ugyldig Tripletex-ID.', 'lh-ttx'));
    if ($version !== null) $payload['version'] = $version;

    // OpenAPI: PUT /customer/{id} expects a (partial) Customer object
    $res = ttx_put("/customer/{$id}", $payload);
    if (is_wp_error($res)) return $res;

    return true;
}

/** -------------------------------------------------------------------------
 * Products
 * -------------------------------------------------------------------------- */
/**
 * Get product price (ex. VAT). Supports optional price list via /productprice.
 *
 * @return float|\WP_Error
 */
function ttx_products_get_price(int $product_id, ?int $price_list_id = null) {
    if ($product_id <= 0) return ttx_error('ttx_id_invalid', __('Ugyldig produkt-ID.', 'lh-ttx'));

    if ($price_list_id) {
        // Try dedicated product price endpoint if available
        $res = ttx_get('/productprice', [
            'product.id'   => $product_id,
            'priceList.id' => $price_list_id,
            'count'        => 1,
            'fields'       => 'id,price.value,product(id),priceList(id)',
        ]);
        if (is_wp_error($res)) return $res;
        if (is_array($res) && isset($res[0]['price']['value'])) {
            return (float) $res[0]['price']['value'];
        }
    }

    // Fallback: read price from product itself (field name depends on setup)
    $res = ttx_get("/product/{$product_id}", [
        'fields' => 'id,salesPrice.value,price.value,unitPrice.value',
    ]);
    if (is_wp_error($res)) return $res;

    $candidates = [
        $res['salesPrice']['value'] ?? null,
        $res['price']['value'] ?? null,
        $res['unitPrice']['value'] ?? null,
    ];
    foreach ($candidates as $v) {
        if ($v !== null) return (float) $v;
    }

    return ttx_error('ttx_price_missing', __('Fant ingen prisfelt for produktet.', 'lh-ttx'), ['response' => $res]);
}

/**
 * Get stock (qty). Endpoint naming varies; try an inventory search, then product.
 *
 * @return int|\WP_Error
 */
function ttx_products_get_stock(int $product_id, ?int $warehouse_id = null) {
    if ($product_id <= 0) return ttx_error('ttx_id_invalid', __('Ugyldig produkt-ID.', 'lh-ttx'));

    // Try common inventory endpoint pattern
    $q = [
        'product.id' => $product_id,
        'count'      => 1,
        'fields'     => 'quantity,available,product(id)',
    ];
    if ($warehouse_id) $q['warehouse.id'] = $warehouse_id;

    $res = ttx_get('/inventory/available', $q);
    if (!is_wp_error($res) && is_array($res) && isset($res[0])) {
        $row = $res[0];
        $qty = $row['available'] ?? $row['quantity'] ?? null;
        if ($qty !== null) return (int) $qty;
    }

    // Fallback: read stock from product if exposed
    $res2 = ttx_get("/product/{$product_id}", [
        'fields' => 'id,stock.quantity,stock.available,inventory',
    ]);
    if (is_wp_error($res2)) return $res2;

    $qty2 = $res2['stock']['available'] ?? $res2['stock']['quantity'] ?? $res2['inventory'] ?? null;
    if ($qty2 !== null) return (int) $qty2;

    return ttx_error('ttx_stock_missing', __('Fant ikke lagerstatus for produktet.', 'lh-ttx'), ['response' => $res2]);
}

/** -------------------------------------------------------------------------
 * Orders
 * -------------------------------------------------------------------------- */

/**
 * Create a new order; return new id.
 *
 * @return int|\WP_Error
 */
function ttx_orders_create(array $payload) {
    // OpenAPI: POST /order expects an Order object
    $res = ttx_post('/order', $payload);
    if (is_wp_error($res)) return $res;

    $id = (int) ($res['id'] ?? 0);
    if ($id <= 0) {
        return ttx_error('ttx_create_missing_id', __('Tripletex returnerte ikke en gyldig ordre-ID.', 'lh-ttx'), ['response' => $res]);
    }
    return $id;
}