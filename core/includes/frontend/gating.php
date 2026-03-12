<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LavendelHygiene_Gating {
    public function __construct() {
        /* UX notices */
        add_action( 'woocommerce_account_content',                [ $this, 'maybe_show_pending_notice' ] );
        add_action( 'woocommerce_single_product_summary',         [ $this, 'maybe_product_page_notice' ], 12 );
        add_action( 'woocommerce_single_product_summary',         [ $this, 'maybe_volume_pricing_notice' ], 12 );
        add_action( 'woocommerce_single_product_summary',         [ $this, 'maybe_not_purchasable_pricing_notice' ], 12 );

        add_filter( 'render_block', [ $this, 'inject_notice_for_blocks_cart_checkout' ], 10, 2 );
        add_filter( 'render_block', [ $this, 'inject_notice_for_blocks_signup' ], 10, 2 );


        /* gating: prices, purchasability, cart/checkout access */
        add_filter( 'woocommerce_get_price_html',                 [ $this, 'filter_price_html' ], 9, 2 );
        add_filter( 'woocommerce_variable_price_html',            [ $this, 'filter_price_html' ], 9, 2 );
        add_filter( 'woocommerce_structured_data_product_offer', [ $this, 'filter_structured_data_offer' ], 10, 2 );
        add_filter( 'woocommerce_structured_data_product',       [ $this, 'filter_structured_data_product' ], 10, 2 );

        add_filter( 'woocommerce_is_purchasable',                 [ $this, 'filter_is_purchasable' ], 10, 2 );
        add_filter( 'woocommerce_variation_is_purchasable',       [ $this, 'filter_variation_is_purchasable' ], 10, 2 );
        add_filter( 'woocommerce_available_variation',            [ $this, 'filter_available_variation_price_html' ], 10, 3 );
        add_action( 'template_redirect',                          [ $this, 'enforce_cart_checkout_gate' ], 20 );

        // gating: prevent catalog-only products from add to cart
        add_filter( 'woocommerce_add_to_cart_validation',         [ $this, 'block_add_to_cart_for_catalog_only' ], 10, 4 );
        add_filter( 'woocommerce_loop_add_to_cart_link',          [ $this, 'maybe_remove_loop_add_to_cart_link' ], 10, 2 );

        // hide prices in API 
        add_filter( 'rest_request_after_callbacks',                 [ $this, 'scrub_store_api_prices' ], 10, 3 );
        add_filter( 'woocommerce_hydration_request_after_callbacks',[ $this, 'scrub_store_api_prices' ], 10, 3 );
    }

    /* ---------------- Pricing & purchasing ---------------- */

    /**
     * Parent-level gating:
     * - explicit SKU catalog-only list
     * - zero-price only for simple/non-variable products
     */
    private function is_catalog_only_parent( $product ) {
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return false;
        }

        // Normalize variation to parent.
        if ( $product->is_type( 'variation' ) ) {
            $product = wc_get_product( $product->get_parent_id() );
            if ( ! $product ) return false;
        }

        $sku = (string) $product->get_sku();
        $catalog_only_skus = array( '100', '108', '1000', '700006590', '6827', 'MDS100EU', 'SNG-32CS-EU-A', '700005212' );
        if ( $sku !== '' && in_array( $sku, $catalog_only_skus, true ) ) {
            return true;
        }

        // Only simple/non-variable products are blocked by zero price at parent level.
        if ( ! $product->is_type( 'variable' ) ) {
            $price = $product->get_price();
            if ( $price !== '' && (float) $price <= 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Variation-only gating for zero-priced variations.
     */
    private function is_blocked_variation( $variation ) {
        if ( ! $variation || ! is_a( $variation, 'WC_Product_Variation' ) ) {
            return false;
        }

        $price = $variation->get_price();
        return ( $price !== '' && (float) $price <= 0 );
    }

    private function is_installation_product( $product ) {
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return false;
        }

        if ( $product->is_type( 'variation' ) ) {
            $product = wc_get_product( $product->get_parent_id() );
            if ( ! $product ) return false;
        }

        $sku = (string) $product->get_sku();
        if ( $sku === '' ) return false;

        $catalog_only_skus = array( '100', '108', '1000' );
        return in_array( $sku, $catalog_only_skus, true );
    }

    public function block_add_to_cart_for_catalog_only( $passed, $product_id, $quantity, $variation_id = 0 ) {
        // Variation add-to-cart: block only the selected zero-priced variation.
        if ( ! empty( $variation_id ) ) {
            $variation = wc_get_product( $variation_id );
            if ( $this->is_blocked_variation( $variation ) ) {
                wc_add_notice(
                    __( 'Denne varianten selges ikke direkte i nettbutikken. Velg en annen variant eller kontakt oss.', 'lavendelhygiene' ),
                    'notice'
                );
                return false;
            }
        }

        // Parent/simple product gating.
        $product = wc_get_product( $product_id );
        if ( $this->is_catalog_only_parent( $product ) ) {
            wc_add_notice(
                __( 'Dette produktet selges ikke direkte i nettbutikken. Kontakt oss for tilbud eller mer informasjon.', 'lavendelhygiene' ),
                'notice'
            );
            return false;
        }

        return $passed;
    }

    public function maybe_remove_loop_add_to_cart_link( $html, $product ) {
        return $this->is_catalog_only_parent( $product ) ? '' : $html;
    }

    public function filter_price_html( $price_html, $product ) {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            return $price_html;
        }
        if ( LavendelHygiene_Core::user_is_restricted() ) {
            return '';
        }
        if ( $this->is_catalog_only_parent( $product ) ) {
            return '';
        }
        return $price_html;
    }

    public function filter_available_variation_price_html( $data, $product, $variation ) {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            return $data;
        }

        if ( LavendelHygiene_Core::user_is_restricted() ) {
            $data['price_html'] = '';
            foreach ( array( 'display_price', 'display_regular_price' ) as $k ) {
                if ( array_key_exists( $k, $data ) ) {
                    $data[ $k ] = null;
                }
            }
            $data['is_purchasable'] = false;
            return $data;
        }

        if ( $this->is_blocked_variation( $variation ) ) {
            $data['price_html'] = '';
            $data['is_purchasable'] = false;
            $data['variation_is_active'] = false;
        }

        return $data;
    }

    public function filter_structured_data_offer( $offer, $product ) {
        // if visitor can't see prices, structured data must not include it
        if ( LavendelHygiene_Core::user_is_restricted() || $this->is_catalog_only_parent( $product ) ) {
            return array();
        }
        return $offer;
    }

    public function filter_structured_data_product( $markup, $product ) {
        if ( LavendelHygiene_Core::user_is_restricted() || $this->is_catalog_only_parent( $product ) ) {
            unset( $markup['offers'] );
        }
        return $markup;
    }

    public function filter_is_purchasable( $purchasable, $product ) {
        if ( LavendelHygiene_Core::user_is_restricted() ) {
            return false;
        }

        if ( $this->is_catalog_only_parent( $product ) ) {
            return false;
        }

        return $purchasable;
    }

    public function filter_variation_is_purchasable( $purchasable, $variation ) {
        if ( LavendelHygiene_Core::user_is_restricted() ) {
            return false;
        }

        if ( $this->is_blocked_variation( $variation ) ) {
            return false;
        }

        // Keep parent SKU-based catalog-only rule for variations.
        if ( $this->is_catalog_only_parent( $variation ) ) {
            return false;
        }

        return $purchasable;
    }

    /* ---------------- Cart/checkout access ---------------- */

    public function enforce_cart_checkout_gate() {
        if ( LavendelHygiene_Core::user_is_restricted() ) {
            $is_cart     = function_exists( 'is_cart' )     && is_cart();
            $is_checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page();

            if ( $is_cart || $is_checkout ) {
                if ( ! is_user_logged_in() ) {
                    wc_add_notice( __( 'Du må logge inn for å se priser og handle.', 'lavendelhygiene' ), 'notice' );
                } else {
                    wc_add_notice( __( 'Konto må bli godkjent for å se priser og handle, kontakt oss ved spørsmål.', 'lavendelhygiene' ), 'notice' );
                }
                wp_safe_redirect( wc_get_page_permalink( 'myaccount' ) );
                exit;
            }
        }
    }

    /* ---------------- UX notices ---------------- */

    public function maybe_show_pending_notice() {
        if ( is_user_logged_in() && LavendelHygiene_Core::user_is_pending( get_current_user_id() ) ) {
            echo '<div class="woocommerce-info">' . esc_html__( 'Kontoen din avventer godkjenning. Vi tar kontakt.', 'lavendelhygiene' ) . '</div>';
        }
    }

    public function maybe_product_page_notice() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) return;

        if ( ! is_user_logged_in() ) {
            $login_url   = wc_get_page_permalink( 'myaccount' );
            $contact_url = home_url( '/kontakt/' );

            echo '<div class="woocommerce-info">' . wp_kses_post(
                sprintf(
                    __( '<a href="%s">Logg inn</a> for å se pris, eller <a href="%s">kontakt oss</a> for tilbud.', 'lavendelhygiene' ),
                    esc_url( $login_url ),
                    esc_url( $contact_url )
                )
            ) . '</div>';
            return;
        }
        if ( LavendelHygiene_Core::user_is_pending( get_current_user_id() ) ) {
            echo '<div class="woocommerce-info">' . esc_html__( 'Konto må bli godkjent for å se priser og handle, kontakt oss ved spørsmål.', 'lavendelhygiene' ) . '</div>';
        }
    }

    public function maybe_volume_pricing_notice() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
        if ( LavendelHygiene_Core::user_is_restricted() ) return; // show only when price is shown

        global $product;
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) return;

        $flag = get_post_meta( $product->get_id(), 'show_volume_pricing_notice', true );
        $enabled = ( $flag === 'yes' || $flag === '1' );

        if ( ! $enabled ) return;

        $contact_url = home_url( '/kontakt/' );

        $message = LavendelHygiene_Messages::render( 'volume_pricing_notice', [
            'contact_url' => esc_url( $contact_url ),
        ] );

        echo '<div class="woocommerce-info">' . wp_kses_post( $message ) . '</div>';
    }

    public function maybe_not_purchasable_pricing_notice() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
        if ( LavendelHygiene_Core::user_is_restricted() ) return;

        $product = wc_get_product( get_queried_object_id() );
        if ( ! $product ) return;

        if ( ! $this->is_catalog_only_parent( $product ) ) return;

        $contact_url = home_url( '/kontakt/' );

        if ( $this->is_installation_product( $product ) ) {
            echo '<div class="woocommerce-info">' . wp_kses_post(
                LavendelHygiene_Messages::render( 'installation_product_notice', [
                    'contact_url' => esc_url( $contact_url ),
                ] )
            ) . '</div>';
            return;
        }

        // Zero-price catalog-only message
        echo '<div class="woocommerce-info">' . wp_kses_post(
            LavendelHygiene_Messages::render( 'catalog_only_notice', [
                'contact_url' => esc_url( $contact_url ),
            ] )
        ) . '</div>';

        // For kjøp av Hygienematter vil pris for montering komme i tillegg, <a href="%s">kontakt oss</a> for spørsmål.
    }


    public function inject_notice_for_blocks_cart_checkout( $block_content, $block ) {
        if ( empty( $block['blockName'] ) ) {
            return $block_content;
        }

        if ( ! ( function_exists( 'is_cart' ) && is_cart() ) ) {
            return $block_content;
        }

        if ( $block['blockName'] !== 'woocommerce/cart' ) {
            return $block_content;
        }

        $shipping_page = get_page_by_path( 'frakt-og-levering' );
        $shipping_page_url = $shipping_page ? get_permalink( $shipping_page ) : home_url( '/frakt-og-levering/' );

        // $message = __( 'Hvis du har fast rabatt eller avtalepris reflekteres dette her.<br>Hvis du har fått et tilbud reflekteres det derimot ikke automatisk. Legg ved din tilbudsreferanse i kassen, så vil tilbudets priser reflekteres i endelig faktura.', 'lavendelhygiene' );
        // $message = __( 'Merk: Individuelle tilbud er ikke vist i nettbutikken, men vil bli reflektert i fakturaen. Ved tilbud, legg inn ditt tilbud nummer i kassen.', 'lavendelhygiene' );

        $message = LavendelHygiene_Messages::render( 'cart_checkout_notice', [
            'shipping_url' => esc_url( $shipping_page_url ),
        ] );

        static $printed = false;
        $style = '';
        if ( ! $printed ) {
            $printed = true;
            $style = '<style id="lavh-cart-checkout-notice-style">
                .lavh-cart-checkout-notice{display:flex;justify-content:center;margin:0 0 30px;}
                .lavh-cart-checkout-notice .wc-block-components-notice-banner{
                    width:fit-content;
                    max-width:800px;
                    font-size:1rem;
                    line-height:1.4;
                    padding:14px 22px;
                    border:0px;
                    background:#f3f8ff;
                    border-radius:8px;
                }
                .lavh-cart-checkout-notice .wc-block-components-notice-banner__content{
                    text-align:center;
                    font-weight:500;
                    letter-spacing:.2px;
                }
                @media (min-width: 900px){
                    .lavh-cart-checkout-notice .wc-block-components-notice-banner{font-size:1.05rem;}
                }
            </style>';
        }

        $allowed = array(
            'a'  => array(
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ),
            'br' => array(),
        );
        $notice_html = sprintf(
            '<div class="lavh-cart-checkout-notice">
                <div class="wc-block-components-notice-banner is-info" role="status" aria-live="polite">
                    <div class="wc-block-components-notice-banner__content">%s</div>
                </div>
            </div>',
            wp_kses( $message, $allowed )
        );

        return $style . $notice_html . $block_content;
    }

    public function inject_notice_for_blocks_signup( $block_content, $block ) {
        if ( empty( $block['blockName'] ) ) {
            return $block_content;
        }

        if ( ! ( function_exists( 'is_account_page' ) && is_account_page() ) || is_user_logged_in() ) {
            return $block_content;
        }

        $allowed_blocks = array(
            'woocommerce/my-account',
            'woocommerce/customer-account',
            'woocommerce/classic-shortcode',
            'core/shortcode',
        );

        if ( ! in_array( $block['blockName'], $allowed_blocks, true ) ) {
            return $block_content;
        }

        static $injected = false;
        if ( $injected ) {
            return $block_content;
        }
        $injected = true;

        $message = LavendelHygiene_Messages::render( 'signup_notice', [
            'login_url' => esc_url( wc_get_page_permalink( 'myaccount' ) ),
            'contact_url' => esc_url( home_url( '/kontakt/' ) ),
        ] );

        static $printed_style = false;
        $style = '';
        if ( ! $printed_style ) {
            $printed_style = true;
            $style = '<style id="lavh-signup-notice-style">
                .lavh-signup-notice{display:flex;justify-content:center;margin:0 0 24px;}
                .lavh-signup-notice .wc-block-components-notice-banner{
                    width:fit-content;
                    max-width:1000px;
                    font-size:18px;
                    line-height:1.4;
                    padding: 30px !important;
                    border:0;
                    background:#f3f8ff;
                    border-radius:15px;
                    margin-top: 0px;
                }
                .lavh-signup-notice .wc-block-components-notice-banner__content{
                    text-align:left;
                    font-weight:500;
                    letter-spacing:.2px;
                }
            </style>';
        }

        $notice_html = sprintf(
            '<div class="lavh-signup-notice">
                <div class="wc-block-components-notice-banner is-info" role="status" aria-live="polite">
                    <div class="wc-block-components-notice-banner__content">%s</div>
                </div>
            </div>',
            wp_kses_post( $message )
        );

        return $style . $notice_html . $block_content;
    }

    public function scrub_store_api_prices( $response, $server, $request ) {
        if ( ! LavendelHygiene_Core::user_is_restricted() ) {
            return $response;
        }

        $route = $request->get_route(); // e.g. /wc/store/v1/products
        if ( strpos( $route, '/wc/store/' ) !== 0 ) {
            return $response;
        }

        $data = ( $response instanceof WP_REST_Response ) ? $response->get_data() : $response;
        $data = $this->scrub_price_fields_recursive( $data );

        if ( $response instanceof WP_REST_Response ) {
            $response->set_data( $data );
            return $response;
        }

        return $data;
    }

    private function scrub_price_fields_recursive( $data ) {
        if ( is_array( $data ) ) {
            // Store API uses a nested "prices" object.
            if ( isset( $data['prices'] ) && is_array( $data['prices'] ) ) {
                foreach ( array( 'price', 'regular_price', 'sale_price', 'price_range' ) as $k ) {
                    if ( array_key_exists( $k, $data['prices'] ) ) {
                        $data['prices'][ $k ] = null;
                    }
                }
            }

            // Common price keys found in various Woo responses.
            foreach ( array( 'price_html', 'price', 'regular_price', 'sale_price', 'display_price', 'display_regular_price' ) as $k ) {
                if ( array_key_exists( $k, $data ) ) {
                    $data[ $k ] = null;
                }
            }

            foreach ( $data as $k => $v ) {
                $data[ $k ] = $this->scrub_price_fields_recursive( $v );
            }
        }

        return $data;
    }
}