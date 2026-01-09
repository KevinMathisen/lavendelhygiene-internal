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
    function lh_ttx_get_base_url() { return 'https://tripletex.no/v2'; }
}
if (!class_exists('LH_Ttx_Logger')) {
    final class LH_Ttx_Logger { public static function info($m,$c=[]){ } public static function error($m,$c=[]){ } }
}

function ttx_log_attempt(string $phase, array $ctx): void {
    // Redact any auth header if accidentally passed
    if (isset($ctx['request']['headers']['Authorization'])) {
        $ctx['request']['headers']['Authorization'] = '[redacted]';
    }
    LH_Ttx_Logger::info("Ttx request: {$phase}", $ctx);
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
 * Calls /token/session/:create
 *
 * @return string|\WP_Error
 */
function ttx_get_session_token() {
    if (function_exists('lh_ttx_get_cached_session')) {
        $cached = lh_ttx_get_cached_session();

        $cachedToken   = (string) ($cached['token'] ?? '');
        $cachedExpires = (string) ($cached['expires'] ?? '');
        $now = new DateTimeImmutable('now');

        if ($cachedToken !== '' && $cachedExpires !== '') {
            try {
                // If only a date is provided (YYYY-MM-DD), treat it as start of day
                //  i.e. if we are at the same date, the token has expired
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cachedExpires)) {
                    $exp = new DateTimeImmutable($cachedExpires . ' 00:00:00');
                } else {
                    $exp = new DateTimeImmutable($cachedExpires);
                }
                $bufferNow = (new DateTimeImmutable('now'))->add(new DateInterval('PT10M'));
                if ($exp > $bufferNow) {
                    return $cachedToken;
                }
            } catch (\Exception $e) {
                // fall through to create a new session
            }
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

    // Build request
    $expirationDate = (new DateTimeImmutable('now'))->add(new DateInterval('P2D'))->format('Y-m-d');
    $url = ttx_build_url('/token/session/:create');

    $args = [
        'method'  => 'POST',
        'timeout' => 20,
        'headers' => [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent'   => 'LavendelhygieneTripletex/' . (defined('LH_TTX_VERSION') ? LH_TTX_VERSION : 'dev'),
        ],
        'body'    => wp_json_encode([
            'consumerToken'  => $consumer,
            'employeeToken'  => $employee,
            'expirationDate' => $expirationDate,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
    $rid    = wp_remote_retrieve_header($res, 'x-tlx-request-id');

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

    // Response body format
    // { "value": { ... "expirationDate": "2020-01-01", "token": "eyJ0b2...0=", ... }}

    $data = json_decode($body, true);
    $data = ttx_unwrap($data);

    $token = (string) ($data['token'] ?? '');
    $expiresStr = (string) ($data['expirationDate'] ?? '');

    if ($token === '') {
        LH_Ttx_Logger::error('Tripletex session create: token missing', ['req_id' => $rid]);
        return ttx_error('ttx_session_missing', __('Mangler session token fra Tripletex.', 'lh-ttx'));
    }

    if (function_exists('lh_ttx_set_cached_session')) {
        lh_ttx_set_cached_session($token, $expiresStr);
    }

    LH_Ttx_Logger::info('Tripletex session created', [
        'req_id' => $rid,
        'exp'    => $expiresStr,
    ]);

    return $token;
}

/** Clear cached session */
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

/** Default JSON headers  */
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
            ttx_log_attempt('transport_error', [
                'method'  => strtoupper($method),
                'url'     => $url,
                'requestBody' => $bodyArr,
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
        $rid    = wp_remote_retrieve_header($res, 'x-tlx-request-id');
        $retryAfterHeader = wp_remote_retrieve_header($res, 'retry-after');

        $decodedForLog = null;
        if ($body !== '') {
            $tmp = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decodedForLog = $tmp;
            }
        }

        // Log reponse
        ttx_log_attempt('response', [
            'method'      => strtoupper($method),
            'url'         => $url,
            'status'      => $status,
            'requestId'   => $rid ?: null,
            'requestBody' => $bodyArr,
            'responseBody' => $decodedForLog,
        ]);

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
    // POST /customer expects a Customer object in the body
    $res = ttx_post('/customer', $payload);
    if (is_wp_error($res)) return $res;

    $id = (int) ($res['id'] ?? 0);
    if ($id <= 0) {
        return ttx_error('ttx_create_missing_id', __('Tripletex returnerte ikke en gyldig ID.', 'lh-ttx'), ['response' => $res]);
    }
    return $id;
}

/**
 * Update customer by id.
 *
 * @return bool|\WP_Error
 */
function ttx_customers_update(int $id, array $payload, ?int $version = null) {
    if ($id <= 0) return ttx_error('ttx_id_invalid', __('Ugyldig Tripletex-ID.', 'lh-ttx'));
    if ($version !== null) $payload['version'] = $version;

    // PUT /customer/{id} expects a (partial) Customer object
    $res = ttx_put("/customer/{$id}", $payload);
    if (is_wp_error($res)) return $res;

    return true;
}

/**
 * Fetch a customer by id.
 *
 * @return array|\WP_Error
 */
function ttx_customers_get(int $id, $fields = null) {
    if ($id <= 0) return ttx_error('ttx_id_invalid', __('Ugyldig Tripletex-ID.', 'lh-ttx'));
    $defaultFields = 'id,email,phoneNumber,postalAddress(addressLine1,addressLine2,postalCode,city),deliveryAddress(addressLine1,addressLine2,postalCode,city)';
    $fieldsParam = $fields ? $fields : $defaultFields;

    $res = ttx_get("/customer/{$id}", ['fields' => $fieldsParam]);
    if (is_wp_error($res)) return $res;
    return $res;
}

/** -------------------------------------------------------------------------
 * Products
 * -------------------------------------------------------------------------- */
/**
 * Get product price (ex. VAT).
 *
 * @return float|\WP_Error
 */
function ttx_products_get_price(int $product_id) {
    if ($product_id <= 0) return ttx_error('ttx_id_invalid', __('Ugyldig produkt-ID.', 'lh-ttx'));

    $res = ttx_get("/product/{$product_id}", [
        'fields' => 'id,priceExcludingVatCurrency',
    ]);
    if (is_wp_error($res)) return $res;

    if ($res['priceExcludingVatCurrency'] !== null) return (float) $res['priceExcludingVatCurrency'];

    return ttx_error('ttx_price_missing', __('Fant ingen prisfelt for produktet.', 'lh-ttx'), ['response' => $res]);
}

/**
 * Get stock (qty)
 *
 * @return int|\WP_Error
 */
function ttx_products_get_stock(int $product_id, ?int $warehouse_id = null) {
    if ($product_id <= 0) return ttx_error('ttx_id_invalid', __('Ugyldig produkt-ID.', 'lh-ttx'));

    $res = ttx_get("/product/{$product_id}", [
        'fields' => 'id,availableStock',
    ]);
    if (is_wp_error($res)) return $res;

    if ($res['availableStock'] !== null) return (int) $res['availableStock'];

    return ttx_error('ttx_stock_missing', __('Fant ikke lagerstatus for produktet.', 'lh-ttx'), ['response' => $res]);
}

/**
 * Get product tripletex id by SKU/product number
 *
 * @return int|\WP_Error
 */
function ttx_products_get_ttx_id_from_sku(string $product_sku) {
    $sku = trim($product_sku);
    if ($sku === '') {
        return ttx_error('product_sku_invalid', __('Ugyldig produktnummer (SKU).', 'lh-ttx'));
    }

    $res = ttx_get('/product', [
        'productNumber' => $sku,
        'count'         => 1,
        'fields'        => 'id',
    ]);
    if (is_wp_error($res)) return $res;

    if (is_array($res) && !empty($res)) {
        $first = (array) $res[0];
        $id = (int) ($first['id'] ?? 0);
        if ($id > 0) return $id;
    }

    return ttx_error('ttx_product_not_found', __('Fant ikke Tripletex-produkt for gitt SKU.', 'lh-ttx'), [
        'sku'      => $sku,
    ]);
}

/** -------------------------------------------------------------------------
 * Orders
 * -------------------------------------------------------------------------- */

/**
 * Create a new order, return new id.
 *
 * @return int|\WP_Error
 */
function ttx_orders_create(array $payload) {
    // POST /order expects Order object
    $res = ttx_post('/order', $payload);
    if (is_wp_error($res)) return $res;

    $id = (int) ($res['id'] ?? 0);
    if ($id <= 0) {
        return ttx_error('ttx_create_missing_id', __('Tripletex returnerte ikke en gyldig ordre-ID.', 'lh-ttx'), ['response' => $res]);
    }
    return $id;
}

/** -------------------------------------------------------------------------
 * DiscountPolicy
 * -------------------------------------------------------------------------- */

/**
 * List discount policies for a Tripletex customer.
 *
 * @param int   $customer_id Tripletex customer id
 * @return array|\WP_Error Array of DiscountPolicy records
 */
function ttx_discountpolicy_list(int $customer_id) {
    if ($customer_id <= 0) {
        return ttx_error('ttx_customer_id_invalid', __('Ugyldig Tripletex-kunde-ID.', 'lh-ttx'));
    }

    $query = [
        'customerId'    => $customer_id,
        'discountType'  => 'CUSTOMER_DISCOUNT',
        'from'          => 0,
        'count'         => 1000,
        'fields'        => 'percentage,salesPriceWithDiscount,product(id)',
    ];

    $res = ttx_get('/discountPolicy', $query);
    if (is_wp_error($res)) return $res;

    if (!is_array($res)) {
        return ttx_error('ttx_discountpolicy_invalid_response', __('Ugyldig respons fra Tripletex (discountPolicy).', 'lh-ttx'), [
            'response' => $res,
        ]);
    }

    return $res;
}