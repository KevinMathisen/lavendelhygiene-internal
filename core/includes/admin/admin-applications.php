<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Admin: Approve/deny/create tripletex user page ---------- */

class LavendelHygiene_AdminApplications {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_post_lavendelhygiene_set_tripletex_id', [ $this, 'handle_set_tripletex_id' ] ); // non-AJAX fallback

        add_action( 'admin_post_lavendelhygiene_approve', [ $this, 'handle_approve' ] );
        add_action( 'admin_post_lavendelhygiene_deny', [ $this, 'handle_deny' ] );
        add_action( 'admin_post_lavendelhygiene_restore_pending', [ $this, 'handle_restore_pending' ] );
        add_action( 'admin_post_lavendelhygiene_delete_denied', [ $this, 'handle_delete_denied' ] );

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

        $denied_q = new WP_User_Query( [
            'number'     => 50,
            'fields'     => [ 'ID', 'user_login', 'user_email' ],
            'orderby'    => 'registered',
            'order'      => 'DESC',
            'meta_key'   => LavendelHygiene_Core::META_STATUS,
            'meta_value' => 'denied',
        ] );
        $denied_users = $denied_q->get_results();

        $svc = new LavendelHygiene_TripletexLinkingService();
        $notify_email = get_option( 'lavendelhygiene_notify_email', get_option( 'admin_email' ) );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pending Users', 'lavendelhygiene' ); ?></h1>

