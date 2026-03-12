<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LavendelHygiene_AdminMessages {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_post_lavendelhygiene_save_messages', [ $this, 'handle_save' ] );
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('LH: Administrer meldinger', 'lavendelhygiene'),
            __('LH: Administrer meldinger', 'lavendelhygiene'),
            'manage_options',
            'lavendelhygiene-messages',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'No permission.', 'lavendelhygiene' ) );

        $messages = LavendelHygiene_Messages::all();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Administrer meldinger', 'lavendelhygiene' ); ?></h1>

            <?php if ( isset( $_GET['updated'] ) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Meldinger oppdatert.', 'lavendelhygiene' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="lavendelhygiene_save_messages" />
                <?php wp_nonce_field( 'lavendelhygiene_save_messages' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="signup_notice">Ny kunde-melding</label></th>
                            <td><textarea name="messages[signup_notice]" id="signup_notice" rows="8" class="large-text"><?php echo esc_textarea( $messages['signup_notice'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="cart_checkout_notice">Kasse/handlekurv-melding</label></th>
                            <td><textarea name="messages[cart_checkout_notice]" id="cart_checkout_notice" rows="6" class="large-text"><?php echo esc_textarea( $messages['cart_checkout_notice'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="installation_product_notice">Installasjonsprodukt</label></th>
                            <td><textarea name="messages[installation_product_notice]" id="installation_product_notice" rows="5" class="large-text"><?php echo esc_textarea( $messages['installation_product_notice'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="catalog_only_notice">Ikke solgt i nettbutikk</label></th>
                            <td><textarea name="messages[catalog_only_notice]" id="catalog_only_notice" rows="5" class="large-text"><?php echo esc_textarea( $messages['catalog_only_notice'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="temporary_unavailable_notice">Midlertidig utilgjengelig</label></th>
                            <td><textarea name="messages[temporary_unavailable_notice]" id="temporary_unavailable_notice" rows="5" class="large-text"><?php echo esc_textarea( $messages['temporary_unavailable_notice'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="volume_pricing_notice">Volumpris-melding</label></th>
                            <td><textarea name="messages[volume_pricing_notice]" id="volume_pricing_notice" rows="5" class="large-text"><?php echo esc_textarea( $messages['volume_pricing_notice'] ); ?></textarea></td>
                        </tr>
                    </tbody>
                </table>

                <p class="description">
                    <?php esc_html_e( 'Tilgjengelige plassholdere: {contact_url}, {shipping_url}, {login_url}', 'lavendelhygiene' ); ?>
                </p>

                <?php submit_button( __( 'Lagre meldinger', 'lavendelhygiene' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No permission.', 'lavendelhygiene' ) );
        }

        check_admin_referer( 'lavendelhygiene_save_messages' );

        $input = isset( $_POST['messages'] ) && is_array( $_POST['messages'] ) ? $_POST['messages'] : [];
        $clean = LavendelHygiene_Messages::sanitize_messages_input( $input );

        update_option( LavendelHygiene_Messages::OPTION_KEY, $clean );

        wp_safe_redirect( admin_url( 'admin.php?page=lavendelhygiene-messages&updated=1' ) );
        exit;
    }
}