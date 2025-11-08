<?php
/**
 * LavendelHygiene Tripletex: Services & Mappers
 *
 */

if (!defined('ABSPATH')) exit;

/** Meta keys (define here if not defined in your bootstrap) */
if (!defined('LH_TTX_META_TRIPLETEX_ID'))     define('LH_TTX_META_TRIPLETEX_ID', 'tripletex_customer_id');
if (!defined('LH_TTX_META_TTX_ORDER_ID'))     define('LH_TTX_META_TTX_ORDER_ID', '_tripletex_order_id');
if (!defined('LH_TTX_META_TTX_STATUS'))       define('LH_TTX_META_TTX_STATUS',   '_tripletex_status');
if (!defined('LH_TTX_META_TTX_LAST_SYNC_AT')) define('LH_TTX_META_TTX_LAST_SYNC_AT', '_tripletex_last_sync_at');

if (!class_exists('LH_Ttx_Logger')) {
    // Fallback no-op logger if file included directly (in practice the main plugin defines this).
    final class LH_Ttx_Logger { public static function info($m,$c=[]){ } public static function error($m,$c=[]){ } }
}

/* ========================================================================== */
/* Customers Service                                                           */
/* ========================================================================== */

final class LH_Ttx_Customers_Service {

    /**
     * Create a new customer in Tripletex from WP user profile and link it.
     *
     * @param int $user_id
     * @return int|\WP_Error New Tripletex customer ID
     */
    public function create_and_link(int $user_id) {
        $payload = $this->map_user_to_tripletex_payload($user_id, ['for_create' => true]);

        if (empty($payload['name'])) {
            return new WP_Error('payload_invalid', __('Kundenavn mangler.', 'lh-ttx'));
        }

        $created_id = ttx_customers_create($payload);
        if (is_wp_error($created_id)) return $created_id;

        $this->link_user_to_tripletex($user_id, (int) $created_id);

        LH_Ttx_Logger::info('Created Tripletex customer and linked user', [
            'user_id' => $user_id,
            'ttx_id'  => (int) $created_id,
        ]);

        return (int) $created_id;
    }

    /**
     * Sync user changes to Tripletex.
     * Called from: on_profile_update, woocommerce_customer_save_address
     *
     * @param int $user_id
     * @return true|\WP_Error
     */
    public function sync_user(int $user_id) {
        $ttx_id = $this->get_linked_tripletex_id($user_id);

        if (!$ttx_id) {
            // If no link, then we do nothing
            return new WP_Error('ttx_id_not_set', __('Kunde har ikke satt tripletex id.', 'lh-ttx'));
        }

        // perform partial update with changed fields
        $local   = $this->map_user_to_tripletex_payload($user_id, ['for_create' => false]);
        $version = null; 

        $res = ttx_customers_update($ttx_id, $local, $version);
        if (is_wp_error($res)) return $res;

        LH_Ttx_Logger::info('Synced user to Tripletex', [
            'user_id' => $user_id,
            'ttx_id'  => $ttx_id,
        ]);

        return true;
    }

    /* ------------------------- Helpers ------------------------- */

    /**
     * Read linked Tripletex ID from user meta.
     */
    public function get_linked_tripletex_id(int $user_id): int {
        $val = get_user_meta($user_id, LH_TTX_META_TRIPLETEX_ID, true);
        return $val ? (int) $val : 0;
    }

    /**
     * Persist link to Tripletex on the user and emit existing core hooks if present.
     */
    private function link_user_to_tripletex(int $user_id, int $ttx_id): void {
        update_user_meta($user_id, LH_TTX_META_TRIPLETEX_ID, $ttx_id);

        // Mirror LH core audit fields
        update_user_meta($user_id, 'tripletex_linked_by', get_current_user_id());
        update_user_meta($user_id, 'tripletex_linked_at', current_time('mysql'));

        /**
         * Maintain compatibility with LH core actions
         */
        do_action('lavendelhygiene_tripletex_linked', $user_id, (string) $ttx_id, get_current_user_id());
    }

