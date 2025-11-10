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
                    'permission_callback' => [$this, 'permission_check'],
                ]
            );
        });
    }

    /* ------------------------ Handler ------------------------ */

    public function permission_check(\WP_REST_Request $request) {
        $auth = $this->verify_auth($request);
        return is_wp_error($auth) ? $auth : true;
    }

    public function handle_event(\WP_REST_Request $request) {
        // auth handled by permission_check

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
            LH_Ttx_Logger::info('Webhook product event: no local mapping', [
                'event'          => $event,
                'ttx_product_id' => $ttx_product_id,
                'subscriptionId' => $subscriptionId,
                'requestId'      => $requestId,
            ]);
            return new \WP_REST_Response(['ok' => true, 'mapped' => false], 200);
        }

        if ($event !== 'product.update') {
            LH_Ttx_Logger::info('Webhook product event ignored (unhandled verb)', [
                'event'          => $event,
                'ttx_product_id' => $ttx_product_id,
                'subscriptionId' => $subscriptionId,
                'requestId'      => $requestId,
            ]);

            return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
        }

        $price = $value['priceExcludingVatCurrency'] ?? null;

        $svc = LH_Ttx_Service_Registry::instance()->products();

        // pass price, so if it is defined we use this directly instead of asking tripletex for updated price
        $priceRes = $svc->sync_price_from_tripletex($product_id, $price);
        if (is_wp_error($priceRes)) {
            LH_Ttx_Logger::error('Webhook product price sync failed', [
                'ttx_product_id' => $ttx_product_id,
                'event'          => $event,
                'error'          => $priceRes->get_error_message(),
                'requestId'      => $requestId,
            ]);
            // 200 to avoid disabling
            return new \WP_REST_Response(['ok' => false, 'error' => $priceRes->get_error_message()], 200);
        }

        // TODO: confirm if product.update is triggered on stock updates.
        // $stockRes = $svc->sync_stock_from_tripletex($product_id, null);

        LH_Ttx_Logger::info('Webhook product sync OK', [
            'ttx_product_id' => $ttx_product_id,
            'event'          => $event,
            'subscriptionId' => $subscriptionId,
            'requestId'      => $requestId,
        ]);

        return new \WP_REST_Response(['ok' => true], 200);
    }

    /* ------------------------ Helpers ------------------------ */

    private function verify_auth(\WP_REST_Request $request) {
        $secret = (string) lh_ttx_get_webhook_secret();
        if ($secret === '') {
            // No secret configured
            LH_Ttx_Logger::info('Webhook received without configured secret', []);
            return new \WP_Error('unauthorized', __('Ugyldig eller manglende webhook-autentisering.', 'lh-ttx'));
        }

        // Authorization: Bearer <secret>
        $auth = $request->get_header('authorization');
        if (is_string($auth) && stripos($auth, 'bearer ') === 0) {
            $token = trim(substr($auth, 7));
            if (hash_equals($secret, $token)) return true;
        }

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
        // Tripletex product IDs == Woo product/variation IDs
        $product = wc_get_product($ttx_product_id);
        return $product ? (int) $product->get_id() : 0;
    }
}