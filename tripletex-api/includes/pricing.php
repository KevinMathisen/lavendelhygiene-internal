<?php
/**
 * LavendelHygiene Tripletex — Pricing (customer discounts)
 */

if (!defined('ABSPATH')) exit;

final class LH_Ttx_Pricing_Hooks {

    private LH_Ttx_Service_Registry $services;

    public function __construct(LH_Ttx_Service_Registry $services) {
        $this->services = $services;
    }

    public function init(): void {
        // Cart + checkout (authoritative)
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_cart_discounts'], 20, 1);

        // Single product page price overrides (simple + variations)
        add_filter('woocommerce_product_get_price',              [$this, 'filter_product_price'], 20, 2);
        add_filter('woocommerce_product_variation_get_price',    [$this, 'filter_product_price'], 20, 2);

        // Ensure variation JSON uses discounted display_price when selecting variations on product page
        add_filter('woocommerce_available_variation', [$this, 'filter_available_variation'], 20, 3);

        // Savings / original price info on single product page (simple + variable parent)
        add_action('woocommerce_single_product_summary', [$this, 'render_savings_notice'], 12);

        // Optional: ensure product pages for logged-in users are not cached
        add_action('template_redirect', [$this, 'maybe_disable_cache'], 0);
    }

    /* =========================================================================
     * Cart / Checkout
     * ========================================================================= */

    public function apply_cart_discounts($cart): void {
        if (!($cart instanceof WC_Cart)) return;

        // Avoid messing with backend calculations (unless ajax)
        if (is_admin() && !wp_doing_ajax()) return;

        if (!is_user_logged_in()) return;

        // Prevent double-application within the same request
        static $done = false;
        if ($done) return;
        $done = true;

        $user_id = get_current_user_id();

        // Fetch discount map once
        $map = $this->services->discounts()->get_discount_map_for_user($user_id);
        if (is_wp_error($map)) {
            LH_Ttx_Logger::error('Tripletex discounts: failed to load discount map for cart', [
                'error' => $map->get_error_message(),
            ]);
            return;
        }
        if (empty($map)) return;

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (empty($cart_item['data']) || !($cart_item['data'] instanceof WC_Product)) continue;

            $product = $cart_item['data'];

            $ttx_pid = get_tripletex_product_id_from_wc_product($product);
            if (is_wp_error($ttx_pid)) continue;

            $ttx_pid = (int) $ttx_pid;
            if ($ttx_pid <= 0) continue;

            if (!isset($map[$ttx_pid])) continue;

            $new_price = (float) ($map[$ttx_pid]['price'] ?? 0);
            if ($new_price <= 0) continue;

            $product->set_price(wc_format_decimal($new_price, wc_get_price_decimals()));
        }
    }

    /* =========================================================================
     * Single product page pricing
     * ========================================================================= */

    public function filter_product_price($price, $product) {
        if (!($product instanceof WC_Product)) return $price;
        if (!is_user_logged_in()) return $price;

        // Only apply on single product page or its variation AJAX
        if (!$this->is_product_context_for($product)) return $price;

        $disc = $this->services->discounts()->get_discount_for_product($product, get_current_user_id());
        if (is_wp_error($disc) || !$disc) return $price;

        $new_price = (float) ($disc['price'] ?? 0);
        if ($new_price <= 0) return $price;

        return wc_format_decimal($new_price, wc_get_price_decimals());
    }

    /**
     * Make variation selection show discounted price in the variation JSON on the product page.
     */
    public function filter_available_variation(array $data, $product, $variation): array {
        if (!is_user_logged_in()) return $data;
        if (!is_product()) return $data; // only needed on product page render

        if (!($variation instanceof WC_Product_Variation)) return $data;

        $disc = $this->services->discounts()->get_discount_for_product($variation, get_current_user_id());
        if (is_wp_error($disc) || !$disc) return $data;

        $new_price = (float) ($disc['price'] ?? 0);
        if ($new_price <= 0) return $data;

        // Keep regular as-is so theme can show strike-through
        $regular = $this->get_raw_regular_price($variation);
        if ($regular !== null && $regular > 0) {
            $data['display_regular_price'] = (float) $regular;
        }

        $data['display_price'] = (float) $new_price;

        // Ensure the price_html shown in the variation selector updates correctly
        if (!empty($data['display_regular_price']) && (float)$data['display_regular_price'] > (float)$new_price) {
            $data['price_html'] = wc_format_sale_price(
                wc_price((float)$data['display_regular_price']),
                wc_price((float)$new_price)
            ) . $variation->get_price_suffix();
        } else {
            $data['price_html'] = wc_price((float)$new_price) . $variation->get_price_suffix();
        }

        return $data;
    }

    /**
     * Render "you save" info on the single product page (under price).
     * - Shows original price (raw regular) + discount percent + savings amount.
     * - For variable products, this runs on the parent product; the selected variation updates via WC UI.
     */
    public function render_savings_notice(): void {
        if (!is_user_logged_in()) return;
        if (!is_product()) return;

        global $product;
        if (!($product instanceof WC_Product)) return;

        $disc = $this->services->discounts()->get_discount_for_product($product, get_current_user_id());

        if (is_wp_error($disc) || !$disc) return;

        $new_price = (float) ($disc['price'] ?? 0);
        $pct       = (float) ($disc['pct'] ?? 0);
        if ($new_price <= 0 || $pct <= 0) return;

        $regular = $this->get_raw_regular_price($product);
        if ($regular === null || $regular <= 0) return;

        $save_amount = max(0, (float)$regular - (float)$new_price);

        echo '<div class="lh-ttx-savings" style="margin-top:6px;">';
        echo '<small>';
        echo esc_html(sprintf(
            __('Din avtalepris: %s. Du sparer %s%% (%s).', 'lh-ttx'),
            wp_strip_all_tags(wc_price($new_price)),
            $this->format_pct($pct),
            wp_strip_all_tags(wc_price($save_amount))
        ));
        echo '</small>';
        echo '</div>';
    }

    public function maybe_disable_cache(): void {
        if (!is_user_logged_in()) return;
        if (!is_product()) return;

        if (!headers_sent()) {
            if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
            nocache_headers();
        }
    }

    /* =========================================================================
     * Helpers
     * ========================================================================= */

    /**
     * Apply discount pricing only in:
     * - single product page (queried product or its variations)
     * - variation selection AJAX (wc-ajax=get_variation)
     */
    private function is_product_context_for(WC_Product $product): bool {
        if (is_product()) {
            $qid = (int) get_queried_object_id();
            $pid = (int) $product->get_id();
            $par = (int) $product->get_parent_id();

            return ($qid === $pid) || ($qid === $par);
        }

        // Variation selection endpoint
        if (wp_doing_ajax() && !empty($_REQUEST['wc-ajax']) && $_REQUEST['wc-ajax'] === 'get_variation') {
            // product_id is the parent variable product id in this ajax call
            $req_parent = isset($_REQUEST['product_id']) ? (int) $_REQUEST['product_id'] : 0;
            if ($req_parent <= 0) return false;

            $pid = (int) $product->get_id();
            $par = (int) $product->get_parent_id();

            return ($req_parent === $pid) || ($req_parent === $par);
        }

        return false;
    }

    /**
     * Raw regular price (bypass filters) to avoid recursion.
     */
    private function get_raw_regular_price(WC_Product $product): ?float {
        $raw = $product->get_regular_price('edit'); // raw meta
        if ($raw === '' || $raw === null) return null;
        return (float) $raw;
    }

    private function format_pct(float $pct): string {
        // 15.00000 -> "15"
        $s = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
