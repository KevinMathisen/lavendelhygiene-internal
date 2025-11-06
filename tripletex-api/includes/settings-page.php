<?php
/**
 * Settings Page for LavendelHygiene Tripletex
 *
 * Exposes a simple admin UI to store tokens/base URL/company id and
 * to test/clear the Tripletex session token.
 *
 * Depends on:
 *  - Option keys & helpers defined in the main plugin file:
 *      LH_TTX_OPT_* constants
 *      lh_ttx_get_* / lh_ttx_set_* helper functions
 *  - API helper: ttx_get_session_token()
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('LH_Ttx_Settings_Page')):

final class LH_Ttx_Settings_Page {

    public function init(): void {
        add_action('admin_menu',        [$this, 'register_menu']);
        add_action('admin_init',        [$this, 'handle_post']);
    }

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Tripletex', 'lh-ttx'),
            __('Tripletex', 'lh-ttx'),
            'manage_woocommerce',
            'lh-ttx-settings',
            [$this, 'render']
        );
    }

    /** Handle Save / Test / Clear actions */
    public function handle_post(): void {
        if (!is_admin() || !isset($_GET['page']) || $_GET['page'] !== 'lh-ttx-settings') {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        $action = isset($_POST['lh_ttx_action']) ? sanitize_text_field(wp_unslash($_POST['lh_ttx_action'])) : '';
        if (!$action) return;

        check_admin_referer('lh_ttx_settings');

        switch ($action) {
            case 'save':
                $this->save_settings();
                break;

            case 'test':
                $this->test_connection();
                break;

            case 'clear_session':
                $this->clear_session();
                break;
        }
    }

    private function save_settings(): void {
        $base_url     = isset($_POST['base_url'])     ? esc_url_raw(trim((string) wp_unslash($_POST['base_url']))) : '';
        $consumer     = isset($_POST['consumer'])     ? trim((string) wp_unslash($_POST['consumer'])) : '';
        $employee     = isset($_POST['employee'])     ? trim((string) wp_unslash($_POST['employee'])) : '';
        $company_id   = isset($_POST['company_id'])   ? (int) $_POST['company_id'] : 0;
        $webhook      = isset($_POST['webhook'])      ? sanitize_text_field((string) wp_unslash($_POST['webhook'])) : '';

        // Normalize base URL (no trailing slash)
        if ($base_url !== '') {
            $base_url = rtrim($base_url, "/ \t\n\r\0\x0B");
        }

        // Persist (autoload=no handled by helpers)
        if ($base_url)   { lh_ttx_set_base_url($base_url); }
        lh_ttx_set_company_id($company_id);
        lh_ttx_set_webhook_secret($webhook);

        // Only overwrite tokens if non-empty (so we can leave masked values)
        if ($consumer !== '') { lh_ttx_set_consumer_token($consumer); }
        if ($employee !== '') { lh_ttx_set_employee_token($employee); }

        // Clearing session ensures next API call refreshes with updated creds
        lh_ttx_clear_cached_session();

        add_settings_error('lh-ttx', 'saved', __('Settings saved.', 'lh-ttx'), 'updated');
        // Redirect to avoid resubmission
        wp_safe_redirect(add_query_arg(['page' => 'lh-ttx-settings'], admin_url('admin.php')));
        exit;
    }

    private function test_connection(): void {
        if (!function_exists('ttx_get_session_token')) {
            add_settings_error('lh-ttx', 'missing_api', __('API not loaded; cannot test connection.', 'lh-ttx'), 'error');
            return;
        }

        $token = ttx_get_session_token();
        if (is_wp_error($token)) {
            add_settings_error('lh-ttx', 'test_failed', sprintf(
                /* translators: %s: error message */
                __('Test failed: %s', 'lh-ttx'), $token->get_error_message()
            ), 'error');
        } else {
            $cache = lh_ttx_get_cached_session();
            $exp   = !empty($cache['expires']) ? date_i18n(get_option('date_format').' '.get_option('time_format'), (int) $cache['expires']) : __('unknown', 'lh-ttx');
            add_settings_error('lh-ttx', 'test_ok', sprintf(
                /* translators: %s: date/time string */
                __('Connection OK. Session token acquired. Expires: %s', 'lh-ttx'), esc_html($exp)
            ), 'updated');
        }

        // Redirect to show notice
        wp_safe_redirect(add_query_arg(['page' => 'lh-ttx-settings'], admin_url('admin.php')));
        exit;
    }

    private function clear_session(): void {
        lh_ttx_clear_cached_session();
        add_settings_error('lh-ttx', 'session_cleared', __('Session token cleared.', 'lh-ttx'), 'updated');
        wp_safe_redirect(add_query_arg(['page' => 'lh-ttx-settings'], admin_url('admin.php')));
        exit;
    }

    /** Render settings page */
    public function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'lh-ttx'));
        }

        $base_url   = function_exists('lh_ttx_get_base_url')      ? lh_ttx_get_base_url()      : '';
        $consumer   = function_exists('lh_ttx_get_consumer_token')? lh_ttx_get_consumer_token(): '';
        $employee   = function_exists('lh_ttx_get_employee_token')? lh_ttx_get_employee_token(): '';
        $company_id = function_exists('lh_ttx_get_company_id')    ? lh_ttx_get_company_id()    : 0;
        $webhook    = function_exists('lh_ttx_get_webhook_secret')? lh_ttx_get_webhook_secret(): '';

        $session    = function_exists('lh_ttx_get_cached_session')? lh_ttx_get_cached_session(): ['token'=>'','expires'=>0];

        $consumer_mask = $this->mask_token($consumer);
        $employee_mask = $this->mask_token($employee);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Tripletex', 'lh-ttx'); ?></h1>

            <?php settings_errors('lh-ttx'); ?>

            <form method="post" action="<?php echo esc_url( add_query_arg(['page' => 'lh-ttx-settings'], admin_url('admin.php')) ); ?>">
                <?php wp_nonce_field('lh_ttx_settings'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="lh-ttx-base-url"><?php esc_html_e('Base URL', 'lh-ttx'); ?></label>
                            </th>
                            <td>
                                <input type="url" id="lh-ttx-base-url" name="base_url" class="regular-text code"
                                       placeholder="https://tripletex.no/v2"
                                       value="<?php echo esc_attr($base_url); ?>" />
                                <p class="description"><?php esc_html_e('Tripletex API base. Usually https://tripletex.no/v2', 'lh-ttx'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="lh-ttx-company-id"><?php esc_html_e('Target company ID', 'lh-ttx'); ?></label>
                            </th>
                            <td>
                                <input type="number" id="lh-ttx-company-id" name="company_id" class="small-text" min="0"
                                       value="<?php echo esc_attr((string) $company_id); ?>" />
                                <p class="description"><?php esc_html_e('0 for employee’s company. Otherwise an accountant client company id.', 'lh-ttx'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="lh-ttx-consumer"><?php esc_html_e('Consumer token', 'lh-ttx'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="lh-ttx-consumer" name="consumer" class="regular-text"
                                       value="" placeholder="<?php echo esc_attr($consumer_mask); ?>" autocomplete="off" />
                                <button type="button" class="button button-secondary" data-ttx-toggle="#lh-ttx-consumer"><?php esc_html_e('Show', 'lh-ttx'); ?></button>
                                <p class="description"><?php esc_html_e('Leave empty to keep existing token.', 'lh-ttx'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="lh-ttx-employee"><?php esc_html_e('Employee token', 'lh-ttx'); ?></label>
                            </th>
                            <td>
                                <input type="password" id="lh-ttx-employee" name="employee" class="regular-text"
                                       value="" placeholder="<?php echo esc_attr($employee_mask); ?>" autocomplete="off" />
                                <button type="button" class="button button-secondary" data-ttx-toggle="#lh-ttx-employee"><?php esc_html_e('Show', 'lh-ttx'); ?></button>
                                <p class="description"><?php esc_html_e('Leave empty to keep existing token.', 'lh-ttx'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="lh-ttx-webhook"><?php esc_html_e('Webhook secret (optional)', 'lh-ttx'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="lh-ttx-webhook" name="webhook" class="regular-text"
                                       value="<?php echo esc_attr($webhook); ?>" />
                                <p class="description"><?php esc_html_e('Used to verify incoming webhooks (if enabled).', 'lh-ttx'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('Session status', 'lh-ttx'); ?></th>
                            <td>
                                <?php
                                $has   = !empty($session['token']);
                                $expTs = (int) ($session['expires'] ?? 0);
                                $exp   = $expTs ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $expTs) : __('unknown', 'lh-ttx');
                                ?>
                                <p>
                                    <strong><?php echo $has ? esc_html__('Active', 'lh-ttx') : esc_html__('Not set', 'lh-ttx'); ?></strong>
                                    <?php if ($has): ?>
                                        — <?php printf(esc_html__('Expires: %s', 'lh-ttx'), esc_html($exp)); ?>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <input type="hidden" name="lh_ttx_action" value="save" />
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save changes', 'lh-ttx'); ?></button>
                    <button type="submit" class="button" name="lh_ttx_action" value="test"><?php esc_html_e('Test connection', 'lh-ttx'); ?></button>
                    <button type="submit" class="button button-secondary" name="lh_ttx_action" value="clear_session"><?php esc_html_e('Clear session token', 'lh-ttx'); ?></button>
                </p>
            </form>
        </div>

        <script>
        (function(){
            document.addEventListener('click', function(e){
                const btn = e.target.closest('button[data-ttx-toggle]');
                if (!btn) return;
                e.preventDefault();
                const sel = btn.getAttribute('data-ttx-toggle');
                const input = document.querySelector(sel);
                if (!input) return;
                input.type = input.type === 'password' ? 'text' : 'password';
                btn.textContent = input.type === 'password' ? '<?php echo esc_js(__('Show', 'lh-ttx')); ?>' : '<?php echo esc_js(__('Hide', 'lh-ttx')); ?>';
            });
        })();
        </script>
        <?php
    }

    private function mask_token(string $token): string {
        if ($token === '') return '';
        $len = strlen($token);
        if ($len <= 6) return str_repeat('•', $len);
        return str_repeat('•', $len - 4) . substr($token, -4);
    }
}

endif;
