<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Admin: Tripletex customer discounts ---------- */

class LavendelHygiene_AdminDiscounts {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_post_lavendelhygiene_refresh_discounts', [ $this, 'handle_refresh' ] );
    }

    public function admin_menu() {
        if ( ! current_user_can( 'list_users' ) && ! current_user_can( 'promote_users' ) ) return;

        add_users_page(
            __( 'LH: User Discounts', 'lavendelhygiene' ),
            __( 'LH: User Discounts', 'lavendelhygiene' ),
            'read',
            'lavendelhygiene-discounts',
            [ $this, 'render_admin_discounts' ]
        );
    }

    public function render_admin_discounts() {
        $this->assert_access();

        $customers = $this->get_linked_customers();
        $selected_user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        $selected_customer_id = $selected_user_id > 0
            ? (int) get_user_meta( $selected_user_id, LH_TTX_META_TRIPLETEX_ID, true )
            : 0;

        if ( $selected_customer_id <= 0 || ! isset( $customers[ $selected_customer_id ] ) ) {
            $selected_user_id = 0;
            $selected_customer_id = 0;
        }

        $discounts = [];
        $error = '';
        if ( $selected_customer_id > 0 ) {
            $selected_user_id = (int) $customers[ $selected_customer_id ]['users'][0]['id'];
            $discounts = LH_Ttx_Service_Registry::instance()->discounts()->get_discount_map_for_user( $selected_user_id );
            if ( is_wp_error( $discounts ) ) {
                $error = $discounts->get_error_message();
                $discounts = [];
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LH: User Discounts', 'lavendelhygiene' ); ?></h1>

            <?php if ( isset( $_GET['discounts_refreshed'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Discount cache refreshed from Tripletex.', 'lavendelhygiene' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $error !== '' ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <p><?php esc_html_e( 'Select a linked Tripletex customer to inspect the discounts shared by its connected users.', 'lavendelhygiene' ); ?></p>

            <?php if ( empty( $customers ) ) : ?>
                <p><?php esc_html_e( 'No users with a Tripletex customer ID were found.', 'lavendelhygiene' ); ?></p>
            <?php else : ?>
                <form method="get" style="margin:16px 0;">
                    <input type="hidden" name="page" value="lavendelhygiene-discounts">
                    <label for="lh-ttx-discount-customer"><strong><?php esc_html_e( 'Tripletex customer', 'lavendelhygiene' ); ?></strong></label>
                    <select id="lh-ttx-discount-customer" name="user_id">
                        <option value="0"><?php esc_html_e( 'Select customer', 'lavendelhygiene' ); ?></option>
                        <?php foreach ( $customers as $customer_id => $customer ) : ?>
                            <?php $label = sprintf( '%d - %s - %s', $customer_id, $customer['company'], implode( ', ', array_column( $customer['users'], 'label' ) ) ); ?>
                            <option value="<?php echo (int) $customer['users'][0]['id']; ?>" <?php selected( $selected_customer_id, $customer_id ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'View discounts', 'lavendelhygiene' ); ?></button>
                </form>
            <?php endif; ?>

            <?php if ( $selected_customer_id > 0 ) : ?>
                <h2><?php echo esc_html( sprintf( __( '%s (Tripletex customer %d)', 'lavendelhygiene' ), $customers[ $selected_customer_id ]['company'], $selected_customer_id ) ); ?></h2>
                <p>
                    <?php esc_html_e( 'Company:', 'lavendelhygiene' ); ?>
                    <?php echo esc_html( $customers[ $selected_customer_id ]['company'] ); ?><br>
                    <?php esc_html_e( 'Connected users:', 'lavendelhygiene' ); ?>
                    <?php echo esc_html( implode( ', ', array_column( $customers[ $selected_customer_id ]['users'], 'label' ) ) ); ?>
                </p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:12px 0 18px;">
                    <input type="hidden" name="action" value="lavendelhygiene_refresh_discounts">
                    <input type="hidden" name="user_id" value="<?php echo (int) $selected_user_id; ?>">
                    <?php wp_nonce_field( 'lavendelhygiene_refresh_discounts_' . $selected_customer_id ); ?>
                    <button type="submit" class="button"><?php esc_html_e( 'Refresh customer cache', 'lavendelhygiene' ); ?></button>
                </form>

                <?php if ( empty( $discounts ) ) : ?>
                    <p><?php esc_html_e( 'No discount policies were found for this customer.', 'lavendelhygiene' ); ?></p>
                <?php else : ?>
                    <table class="widefat striped">
                        <thead>
                        <tr>
                            <th><?php esc_html_e( 'Product', 'lavendelhygiene' ); ?></th>
                            <th><?php esc_html_e( 'Original WooCommerce price', 'lavendelhygiene' ); ?></th>
                            <th><?php esc_html_e( 'Tripletex source', 'lavendelhygiene' ); ?></th>
                            <th><?php esc_html_e( 'Tripletex value', 'lavendelhygiene' ); ?></th>
                            <th><?php esc_html_e( 'WooCommerce effective price', 'lavendelhygiene' ); ?></th>
                            <th><?php esc_html_e( 'Calculated WooCommerce discount', 'lavendelhygiene' ); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $discounts as $ttx_product_id => $discount ) :
                            $product = lh_ttx_find_wc_product_by_tripletex_product_id( (int) $ttx_product_id );
                            $price = (float) ( $discount['price'] ?? 0 );
                            $pct = (float) ( $discount['pct'] ?? 0 );
                            $regular = $product instanceof WC_Product ? (float) $product->get_regular_price( 'edit' ) : 0;
                            $calculated_pct = $regular > 0 ? max( 0, ( ( $regular - $price ) / $regular ) * 100 ) : 0;
                            $source = $pct > 0 ? __( 'Percentage', 'lavendelhygiene' ) : __( 'Fixed price', 'lavendelhygiene' );
                            ?>
                            <tr>
                                <td>
                                    <?php if ( $product instanceof WC_Product ) : ?>
                                        <a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
                                        <br><small><?php echo esc_html( sprintf( 'Tripletex product ID: %d', (int) $ttx_product_id ) ); ?></small>
                                    <?php else : ?>
                                        <strong><?php esc_html_e( 'WooCommerce product not found', 'lavendelhygiene' ); ?></strong>
                                        <br><small><?php echo esc_html( sprintf( 'Tripletex product ID: %d', (int) $ttx_product_id ) ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $regular > 0 ? wp_kses_post( wc_price( $regular ) ) : '&mdash;'; ?></td>
                                <td><?php echo esc_html( $source ); ?></td>
                                <td><?php echo $pct > 0 ? esc_html( $this->format_percent( $pct ) . '%' ) : wp_kses_post( wc_price( $price ) ); ?></td>
                                <td><?php echo $price > 0 ? wp_kses_post( wc_price( $price ) ) : '&mdash;'; ?></td>
                                <td><?php echo $regular > 0 ? esc_html( $this->format_percent( $calculated_pct ) . '%' ) : '&mdash;'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_refresh() {
        $this->assert_access();

        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $customer_id = $user_id > 0 ? (int) get_user_meta( $user_id, LH_TTX_META_TRIPLETEX_ID, true ) : 0;
        if ( $customer_id <= 0 ) wp_die( __( 'Invalid Tripletex customer.', 'lavendelhygiene' ) );

        check_admin_referer( 'lavendelhygiene_refresh_discounts_' . $customer_id );

        $discounts = LH_Ttx_Service_Registry::instance()->discounts()->get_discount_map_for_user( $user_id, true );
        if ( is_wp_error( $discounts ) ) wp_die( esc_html( $discounts->get_error_message() ) );

        wp_safe_redirect( add_query_arg( [
            'page' => 'lavendelhygiene-discounts',
            'user_id' => $user_id,
            'discounts_refreshed' => 1,
        ], admin_url( 'users.php' ) ) );
        exit;
    }

    private function assert_access() {
        if ( ! current_user_can( 'list_users' ) && ! current_user_can( 'promote_users' ) ) {
            wp_die( __( 'You do not have permission.', 'lavendelhygiene' ) );
        }
    }

    private function get_linked_customers(): array {
        $users = get_users( [
            'meta_key' => LH_TTX_META_TRIPLETEX_ID,
            'meta_compare' => 'EXISTS',
            'fields' => [ 'ID', 'user_email', 'display_name' ],
            'number' => -1,
        ] );
        $customers = [];

        foreach ( $users as $user ) {
            $customer_id = (int) get_user_meta( $user->ID, LH_TTX_META_TRIPLETEX_ID, true );
            if ( $customer_id <= 0 ) continue;

            $label = trim( (string) $user->display_name );
            if ( $label === '' ) $label = (string) $user->user_email;
            $company = trim( (string) get_user_meta( $user->ID, 'billing_company', true ) );
            if ( $company === '' ) $company = __( 'Company not set', 'lavendelhygiene' );

            $customers[ $customer_id ]['company'] = $company;
            $customers[ $customer_id ]['users'][] = [
                'id' => (int) $user->ID,
                'label' => $label,
            ];
        }

        ksort( $customers, SORT_NUMERIC );
        return $customers;
    }

    private function format_percent( float $value ): string {
        $formatted = rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
        return $formatted === '' ? '0' : $formatted;
    }
}