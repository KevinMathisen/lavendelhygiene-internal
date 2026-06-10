<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('LH_Ttx_Logger')) {
    // Fallback no-op logger if file included directly.
    final class LH_Ttx_Logger { public static function info($m,$c=[]){ } public static function error($m,$c=[]){ } }
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