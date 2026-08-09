<?php
/**
 * LavendelHygiene Tripletex: Global Helper Functions
 */

if (!defined('ABSPATH')) exit;

if (!defined('LH_TTX_META_TRIPLETEX_ID'))               define('LH_TTX_META_TRIPLETEX_ID', 'tripletex_customer_id');
if (!defined('LH_TTX_META_TTX_CONTACT_ID'))             define('LH_TTX_META_TTX_CONTACT_ID', '_tripletex_contact_id');
if (!defined('LH_TTX_META_TTX_DELIVERY_ADDRESS_ID'))    define('LH_TTX_META_TTX_DELIVERY_ADDRESS_ID', '_tripletex_delivery_address_id');
if (!defined('LH_TTX_META_IS_AVDELING'))                define('LH_TTX_META_IS_AVDELING', '_lh_ttx_is_avdeling');
if (!defined('LH_TTX_META_AVDELING_NAME'))              define('LH_TTX_META_AVDELING_NAME', '_lh_ttx_avdeling_name');
if (!defined('LH_TTX_META_TTX_ORDER_ID'))               define('LH_TTX_META_TTX_ORDER_ID', '_tripletex_order_id');
if (!defined('LH_TTX_META_TTX_STATUS'))                 define('LH_TTX_META_TTX_STATUS',   '_tripletex_status');
if (!defined('LH_TTX_META_TTX_LAST_SYNC_AT'))           define('LH_TTX_META_TTX_LAST_SYNC_AT', '_tripletex_last_sync_at');

/**
 * Get the linked Tripletex customer ID for a WordPress user.
 *
 * @param int $user_id WordPress user ID.
 * @return int Tripletex customer ID, or 0 if not linked.
 */
function lh_ttx_get_linked_tripletex_id(int $user_id): int {
    if ($user_id <= 0) return 0;
    $val = get_user_meta($user_id, LH_TTX_META_TRIPLETEX_ID, true);
    return $val ? (int) $val : 0;
}


/**
 * Get or resolve the Tripletex product ID from a WooCommerce product's SKU.
 * Caches the result in product meta `_tripletex_product_id`.
 *
 * @param \WC_Product $product The WooCommerce product object.
 * @return int|\WP_Error The Tripletex product ID or a WP_Error on failure.
 */
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
    $ignore_skus = [ '100', '108', '1000', '1001', '1002', '7382010019' ];
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

/**
 * Find a WooCommerce product or variation by stored Tripletex product ID.
 *
 * @param int $ttx_product_id Tripletex product ID.
 * @return \WC_Product|null Matching WooCommerce product object, or null if not found.
 */
function lh_ttx_find_wc_product_by_tripletex_product_id(int $ttx_product_id) {
    if ($ttx_product_id <= 0) return null;

    $query = new WP_Query([
        'post_type'      => ['product', 'product_variation'],
        'post_status'    => ['publish', 'private', 'draft', 'pending', 'future'],
        'fields'         => 'ids',
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'     => '_tripletex_product_id',
                'value'   => (string) $ttx_product_id,
                'compare' => '=',
            ],
        ],
    ]);

    if (empty($query->posts)) return null;

    $product = wc_get_product((int) $query->posts[0]);
    return $product ?: null;
}

function lh_ttx_is_avdeling(int $user_id): bool {
    if ($user_id <= 0) return false;

    $val = get_user_meta($user_id, LH_TTX_META_IS_AVDELING, true);

    return in_array($val, ['1', 1, true, 'yes', 'on'], true);
}

function lh_ttx_get_avdeling_name(int $user_id): string {
    if ($user_id <= 0) return '';

    return trim((string) get_user_meta($user_id, LH_TTX_META_AVDELING_NAME, true));
}

function lh_ttx_get_linked_contact_tripletex_id(int $user_id): int {
    if ($user_id <= 0) return 0;

    return (int) get_user_meta($user_id, LH_TTX_META_TTX_CONTACT_ID, true);
}

function lh_ttx_set_linked_contact_tripletex_id(int $user_id, int $contact_id): void {
    if ($user_id <= 0) return;

    update_user_meta($user_id, LH_TTX_META_TTX_CONTACT_ID, max(0, $contact_id));
}

function lh_ttx_get_linked_delivery_address_id(int $user_id): int {
    if ($user_id <= 0) return 0;

    return (int) get_user_meta($user_id, LH_TTX_META_TTX_DELIVERY_ADDRESS_ID, true);
}

function lh_ttx_set_linked_delivery_address_id(int $user_id, int $delivery_address_id): void {
    if ($user_id <= 0) return;

    update_user_meta($user_id, LH_TTX_META_TTX_DELIVERY_ADDRESS_ID, max(0, $delivery_address_id));
}