<?php
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

        // Ensure customer is linked
        $customer_service = (new LH_Ttx_Customers_Service());
        $user_id = (int) $order->get_user_id();

        if ($user_id <= 0) {
            return new WP_Error('guest_not_supported', __('Gjestebestillinger støttes ikke for Tripletex-synk.', 'lh-ttx'));
        }

        $ttx_customer_id = lh_ttx_get_linked_tripletex_id($user_id);
        if (!$ttx_customer_id) {
            return new WP_Error('link_failed', __('Kunne ikke linke kunde mot Tripletex.', 'lh-ttx'));
        }

        // Build payload
        $payload = $this->map_order_to_tripletex_payload($order, $ttx_customer_id);

        // Create in Tripletex
        $created_id = ttx_orders_create($payload);
        if (is_wp_error($created_id)) {
            LH_Ttx_Logger::error('Tripletex order create failed', [
                'order_id' => $order_id,
                'user_id'  => $user_id,
                'payload'  => $payload, // full payload sent to Tripletex
                'error'    => $created_id->get_error_message(),
                'data'     => $created_id->get_error_data(),
            ]);
            return $created_id;
        }

        // Store mapping + note
        $order->update_meta_data( LH_TTX_META_TTX_ORDER_ID, (int) $created_id );
        $order->update_meta_data( LH_TTX_META_TTX_LAST_SYNC_AT, time() );
        $order->save();

        $order->add_order_note(sprintf(__('Tripletex-ordre opprettet (ID: %d).', 'lh-ttx'), (int) $created_id));

        LH_Ttx_Logger::info('Created Tripletex order', [
            'order_id' => $order_id,
            'ttx_id'   => (int) $created_id,
        ]);

        return (int) $created_id;
    }

    /* ------------------------- Helpers ------------------------- */

    /**
     * Build a minimal Tripletex order payload from WC_Order.
     * Fill in only what you actually use; leave TODOs for spec-specific fields.
     *
     * @param \WC_Order $order
     * @param int       $ttx_customer_id
     * @return array
     */
    private function map_order_to_tripletex_payload(\WC_Order $order, int $ttx_customer_id): array {
        $currency = $order->get_currency();
        $order_dt = (new DateTimeImmutable('@' . $order->get_date_created()->getTimestamp()))->format('Y-m-d');

        $payload = [
            'customer'      => [ 'id' => $ttx_customer_id ],
            'status'        => 'CONFIRMATION_SENT',
            'orderDate'     => $order_dt, 
            'deliveryDate'  => $order_dt,
        ];

        $payload['invoiceComment'] = $this->compose_invoice_comment($order);

        // prepare discount service for user so we can check if they have discounts
        $user_id = (int) $order->get_user_id();
        $discSvc = new LH_Ttx_Discounts_Service();

        $lines = [];
        foreach ($order->get_items() as $item) {
            $product   = $item->get_product();
            $qty       = (float) $item->get_quantity();

            $line = [ 'count' => $qty, ];

            $ttx_product_id = get_tripletex_product_id_from_wc_product($product);

            if (!is_wp_error($ttx_product_id) && $ttx_product_id > 0) {
                $line['product'] = [ 'id' => (int) $ttx_product_id ];

                // apply discount if user has any for this product
                if ($user_id > 0 && $product) {
                    $d = $discSvc->get_discount_for_product($product, $user_id);
                    if (!is_wp_error($d) && is_array($d)) {
                        $pct = (float) ($d['pct'] ?? 0);
                        if ($pct > 0) {
                            $line['discount'] = $pct;
                        }
                    }
                }
            } else {
                // Fallback: send description only
                $line['description'] = $item->get_name();
            }

            $lines[] = $line;
        }

        $payload['orderLines'] = $lines;

        return $payload;
    }

    private function compose_invoice_comment(\WC_Order $order): string {
        // Comment should contain plain text in following format:
        // ORDER FRA NETTBUTIKK, SE INFO UNDER
        //  <order_comments>
        // 
        // KONTAKTPERSON LEVERING:
        // <shipping_first_name> <shipping_last_name> <shipping_phone>
        // <order_email>
        //
        // LEVERINGS ADRESSE:
        // <shipping_address_1> <shipping_address_2 (optional)>
        // <shipping_postcode> <shipping_city> <shipping_country> 
        
        $customer_note = trim((string) $order->get_customer_note());

        $ship_first = trim((string) $order->get_shipping_first_name());
        $ship_last  = trim((string) $order->get_shipping_last_name());

        $ship_phone = trim((string) $order->get_shipping_phone());
        if ($ship_phone === '') {
            $ship_phone = trim((string) $order->get_billing_phone());
        }

        $order_email = trim((string) $order->get_billing_email());

        $addr1   = (string) $order->get_shipping_address_1();
        $addr2   = (string) $order->get_shipping_address_2();
        $post    = (string) $order->get_shipping_postcode();
        $city    = (string) $order->get_shipping_city();
        $country_code = (string) $order->get_shipping_country();

        $lines = [];
        $lines[] = 'ORDER FRA NETTBUTIKK, SE INFO UNDER';

        if ($customer_note !== '') {
            $lines[] = $customer_note;
            $lines[] = '';
        }

        $lines[] = 'KONTAKTPERSON LEVERING:';
        $contactParts = array_filter([$ship_first, $ship_last, $ship_phone], static function($v) {
            return $v !== null && $v !== '';
        });
        $lines[] = count($contactParts) ? implode(' ', $contactParts) : '-';
        if ($order_email !== '') {
            $lines[] = $order_email;
        }

        $lines[] = '';
        $lines[] = 'LEVERINGS ADRESSE:';
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