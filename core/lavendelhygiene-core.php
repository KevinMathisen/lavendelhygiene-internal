<?php
/**
 * Plugin Name: Lavendel Hygiene Core
 * Description: B2B registration + approval + gating for WooCommerce (pending/approved flow, org.nr capture, emails, checkout restrictions).
 * Author: Kevin Nikolai Mathisen
 * Version: 1.2.0
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LavendelHygiene_Core {
    const VERSION              = '1.2.0';

    const PENDING_ROLE         = 'b2b_pending';

    const META_STATUS          = 'b2b_status'; // pending|approved|denied
    const META_ORGNR           = 'orgnr';
    const META_APPROVED_BY     = 'b2b_approved_by';
    const META_APPROVED_AT     = 'b2b_approved_at';
    const META_SECTOR          = 'company_sector';
    const META_USE_EHF         = 'use_ehf';
    const META_TRIPLETEX_ID    = 'tripletex_customer_id';
    const META_TTX_LINKED_BY   = 'tripletex_linked_by';
    const META_TTX_LINKED_AT   = 'tripletex_linked_at';

    public function __construct() {
        // lifecycle
        register_activation_hook( __FILE__, [ $this, 'on_activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'on_deactivate' ] );

        new LavendelHygiene_Registration();
        new LavendelHygiene_AdminApplications();
        new LavendelHygiene_ProfileFields();
        new LavendelHygiene_Gating();
        new LavendelHygiene_Notifications();
        new LavendelHygiene_AccountPhoneField();

        // Minor label tweak
        add_filter( 'woocommerce_countries_tax_or_vat', fn($label) => 'MVA (25%)' );
    }

    public function on_activate() {
        if ( ! get_role( self::PENDING_ROLE ) ) {
            add_role( self::PENDING_ROLE, 'B2B Pending', [ 'read' => true ] );
        }
        if ( ! get_role( 'customer' ) ) {
            add_role( 'customer', 'Customer', [ 'read' => true, 'level_0' => true ] );
        }
        if ( ! get_option( 'lavendelhygiene_notify_email' ) ) {
            add_option( 'lavendelhygiene_notify_email', get_option( 'admin_email' ) );
        }
        flush_rewrite_rules();
    }

    public function on_deactivate() { flush_rewrite_rules(); }

    /* Shared helpers */
    public static function user_is_approved( $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return false;
        $status = get_user_meta( $user_id, self::META_STATUS, true );
        return ( $status === 'approved' );
    }

    public static function user_is_pending( $user_id = 0 ): bool {
        $user_id = $user_id ?: get_current_user_id();
        if ( ! $user_id ) return false;
        $status = get_user_meta( $user_id, self::META_STATUS, true );
        $roles  = (array) ( get_userdata( $user_id )->roles ?? [] );
        return ( $status === 'pending' || in_array( self::PENDING_ROLE, $roles, true ) );
    }

    public static function user_is_restricted(): bool {
        // Admins/shop managers bypass gating
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) return false;

        if ( ! is_user_logged_in() ) return true; // guests restricted
        return ! self::user_is_approved( get_current_user_id() ); // pending restricted
    }
}

/* ---------- Service for setting local tripletex id for users ---------- */

