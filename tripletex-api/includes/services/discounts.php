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

    /** Cache TTL in seconds (5 minutes) */
    private const CACHE_TTL = 300;
    private const SNAPSHOT_OPTION_PREFIX = 'lh_ttx_disc_snapshot_';

    /**
     * Get discount map for a WP user (cached).
     *
     * Returns a map keyed by Tripletex product id:
     *  [
     *    38048015 => ['price' => 3502.00, 'pct' => 15.0, 'mode' => 'percentage'],
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

        $snapshot = get_option( $this->snapshot_option( $ttx_customer_id ), null );
        $cached_map = is_array( $snapshot['map'] ?? null ) ? $snapshot['map'] : null;
        $fetched_at = (int) ( $snapshot['fetched_at'] ?? 0 );

        if ( ! $force_refresh && $cached_map !== null && $fetched_at > 0 && ( time() - $fetched_at ) < self::CACHE_TTL ) {
            return $cached_map;
        }

        // Fetch all discount policies for customer
        $all = ttx_discountpolicy_list($ttx_customer_id);
        if (is_wp_error($all)) return $all;

        $map = $this->build_discount_map($all);

        // Keep the snapshot indefinitely; fetched_at controls freshness for users.
        $this->save_snapshot( $ttx_customer_id, $map, time() );

        return $map;
    }

    /**
    * Return the last locally stored discount map without making an API request.
     *
     * @return array|null Null when this customer's map is not cached.
     */
    public function get_cached_discount_map_for_user(int $user_id): ?array {
        if ($user_id <= 0) return null;

        $ttx_customer_id = lh_ttx_get_linked_tripletex_id($user_id);
        if ($ttx_customer_id <= 0) return null;

        $snapshot = get_option( $this->snapshot_option( $ttx_customer_id ), null );
        return is_array( $snapshot['map'] ?? null ) ? $snapshot['map'] : null;
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

        $snapshot = get_option( $this->snapshot_option( $ttx_customer_id ), null );
        if ( ! is_array( $snapshot['map'] ?? null ) ) return;

        $snapshot['fetched_at'] = 0;
        update_option( $this->snapshot_option( $ttx_customer_id ), $snapshot, false );
    }

    /* ------------------------- Internals ------------------------- */

    private function snapshot_option( int $ttx_customer_id ): string {
        return self::SNAPSHOT_OPTION_PREFIX . (string) $ttx_customer_id;
    }

    private function save_snapshot( int $ttx_customer_id, array $map, int $fetched_at ): void {
        $option = $this->snapshot_option( $ttx_customer_id );
        $snapshot = [ 'map' => $map, 'fetched_at' => $fetched_at ];

        if ( get_option( $option, null ) === null ) {
            add_option( $option, $snapshot, '', false );
            return;
        }

        update_option( $option, $snapshot, false );
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

            // A positive percentage is authoritative when both values are present.
            if ($pct !== null && $pct > 0) {
                $map[$product_id] = [
                    'price' => (float) $price,
                    'pct'   => (float) $pct,
                    'mode'  => 'percentage',
                ];
                continue;
            }

            // Sales users may set a customer-specific price without a percentage.
            if ($price !== null && $price > 0) {
                $map[$product_id] = [
                    'price' => (float) $price,
                    'pct'   => 0.0,
                    'mode'  => 'fixed_price',
                ];
            }
        }

        return $map;
    }
}