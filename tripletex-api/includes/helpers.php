<?php
/**
 * LavendelHygiene Tripletex: Global Helper Functions
 */

if (!defined('ABSPATH')) exit;


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
    $ignore_skus = [ '100', '108', '1000', '1001', '1002' ];
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