            <?php if ( isset($_GET['notify_email_updated']) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Notification email updated.', 'lavendelhygiene'); ?></p></div>
            <?php endif; ?>
            <?php if ( isset($_GET['tripletex_updated']) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Tripletex ID saved. Synchronization with tripletex started.', 'lavendelhygiene'); ?></p></div>
            <?php endif; ?>
            <?php if ( isset($_GET['tripletex_created']) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Customer created in Tripletex.', 'lavendelhygiene'); ?></p></div>
            <?php endif; ?>
            <?php if ( isset($_GET['restored_pending']) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Customer restored to pending.', 'lavendelhygiene'); ?></p></div>
            <?php endif; ?>
            <?php if ( isset($_GET['denied_deleted']) ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e('Denied customer deleted.', 'lavendelhygiene'); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($_GET['tripletex_error'])) : ?>
                <div class="notice notice-error">
                    <p> <?php echo esc_html(sanitize_text_field(wp_unslash($_GET['tripletex_error']))); ?> </p>
                </div>
            <?php endif; ?>
            <?php if (!empty($_GET['approval_error'])) : ?>
                <div class="notice notice-error">
                    <p> <?php echo esc_html(sanitize_text_field(wp_unslash($_GET['approval_error']))); ?> </p>
                </div>
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
                        <th><?php esc_html_e( 'Name', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Company', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Org.nr', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Avdeling', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Existing company', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Tripletex ID', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $users as $u ) :
                        $company = get_user_meta( $u->ID, 'billing_company', true );
                        $orgnr   = get_user_meta( $u->ID, LavendelHygiene_Core::META_ORGNR, true );

                        $is_avdeling = get_user_meta( $u->ID, LavendelHygiene_Core::META_IS_AVDELING, true ) === '1';
                        $avdeling_name = (string) get_user_meta( $u->ID, LavendelHygiene_Core::META_AVDELING_NAME, true );

                        $first_name = (string) get_user_meta( $u->ID, 'first_name', true );
                        $last_name  = (string) get_user_meta( $u->ID, 'last_name', true );
                        $name = trim( $first_name . ' ' . $last_name );

                        $link_context = $this->get_company_link_context( (int) $u->ID, $svc );
                        $saved_ttx_id = (string) $link_context['saved_ttx_id'];
                        $suggested_ttx_id = (string) $link_context['suggested_ttx_id'];
                        $has_company_conflict = (bool) $link_context['has_conflict'];
                        $can_approve = (bool) $link_context['can_approve'];
                        $can_create_tripletex = (bool) $link_context['can_create_tripletex'];

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
                            <td><?php echo esc_html( $name ); ?></td>
                            <td><?php echo esc_html( $u->user_email ); ?></td>
                            <td><?php echo esc_html( $company ); ?></td>
                            <td><?php echo esc_html( $orgnr ); ?></td>
                            <td>
                                <?php if ( $is_avdeling ) : ?>
                                    <strong><?php esc_html_e( 'Ja', 'lavendelhygiene' ); ?></strong>
                                    <?php if ( $avdeling_name !== '' ) : ?>
                                        <br><small><?php echo esc_html( $avdeling_name ); ?></small>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <?php esc_html_e( 'Nei', 'lavendelhygiene' ); ?>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($has_company_conflict) : ?>

                                    <strong style="color:#b32d2e;">
                                        <?php esc_html_e('Tripletex conflict', 'lavendelhygiene'); ?>
                                    </strong>

                                    <?php if (!empty($link_context['conflicting_ttx_ids'])) : ?>
                                        <br>
                                        <small>
                                            <?php
                                            echo esc_html(
                                                sprintf(
                                                    __('IDs found: %s', 'lavendelhygiene'),
                                                    implode(', ', $link_context['conflicting_ttx_ids'])
                                                )
                                            );
                                            ?>
                                        </small>
                                    <?php endif; ?>

                                <?php elseif ($link_context['has_other_users']) : ?>

                                    <strong> <?php esc_html_e('Yes','lavendelhygiene'); ?> </strong>

                                    <?php foreach ($link_context['other_users'] as $other_user) : ?>
                                        <br>
                                        <small>
                                            <?php
                                            $other_label = $other_user['name'] !== '' ? $other_user['name'] : $other_user['email'];
                                            echo esc_html($other_label);
                                            ?>
                                        </small>
                                    <?php endforeach; ?>

                                <?php else : ?>
                                    <?php esc_html_e('No', 'lavendelhygiene'); ?>
                                <?php endif; ?>
                            </td>


                            <td>
                                <?php if ($has_company_conflict) : ?>
                                    <div style="color:#b32d2e;font-weight:600;">
                                        <?php esc_html_e('Resolve the company Tripletex-ID conflict before linking this user.','lavendelhygiene'); ?>
                                    </div>
                                <?php elseif ($saved_ttx_id !== '') : ?>
                                    <!-- Already saved: locked on this page -->
                                    <input
                                        type="text"
                                        value="<?php echo esc_attr($saved_ttx_id); ?>"
                                        readonly
                                        aria-readonly="true"
                                        style="width:140px;background:#f0f0f1;"
                                    >
                                    <p class="description" style="margin-top:5px;">
                                        <?php esc_html_e(
                                            'Saved and locked. Edit the user profile to change Tripletex ID.',
                                            'lavendelhygiene'
                                        ); ?>
                                    </p>

                                <?php elseif ($suggested_ttx_id !== '') : ?>
                                    <!-- Suggested from another local user: may be saved, but not modified -->
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                        class="lavendelhygiene-ttx-form">

                                        <input type="hidden" name="action" value="lavendelhygiene_set_tripletex_id">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($set_nonce); ?>">

                                        <input type="text" name="tripletex_customer_id" value="<?php echo esc_attr($suggested_ttx_id); ?>"
                                            readonly aria-readonly="true" style="width:140px;background:#fff8e5;">

                                        <button type="submit" class="button"> <?php esc_html_e('Save ID', 'lavendelhygiene'); ?> </button>
                                    </form>

                                    <p class="description" style="margin-top:5px;color:#8a6116;">
                                        <?php esc_html_e('This ID was found on another user with the same organisation number. Double-check the ID before saving.', 'lavendelhygiene'); ?>
                                    </p>

                                <?php else : ?>
                                    <!-- No existing ID found: sales may enter one manually -->
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="lavendelhygiene-ttx-form">

                                        <input type="hidden" name="action" value="lavendelhygiene_set_tripletex_id">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $u->ID; ?>">
                                        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($set_nonce); ?>">

                                        <input type="text" name="tripletex_customer_id" value="" placeholder="e.g. 123456"
                                            inputmode="numeric" pattern="[0-9]+" required style="width:140px;">

                                        <button type="submit" class="button"> <?php esc_html_e('Save ID', 'lavendelhygiene'); ?> </button>
                                    </form>

                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($can_approve) : ?>
                                    <a href="<?php echo esc_url($approve_url); ?>" class="button button-primary"> <?php esc_html_e('Approve', 'lavendelhygiene'); ?> </a>
                                <?php else : ?>

                                    <button type="button" class="button button-primary" disabled aria-disabled="true"> <?php esc_html_e('Approve', 'lavendelhygiene'); ?> </button>

                                    <p class="description" style="margin:5px 0 0;">
                                        <?php if ($has_company_conflict) : ?>
                                            <?php esc_html_e('Resolve the Tripletex-ID conflict before approval.','lavendelhygiene'); ?>
                                        <?php else : ?>
                                            <?php esc_html_e('Save the Tripletex ID before approval.', 'lavendelhygiene'); ?>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>

                                <a
                                    href="<?php echo esc_url( $deny_url ); ?>"
                                    class="button"
                                    onclick="return confirm('<?php echo esc_js( __( 'Deny this customer? They will be moved out of the pending queue.', 'lavendelhygiene' ) ); ?>');"
                                > <?php esc_html_e('Deny','lavendelhygiene'); ?> </a>

                                <a href="<?php echo esc_url(get_edit_user_link($u->ID)); ?>" class="button"> <?php esc_html_e('View', 'lavendelhygiene'); ?> </a>

                                <?php if ($can_create_tripletex) : ?>
                                    <a href="<?php echo esc_url($ttx_create_url); ?>" class="button button-secondary">
                                        <?php esc_html_e('Create in Tripletex', 'lavendelhygiene'); ?> 
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr style="margin:24px 0;">

            <h2><?php esc_html_e( 'Denied customers', 'lavendelhygiene' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Restore a denied customer back to the pending queue, or delete the account if it was denied in error and should not be kept.', 'lavendelhygiene' ); ?></p>

            <?php if ( empty( $denied_users ) ) : ?>
                <p><?php esc_html_e( 'No denied customers found.', 'lavendelhygiene' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e( 'User', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'lavendelhygiene' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $denied_users as $denied_user ) :
                        $restore_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=lavendelhygiene_restore_pending&user_id=' . $denied_user->ID ),
                            'lavendelhygiene_restore_pending_' . $denied_user->ID
                        );
                        $delete_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=lavendelhygiene_delete_denied&user_id=' . $denied_user->ID ),
                            'lavendelhygiene_delete_denied_' . $denied_user->ID
                        );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $denied_user->user_login ); ?></td>
                            <td><?php echo esc_html( $denied_user->user_email ); ?></td>
                            <td>
                                <a href="<?php echo esc_url( $restore_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Restore', 'lavendelhygiene' ); ?></a>
                                <a
                                    href="<?php echo esc_url( $delete_url ); ?>"
                                    class="button"
                                    onclick="return confirm('<?php echo esc_js( __( 'Delete this denied customer permanently?', 'lavendelhygiene' ) ); ?>');"
                                ><?php esc_html_e( 'Delete', 'lavendelhygiene' ); ?></a>
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
        if (!$user) wp_die(__('User not found.', 'lavendelhygiene'));

        $svc = new LavendelHygiene_TripletexLinkingService();
        $ttx_id = $svc->get_ttx_id($user_id);

        $redirect_url = admin_url('users.php?page=lavendelhygiene-applications');

        if ($ttx_id === '') {
            wp_safe_redirect(add_query_arg('approval_error', __('The user must have a saved Tripletex ID before approval.','lavendelhygiene'), $redirect_url));
            exit;
        }

        $orgnr = $svc->get_user_orgnr($user_id);
        $company_state = $svc->get_company_state_for_orgnr($orgnr);
        if (!empty($company_state['has_conflict'])) {
            wp_safe_redirect(add_query_arg('approval_error', __('Users with this organisation number are linked to conflicting Tripletex IDs.','lavendelhygiene'), $redirect_url) );
            exit;
        }

        $user->set_role( 'customer' );
        update_user_meta( $user_id, LavendelHygiene_Core::META_STATUS, 'approved' );
        update_user_meta( $user_id, LavendelHygiene_Core::META_APPROVED_BY, get_current_user_id() );
        update_user_meta( $user_id, LavendelHygiene_Core::META_APPROVED_AT, current_time( 'mysql' ) );

        /* Notify user through email that they were approved (HTML) */
        $site_url  = home_url( '/' );
        $login_url = wc_get_page_permalink( 'myaccount' );

        $subject = __( '[Lavendel Hygiene AS] Kontoen din er godkjent', 'lavendelhygiene' );

        $body = sprintf(
            '<p>%s</p><br>
            <p>%s</p><br>
            <p>
                <a href="%s">%s</a><br>
                <a href="%s">%s</a>
            </p><br>
            <p>%s</p>',
            esc_html__( 'Hei!', 'lavendelhygiene' ),
            esc_html__( 'Kontoen din hos Lavendel Hygiene er godkjent. Du kan nå se priser og bestille produkter direkte fra nettbutikken.', 'lavendelhygiene' ),
            esc_url( $login_url ),
            esc_html__( 'Gå til Min konto (innlogging)', 'lavendelhygiene' ),
            esc_url( $site_url ),
            esc_html__( 'Gå til forsiden', 'lavendelhygiene' ),
            esc_html__( 'Hilsen oss i Lavendel Hygiene', 'lavendelhygiene' )
        );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        wp_mail( $user->user_email, $subject, $body, $headers );

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

    public function handle_restore_pending() {
        if ( ! current_user_can( 'promote_users' ) ) {
            wp_die( __( 'No permission.', 'lavendelhygiene' ) );
        }

        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        check_admin_referer( 'lavendelhygiene_restore_pending_' . $user_id );

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            wp_die( __( 'User not found.', 'lavendelhygiene' ) );
        }

        $user->set_role( LavendelHygiene_Core::PENDING_ROLE );
        update_user_meta( $user_id, LavendelHygiene_Core::META_STATUS, 'pending' );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_APPROVED_BY );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_APPROVED_AT );

        wp_safe_redirect( admin_url( 'users.php?page=lavendelhygiene-applications&restored_pending=1' ) );
        exit;
    }

    public function handle_delete_denied() {
        if ( ! current_user_can( 'delete_users' ) ) {
            wp_die( __( 'No permission.', 'lavendelhygiene' ) );
        }

        $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
        check_admin_referer( 'lavendelhygiene_delete_denied_' . $user_id );

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) {
            wp_die( __( 'User not found.', 'lavendelhygiene' ) );
        }

        if ( ! function_exists( 'wp_delete_user' ) ) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        delete_user_meta( $user_id, LavendelHygiene_Core::META_STATUS );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_APPROVED_BY );
        delete_user_meta( $user_id, LavendelHygiene_Core::META_APPROVED_AT );
        wp_delete_user( $user_id );