    /**
     * Minimal mapping from WP user meta to Tripletex customer payload.
     * Keep this boring and explicit; Tripletex expects specific fields.
     */
    private function map_user_to_tripletex_payload(int $user_id, array $opts = []): array {
        $for_create = (bool)($opts['for_create'] ?? false);

        $user   = get_user_by('id', $user_id);
        $orgnr  = preg_replace('/\D+/', '', (string) get_user_meta($user_id, 'orgnr', true));
        $use_ehf = (string) get_user_meta($user_id, 'use_ehf', true); // 'yes'|'no'

        // Billing
        $name        = (string) get_user_meta($user_id, 'billing_company', true);
        $email       = (string) get_user_meta($user_id, 'billing_email', true) ?: ($user->user_email ?? '');
        $phone       = (string) get_user_meta($user_id, 'billing_phone', true);

        $inv_addr_1  = (string) get_user_meta($user_id, 'billing_address_1', true);
        $inv_post    = (string) get_user_meta($user_id, 'billing_postcode', true);
        $inv_city    = (string) get_user_meta($user_id, 'billing_city', true);
        $inv_country = (string) get_user_meta($user_id, 'billing_country', true) ?: 'NO';

        // Shipping
        $ship_addr_1  = (string) get_user_meta($user_id, 'shipping_address_1', true);
        $ship_post    = (string) get_user_meta($user_id, 'shipping_postcode', true);
        $ship_city    = (string) get_user_meta($user_id, 'shipping_city', true);
        $ship_country = (string) get_user_meta($user_id, 'shipping_country', true) ?: 'NO';

        // Tripletex Customer object (partial allowed on PUT)
        $payload = [
            'name'               => $for_create ? ($name ?: null) : null,
            'organizationNumber' => $for_create ? ($orgnr ?: null) : null,
            'email'              => $email ?: null,
            'invoiceEmail'       => $for_create ? ($email ?: null) : null,
            'phoneNumber'        => $phone ?: null,
            'invoiceSendMethod' =>  $for_create ? ($use_ehf === 'yes' ? 'EHF' : 'EMAIL') : null,
            'postalAddress' => [    
                'addressLine1' => $inv_addr_1 ?: null,
                'postalCode'   => $inv_post ?: null,
                'city'         => $inv_city ?: null,
                'country'      => [ 'isoAlpha2Code' => $inv_country ], // may not be able to set country
            ],
            'deliveryAddress' => [
                'addressLine1' => ($ship_addr_1 ?: $inv_addr_1) ?: null,
                'postalCode'   => ($ship_post   ?: $inv_post)   ?: null,
                'city'         => ($ship_city   ?: $inv_city)   ?: null,
                'country'      => [ 'isoAlpha2Code' => ($ship_country ?: $inv_country) ],
            ],
        ];

        // Remove nulls/empties
        $payload = array_filter($payload, static function($v) { return $v !== null && $v !== ''; });
        if (isset($payload['postalAddress'])) {
            $payload['postalAddress'] = array_filter($payload['postalAddress'], static fn($v) => $v !== null && $v !== '');
        }
        if (isset($payload['deliveryAddress'])) {
            $payload['deliveryAddress'] = array_filter($payload['deliveryAddress'], static fn($v) => $v !== null && $v !== '');
        }

        return $payload;
    }
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

        $ttx_customer_id = $customer_service->get_linked_tripletex_id($user_id);
        if (!$ttx_customer_id) {
            $link = $customer_service->sync_user($user_id);
            if (is_wp_error($link)) return $link;
            $ttx_customer_id = $customer_service->get_linked_tripletex_id($user_id);
            if (!$ttx_customer_id) {
                return new WP_Error('link_failed', __('Kunne ikke linke kunde mot Tripletex.', 'lh-ttx'));
            }
        }

        // Build payload
        $payload = $this->map_order_to_tripletex_payload($order, $ttx_customer_id);

        // Create in Tripletex
        $created_id = ttx_orders_create($payload);
        if (is_wp_error($created_id)) return $created_id;

