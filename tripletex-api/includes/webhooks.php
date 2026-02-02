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
        if (strpos($event, 'order.') === 0) {
            return $this->handle_order_event($event, $objectId, $value, $subscriptionId, $requestId);
        }

        LH_Ttx_Logger::info('Webhook ignored (unsupported event)', [
            'event'          => $event,
            'subscriptionId' => $subscriptionId,
            'id'             => $objectId,
        ]);

        // Return 200 to avoid disabling the subscription even if we ignore it
        return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
    }

    /* --------------------- Event types ---------------------- */

    private function handle_product_event(string $event, int $ttx_product_id, ?array $value, int $subscriptionId, ?string $requestId) {
        $sku = isset($value['number']) ? trim((string) $value['number']) : '';
        if ($sku === '') {
            LH_Ttx_Logger::info('Webhook product event: missing SKU, aborting', [
                'event'          => $event,
                'ttx_product_id' => $ttx_product_id,
                'subscriptionId' => $subscriptionId,
            ]);
            return new \WP_REST_Response(['ok' => true, 'mapped' => false, 'reason' => 'missing_sku'], 200);
        }

        // Find local product by SKU
        $product_id = $this->find_wc_product_by_sku($sku);
        if ($product_id <= 0) {
            LH_Ttx_Logger::info('Webhook product event: no local product with SKU', [
                'event'          => $event,
                'sku'            => $sku,
                'ttx_product_id' => $ttx_product_id,
                'subscriptionId' => $subscriptionId,
            ]);
            return new \WP_REST_Response(['ok' => true, 'mapped' => false, 'reason' => 'sku_not_found'], 200);
        }

        if ($event !== 'product.update') {
            LH_Ttx_Logger::info('Webhook product event ignored (unhandled verb)', [
                'event'          => $event,
                'sku'            => $sku,
                'ttx_product_id' => $ttx_product_id,
                'subscriptionId' => $subscriptionId,
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
                'sku'            => $sku,
                'event'          => $event,
                'error'          => $priceRes->get_error_message(),
            ]);
            // 200 to avoid disabling
            return new \WP_REST_Response(['ok' => false, 'error' => $priceRes->get_error_message()], 200);
        }

        // TODO: confirm if product.update is triggered on stock updates.
        // $stockRes = $svc->sync_stock_from_tripletex($product_id, null);

        LH_Ttx_Logger::info('Webhook product sync OK', [
            'ttx_product_id' => $ttx_product_id,
            'sku'            => $sku,
            'event'          => $event,
            'subscriptionId' => $subscriptionId,
        ]);

        return new \WP_REST_Response(['ok' => true], 200);
    }

    private function handle_order_event(string $event, int $ttx_order_id, ?array $value, int $subscriptionId, ?string $requestId) {
        // We only care about order.update for now
        if ($event !== 'order.update') {
            LH_Ttx_Logger::info('Webhook order event ignored (unhandled verb)', [
                'event'          => $event,
                'ttx_order_id'   => $ttx_order_id,
                'subscriptionId' => $subscriptionId,
            ]);

            return new \WP_REST_Response(['ok' => true, 'ignored' => true], 200);
        }

        if ($value === null) {
            LH_Ttx_Logger::info('Webhook order.update received without value payload', [
                'event'          => $event,
                'ttx_order_id'   => $ttx_order_id,
                'subscriptionId' => $subscriptionId,
            ]);

            // still 200 so Tripletex does not disable the subscription
            return new \WP_REST_Response(['ok' => true, 'ignored' => true, 'reason' => 'missing_value'], 200);
        }

        $rawStatus = $value['status'] ?? '';
        $status = strtoupper(trim((string) $rawStatus));

        if ($status !== 'READY_FOR_INVOICING') {
            LH_Ttx_Logger::info('Webhook order.update status not handled', [
                'event'          => $event,
                'ttx_order_id'   => $ttx_order_id,
                'subscriptionId' => $subscriptionId,
                'status'         => $status,
            ]);

            return new \WP_REST_Response(['ok' => true,'ignored' => true,
                'reason' => 'status_not_ready_for_invoicing','status' => $status], 200);
        }

        $wc_order_id = $this->find_wc_order_by_tripletex_order_id($ttx_order_id);

        if ($wc_order_id <= 0) {
            LH_Ttx_Logger::info('Webhook order.update: no local order with Tripletex order id', [
                'event'          => $event,
                'ttx_order_id'   => $ttx_order_id,
                'subscriptionId' => $subscriptionId,
                'status'         => $status,
            ]);

            return new \WP_REST_Response(['ok' => true,'mapped' => false,'reason' => 'order_not_found'], 200);
        }

        $order = wc_get_order($wc_order_id);
        if (!$order) {
            LH_Ttx_Logger::error('Webhook order.update: wc_get_order failed', [
                'event'          => $event,
                'ttx_order_id'   => $ttx_order_id,
                'subscriptionId' => $subscriptionId,
                'wc_order_id'    => $wc_order_id,
            ]);

            return new \WP_REST_Response(['ok' => false,'error' => 'order_load_failed'], 200);
        }

        // Avoid re-doing work if already completed
        if ($order->get_status() === 'completed') {
            LH_Ttx_Logger::info('Webhook order.update: local order already completed', [
                'event'          => $event,
                'ttx_order_id'   => $ttx_order_id,
                'subscriptionId' => $subscriptionId,
                'wc_order_id'    => $wc_order_id,
            ]);

            return new \WP_REST_Response(['ok' => true, 'mapped' => true, 'already_completed' => true], 200);
        }

        $order->update_status(
            'completed',
            __('Oppdatert til «fullført» fra Tripletex (status READY_FOR_INVOICING).', 'lh-ttx')
        );

        LH_Ttx_Logger::info('Webhook order.update: local order marked completed from Tripletex', [
            'event'          => $event,
            'ttx_order_id'   => $ttx_order_id,
            'subscriptionId' => $subscriptionId,
            'wc_order_id'    => $wc_order_id,
            'status'         => $status,
        ]);

        return new \WP_REST_Response(['ok' => true,'mapped' => true,'wc_order_id' => $wc_order_id], 200);
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

        LH_Ttx_Logger::info(' - Unauthorized webhook request recieved');

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

    private function find_wc_product_by_sku(string $sku): int {
        if ($sku === '') return 0;
        // Core helper returns first matching product id or 0
        $id = wc_get_product_id_by_sku($sku);
        return $id ? (int) $id : 0;
    }

    private function find_wc_order_by_tripletex_order_id(int $ttx_order_id): int {
        if ($ttx_order_id <= 0) return 0;

        // Use the constant if available, otherwise fall back to the raw meta key
        $meta_key = defined('LH_TTX_META_TTX_ORDER_ID') ? LH_TTX_META_TTX_ORDER_ID : '_tripletex_order_id';

        $orders = wc_get_orders([
            'type'       => 'shop_order',
            'limit'      => 1,
            'return'     => 'ids',
            'meta_query' => [
                [
                    'key'     => $meta_key,
                    'value'   => (string) $ttx_order_id,
                    'compare' => '=',
                ],
            ],
        ]);

        if (empty($orders)) return 0;
        return (int) $orders[0];
    }

}