        wp_safe_redirect( admin_url( 'users.php?page=lavendelhygiene-applications&denied_deleted=1' ) );
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

        $redirect_url = admin_url('users.php?page=lavendelhygiene-applications');

        $svc = new LavendelHygiene_TripletexLinkingService();
        $submitted_id = $svc->sanitize_ttx_id((string) wp_unslash($_POST['tripletex_customer_id'] ?? ''));

        if ($submitted_id === '') {
            wp_safe_redirect( add_query_arg('tripletex_error', __('Tripletex ID cannot be empty.','lavendelhygiene'), $redirect_url));
            exit;
        }

        $current_id = $svc->get_ttx_id($user_id);
        if ($current_id !== '') {
            if ($current_id === $submitted_id) {
                wp_safe_redirect(add_query_arg('tripletex_updated', '1', $redirect_url));
                exit;
            }

            wp_safe_redirect(add_query_arg('tripletex_error', __('Tripletex ID already saved. Edit user profile if ID must be changed.','lavendelhygiene'), $redirect_url));
            exit;
        }

        // Save ttx ID and fire lavendelhygiene_tripletex_linked (which runs sync_user() in tripltex plugin)
        $res = $svc->save_ttx_id_from_input($user_id,$submitted_id,get_current_user_id());

        if (is_wp_error($res)) {
            wp_safe_redirect(add_query_arg('tripletex_error', $res->get_error_message(), $redirect_url));
            exit;
        }

