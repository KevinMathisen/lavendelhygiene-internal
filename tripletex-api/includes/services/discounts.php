<?php
<?php
if (!defined('ABSPATH')) exit;

if (!class_exists('LH_Ttx_Logger')) {
    // Fallback no-op logger if file included directly.
    final class LH_Ttx_Logger { public static function info($m,$c=[]){ } public static function error($m,$c=[]){ } }
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