class LavendelHygiene_TripletexLinkingService {
    public function get_ttx_id( int $user_id ): string {
        return (string) get_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID, true );
    }

    public function sanitize_ttx_id( string $raw ): string {
        return preg_replace( '/\D+/', '', $raw );
    }

    public function is_ttx_id_unique( string $ttx_id, int $exclude_user_id = 0 ): bool {
        if ( $ttx_id === '' ) return true;
        $q = new WP_User_Query( [
            'number'   => 1,
            'fields'   => 'ID',
            'meta_key' => LavendelHygiene_Core::META_TRIPLETEX_ID,
            'meta_value' => $ttx_id,
            'meta_compare' => '=',
        ] );
        $found = $q->get_results();
        if ( empty( $found ) ) return true;
        $first = (int) $found[0];
        return $first === (int) $exclude_user_id;
    }

    public function set_ttx_id_wp( int $user_id, string $id, int $actor_user_id ): void {
        update_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID, $id );
        update_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_BY, $actor_user_id );
        update_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_AT, current_time( 'mysql' ) );
        /**
         * Fires when a user is linked to a Tripletex customer ID.
         */
        do_action( 'lavendelhygiene_tripletex_linked', $user_id, $id, $actor_user_id );
    }

    public function clear_ttx_id_wp( int $user_id ): void {
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TRIPLETEX_ID );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_BY );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_TTX_LINKED_AT );
        do_action( 'lavendelhygiene_tripletex_unlinked', $user_id );
    }

    public function save_ttx_id_from_input( int $user_id, string $raw, int $actor_user_id ) {
        $id = $this->sanitize_ttx_id($raw);
        if ( $id === '' ) { $this->clear_ttx_id_wp($user_id); return 'cleared'; }
        if ( ! $this->is_ttx_id_unique($id, $user_id) ) {
            return new WP_Error( 'ttx_duplicate', __( 'Tripletex ID already linked to another user.', 'lavendelhygiene' ) );
        }
        $this->set_ttx_id_wp($user_id, $id, $actor_user_id);
        return 'saved';
    }

}

/* ---------- Admin: Approve/deny/create tripletex user page ---------- */

class LavendelHygiene_AdminApplications {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_post_lavendelhygiene_set_tripletex_id', [ $this, 'handle_set_tripletex_id' ] ); // non-AJAX fallback

        add_action( 'admin_post_lavendelhygiene_approve', [ $this, 'handle_approve' ] );
        add_action( 'admin_post_lavendelhygiene_deny', [ $this, 'handle_deny' ] );

