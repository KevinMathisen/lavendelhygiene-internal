<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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

            /* Notify user through email that they were approved (HTML) */
            $site_url  = home_url( '/' );
            $login_url = wc_get_page_permalink( 'myaccount' );

            $subject = __( '[Lavendel Hygiene AS] Kontoen din er godkjent', 'lavendelhygiene' );

            $body = sprintf(
                '<p>%s</p>
                <p>%s</p>
                <p>
                    <a href="%s">%s</a><br>
                    <a href="%s">%s</a>
                </p>
                <p>%s</p>',
                esc_html__( 'Hei!', 'lavendelhygiene' ),
                esc_html__( 'Kontoen din hos Lavendel Hygiene er godkjent. Du kan nå se priser og bestille produkter direkte fra nettbutikken.', 'lavendelhygiene' ),
                esc_url( $login_url ),
                esc_html__( 'Gå til Min konto (innlogging)', 'lavendelhygiene' ),
                esc_url( $site_url ),
                esc_html( wp_parse_url( $site_url, PHP_URL_HOST ) ?: $site_url ),
                esc_html__( 'Hilsen oss i Lavendel Hygiene', 'lavendelhygiene' )
            );

            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

            wp_mail( $user->user_email, $subject, $body, $headers );
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