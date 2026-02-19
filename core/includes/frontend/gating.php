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

        /* gating: prices, purchasability, cart/checkout access */
        add_filter( 'woocommerce_get_price_html',                 [ $this, 'filter_price_html' ], 9, 2 );
        add_filter( 'woocommerce_variable_price_html',            [ $this, 'filter_price_html' ], 9, 2 );
        add_filter( 'woocommerce_is_purchasable',                 [ $this, 'filter_is_purchasable' ], 10, 2 );
        add_filter( 'woocommerce_variation_is_purchasable',       [ $this, 'filter_variation_is_purchasable' ], 10, 2 );
        add_filter( 'woocommerce_available_variation',            [ $this, 'filter_available_variation_price_html' ], 10, 3 );
        add_action( 'template_redirect',                          [ $this, 'enforce_cart_checkout_gate' ], 20 );
        
        // gating: prevent catalog-only products from add to cart
        add_filter( 'woocommerce_add_to_cart_validation',         [ $this, 'block_add_to_cart_for_catalog_only' ], 10, 3 );
        add_filter( 'woocommerce_loop_add_to_cart_link',          [ $this, 'maybe_remove_loop_add_to_cart_link' ], 10, 2 );
    }

    /* ---------------- Pricing & purchasing ---------------- */

    private function is_catalog_only_product( $product ) {
        if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
            return false;
        }

        // Normalize variations to parent
        if ( $product->is_type( 'variation' ) ) {
            $product = wc_get_product( $product->get_parent_id() );
            if ( ! $product ) return false;
        }

        // Treat zero-priced products as catalog-only
        $price = $product->get_price();
        if ( $price !== '' && (float) $price <= 0 ) {
            return true;
        }

        $sku = (string) $product->get_sku();
        if ( $sku === '' ) return false;

        $catalog_only_skus = array( '100', '108', '1000' );

        return in_array( $sku, $catalog_only_skus, true );
    }

    private function is_catalog_only_by_sku( $product ) {
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

    public function block_add_to_cart_for_catalog_only( $passed, $product_id, $quantity ) {
        $product = wc_get_product( $product_id );
        if ( $this->is_catalog_only_product( $product ) ) {
            wc_add_notice(
                __( 'Dette produktet selges ikke direkte i nettbutikken. Kontakt oss for tilbud eller mer informasjon.', 'lavendelhygiene' ),
                'notice'
            );
            return false;
        }
        return $passed;
    }

    public function maybe_remove_loop_add_to_cart_link( $html, $product ) {
        return $this->is_catalog_only_product( $product ) ? '' : $html;
    }

    public function filter_price_html( $price_html, $product ) {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            return $price_html;
        }
        if ( LavendelHygiene_Core::user_is_restricted() ) {
            return '';
        }
        if ( $this->is_catalog_only_product( $product ) ) {
            return '';
        }
        return $price_html; // approved
    }

    public function filter_available_variation_price_html( $data, $product, $variation ) {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            return $data;
        }
        if ( LavendelHygiene_Core::user_is_restricted() ) {
            $data['price_html'] = ''; //  hide the per-variation price_html payload used on variable products
        }
        if ( $this->is_catalog_only_product( $product ) ) {
            $data['price_html'] = '';
        }
        return $data;
    }

    public function filter_is_purchasable( $purchasable, $product ) {
        if ( LavendelHygiene_Core::user_is_restricted() ) {
            return false;
        }

        if ( $this->is_catalog_only_product( $product ) ) {
            return false;
        }

        return $purchasable;
    }

    public function filter_variation_is_purchasable( $purchasable, $variation ) {
        if ( LavendelHygiene_Core::user_is_restricted() ) {
            return false;
        }

        if ( $this->is_catalog_only_product( $variation ) ) {
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

        echo '<div class="woocommerce-info">' . wp_kses_post(
            sprintf(
                __( 'Nettsidepris gjelder ved standard bestilling. Ved større volum kan vi tilby avtalepris og tilpassede leveringsbetingelser, <a href="%s">kontakt oss</a> for tilbud.', 'lavendelhygiene' ),
                esc_url( $contact_url )
            )
        ) . '</div>';
    }

    public function maybe_not_purchasable_pricing_notice() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
        if ( LavendelHygiene_Core::user_is_restricted() ) return;

        $product = wc_get_product( get_queried_object_id() );
        if ( ! $product ) return;

        if ( ! $this->is_catalog_only_product( $product ) ) return;

        $contact_url = home_url( '/kontakt/' );

        if ( $this->is_catalog_only_by_sku( $product ) ) {
            echo '<div class="woocommerce-info">' . wp_kses_post(
                sprintf(
                    __( 'Produktet krever invidiuell tilpasning og installasjon. Pris fastsettes basert på ønsket løsning og omfang, og selges derfor ikke direkte i nettbutikken.<br><a href="%s">Kontakt oss</a> for tilbud eller mer informasjon!', 'lavendelhygiene' ),
                    esc_url( $contact_url )
                )
            ) . '</div>';
            return;
        }

        // Zero-price catalog-only message
        echo '<div class="woocommerce-info">' . wp_kses_post(
            sprintf(
                __( 'Dette produktet selges ikke for tiden direkte i nettbutikken.<br><a href="%s">Kontakt oss</a> for tilbud eller mer informasjon!', 'lavendelhygiene' ),
                esc_url( $contact_url )
            )
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

            $message = __( 'Hvis du har fast rabatt eller avtalepris reflekteres dette her.<br>Hvis du har fått et tilbud reflekteres det derimot ikke automatisk. Legg ved din tilbudsreferanse i kassen, så vil tilbudets priser reflekteres i endelig faktura.', 'lavendelhygiene' );
            // $message = __( 'Merk: Individuelle tilbud er ikke vist i nettbutikken, men vil bli reflektert i fakturaen. Ved tilbud, legg inn ditt tilbud nummer i kassen.', 'lavendelhygiene' );

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

        $notice_html = sprintf(
            '<div class="lavh-cart-checkout-notice">
                <div class="wc-block-components-notice-banner is-info" role="status" aria-live="polite">
                    <div class="wc-block-components-notice-banner__content">%s</div>
                </div>
            </div>',
            wp_kses_post( $message )
        );

        return $style . $notice_html . $block_content;
    }
}