        // Store mapping + note
        update_post_meta($order_id, LH_TTX_META_TTX_ORDER_ID, (int) $created_id);
        update_post_meta($order_id, LH_TTX_META_TTX_LAST_SYNC_AT, time());
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
            'orderDate'     => $order_dt, // TODO: Check how many of these defaults to correct.
            'currency'      => [ 'code' => $currency ],
            'yourOrderNumber' => (string) $order->get_order_number(),
            // TODO: add other possible fields, e.g. delivery terms, references, comments, etc.
        ];

        $lines = [];
        foreach ($order->get_items() as $item) {
            $product   = $item->get_product();
            $qty       = (float) $item->get_quantity();
            $total_ex  = $this->line_total_ex_vat($item);
            $unit_ex   = $qty > 0 ? ($total_ex / $qty) : 0.0;

            $line = [
                'count'     => $qty,
                // Tripletex uses Money on unitPrice: {value, currency}
                'unitPriceExcludingVatCurrency' => (float) wc_format_decimal($unit_ex, 6),
                'currency' => ['code' => $currency],
            ];

            $ttx_product_id = $this->get_tripletex_product_id_from_wc_product($product);
            if ($ttx_product_id) {
                $line['product'] = [ 'id' => $ttx_product_id ];
            } else {
                $line['description'] = $item->get_name();
            }

            // Discount
            // may calculcate customers discount for product here
            // $line['discount'] = 10.0;

            // may set vat type to 25%, or use number 3, but may also not be needed.
            // $line['vatType'] = [ 'number': '3'];

            $lines[] = $line;
        }

        $payload['orderLines'] = $lines;

        return $payload;
    }

    /**
     * Compute ex-VAT total per line.
     */
    private function line_total_ex_vat(\WC_Order_Item_Product $item): float {
        return (float) wc_format_decimal((float) $item->get_total(), 6);
    }

    /**
     * Resolve Tripletex product id from a WC product.
     */
    private function get_tripletex_product_id_from_wc_product(?\WC_Product $product): int {
        if (!$product) return 0;
        $id = (int) get_post_meta($product->get_id(), '_tripletex_product_id', true);
        if ($id > 0) return $id;

        return 0;
    }
}

/* ========================================================================== */
/* Products Service                                                           */
/* ========================================================================== */

final class LH_Ttx_Products_Service {

    /**
     * Pull price from Tripletex and apply to WooCommerce product.
     *
     * @param int      $product_id
     * @param int|null $new_price (if null we get new price from tripletex)
     * @return true|\WP_Error
     */
    public function sync_price_from_tripletex(int $product_id, ?int $new_price = null) {
        $product = wc_get_product($product_id);
        if (!$product) return new WP_Error('product_missing', __('Finner ikke produkt.', 'lh-ttx'));

        $ttx_pid = (int) get_post_meta($product_id, '_tripletex_product_id', true);
        if ($ttx_pid <= 0) {
            return new WP_Error('product_not_linked', __('Produktet er ikke linket til Tripletex.', 'lh-ttx'));
        }

        if (!$new_price) {
            $price = ttx_products_get_price($ttx_pid);
            if (is_wp_error($price)) return $price;
        } else {
            $price = $new_price;
        }

        // Apply price (choose regular or sale strategy)
        $product->set_regular_price(wc_format_decimal((float) $price, 2));
        $product->save();

        LH_Ttx_Logger::info('Synced price from Tripletex', [
            'product_id' => $product_id,
            'ttx_id'     => $ttx_pid,
            'price'      => $price,
        ]);

        return true;
    }

    /**
     * Pull stock from Tripletex and apply to WooCommerce product.
     *
     * @param int      $product_id
     * @param int|null $warehouse_id
     * @return true|\WP_Error
     */
    public function sync_stock_from_tripletex(int $product_id, ?int $warehouse_id = null) {
        $product = wc_get_product($product_id);
        if (!$product) return new WP_Error('product_missing', __('Finner ikke produkt.', 'lh-ttx'));

        $ttx_pid = (int) get_post_meta($product_id, '_tripletex_product_id', true);
        if ($ttx_pid <= 0) {
            return new WP_Error('product_not_linked', __('Produktet er ikke linket til Tripletex.', 'lh-ttx'));
        }

        $qty = ttx_products_get_stock($ttx_pid, $warehouse_id);
        if (is_wp_error($qty)) return $qty;

        $product->set_manage_stock(true);
        $product->set_stock_quantity((int) $qty);
        $product->save();

        LH_Ttx_Logger::info('Synced stock from Tripletex', [
            'product_id' => $product_id,
            'ttx_id'     => $ttx_pid,
            'qty'        => (int) $qty,
        ]);

        return true;
    }
}
