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
        $ttx_id = lh_ttx_get_linked_tripletex_id($user_id);

        if (!$ttx_id) {
            // If no link, then we do nothing
            return new WP_Error('ttx_id_not_set', __('Kunde har ikke satt tripletex id.', 'lh-ttx'));
        }

        // get current tripletex customer data
        $remote = ttx_customers_get($ttx_id);
        if (is_wp_error($remote)) return $remote;

        // update delivery adress if modified
        $delivery_updated = $this->sync_delivery_address_object($user_id, (array) $remote);
        if (is_wp_error($delivery_updated)) return $delivery_updated;

        // build minimal update payload with only changed fields (and not delivery address)
        $update = $this->build_customer_update_payload_from_map($user_id, (array) $remote);

        if (empty($update) && !$delivery_updated) {
            LH_Ttx_Logger::info('Tripletex: no customer changes to sync', [
                'user_id' => $user_id,
                'ttx_id'  => $ttx_id,
            ]);
            return true;
        } elseif (empty($update) && $delivery_updated) {
            LH_Ttx_Logger::info('Tripletex: synced user delivery address to tripletex', [
                'user_id' => $user_id,
                'ttx_id'  => $ttx_id,
            ]);
            return true;
        }

        $res = ttx_customers_update($ttx_id, $update, null);
        if (is_wp_error($res)) return $res;

        LH_Ttx_Logger::info('Synced user to Tripletex', [
            'user_id' => $user_id,
            'ttx_id'  => $ttx_id,
            'fields'  => array_keys($update),
        ]);
        return true;
    }

    /* ------------------------- Helpers ------------------------- */

    /**
     * Persist link to Tripletex on the user and emit existing core hooks if present.
     */
    private function link_user_to_tripletex(int $user_id, int $ttx_id): void {
        update_user_meta($user_id, LH_TTX_META_TRIPLETEX_ID, $ttx_id);

        // Mirror LH core audit fields
        update_user_meta($user_id, 'tripletex_linked_by', get_current_user_id());
        update_user_meta($user_id, 'tripletex_linked_at', current_time('mysql'));

        // Maintain compatibility with LH core actions
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
        $inv_addr_2  = (string) get_user_meta($user_id, 'billing_address_2', true);
        $inv_post    = (string) get_user_meta($user_id, 'billing_postcode', true);
        $inv_city    = (string) get_user_meta($user_id, 'billing_city', true);
        $inv_country = (string) get_user_meta($user_id, 'billing_country', true) ?: 'NO';

        // Shipping
        $ship_addr_1  = (string) get_user_meta($user_id, 'shipping_address_1', true);
        $ship_addr_2  = (string) get_user_meta($user_id, 'shipping_address_2', true);
        $ship_post    = (string) get_user_meta($user_id, 'shipping_postcode', true);
        $ship_city    = (string) get_user_meta($user_id, 'shipping_city', true);
        $ship_country = (string) get_user_meta($user_id, 'shipping_country', true) ?: 'NO';

        // Shipping phone (custom)
        $ship_phone_raw = (string) get_user_meta($user_id, 'shipping_phone', true);
        $ship_phone_raw = $ship_phone_raw ?: $phone;

        $ttx_ship_phone = trim((string) $ship_phone_raw);
        if (empty($ttx_ship_phone)) {
            $ttx_ship_line1 = ($ship_addr_1 ?: $inv_addr_1) ?: null;
            $ttx_ship_line2 = ($ship_addr_2 ?: $inv_addr_2) ?: null;
        } else {
            $ttx_ship_line1 = ('Tlf ' . $ttx_ship_phone);
            $ttx_ship_line2 = ($ship_addr_1 ?: $inv_addr_1) ?: null;
        }
        

        // Tripletex Customer object (partial allowed on PUT)
        $payload = [
            'name'               => $for_create ? ($name ?: null) : null,
            'organizationNumber' => $for_create ? ($orgnr ?: null) : null,
            'email'              => $email ?: null,
            'invoiceEmail'       => $for_create ? ($email ?: null) : null,
            'phoneNumber'        => $phone ?: null,
            'invoiceSendMethod' =>  $for_create ? ($use_ehf === 'yes' ? 'EHF' : 'EMAIL') : null,
            'isPrivateIndividual' => $for_create ? (FALSE) : null,
            'postalAddress' => [
                'addressLine1' => $inv_addr_1 ?: null,
                'addressLine2' => $inv_addr_2 ?: null,
                'postalCode'   => $inv_post ?: null,
                'city'         => $inv_city ?: null,
                'country'      => [ 'isoAlpha2Code' => $inv_country ],
            ],
            'deliveryAddress' => [
                'addressLine1' => $ttx_ship_line1,
                'addressLine2' => $ttx_ship_line2,
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

    /**
     * Build a minimal update payload with only changed fields vs Tripletex
     * Avoids sending unchanged postal/delivery addresses (prevents duplicates)
     */
    private function build_customer_update_payload_from_map(int $user_id, array $remote): array {
        $local = $this->map_user_to_tripletex_payload($user_id, ['for_create' => false]);
        $payload = [];

        foreach (['email', 'phoneNumber'] as $k) {
            if (isset($local[$k])) {
                $remoteVal = (string) ($remote[$k] ?? '');
                $localVal  = (string) $local[$k];
                $equal = $k === 'phoneNumber'
                    ? $this->equals_strip_space($localVal, $remoteVal)
                    : $this->equals_ci_space($localVal, $remoteVal);
                if (!$equal) $payload[$k] = $localVal;
            }
        }

        // Postal address: if any field differs, send the full address
        $addrKey = 'postalAddress';
        if (isset($local[$addrKey])) {
            $remoteAddr = (array) ($remote[$addrKey] ?? []);
            $localAddr  = (array) $local[$addrKey];

            $different = false;
            foreach (['addressLine1','addressLine2','postalCode','city'] as $field) {
                if (!array_key_exists($field, $localAddr)) continue;
                $l = (string) $localAddr[$field];
                $r = (string) ($remoteAddr[$field] ?? '');
                $equal = $field === 'postalCode'
                    ? $this->equals_strip_space($l, $r)
                    : $this->equals_ci_space($l, $r);
                if ($l !== '' && !$equal) { $different = true; break; }
            }

            if ($different) {
                $payload[$addrKey] = $localAddr;
            }
        }

        return $payload;
    }

    private function equals_ci_space(string $a, string $b): bool {
        return $this->norm_ci_space($a) === $this->norm_ci_space($b);
    }
    private function equals_strip_space(string $a, string $b): bool {
        return $this->norm_strip_space($a) === $this->norm_strip_space($b);
    }
    private function norm_ci_space(string $v): string {
        $v = preg_replace('/\s+/', ' ', trim((string) $v));
        return mb_strtolower($v, 'UTF-8');
    }
    private function norm_strip_space(string $v): string {
        return preg_replace('/\s+/', '', (string) $v);
    }

    /**
     * Update Tripletex deliveryAddress object to prevent duplicates.
     *
     * @return bool|\WP_Error True if updated, false if no changes / no deliveryAddress id.
     */
    private function sync_delivery_address_object(int $user_id, array $remote): bool|\WP_Error {
        $localDel  = $this->map_user_to_tripletex_payload($user_id, ['for_create' => false])['deliveryAddress'] ?? null;
        $remoteDel = (array) ($remote['deliveryAddress'] ?? []);
        $delId     = (int) ($remoteDel['id'] ?? 0);

        if (!is_array($localDel) || $delId <= 0) return false;

        $desired = (array) $localDel;
        $diff    = [];

        // field => comparator method
        $cmp = [
            'addressLine1' => 'equals_ci_space',
            'addressLine2' => 'equals_ci_space',
            'city'         => 'equals_ci_space',
            'postalCode'   => 'equals_strip_space',
        ];

        foreach ($cmp as $field => $fn) {
            $l = (string) ($desired[$field] ?? '');
            if ($l === '') continue;

            $r = (string) ($remoteDel[$field] ?? '');
            if (!$this->{$fn}($l, $r)) $diff[$field] = $l;
        }

        $lC = (string) ($desired['country']['isoAlpha2Code'] ?? '');
        if ($lC !== '') {
            $rC = (string) ($remoteDel['country']['isoAlpha2Code'] ?? '');
            if (!$this->equals_ci_space($lC, $rC)) $diff['country'] = ['isoAlpha2Code' => $lC];
        }

        if (!$diff) return false;

        $res = ttx_delivery_address_update($delId, $diff);
        if (is_wp_error($res)) return $res;

        LH_Ttx_Logger::info('Tripletex: updated deliveryAddress', [
            'user_id'            => $user_id,
            'deliveryAddress_id' => $delId,
            'fields'             => array_keys($diff),
        ]);

        return true;
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

        $ttx_customer_id = lh_ttx_get_linked_tripletex_id($user_id);
        if (!$ttx_customer_id) {
            return new WP_Error('link_failed', __('Kunne ikke linke kunde mot Tripletex.', 'lh-ttx'));
        }

        // Build payload
        $payload = $this->map_order_to_tripletex_payload($order, $ttx_customer_id);

        // Create in Tripletex
        $created_id = ttx_orders_create($payload);
        if (is_wp_error($created_id)) return $created_id;

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

        $lines = [];
        foreach ($order->get_items() as $item) {
            $product   = $item->get_product();
            $qty       = (float) $item->get_quantity();

            $line = [ 'count' => $qty, ];

            $ttx_product_id = get_tripletex_product_id_from_wc_product($product);

            if (!is_wp_error($ttx_product_id) && $ttx_product_id > 0) {
                $line['product'] = [ 'id' => (int) $ttx_product_id ];
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
    public function sync_price_from_tripletex(int $product_id, ?float $new_price = null) {
        $product = wc_get_product($product_id);
        if (!$product) return new WP_Error('product_missing', __('Finner ikke produkt.', 'lh-ttx'));

        $ttx_pid = get_tripletex_product_id_from_wc_product($product);
        if (is_wp_error($ttx_pid) || (int)$ttx_pid <= 0) {
            return new WP_Error('ttx_product_missing', __('Fant ikke Tripletex-produkt-ID for pris-sync.', 'lh-ttx'));
        }

        if (!$new_price) {
            $price = ttx_products_get_price($ttx_pid);
            if (is_wp_error($price)) return $price;
        } else {
            $price = $new_price;
        }

        // Apply price
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

        $ttx_pid = get_tripletex_product_id_from_wc_product($product);
        if (is_wp_error($ttx_pid) || (int)$ttx_pid <= 0) {
            return new WP_Error('ttx_product_missing', __('Fant ikke Tripletex-produkt-ID for lager-sync.', 'lh-ttx'));
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

function get_tripletex_product_id_from_wc_product(\WC_Product $product) {
    if (!$product) { return new WP_Error('wc_product_invalid', __('Ugyldig WooCommerce-produkt.', 'lh-ttx')); }

    // Never resolve Tripletex ID for variable (parent) products
    // Variable parents are not purchasable; only variations (and simple products) should sync
    if ($product->is_type('variable')) {
        $product->update_meta_data('_tripletex_product_id', 0);
        $product->save();
        return 0;
    }

    // ignore catalog-only products
    $ignore_skus = [ '100', '108', 'T-2011-02' ];
    $sku = trim((string) $product->get_sku());
    if ($sku !== '' && in_array($sku, $ignore_skus, true)) {
        $product->update_meta_data('_tripletex_product_id', 0);
        $product->save();
        return 0;
    }

    $wc_id = (int) $product->get_id();

    $stored = (int) $product->get_meta('_tripletex_product_id', true);
    if ($stored > 0) { return $stored; }

    // Need SKU
    $sku = trim((string) $product->get_sku());
    if ($sku === '') { return new WP_Error('sku_missing', __('Produkt mangler SKU for Tripletex-oppslag.', 'lh-ttx')); }

    $ttx_id = ttx_products_get_ttx_id_from_sku($sku);
    if (is_wp_error($ttx_id)) { return $ttx_id; }

    $ttx_id = (int) $ttx_id;
    if ($ttx_id <= 0) { return new WP_Error('ttx_id_invalid', __('Ugyldig Tripletex-produkt-ID.', 'lh-ttx')); }

    // Persist mapping
    $product->update_meta_data( '_tripletex_product_id', $ttx_id );
    $product->save();

    return $ttx_id;
}

function lh_ttx_get_linked_tripletex_id(int $user_id): int {
    if ($user_id <= 0) return 0;
    $val = get_user_meta($user_id, LH_TTX_META_TRIPLETEX_ID, true);
    return $val ? (int) $val : 0;
}


/* ========================================================================== */
/* Discounts Service                                                           */
/* ========================================================================== */

final class LH_Ttx_Discounts_Service {

    /** Cache TTL in seconds (1 hour) */
    private const CACHE_TTL = 3600;
    private const TRANSIENT_PREFIX = 'lh_ttx_disc_';

    /**
     * Get discount map for a WP user (cached).
     *
     * Returns a map keyed by Tripletex product id:
     *  [
     *    38048015 => ['price' => 3502.00, 'pct' => 15.0],
     *    ...
     *  ]
     *
     * @param int  $user_id
     * @param bool $force_refresh
     * @return array|\WP_Error
     */
    public function get_discount_map_for_user(int $user_id, bool $force_refresh = false) {
        if ($user_id <= 0) return [];

        $ttx_customer_id = lh_ttx_get_linked_tripletex_id($user_id);
        if ($ttx_customer_id <= 0) { return []; } // not linked => no discounts 

        $tkey = $this->transient_key($ttx_customer_id);

        if (!$force_refresh) {
            $cached = get_transient($tkey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // Fetch all discount policies for customer
        $all = ttx_discountpolicy_list($ttx_customer_id);
        if (is_wp_error($all)) return $all;

        $map = $this->build_discount_map($all);

        // Cache even empty maps (reduce api calls)
        set_transient($tkey, $map, self::CACHE_TTL);

        return $map;
    }

    /**
     * Return discount data for a WC product for this user, if any.
     *
     * @param \WC_Product $product
     * @param int         $user_id
     * @return array|null|\WP_Error
     */
    public function get_discount_for_product(\WC_Product $product, int $user_id) {
        if ($user_id <= 0) return null;

        $ttx_pid = get_tripletex_product_id_from_wc_product($product);
        if (is_wp_error($ttx_pid)) return $ttx_pid;

        $ttx_pid = (int) $ttx_pid;
        if ($ttx_pid <= 0) return null;

        $map = $this->get_discount_map_for_user($user_id);
        if (is_wp_error($map)) return $map;

        return $map[$ttx_pid] ?? null;
    }

    /**
     * Invalidate discount cache for a WP user.
     *
     * @param int $user_id
     * @return void
     */
    public function invalidate_user_discount_cache(int $user_id): void {
        if ($user_id <= 0) return;

        $ttx_customer_id = lh_ttx_get_linked_tripletex_id($user_id);
        if ($ttx_customer_id <= 0) return;

        delete_transient($this->transient_key($ttx_customer_id));
    }

    /* ------------------------- Internals ------------------------- */

    private function transient_key(int $ttx_customer_id): string {
        // Keep it short and deterministic
        return self::TRANSIENT_PREFIX . (string) $ttx_customer_id;
    }

    /**
     * Build a map keyed by Tripletex product id
     *
     * @param array $policies
     * @return array
     */
    private function build_discount_map(array $policies): array {
        $map = [];

        foreach ($policies as $p) {
            if (!is_array($p)) continue;

            $product_id = (int) ($p['product']['id'] ?? 0);
            if ($product_id <= 0) continue;

            $price = isset($p['salesPriceWithDiscount']) ? (float) $p['salesPriceWithDiscount'] : null;
            $pct   = isset($p['percentage']) ? (float) $p['percentage'] : null;

            // Require both
            if ($price === null || $pct === null) continue;

            $map[$product_id] = [ 'price' => (float) $price, 'pct' => (float) $pct, ];
        }

        return $map;
    }
}