        add_action( 'admin_post_lavendelhygiene_save_notify_email', [ $this, 'handle_save_notify_email' ] );
    }

    public function admin_menu() {
        add_users_page(
            __( 'LH: Pending Users', 'lavendelhygiene' ),
            __( 'LH: Pending Users', 'lavendelhygiene' ),
            'list_users',
            'lavendelhygiene-applications',
            [ $this, 'render_admin_applications' ]
        );
    }

    public function render_admin_applications() {
        if ( ! current_user_can( 'list_users' ) ) wp_die( __( 'You do not have permission.', 'lavendelhygiene' ) );

        $q = new WP_User_Query( [
            'role'    => LavendelHygiene_Core::PENDING_ROLE,
            'number'  => 100,
            'fields'  => [ 'ID', 'user_login', 'user_email' ],
            'orderby' => 'registered',
            'order'   => 'ASC',
        ] );
        $users = $q->get_results();
        $svc = new LavendelHygiene_TripletexLinkingService();
        $notify_email = get_option( 'lavendelhygiene_notify_email', get_option( 'admin_email' ) );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pending Users', 'lavendelhygiene' ); ?></h1>

            <?php if ( isset($_GET['notify_email_updated']) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Notification email updated.', 'lavendelhygiene'); ?></p></div>
            <?php endif; ?>
            <?php if ( isset($_GET['tripletex_updated']) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Tripletex ID updated.', 'lavendelhygiene'); ?></p></div>
            <?php endif; ?>
            <?php if ( isset($_GET['tripletex_created']) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Customer created in Tripletex.', 'lavendelhygiene'); ?></p></div>
            <?php endif; ?>

            <h3><?php esc_html_e('Notification settings', 'lavendelhygiene'); ?></h3>
            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="margin-bottom:16px;">
                <input type="hidden" name="action" value="lavendelhygiene_save_notify_email" />
                <?php wp_nonce_field( 'lavendelhygiene_save_notify_email' ); ?>
                <label for="lavh_notify_email"><strong><?php esc_html_e('Email address to notify when new users register', 'lavendelhygiene'); ?></strong></label>
                <input type="email" id="lavh_notify_email" name="notify_email" value="<?php echo esc_attr( $notify_email ); ?>" class="regular-text" required />
                <p class="description"><?php esc_html_e('This email address will receive an email when a new user registers.', 'lavendelhygiene'); ?></p>
                <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'lavendelhygiene'); ?></button>
            </form>

            <?php if ( empty( $users ) ) : ?>
                <p><?php esc_html_e( 'No pending applications.', 'lavendelhygiene' ); ?></p>
            <?php else : ?>
                <table class="widefat striped" id="lavendelhygiene-apps">
                    <thead>
                    <tr>
                        <th><?php esc_html_e( 'User', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Company', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Org.nr', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Tripletex ID', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $users as $u ) :
                        $company = get_user_meta( $u->ID, 'billing_company', true );
                        $orgnr   = get_user_meta( $u->ID, LavendelHygiene_Core::META_ORGNR, true );
                        $ttx_id  = $svc->get_ttx_id( $u->ID );

                        $approve_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=lavendelhygiene_approve&user_id=' . $u->ID ),
                            'lavendelhygiene_approve_' . $u->ID
                        );
                        $deny_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=lavendelhygiene_deny&user_id=' . $u->ID ),
                            'lavendelhygiene_deny_' . $u->ID
                        );
                        $set_nonce = wp_create_nonce( 'lavendelhygiene_set_tripletex_id_' . $u->ID );
                        // tripletex create calls Tripletex plugin
                        $ttx_create_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=lavendelhygiene_create_tripletex&user_id=' . $u->ID ),
                            'lavendelhygiene_create_tripletex_' . $u->ID
                        );
                        ?>
                        <tr data-user-id="<?php echo (int) $u->ID; ?>">
                            <td><?php echo esc_html( $u->user_login ); ?></td>
                            <td><?php echo esc_html( $u->user_email ); ?></td>
                            <td><?php echo esc_html( $company ); ?></td>
                            <td><?php echo esc_html( $orgnr ); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="lavendelhygiene-ttx-form">
                                    <input type="hidden" name="action" value="lavendelhygiene_set_tripletex_id">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                                    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $set_nonce ); ?>">
                                    <input type="text" name="tripletex_customer_id" value="<?php echo esc_attr( $ttx_id ); ?>" placeholder="e.g. 123456" style="width:120px;">
                                    <button type="submit" class="button"><?php esc_html_e('Save ID','lavendelhygiene'); ?></button>
                                    <span class="lavendelhygiene-ttx-msg" style="margin-left:.5rem;"></span>
                                </form>
                            </td>
                            <td>
                                <a href="<?php echo esc_url( $approve_url ); ?>" class="button button-primary"><?php esc_html_e( 'Approve', 'lavendelhygiene' ); ?></a>
                                <a href="<?php echo esc_url( $deny_url ); ?>" class="button"><?php esc_html_e( 'Deny', 'lavendelhygiene' ); ?></a>
                                <a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>" class="button"><?php esc_html_e( 'View', 'lavendelhygiene' ); ?></a>
                                <a href="<?php echo esc_url( $ttx_create_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Create in Tripletex', 'lavendelhygiene' ); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }


    public function handle_approve() {
        if ( ! current_user_can( 'promote_users' ) ) wp_die( __( 'No permission.', 'lavendelhygiene' ) );
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        check_admin_referer( 'lavendelhygiene_approve_' . $user_id );

        $user = get_user_by( 'id', $user_id );
        if ( $user ) {
            $user->set_role( 'customer' );
            update_user_meta( $user_id, LavendelHygiene_Core::META_STATUS, 'approved' );
            update_user_meta( $user_id, LavendelHygiene_Core::META_APPROVED_BY, get_current_user_id() );
            update_user_meta( $user_id, LavendelHygiene_Core::META_APPROVED_AT, current_time( 'mysql' ) );

            /* Notify user through email that they were approved */
            wp_mail(
                $user->user_email,
                __( '[Lavendel Hygiene AS] Kontoen din er godkjent', 'lavendelhygiene' ),
                __( "Hei!\n\nKontoen din hos Lavendel Hygiene er godkjent. Du kan nå se priser og bestille produkter direkte fra nettbutikken!\n\nKontakt oss hvis du har noen spørsmål.\n\nHilsen oss i Lavendel Hygiene", 'lavendelhygiene' )
            );
        }
        wp_safe_redirect( admin_url( 'users.php?page=lavendelhygiene-applications&approved=1' ) );
        exit;
    }

    public function handle_deny() {
        if ( ! current_user_can( 'promote_users' ) ) wp_die( __( 'No permission.', 'lavendelhygiene' ) );
        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        check_admin_referer( 'lavendelhygiene_deny_' . $user_id );

        $user = get_user_by( 'id', $user_id );
        if ( $user ) {
            update_user_meta( $user_id, LavendelHygiene_Core::META_STATUS, 'denied' );
            $user->set_role( 'subscriber' );

            /* Notify user through email that they were denied */
            wp_mail(
                $user->user_email,
                __( 'Konto avslått', 'lavendelhygiene' ),
                __( "Beklager, kontoen din hos Lavendel Hygiene ble ikke godkjent.\n\nKontakt oss hvis du har noen spørsmål.\n\nHilsen oss i Lavendel Hygiene", 'lavendelhygiene' )
            );
        }
        wp_safe_redirect( admin_url( 'users.php?page=lavendelhygiene-applications&denied=1' ) );
        exit;
    }

    public function handle_set_tripletex_id() {
        if ( ! current_user_can( 'promote_users' ) && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'No permission.', 'lavendelhygiene' ) );
        }
        $user_id = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        if ( ! $user_id ) {
            wp_die( __( 'Invalid user.', 'lavendelhygiene' ) );
        }
        check_admin_referer( 'lavendelhygiene_set_tripletex_id_' . $user_id );

        $svc = new LavendelHygiene_TripletexLinkingService();
        $res = $svc->save_ttx_id_from_input( $user_id, (string) ($_POST['tripletex_customer_id'] ?? ''), get_current_user_id() );

        if ( is_wp_error($res) ) {
            wp_die( $res->get_error_message() );
        }

        wp_safe_redirect( admin_url( 'users.php?page=lavendelhygiene-applications&tripletex_updated=1' ) );
        exit;
    }

    public function handle_save_notify_email() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'No permission.', 'lavendelhygiene' ) );
        }
        check_admin_referer( 'lavendelhygiene_save_notify_email' );

        $email = isset($_POST['notify_email']) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '';
        if ( is_email( $email ) ) {
            update_option( 'lavendelhygiene_notify_email', $email );
        } else {
            // If invalid/empty let user know
            wp_die( __( 'Invalid email address.', 'lavendelhygiene' ) );
        }
        wp_safe_redirect( admin_url( 'users.php?page=lavendelhygiene-applications&notify_email_updated=1' ) );
        exit;
    }

}

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
        unset( $cols['name'] ); // remove default name column

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

