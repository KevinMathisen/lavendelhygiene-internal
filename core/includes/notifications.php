<?php
if ( ! defined( 'ABSPATH' ) ) exit;


class LavendelHygiene_Notifications {
    public function __construct() {
        add_action( 'woocommerce_created_customer', [ $this, 'email_on_registration' ], 25, 3 );

        // notify customer when order goes from pending -> on-hold
        add_action('woocommerce_order_status_pending_to_on-hold',
            [ $this, 'send_processing_email_for_invoice_on_hold' ], 10, 2 );
    }

    public function email_on_registration( $user_id, $new_customer_data = [], $password_generated = false ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $roles               = (array) $user->roles;
        $is_woo_registration = isset( $_POST['woocommerce-register-nonce'] ) || in_array( 'customer', $roles, true ) || in_array( LavendelHygiene_Core::PENDING_ROLE, $roles, true );
        if ( ! $is_woo_registration ) return;

        // Internal notification
        $admin_to = sanitize_email( (string) get_option( 'lavendelhygiene_notify_email' ) );
        if ( ! is_email( $admin_to ) ) {
            $admin_to = get_option( 'admin_email' );
        }
        $subject_admin = sprintf( __( 'Ny bedrift-registrering: %s', 'lavendelhygiene' ), $user->user_login );

        $applications_url = admin_url( 'users.php?page=lavendelhygiene-applications' );

        $body_admin = sprintf(
            '<p>Ny registrering venter godkjenning.</p>
            <p>
                <strong>Bruker:</strong> %s<br>
                <strong>E-post:</strong> %s<br>
                <strong>Selskap:</strong> %s<br>
                <strong>Org.nr:</strong> %s
            </p>
            <p><a href="%s">Åpne pending users</a></p>',
            esc_html( $user->user_login ),
            esc_html( $user->user_email ),
            esc_html( (string) get_user_meta( $user_id, 'billing_company', true ) ),
            esc_html( (string) get_user_meta( $user_id, LavendelHygiene_Core::META_ORGNR, true ) ),
            esc_url( $applications_url )
        );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $admin_to, $subject_admin, $body_admin, $headers );
    }

    public function send_processing_email_for_invoice_on_hold( $order_id, $order ) {
        $mailer = WC()->mailer();
        if ( ! $mailer ) {
            return;
        }
        $emails = $mailer->get_emails();

        if ( empty( $emails['WC_Email_Customer_Processing_Order'] ) ) {
            return;
        }

        $processing_email = $emails['WC_Email_Customer_Processing_Order'];

        if ( method_exists( $processing_email, 'is_enabled' ) && ! $processing_email->is_enabled() ) {
            return;
        }

        // Send "Processing order" email
        $processing_email->trigger( $order_id, $order );
    }

}