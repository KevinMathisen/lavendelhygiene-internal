<?php
if ( ! defined( 'ABSPATH' ) ) exit;


class LavendelHygiene_TweakUserSettings {
    public function __construct() {
        add_action( 'woocommerce_edit_account_form', [ $this, 'render_field' ] );
        add_action( 'woocommerce_save_account_details', [ $this, 'save_field' ] );

        add_filter( 'woocommerce_shipping_fields', [ $this, 'add_shipping_phone_field' ] );
        add_filter( 'woocommerce_billing_fields', [ $this, 'maybe_hide_billing_email_on_address_edit' ] );

        // Stop billing name/phone in the *user profile* from being modified by the Checkout Block.
        add_filter( 'update_user_metadata', [ $this, 'prevent_order_overwrite_billing_fields' ], 10, 5 );
    }

    public function prevent_order_overwrite_billing_fields( $check, $user_id, $meta_key, $meta_value, $prev_value ) {
        // Only care about these profile fields
        $protected_keys = array( 'billing_first_name', 'billing_last_name', 'billing_phone' );

        if ( ! in_array( $meta_key, $protected_keys, true ) ) {
            return $check;
        }

        $uri        = $_SERVER['REQUEST_URI'] ?? '';
        $rest_base  = trailingslashit( rest_get_url_prefix() );
        $is_store   = ( false !== strpos( $uri, $rest_base . 'wc/store/' ) );
        $is_checkout= ( false !== strpos( $uri, '/checkout' ) );

        if ( $is_store && $is_checkout ) {
            // Short-circuit the update
            return true;
        }

        return $check;
    } 

    public function add_shipping_phone_field( $fields ) {
        if ( ! isset( $fields['shipping_phone'] ) ) {
            $fields['shipping_phone'] = [
                'type'        => 'tel',
                'label'       => __( 'Telefon (levering)', 'lavendelhygiene' ),
                'required'    => true,
                'class'       => [ 'form-row-wide' ],
                'priority'    => 120,
                'validate'    => [ 'phone' ],
            ];
        }
        return $fields;
    }

    public function maybe_hide_billing_email_on_address_edit( $fields ) {
        if ( function_exists( 'is_account_page' ) && is_account_page()
            && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' ) ) {
            unset( $fields['billing_email'] );
        }
        return $fields;
    }

    public function render_field() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) return;
        $val = get_user_meta( $user_id, 'billing_phone', true );
        echo '<p class="form-row form-row-wide">';
        woocommerce_form_field(
            'billing_phone',
            [
                'type'        => 'tel',
                'label'       => __( 'Telefon', 'lavendelhygiene' ),
                'required'    => true,
                'input_class' => [ 'woocommerce-Input', 'input-text' ],
                'default'     => $val,
            ],
            $val
        );
        echo '</p>';
    }

    public function save_field( $user_id ) {
        if ( ! isset( $_POST['billing_phone'] ) ) return;
        $phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
        update_user_meta( $user_id, 'billing_phone', $phone );
    }
}