/* ---------- Registration, Approval, Gating, Notifications ---------- */

class LavendelHygiene_Registration {
    public function __construct() {
        add_action( 'woocommerce_register_form', [ $this, 'register_fields' ] );
        add_filter( 'woocommerce_registration_errors', [ $this, 'validate_register_fields' ], 10, 3 );
        add_action( 'woocommerce_created_customer', [ $this, 'save_register_fields' ], 10, 3 );
        add_action( 'user_register', [ $this, 'set_pending_role' ], 20 );
    }

     /**
     * Renders extra registration fields (all labels in Norwegian) and styles the layout:
     * - E-post
     * - Kontaktperson fornavn + etternavn (TODO)
     * - Telefon
     * - Firmanavn
     * - Organisasjonsnummer + Bransje/Næring
     * - Bruk EHF
     * - Fakturaadresse
     * - Leveringsadresse
     */
    public function register_fields() {
        if ( is_user_logged_in() ) return;

        // Sector options
        $sector_options = [
            'Datasenter'                   => 'Datasenter',
            'Labaratorier'                 => 'Labaratorier',
            'Helse/Sykehus'                => 'Helse/Sykehus',
            'Bakeri'                       => 'Bakeri',
            'Fiskeoppdrett'                => 'Fiskeoppdrett',
            'Kjøttindustri'                => 'Kjøttindustri',
            'Bryggeri'                     => 'Bryggeri',
            'Meieri'                       => 'Meieri',
            'Annen Næringsmiddelindustri' => 'Annen Næringsmiddelindustri',
            'Annet'                        => 'Annet',
        ];

        // Prefill posted values (after validation error)
        $posted = function( $key, $default = '' ) {
            return isset( $_POST[ $key ] ) ? esc_attr( wp_unslash( $_POST[ $key ] ) ) : $default;
        };

        $posted_sector    = $posted( 'company_sector' );
        $use_ehf_checked  = isset( $_POST['use_ehf'] ) ? (bool) $_POST['use_ehf'] : true; // default checked
        $posted_contact_first   = $posted( 'contact_first_name' );
        $posted_contact_last    = $posted( 'contact_last_name' );
        ?>
        <style>
            /* Layout helpers */
            .lavendelhygiene-reg-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }
            .lavendelhygiene-two { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
            @media (max-width: 640px) { .lavendelhygiene-two { grid-template-columns: 1fr; } }
            .lavendelhygiene-checkbox { display:flex; align-items:center; gap:8px; }
            .lavendelhygiene-section-title { margin: 12px 0 4px; font-weight: 600; }
            /* Make default Woo fields full width */
            .woocommerce form.register .woocommerce-form-row { width: 100%; }
            /* Button spacing */
            .woocommerce form.register .woocommerce-Button { margin-top: 12px; }
        </style>
        <script>
            document.addEventListener('DOMContentLoaded', function(){
                // Localize the native Woo labels (email/password) to Norwegian if needed
                var form = document.querySelector('form.register');
                if(!form) return;
                var emailLabel = form.querySelector('label[for="reg_email"]');
                if(emailLabel && /email/i.test(emailLabel.textContent)) emailLabel.textContent = 'E-post *';
                var passLabel = form.querySelector('label[for="reg_password"]');
                if(passLabel && /password|passord/i.test(passLabel.textContent)) passLabel.textContent = 'Passord *';
            });
        </script>

        <div class="lavendelhygiene-reg-grid">
            <!-- Firmanavn 100% -->
            <p class="form-row form-row-wide">
                <label for="reg_company"><?php esc_html_e( 'Firmanavn', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="company_name" id="reg_company"
                    value="<?php echo $posted( 'company_name' ); ?>" required />
            </p>

            <!-- Orgnr 50% + Sector 50% -->
            <div class="lavendelhygiene-two">
                <p class="form-row">
                    <label for="reg_orgnr"><?php esc_html_e( 'Organisasjonsnummer', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="orgnr" id="reg_orgnr" placeholder="9 siffer"
                        value="<?php echo $posted( 'orgnr' ); ?>" required />
                </p>
                <p class="form-row">
                    <label for="company_sector"><?php esc_html_e( 'Bransje/Næring', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <select name="company_sector" id="company_sector" class="input-select" required>
                        <option value=""><?php esc_html_e( 'Velg…', 'lavendelhygiene' ); ?></option>
                        <?php foreach ( $sector_options as $value => $label ) : ?>
                            <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $posted_sector, $value ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
            </div>

            <!-- EHF checkbox (left-aligned) -->
            <p class="form-row lavendelhygiene-checkbox">
                <label style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="use_ehf" value="1" <?php checked( $use_ehf_checked, true ); ?> />
                    <span><?php esc_html_e( 'Motta faktura på EHF', 'lavendelhygiene' ); ?></span>
                </label>
            </p>

            <!-- Kontaktperson -->
            <div class="lavendelhygiene-section-title"><?php esc_html_e( 'Kontaktperson', 'lavendelhygiene' ); ?></div>
            <div class="lavendelhygiene-two">
                <p class="form-row">
                    <label for="contact_first_name"><?php esc_html_e( 'Fornavn', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="contact_first_name" id="contact_first_name"
                        value="<?php echo $posted_contact_first; ?>" required />
                </p>
                <p class="form-row">
                    <label for="contact_last_name"><?php esc_html_e( 'Etternavn', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="contact_last_name" id="contact_last_name"
                        value="<?php echo $posted_contact_last; ?>" required />
                </p>
            </div>

            <!-- Phone 100% -->
            <p class="form-row form-row-wide">
                <label for="reg_phone"><?php esc_html_e( 'Telefon', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="tel" class="input-text" name="phone" id="reg_phone"
                    value="<?php echo $posted( 'phone' ); ?>" required />
            </p>

            <!-- Fakturaadresse -->
            <div class="lavendelhygiene-section-title"><?php esc_html_e( 'Fakturadresse', 'lavendelhygiene' ); ?></div>
            <p class="form-row form-row-wide">
                <label for="billing_address_1"><?php esc_html_e( 'Adresse', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="billing_address_1" id="billing_address_1"
                    value="<?php echo $posted( 'billing_address_1' ); ?>" required />
            </p>
            <div class="lavendelhygiene-two">
                <p class="form-row">
                    <label for="billing_postcode"><?php esc_html_e( 'Postnummer', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="billing_postcode" id="billing_postcode"
                        value="<?php echo $posted( 'billing_postcode' ); ?>" required />
                </p>
                <p class="form-row">
                    <label for="billing_city"><?php esc_html_e( 'Poststed', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="billing_city" id="billing_city"
                        value="<?php echo $posted( 'billing_city' ); ?>" required />
                </p>
            </div>
            <p class="form-row">
                <label for="billing_country"><?php esc_html_e( 'Land', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="billing_country" id="billing_country"
                    value="<?php echo $posted( 'billing_country', 'NO' ); ?>" required />
            </p>

            <!-- Leveringsadresse -->
            <div class="lavendelhygiene-section-title"><?php esc_html_e( 'Leveringsadresse', 'lavendelhygiene' ); ?></div>
            <p class="form-row form-row-wide">
                <label for="shipping_address_1"><?php esc_html_e( 'Adresse', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="shipping_address_1" id="shipping_address_1"
                    value="<?php echo $posted( 'shipping_address_1' ); ?>" required />
            </p>
            <div class="lavendelhygiene-two">
                <p class="form-row">
                    <label for="shipping_postcode"><?php esc_html_e( 'Postnummer', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="shipping_postcode" id="shipping_postcode"
                        value="<?php echo $posted( 'shipping_postcode' ); ?>" required />
                </p>
                <p class="form-row">
                    <label for="shipping_city"><?php esc_html_e( 'Poststed', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                    <input type="text" class="input-text" name="shipping_city" id="shipping_city"
                        value="<?php echo $posted( 'shipping_city' ); ?>" required />
                </p>
            </div>
            <p class="form-row">
                <label for="shipping_country"><?php esc_html_e( 'Land', 'lavendelhygiene' ); ?> <span class="required">*</span></label>
                <input type="text" class="input-text" name="shipping_country" id="shipping_country"
                    value="<?php echo $posted( 'shipping_country', 'NO' ); ?>" required />
            </p>
        </div>
        <?php
    }

    public function validate_register_fields( $errors, $username, $email ) {
        $required = [
            'company_name'       => __( 'Firmanavn', 'lavendelhygiene' ),
            'contact_first_name'  => __( 'Kontaktperson fornavn', 'lavendelhygiene' ),
            'contact_last_name'   => __( 'Kontaktperson etternavn', 'lavendelhygiene' ),
            'company_sector'     => __( 'Bransje/Næring', 'lavendelhygiene' ),
            'orgnr'              => __( 'Organisasjonsnummer', 'lavendelhygiene' ),
            'phone'              => __( 'Telefon', 'lavendelhygiene' ),
            'billing_address_1'  => __( 'Fakturadresse', 'lavendelhygiene' ),
            'billing_postcode'   => __( 'Faktura postnummer', 'lavendelhygiene' ),
            'billing_city'       => __( 'Faktura poststed', 'lavendelhygiene' ),
            'billing_country'    => __( 'Faktura land', 'lavendelhygiene' ),
            'shipping_address_1' => __( 'Leveringsadresse', 'lavendelhygiene' ),
            'shipping_postcode'  => __( 'Levering postnummer', 'lavendelhygiene' ),
            'shipping_city'      => __( 'Levering poststed', 'lavendelhygiene' ),
            'shipping_country'   => __( 'Levering land', 'lavendelhygiene' ),
        ];
        foreach ( $required as $key => $label ) {
            if ( empty( $_POST[ $key ] ) ) {
                $errors->add(
                    'required_' . $key,
                    sprintf( __( 'Vennligst fyll inn: %s.', 'lavendelhygiene' ), esc_html( $label ) )
                );
            }
        }

        // orgnr: norwegian mod11 checksum
        if ( ! empty( $_POST['orgnr'] ) && ! $this->is_valid_no_orgnr( (string) $_POST['orgnr'] ) ) {
            $errors->add( 'orgnr_invalid', __( 'Ugyldig organisasjonsnummer.', 'lavendelhygiene' ) );
        }

        return $errors;
    }

    private function is_valid_no_orgnr( string $raw ): bool {
        $digits = preg_replace( '/\D+/', '', $raw );
        if ( strlen( $digits ) !== 9 ) return false;

        // Split digits
        $d = array_map( 'intval', str_split( $digits ) );
        // Weights for the first 8 digits
        $w = [3,2,7,6,5,4,3,2];

        $sum = 0;
        for ( $i = 0; $i < 8; $i++ ) {
            $sum += $d[$i] * $w[$i];
        }
        $rem = $sum % 11;
        $k   = 11 - $rem;

        if ( $k === 11 ) $k = 0;     // check digit 0 when remainder is 0
        if ( $k === 10 ) return false; // invalid number

        return $d[8] === $k;
    }

    public function save_register_fields( $customer_id ) {
        // Map + save basics
        $map = [
            'company_name'       => 'billing_company',
            'orgnr'              => LavendelHygiene_Core::META_ORGNR,
            'company_sector'     => LavendelHygiene_Core::META_SECTOR,
            'phone'              => 'billing_phone',

            'contact_first_name' => 'first_name',
            'contact_last_name'  => 'last_name',

            'billing_address_1'  => 'billing_address_1',
            'billing_postcode'   => 'billing_postcode',
            'billing_city'       => 'billing_city',
            'billing_country'    => 'billing_country',

            'shipping_address_1' => 'shipping_address_1',
            'shipping_postcode'  => 'shipping_postcode',
            'shipping_city'      => 'shipping_city',
            'shipping_country'   => 'shipping_country',
        ];
        foreach ( $map as $posted => $meta_key ) {
            if ( isset( $_POST[ $posted ] ) ) {
                update_user_meta( $customer_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $posted ] ) ) );
            }
        }
        if ( isset( $_POST['company_name'] ) ) { // also set shipping company to company name
            update_user_meta($customer_id, 'shipping_company', sanitize_text_field( wp_unslash( $_POST['company_name'] ) ));
        }
        if ( isset( $_POST['phone'] ) ) { // also set shipping phone to phone
            update_user_meta($customer_id, 'shipping_phone', sanitize_text_field( wp_unslash( $_POST['phone'] ) ));
        }
        if ( isset( $_POST['contact_first_name'] ) ) {
            $first = sanitize_text_field( wp_unslash( $_POST['contact_first_name'] ) );
            update_user_meta( $customer_id, 'billing_first_name', $first );
            update_user_meta( $customer_id, 'shipping_first_name', $first );
        }
        if ( isset( $_POST['contact_last_name'] ) ) {
            $last = sanitize_text_field( wp_unslash( $_POST['contact_last_name'] ) );
            update_user_meta( $customer_id, 'billing_last_name', $last );
            update_user_meta( $customer_id, 'shipping_last_name', $last );
        }

        // EHF (checkbox)
        $use_ehf = isset( $_POST['use_ehf'] ) ? 'yes' : 'no';
        update_user_meta( $customer_id, LavendelHygiene_Core::META_USE_EHF, $use_ehf );

        // Set status to pending
        update_user_meta( $customer_id, LavendelHygiene_Core::META_STATUS, 'pending' );
    }

    public function set_pending_role( $user_id ) {
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) return;

        $roles               = (array) $user->roles;
        $is_woo_registration = isset( $_POST['woocommerce-register-nonce'] );

        if ( in_array( 'administrator', $roles, true ) ) return;

        if ( $is_woo_registration || in_array( 'customer', $roles, true ) ) {
            $user->set_role( LavendelHygiene_Core::PENDING_ROLE );
            update_user_meta( $user_id, LavendelHygiene_Core::META_STATUS, 'pending' );
        }
    }
}

class LavendelHygiene_Gating {
    public function __construct() {
        /* UX notices */
        add_action( 'woocommerce_account_content',                [ $this, 'maybe_show_pending_notice' ] );
        add_action( 'woocommerce_single_product_summary',         [ $this, 'maybe_product_page_notice' ], 7 );

        /* gating: prices, purchasability, cart/checkout access */
        add_filter( 'woocommerce_get_price_html',                 [ $this, 'filter_price_html' ], 9, 2 );
        add_filter( 'woocommerce_variable_price_html',            [ $this, 'filter_price_html' ], 9, 2 );
        add_filter( 'woocommerce_is_purchasable',                 [ $this, 'filter_is_purchasable' ], 10, 2 );
        add_filter( 'woocommerce_variation_is_purchasable',       [ $this, 'filter_variation_is_purchasable' ], 10, 2 );
        add_filter( 'woocommerce_available_variation',            [ $this, 'filter_available_variation_price_html' ], 10, 3 );
        add_action( 'template_redirect',                          [ $this, 'enforce_cart_checkout_gate' ], 20 );
    }

    /* ---------------- Pricing & purchasing ---------------- */

    public function filter_price_html( $price_html, $product ) {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
            return $price_html;
        }
        if ( LavendelHygiene_Core::user_is_restricted() ) {
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
        return $data;
    }

    public function filter_is_purchasable( $purchasable, $product ) {
        return LavendelHygiene_Core::user_is_restricted() ? false : $purchasable;
    }

    public function filter_variation_is_purchasable( $purchasable, $variation ) {
        return LavendelHygiene_Core::user_is_restricted() ? false : $purchasable;
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
            echo '<div class="woocommerce-info">' . esc_html__( 'Logg inn for å se pris.', 'lavendelhygiene' ) . '</div>';
            return;
        }
        if ( LavendelHygiene_Core::user_is_pending( get_current_user_id() ) ) {
            echo '<div class="woocommerce-info">' . esc_html__( 'Kontoen din avventer godkjenning.', 'lavendelhygiene' ) . '</div>';
        }
    }
}

class LavendelHygiene_Notifications {
    public function __construct() {
        add_action( 'woocommerce_created_customer', [ $this, 'email_on_registration' ], 25, 3 );
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
        $body_admin    = sprintf(
            "Ny registrering venter godkjenning.\n\nBruker: %s\nE-post: %s\nSelskap: %s\nOrg.nr: %s\n",
            $user->user_login,
            $user->user_email,
            get_user_meta( $user_id, 'billing_company', true ),
            get_user_meta( $user_id, LavendelHygiene_Core::META_ORGNR, true )
        );
        wp_mail( $admin_to, $subject_admin, $body_admin );
    }
}

class LavendelHygiene_AccountPhoneField {
    public function __construct() {
        add_action( 'woocommerce_edit_account_form', [ $this, 'render_field' ] );
        add_action( 'woocommerce_save_account_details', [ $this, 'save_field' ] );
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

/* ---------- Bootstrap ---------- */
new LavendelHygiene_Core();
