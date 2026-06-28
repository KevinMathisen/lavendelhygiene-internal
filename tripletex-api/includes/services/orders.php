<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('LH_Ttx_Logger')) {
    // Fallback no-op logger if file included directly.
    final class LH_Ttx_Logger { public static function info($m,$c=[]){ } public static function error($m,$c=[]){ } }
}


/* ========================================================================== */
/* Orders Service                                                              */
/* ========================================================================== */

final class LH_Ttx_Orders_Service {

    /**
     * Create a Tripletex order from a WooCommerce order.
     * - Ensures the customer is linked/created.
     * - Saves _tripletex_order_id meta on success.
     * - Adds an admin note with the remote id.
     *
     * @param int $order_id
     * @return int|\WP_Error Tripletex order id
     */
    public function create_remote_order(int $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return new WP_Error('order_missing', __('Finner ikke ordre.', 'lh-ttx'));

        $existing_ttx_order_id = (int) $order->get_meta(LH_TTX_META_TTX_ORDER_ID, true);
        if ($existing_ttx_order_id > 0) {
            LH_Ttx_Logger::info('Skipped duplicate Tripletex order creation',
                ['order_id' => $order_id, 'ttx_order_id' => $existing_ttx_order_id,]);
            return $existing_ttx_order_id;
        }

        $user_id = (int) $order->get_user_id();

        if ($user_id <= 0) {
            return new WP_Error('guest_not_supported', __('Gjestebestillinger støttes ikke for Tripletex-synk.', 'lh-ttx'));
        }

        $ttx_customer_id = lh_ttx_get_linked_tripletex_id($user_id);
        if (!$ttx_customer_id) {
            return new WP_Error('link_failed', __('Kunde er ikke koblet mot Tripletex.', 'lh-ttx'));
        }

        $customer_service = new LH_Ttx_Customers_Service();

        $ttx_contact_id = $customer_service->ensure_and_get_contact_tripletex_id($user_id, $ttx_customer_id);
        if (is_wp_error($ttx_contact_id)) {
            LH_Ttx_Logger::error('Tripletex order create failed: contact sync failed', [
                'order_id' => $order_id,
                'user_id'  => $user_id,
                'ttx_customer_id' => $ttx_customer_id,
                'error'    => $ttx_contact_id->get_error_message(),
                'data'     => $ttx_contact_id->get_error_data(),
            ]);
            return $ttx_contact_id;
        }

        $ttx_delivery_address_id = $customer_service->ensure_and_get_delivery_address_tripletex_id($user_id, $ttx_customer_id);

        if (is_wp_error($ttx_delivery_address_id)) {
            LH_Ttx_Logger::error('Tripletex order create failed: delivery address sync failed', [
                'order_id' => $order_id,
                'user_id'  => $user_id,
                'ttx_customer_id' => $ttx_customer_id,
                'ttx_contact_id'  => (int) $ttx_contact_id,
                'error'    => $ttx_delivery_address_id->get_error_message(),
                'data'     => $ttx_delivery_address_id->get_error_data(),
            ]);
            return $ttx_delivery_address_id;
        }

        // Build payload
        $payload = $this->map_order_to_tripletex_payload(
            $order,
            $ttx_customer_id,
            (int) $ttx_contact_id,
            (int) $ttx_delivery_address_id
        );

        // Create in Tripletex
        $created_id = ttx_orders_create($payload);
        if (is_wp_error($created_id)) {
            LH_Ttx_Logger::error('Tripletex order create failed', [
                'order_id'                => $order_id,
                'user_id'                 => $user_id,
                'ttx_customer_id'         => $ttx_customer_id,
                'ttx_contact_id'          => (int) $ttx_contact_id,
                'ttx_delivery_address_id' => (int) $ttx_delivery_address_id,
                'payload'                 => $payload,
                'error'                   => $created_id->get_error_message(),
                'data'                    => $created_id->get_error_data(),
            ]);

            return $created_id;
        }

        // Store mapping + note
        $order->update_meta_data( LH_TTX_META_TTX_ORDER_ID, (int) $created_id );
        $order->update_meta_data( LH_TTX_META_TTX_LAST_SYNC_AT, time() );
        $order->save();

        $order->add_order_note(sprintf(__('Tripletex-ordre opprettet (ID: %d).', 'lh-ttx'), (int) $created_id));

        LH_Ttx_Logger::info('Created Tripletex order', [
            'order_id'                => $order_id,
            'ttx_order_id'            => (int) $created_id,
            'user_id'                 => $user_id,
            'ttx_customer_id'         => $ttx_customer_id,
            'ttx_contact_id'          => (int) $ttx_contact_id,
            'ttx_delivery_address_id' => (int) $ttx_delivery_address_id,
        ]);

        return (int) $created_id;
    }

    /* ------------------------- Helpers ------------------------- */

