<?php
/**
 * Plugin Name: LavendelHygiene Tripletex
 * Description: Tripletex integration for customers, products, and orders
 * Author: Kevin Nikolai Mathisen
 * Version: 0.3.0
 * Requires Plugins: woocommerce
 * 
 * TODO: test, use SKU as tripletex ID, test webhook, add user text/extra info to tripletex order
 *          and maybe we can use webhook to update status of woocommerce order to shipped when this is set in tripletex
 */

if (!defined('ABSPATH')) exit;


define('LH_TTX_VERSION',          '0.3.0');
define('LH_TTX_PLUGIN_FILE',      __FILE__);
define('LH_TTX_PLUGIN_BASENAME',  plugin_basename(__FILE__));
define('LH_TTX_PLUGIN_DIR',       plugin_dir_path(__FILE__));
define('LH_TTX_PLUGIN_URL',       plugin_dir_url(__FILE__));

/** Options (autoload = no) */
define('LH_TTX_OPT_BASE_URL',        'lh_ttx_base_url');
define('LH_TTX_OPT_CONSUMER_TOKEN',  'lh_ttx_consumer_token');
define('LH_TTX_OPT_EMPLOYEE_TOKEN',  'lh_ttx_employee_token');
define('LH_TTX_OPT_COMPANY_ID',      'lh_ttx_company_id');
define('LH_TTX_OPT_WEBHOOK_SECRET',  'lh_ttx_webhook_secret');
define('LH_TTX_OPT_SESSION_TOKEN',   'lh_ttx_session_token');
define('LH_TTX_OPT_SESSION_EXPIRES', 'lh_ttx_session_expires');

/** key we use to link customers to tripletex */
define('LH_TTX_META_TRIPLETEX_ID',      'tripletex_customer_id');

/**
 * Activation: ensure options exist
 */
register_activation_hook(__FILE__, function(){
    add_option(LH_TTX_OPT_BASE_URL,        'https://tripletex.no/v2', '', 'no');
    add_option(LH_TTX_OPT_CONSUMER_TOKEN,  '', '', 'no');
    add_option(LH_TTX_OPT_EMPLOYEE_TOKEN,  '', '', 'no');
    add_option(LH_TTX_OPT_COMPANY_ID,      0,   '', 'no');
    add_option(LH_TTX_OPT_WEBHOOK_SECRET,  '',  '', 'no');
    add_option(LH_TTX_OPT_SESSION_TOKEN,   '',  '', 'no');
    add_option(LH_TTX_OPT_SESSION_EXPIRES, '2000-01-01',   '', 'no');
});

/**
 * Simple logger wrapper
 */
final class LH_Ttx_Logger {
    private static ?WC_Logger $logger = null;

    protected static function logger(): WC_Logger {
        if (!self::$logger) self::$logger = new WC_Logger();
        return self::$logger;
    }

    public static function info(string $message, array $context = []): void {
        self::logger()->info(self::format($message, $context), ['source' => 'lavendelhygiene-tripletex']);
    }

    public static function error(string $message, array $context = []): void {
        self::logger()->error(self::format($message, $context), ['source' => 'lavendelhygiene-tripletex']);
    }

    private static function format(string $message, array $context): string {
        return $message . (!empty($context) ? ' | ' . wp_json_encode($context) : '');
    }
}

/**
 * Token helpers
 * storage only, we do session creation in API file
 */
function lh_ttx_get_option(string $key, $default = '') {
    $val = get_option($key, $default);
    return $val === '' ? $default : $val;
}
function lh_ttx_set_option(string $key, $value): void {
    update_option($key, $value, 'no');
}
function lh_ttx_get_consumer_token(): string { return (string) lh_ttx_get_option(LH_TTX_OPT_CONSUMER_TOKEN, ''); }
function lh_ttx_set_consumer_token(string $val): void { lh_ttx_set_option(LH_TTX_OPT_CONSUMER_TOKEN, $val); }
function lh_ttx_get_employee_token(): string { return (string) lh_ttx_get_option(LH_TTX_OPT_EMPLOYEE_TOKEN, ''); }
function lh_ttx_set_employee_token(string $val): void { lh_ttx_set_option(LH_TTX_OPT_EMPLOYEE_TOKEN, $val); }
function lh_ttx_get_company_id(): int { return (int) lh_ttx_get_option(LH_TTX_OPT_COMPANY_ID, 0); }
function lh_ttx_set_company_id(int $id): void { lh_ttx_set_option(LH_TTX_OPT_COMPANY_ID, $id); }
function lh_ttx_get_base_url(): string { return (string) lh_ttx_get_option(LH_TTX_OPT_BASE_URL, 'https://tripletex.no/v2'); }
function lh_ttx_set_base_url(string $url): void { lh_ttx_set_option(LH_TTX_OPT_BASE_URL, $url); }
function lh_ttx_get_webhook_secret(): string { return (string) lh_ttx_get_option(LH_TTX_OPT_WEBHOOK_SECRET, ''); }
function lh_ttx_set_webhook_secret(string $val): void { lh_ttx_set_option(LH_TTX_OPT_WEBHOOK_SECRET, $val); }

