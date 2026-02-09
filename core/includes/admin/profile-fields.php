<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Admin: Users page + user edit page ---------- */

class LavendelHygiene_ProfileFields {
    public function __construct() {
        add_action( 'show_user_profile', [ $this, 'render' ] );
        add_action( 'edit_user_profile', [ $this, 'render' ] );
        add_action( 'personal_options_update', [ $this, 'save' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save' ] );

        add_action( 'user_profile_update_errors', [ $this, 'validate_profile' ], 10, 3 );

        add_filter( 'manage_users_columns', [ $this, 'users_col' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'users_col_content' ], 10, 3 );
    }
    public function render( $user ) {
        if ( ! current_user_can( 'list_users' ) ) return;
        $svc  = new LavendelHygiene_TripletexLinkingService();
        $ttx  = $svc->get_ttx_id( $user->ID );
        ?>
        <h2><?php esc_html_e('LH: Tripletex link', 'lavendelhygiene'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="tripletex_customer_id"><?php esc_html_e('Tripletex Customer ID','lavendelhygiene'); ?></label></th>
                <td>
                    <input type="text" name="tripletex_customer_id" id="tripletex_customer_id" value="<?php echo esc_attr($ttx); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Numeric Tripletex customer ID. Leave empty to clear.','lavendelhygiene'); ?></p>
                    <?php wp_nonce_field(
                        'lavendelhygiene_profile_tripletex_' . (int) $user->ID,
                        'lavendelhygiene_profile_tripletex_nonce'
                        ); ?>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save( $user_id ) {
        if ( ! current_user_can( 'promote_users' ) && ! current_user_can( 'manage_woocommerce' ) ) return;
        if (
            ! isset( $_POST['lavendelhygiene_profile_tripletex_nonce'] ) ||
            ! wp_verify_nonce( $_POST['lavendelhygiene_profile_tripletex_nonce'], 'lavendelhygiene_profile_tripletex_' . (int) $user_id )
        ) return;
        if ( ! isset( $_POST['tripletex_customer_id'] ) ) return;

        $svc = new LavendelHygiene_TripletexLinkingService();
        $id  = $svc->sanitize_ttx_id( (string) $_POST['tripletex_customer_id'] );

        if ( $id === '' ) { $svc->clear_ttx_id_wp( $user_id ); return; }
        if ( ! $svc->is_ttx_id_unique( $id, $user_id ) ) { return; } // validator already added the error
        $svc->set_ttx_id_wp( $user_id, $id, get_current_user_id() );
    }

    public function validate_profile( $errors, $update, $user ) {
        // Only admins/shop managers can change this field
        if ( ! current_user_can( 'promote_users' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        // Only validate if our field is present
        if ( ! isset( $_POST['tripletex_customer_id'] ) ) {
            return;
        }
        // Nonce check mirrors render()
        if (
            ! isset( $_POST['lavendelhygiene_profile_tripletex_nonce'] ) ||
            ! wp_verify_nonce( $_POST['lavendelhygiene_profile_tripletex_nonce'], 'lavendelhygiene_profile_tripletex_' . (int) $user->ID )
        ) return;

        $svc = new LavendelHygiene_TripletexLinkingService();
        $id  = $svc->sanitize_ttx_id( (string) $_POST['tripletex_customer_id'] );

        // Empty is allowed (clears mapping), so only check uniqueness if non-empty
        if ( $id !== '' && ! $svc->is_ttx_id_unique( $id, (int) $user->ID ) ) {
            $errors->add( 'tripletex_duplicate', __( 'Tripletex ID already linked to another user.', 'lavendelhygiene' ) );
        }
    }

    public function users_col( $cols ) {
        $cols['company'] = __( 'Company', 'lavendelhygiene' );
        $cols['orgnr']   = __( 'Org.nr', 'lavendelhygiene' );
        $cols['b2b_status']   = __( 'Approved?', 'lavendelhygiene' );
        $cols['tripletex_id'] = __( 'Tripletex ID', 'lavendelhygiene' );

        return $cols;
    }

    public function users_col_content( $output, $column_name, $user_id ) {
        if ( 'company' === $column_name ) {
            $val = get_user_meta( $user_id, 'billing_company', true );
            return $val ? esc_html( $val ) : '—';
        }
        if ( 'orgnr' === $column_name ) {
            $val = get_user_meta( $user_id, LavendelHygiene_Core::META_ORGNR, true );
            return $val ? esc_html( $val ) : '—';
        }
        if ( 'tripletex_id' === $column_name ) {
            $val = get_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID, true );
            return $val ? esc_html( $val ) : '—';
        }
        if ( 'b2b_status' === $column_name ) {
            $status = get_user_meta( $user_id, LavendelHygiene_Core::META_STATUS, true );
            if ( ! $status ) {
                $roles  = (array) ( get_userdata( $user_id )->roles ?? [] );
                $status = in_array( LavendelHygiene_Core::PENDING_ROLE, $roles, true ) ? 'pending' : '—';
            }
            return esc_html( $status );
        }
        return $output;
    }
}