    /**
     * Build Tripletex order payload from WC_Order.
     *
     * @param \WC_Order $order
     * @param int       $ttx_customer_id
     * @param int       $ttx_contact_id
     * @param int       $ttx_delivery_address_id
     * @return array
     */
    private function map_order_to_tripletex_payload(
        \WC_Order $order,
        int $ttx_customer_id,
        int $ttx_contact_id,
        int $ttx_delivery_address_id
    ): array {
        $order_dt = (new DateTimeImmutable('@' . $order->get_date_created()->getTimestamp()))->format('Y-m-d');

        $payload = [
            'customer'      => [ 'id' => $ttx_customer_id ],
            'status'        => 'CONFIRMATION_SENT',
            'orderDate'     => $order_dt, 
            'deliveryDate'  => $order_dt,
        ];

        if ($ttx_contact_id > 0) {
            $payload['contact'] = ['id' => $ttx_contact_id];
        }

        if ($ttx_delivery_address_id > 0) {
            $payload['deliveryAddress'] = ['id' => $ttx_delivery_address_id];
        }

        $payload['invoiceComment'] = $this->compose_invoice_comment($order);

        // prepare discount service for user so we can check if they have discounts
        $user_id = (int) $order->get_user_id();
        $discSvc = new LH_Ttx_Discounts_Service();

        $lines = [];
        foreach ($order->get_items() as $item) {
            $product   = $item->get_product();
            $qty       = (float) $item->get_quantity();

            $line = [ 'count' => $qty, ];

            if ($product instanceof \WC_Product) {
                $ttx_product_id = get_tripletex_product_id_from_wc_product($product);

                if (!is_wp_error($ttx_product_id) && (int) $ttx_product_id > 0) {
                    $line['product'] = ['id' => (int) $ttx_product_id];

                    if ($user_id > 0) {
                        $discount = $discSvc->get_discount_for_product($product, $user_id);

                        if (!is_wp_error($discount) && is_array($discount)) {
                            $pct = (float) ($discount['pct'] ?? 0);

                            if ($pct > 0) {
                                $line['discount'] = $pct;
                            }
                        }
                    }
                } else {
                    $line['description'] = $item->get_name();
                }
            } else {
                $line['description'] = $item->get_name();
            }

            $lines[] = $line;
        }

        $payload['orderLines'] = $lines;

        return $payload;
    }

    private function compose_invoice_comment(\WC_Order $order): string {        
        $customer_note = trim((string) $order->get_customer_note());

        $user_id = (int) $order->get_user_id();
        $user = $user_id > 0 ? get_userdata($user_id) : false;
        $user_email = trim((string) $order->get_billing_email());
        $first_name = $user ? trim((string) get_user_meta($user_id, 'first_name', true)) : '';
        $last_name = $user ? trim((string) get_user_meta($user_id, 'last_name', true)) : '';

        $ship_first = trim((string) $order->get_shipping_first_name());
        $ship_last  = trim((string) $order->get_shipping_last_name());

        $ship_phone = trim((string) $order->get_shipping_phone());
        if ($ship_phone === '') {
            $ship_phone = trim((string) $order->get_billing_phone());
        }

        $addr1   = (string) $order->get_shipping_address_1();
        $addr2   = (string) $order->get_shipping_address_2();
        $post    = (string) $order->get_shipping_postcode();
        $city    = (string) $order->get_shipping_city();
        $country_code = (string) $order->get_shipping_country();

        $lines = [];
        $lines[] = 'ORDER FRA NETTBUTIKK, SE INFO UNDER';

        if ($customer_note !== '') {
            $lines[] = '';
            $lines[] = '=== Kundekommentar ===';
            $lines[] = $customer_note;
        }

        $lines[] = '';
        $lines[] = '=== Bestilt av ===';
        $userParts = array_filter([$user_email, $first_name, $last_name], static function($v) {
            return $v !== null && $v !== '';
        });
        $lines[] = count($userParts) ? implode(' ', $userParts) : '-';

        if ($user_id > 0 && lh_ttx_is_avdeling($user_id)) {
            $avdeling_name = lh_ttx_get_avdeling_name($user_id);

            $lines[] = '';
            $lines[] = '=== Avdeling ===';
            $lines[] = $avdeling_name !== '' ? $avdeling_name : '-';
        }

        $lines[] = '';
        $lines[] = '=== Kontaktperson Levering ===';
        $contactParts = array_filter([$ship_first, $ship_last, $ship_phone], static function($v) {
            return $v !== null && $v !== '';
        });
        $lines[] = count($contactParts) ? implode(' ', $contactParts) : '-';

        $lines[] = '';
        $lines[] = '=== Leverings Adresse ===';
        $line1 = trim($addr1 . ($addr2 ? ' ' . $addr2 : ''));
        if ($line1 !== '') $lines[] = $line1;

        $line2Parts = array_filter([$post, $city, $country_code], static function($v) {
            return $v !== null && $v !== '';
        });
        if (count($line2Parts)) $lines[] = implode(' ', $line2Parts);

        // trim trailing spaces per line and join with newlines
        $lines = array_map(static function($s) { return rtrim((string) $s); }, $lines);
        return implode("\n", $lines);
    }
}