/** Session token cache accessors (API code should call these) */
function lh_ttx_get_cached_session(): array {
    return [
        'token'   => (string) lh_ttx_get_option(LH_TTX_OPT_SESSION_TOKEN, ''),
        'expires' => (int)    lh_ttx_get_option(LH_TTX_OPT_SESSION_EXPIRES, '2000-01-01'),
    ];
}
function lh_ttx_set_cached_session(string $token, int $expiresStr): void {
    lh_ttx_set_option(LH_TTX_OPT_SESSION_TOKEN, $token);
    lh_ttx_set_option(LH_TTX_OPT_SESSION_EXPIRES, $expiresStr);
}
function lh_ttx_clear_cached_session(): void {
    lh_ttx_set_option(LH_TTX_OPT_SESSION_TOKEN, '');
    lh_ttx_set_option(LH_TTX_OPT_SESSION_EXPIRES, '2000-01-01');
}

/**
 * Bootstrap: load includes and wire services + hooks
 */
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        LH_Ttx_Logger::error('WooCommerce not active; Tripletex integration disabled.');
        return;
    }

    require_once LH_TTX_PLUGIN_DIR . 'includes/api.php';
    require_once LH_TTX_PLUGIN_DIR . 'includes/services.php';
    require_once LH_TTX_PLUGIN_DIR . 'includes/settings-page.php';
    require_once LH_TTX_PLUGIN_DIR . 'includes/webhooks.php';

    $services = LH_Ttx_Service_Registry::instance();

    // REST/webhooks should always register
    (new LH_Ttx_Webhooks())->init();

    // Admin UI only
    if (is_admin()) {
        (new LH_Ttx_Settings_Page())->init();
    }

    // "Create in Tripletex" action (admin-post)
    add_action('admin_post_lavendelhygiene_create_tripletex', function () use ($services) {
        if (!current_user_can('manage_woocommerce') && !current_user_can('promote_users') && !current_user_can('list_users')) {
            wp_die(__('No permission.', 'lh-ttx'));
        }
        $user_id = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
        check_admin_referer('lavendelhygiene_create_tripletex_' . $user_id);

        $res = $services->customers()->create_and_link($user_id);
        if (is_wp_error($res)) {
            LH_Ttx_Logger::error('Tripletex create failed', ['user_id' => $user_id, 'error' => $res->get_error_message()]);
            wp_die($res->get_error_message());
        }

        wp_safe_redirect(admin_url('users.php?page=lavendelhygiene-applications&tripletex_created=1'));
        exit;
    });

    // ------- WooCommerce hooks ------- 
    add_action('woocommerce_checkout_order_processed', function (int $order_id, array $posted_data, WC_Order $order) use ($services) {
        $orders = $services->orders();
        $result = $orders->create_remote_order($order_id);

        if (is_wp_error($result)) {
            LH_Ttx_Logger::error('Tripletex order creation failed', [
                'order_id' => $order_id,
                'error'    => $result->get_error_message(),
            ]);
            $order->add_order_note(__('Tripletex: Failed to create remote order. Please contact customer and try again.', 'lh-ttx'));
            wc_add_notice(__('Det skjedde en feil i prosesseringen av orderen din. Vennligst kontakt oss.', 'lh-ttx'), 'error');
        }
    }, 20, 3);

    add_action('woocommerce_customer_save_address', function (int $user_id) use ($services) {
        $res = $services->customers()->sync_user($user_id);
        if (is_wp_error($res)) {
            LH_Ttx_Logger::error('Tripletex customer sync failed', [
                'user_id' => $user_id,
                'error'   => $res->get_error_message(),
            ]);
        }
    }, 20, 1);

    add_action('profile_update', function (int $user_id) use ($services) {
        $res = $services->customers()->sync_user($user_id);
        if (is_wp_error($res)) {
            LH_Ttx_Logger::error('Tripletex customer sync (admin profile) failed', [
                'user_id' => $user_id,
                'error'   => $res->get_error_message(),
            ]);
        }
    }, 20, 1);

    /* ---- Misc ---- */
    // Add "settings" link to plugin row on plugin page
    add_filter('plugin_action_links_' . LH_TTX_PLUGIN_BASENAME, function ($links) {
        $url = admin_url('admin.php?page=lh-ttx-settings');
        array_unshift($links, '<a href="'.esc_url($url).'">'.esc_html__('Settings', 'lh-ttx').'</a>');
        return $links;
    });
});

/**
 * Service registry
 */
final class LH_Ttx_Service_Registry {
    private static ?self $instance = null;

    private $customers = null;
    private $orders    = null;
    private $products  = null;

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function customers() {
        return $this->customers ??= new LH_Ttx_Customers_Service();
    }
    public function orders() {
        return $this->orders ??= new LH_Ttx_Orders_Service();
    }
    public function products() {
        return $this->products ??= new LH_Ttx_Products_Service();
    }
}