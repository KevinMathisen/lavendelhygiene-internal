<?php
/**
 * Plugin Name: Lavendel Hygiene Core
 * Description: B2B registration + approval + gating for WooCommerce (pending/approved flow, org.nr capture, emails, checkout restrictions).
 * Author: Kevin Nikolai Mathisen
 * Version: 2.0.0
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define('LH_CORE_PLUGIN_DIR',       plugin_dir_path(__FILE__));

require_once LH_CORE_PLUGIN_DIR . '/includes/ttx-linking-service.php';
require_once LH_CORE_PLUGIN_DIR . '/includes/frontend/registration.php';
require_once LH_CORE_PLUGIN_DIR . '/includes/admin/admin-applications.php';
require_once LH_CORE_PLUGIN_DIR . '/includes/admin/product-editor.php';
require_once LH_CORE_PLUGIN_DIR . '/includes/admin/profile-fields.php';
require_once LH_CORE_PLUGIN_DIR . '/includes/frontend/gating.php';
require_once LH_CORE_PLUGIN_DIR . '/includes/notifications.php';
require_once LH_CORE_PLUGIN_DIR . '/includes/frontend/tweak-user-settings.php';
require_once LH_CORE_PLUGIN_DIR . '/includes/frontend/product-docs-tabs.php';


final class LavendelHygiene_Core {
    const VERSION              = '2.0.0';

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
        new LavendelHygiene_ProductMetaEditor();
        new LavendelHygiene_ProfileFields();
        new LavendelHygiene_Gating();
        new LavendelHygiene_Notifications();
        new LavendelHygiene_TweakUserSettings();
        new LavendelHygiene_ProductDocsTab();

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


/* ---------- Bootstrap ---------- */
new LavendelHygiene_Core();


/* Notes
    <p><span class="lh-tip" data-tip="Produkt blir sendt fra vårt lager i Norge.">Lagervare</span></p>
*/