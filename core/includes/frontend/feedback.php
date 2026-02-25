<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LavendelHygiene_Feedback {
    public function __construct() {
        // add_action( 'wp_footer', [ $this, 'render_feedback_button' ] );
    }

    public function render_feedback_button() {
        // Don't show on the contact page
        if ( is_page( 'kontakt' ) || is_front_page() ) {
            return;
        }

        ?>
        <a href="<?php echo esc_url( home_url( '/kontakt/' ) ); ?>" class="lh-feedback-fab" aria-label="<?php esc_attr_e( 'Gi tilbakemelding', 'lavendelhygiene' ); ?>">
            <?php esc_html_e( 'Tilbakemelding', 'lavendelhygiene' ); ?>
        </a>

        <style>
            .lh-feedback-fab{
                position: fixed;
                left: 20px;
                bottom: 20px;
                z-index: 999;
                background: #6767A5;
                color: #ffffff;
                padding: 12px 16px;
                border-radius: 999px;
                text-decoration: none;
                font-size: 14px;
                line-height: 1;
                box-shadow: 0 4px 12px rgba(0,0,0,.2);
                transition: opacity .3s ease-in-out;
            }

            .lh-feedback-fab:hover,
            .lh-feedback-fab:focus{
                opacity: .9;
                color: #ffffff;
                text-decoration: none;
            }

            @media (max-width: 768px){
                .lh-feedback-fab{
                    right: 12px;
                    bottom: 80px;
                    padding: 9px 12px;
                    font-size: 13px;
                }
            }
        </style>
        <?php
    }
}