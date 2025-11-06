<?php
if (!defined('ABSPATH')) exit;

final class LH_Ttx_Webhooks {

    public function init(): void {
        add_action('rest_api_init', function () {
            // Unified Tripletex event webhook
            register_rest_route(
                'lh-ttx/v1',
                '/webhooks/event',
                [
                    'methods'             => 'POST',
                    'callback'            => [$this, 'handle_event'],
                    'permission_callback' => '__return_true',
                ]
            );
        });
    }

    /* ------------------------ Handler ------------------------ */

    public function handle_event(\WP_REST_Request $request) {
        $auth = $this->verify_auth($request);
        if (is_wp_error($auth)) {
            return new \WP_REST_Response(['error' => $auth->get_error_message()], 401);
        }

        $data = $this->decode_json($request);
        if (is_wp_error($data)) {
            return new \WP_REST_Response(['error' => $data->get_error_message()], 400);
        }

        // Expected payload: { subscriptionId, event, id, value }
        $subscriptionId = (int) ($data['subscriptionId'] ?? 0);
        $event          = (string) ($data['event'] ?? '');
        $objectId       = (int) ($data['id'] ?? 0);
        $value          = is_array($data['value'] ?? null) ? $data['value'] : null; // null on *.delete
        $requestId      = $request->get_header('x-tlx-request-id');

        if ($event === '' || $objectId <= 0) {
            return new \WP_REST_Response(['error' => __('Ugyldig payload: mangler event/id.', 'lh-ttx')], 400);
        }

        // Route by event prefix
        if (strpos($event, 'product.') === 0) {
            return $this->handle_product_event($event, $objectId, $value, $subscriptionId, $requestId);
        }

        // TODO: Add handlers for other events if/when needed (order.*, customer.*, etc.)
        LH_Ttx_Logger::info('Webhook ignored (unsupported event)', [
            'event'          => $event,
            'subscriptionId' => $subscriptionId,
            'id'             => $objectId,
            'requestId'      => $requestId,
        ]);

        // Return 200 to avoid disabling the subscription even if we ignore it
        return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
    }

    /* --------------------- Event types ---------------------- */

    private function handle_product_event(string $event, int $ttx_product_id, ?array $value, int $subscriptionId, ?string $requestId) {
        $product_id = $this->find_wc_product_by_tripletex_id($ttx_product_id);

        if (!$product_id) {
            // No local mapping yet; accept to avoid retries/disabling
            LH_Ttx_Logger::info('Webhook product event: no local mapping', [
                'event'          => $event,
                'ttx_product_id' => $ttx_product_id,
                'subscriptionId' => $subscriptionId,
                'requestId'      => $requestId,
            ]);
            return new \WP_REST_Response(['ok' => true, 'mapped' => false], 200);
        }

        // For create/update: pull fresh data (price; optionally stock)
        if ($event === 'product.create' || $event === 'product.update') {
            // TODO: If priceLists are included in $value, pick correct priceListId. For now, null = default list.
            $price_list_id = null;

            $svc = LH_Ttx_Service_Registry::instance()->products();

            $priceRes = $svc->sync_price_from_tripletex($product_id, $price_list_id);
            if (is_wp_error($priceRes)) {
                LH_Ttx_Logger::error('Webhook product price sync failed', [
                    'ttx_product_id' => $ttx_product_id,
                    'event'          => $event,
                    'error'          => $priceRes->get_error_message(),
                    'requestId'      => $requestId,
                ]);
                // Still 200 to avoid disabling; report failure
                return new \WP_REST_Response(['ok' => false, 'error' => $priceRes->get_error_message()], 200);
            }

            // Optional: stock sync — Tripletex doesn’t publish a distinct stock event; confirm if product.update carries inventory changes.
            // TODO: Determine proper warehouseId and whether inventory changes are reflected on product.update.
            // $stockRes = $svc->sync_stock_from_tripletex($product_id, /* $warehouse_id */ null);

            LH_Ttx_Logger::info('Webhook product sync OK', [
                'ttx_product_id' => $ttx_product_id,
                'event'          => $event,
                'subscriptionId' => $subscriptionId,
                'requestId'      => $requestId,
            ]);

            return new \WP_REST_Response(['ok' => true], 200);
        }

        if ($event === 'product.delete') {
            // TODO: Decide policy. Options:
            // - Unlink mapping meta (_tripletex_product_id) locally
            // - Set product to draft or out-of-stock
            LH_Ttx_Logger::info('Webhook product deleted (no local action yet)', [
                'ttx_product_id' => $ttx_product_id,
                'subscriptionId' => $subscriptionId,
                'requestId'      => $requestId,
            ]);
            return new \WP_REST_Response(['ok' => true], 200);
        }

        // Any other product.* verbs
        LH_Ttx_Logger::info('Webhook product event ignored (unhandled verb)', [
            'event'          => $event,
            'ttx_product_id' => $ttx_product_id,
            'subscriptionId' => $subscriptionId,
            'requestId'      => $requestId,
        ]);

        return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
    }

    /* ------------------------ Helpers ------------------------ */

    private function verify_auth(\WP_REST_Request $request) {
        $secret = (string) lh_ttx_get_webhook_secret();
        if ($secret === '') {
            // No secret configured; accept but log a warning
            LH_Ttx_Logger::info('Webhook received without configured secret', []);
            return new \WP_Error('unauthorized', __('Ugyldig eller manglende webhook-autentisering.', 'lh-ttx'));
        }

        // Recommended: Authorization: Bearer <secret>
        $auth = $request->get_header('authorization');
        if (is_string($auth) && stripos($auth, 'bearer ') === 0) {
            $token = trim(substr($auth, 7));
            if (hash_equals($secret, $token)) return true;
        }

        // Alternative: X-Tripletex-Token: <secret>
        $hdrToken = $request->get_header('x-tripletex-token');
        if (is_string($hdrToken) && hash_equals($secret, $hdrToken)) return true;

        // Fallback: ?token=<secret>
        $qToken = (string) $request->get_param('token');
        if ($qToken !== '' && hash_equals($secret, $qToken)) return true;

        return new \WP_Error('unauthorized', __('Ugyldig eller manglende webhook-autentisering.', 'lh-ttx'));
    }

    private function decode_json(\WP_REST_Request $request) {
        $raw = (string) $request->get_body();
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('invalid_json', __('Ugyldig JSON i webhook payload.', 'lh-ttx'));
        }
        return is_array($data) ? $data : [];
    }

    private function find_wc_product_by_tripletex_id(int $ttx_product_id): int {
        $ids = wc_get_products([
            'limit'      => 1,
            'status'     => 'any',
            'meta_key'   => '_tripletex_product_id',
            'meta_value' => $ttx_product_id,
            'return'     => 'ids',
        ]);
        return is_array($ids) && !empty($ids) ? (int) $ids[0] : 0;
    }
}