        wp_safe_redirect(add_query_arg('tripletex_updated', '1', $redirect_url));
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

    /**
     * Build the Tripletex/company state used by the pending-users table.
     */
    private function get_company_link_context(int $user_id, LavendelHygiene_TripletexLinkingService $svc): array {
        $orgnr = $svc->get_user_orgnr($user_id);
        $saved_ttx_id = $svc->get_ttx_id($user_id);

        // Include current user when checking a saved ID that conflicts with another user's ID.
        $company_state = $svc->get_company_state_for_orgnr($orgnr);

        // Exclude current user when looking for an ID to suggest from
        $other_company_state = $svc->get_company_state_for_orgnr($orgnr, $user_id);

        $has_conflict = (bool) ($company_state['has_conflict'] ?? false);
        $suggested_ttx_id = '';

        if (!$has_conflict && $saved_ttx_id === '') {
            $suggested_ttx_id = (string) ($other_company_state['existing_ttx_id'] ?? '');
        }

        $other_users = [];
        foreach ((array) ($other_company_state['user_ids'] ?? []) as $other_user_id) {
            $other_user_id = (int) $other_user_id;
            $other_user = get_userdata($other_user_id);
            if (!$other_user) continue;

            $first_name = trim( (string) get_user_meta($other_user_id, 'first_name', true) );
            $last_name = trim( (string) get_user_meta($other_user_id, 'last_name', true) );
            $name = trim($first_name . ' ' . $last_name);

            $other_users[] = [
                'id'    => $other_user_id,
                'name'  => $name,
                'email' => (string) $other_user->user_email,
            ];
        }

        return [
            'orgnr'                 => $orgnr,
            'saved_ttx_id'          => $saved_ttx_id,
            'suggested_ttx_id'      => $suggested_ttx_id,
            'has_conflict'          => $has_conflict,
            'conflicting_ttx_ids'   => array_values(
                (array) ($company_state['ttx_ids'] ?? [])
            ),
            'has_other_users'       => !empty($other_users),
            'other_users'           => $other_users,
            'can_approve'           => (
                $saved_ttx_id !== ''
                && !$has_conflict
            ),
            'can_create_tripletex'  => (
                $saved_ttx_id === ''
                && $suggested_ttx_id === ''
                && !$has_conflict
            ),
        ];
    }
}