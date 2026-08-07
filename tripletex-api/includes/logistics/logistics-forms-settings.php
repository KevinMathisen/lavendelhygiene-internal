<?php
if (!defined('ABSPATH')) exit;

use PhpOffice\PhpSpreadsheet\IOFactory;

final class LH_Ttx_Logistics_Settings {
    private const PAGE_SLUG = 'lh-ttx-logistics';
    private const CAPABILITY = 'manage_woocommerce';

    public function init(): void {
        add_action('admin_menu', [$this, 'register_page'], 30);
        add_action('admin_post_lh_ttx_save_logistics_settings', [$this, 'save_settings']);
        add_action('admin_post_lh_ttx_upload_adr_mapping', [$this, 'upload_mapping']);
        add_action('admin_post_lh_ttx_clear_adr_mapping', [$this, 'clear_mapping']);
    }

    public function register_page(): void {
        add_submenu_page(
            'woocommerce',
            __('Logistikkdokumenter', 'lh-ttx'),
            __('Logistikkdokumenter', 'lh-ttx'),
            self::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function save_settings(): void {
        $this->assert_access('lh_ttx_save_logistics_settings');

        $raw = isset($_POST['recipients']) ? wp_unslash((string)$_POST['recipients']) : '';
        $recipients = $this->sanitize_recipients($raw);
        update_option(LH_TTX_OPT_LOGISTICS_RECIPIENTS, implode(', ', $recipients), 'no');

        LH_Ttx_Logger::info('Logistics settings saved', ['recipient_count' => count($recipients)]);
        $this->redirect(['settings-updated' => '1']);
    }

    public function upload_mapping(): void {
        $this->assert_access('lh_ttx_upload_adr_mapping');

        try {
            if (empty($_FILES['adr_mapping']) || !is_array($_FILES['adr_mapping'])) {
                throw new RuntimeException(__('Ingen fil ble lastet opp.', 'lh-ttx'));
            }

            $file = $_FILES['adr_mapping'];
            $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException(sprintf(__('Opplastingen feilet med kode %d.', 'lh-ttx'), $error));
            }

            $name = sanitize_file_name((string)($file['name'] ?? 'mapping.xlsx'));
            $tmp = (string)($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new RuntimeException(__('Ugyldig opplastet fil.', 'lh-ttx'));
            }

            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException(__('ADR-mappingen må være en .xlsx-fil.', 'lh-ttx'));
            }

            $rows = $this->read_rows($tmp);
            $result = LH_Ttx_ADR_Mapping::replace_from_rows($rows, $name);

            $this->redirect([
                'mapping-updated' => '1',
                'imported' => (string)$result['imported'],
                'skipped' => (string)$result['skipped'],
                'duplicates' => (string)$result['duplicates'],
            ]);
        } catch (Throwable $e) {
            LH_Ttx_Logger::error('ADR mapping upload failed', ['message' => $e->getMessage()]);
            $this->redirect(['mapping-error' => rawurlencode($e->getMessage())]);
        }
    }

    public function clear_mapping(): void {
        $this->assert_access('lh_ttx_clear_adr_mapping');
        LH_Ttx_ADR_Mapping::clear();
        $this->redirect(['mapping-cleared' => '1']);
    }

    public function render_page(): void {
        if (!current_user_can(self::CAPABILITY)) wp_die(__('Ingen tilgang.', 'lh-ttx'));

        $recipients = (string)get_option(LH_TTX_OPT_LOGISTICS_RECIPIENTS, '');
        $meta = LH_Ttx_ADR_Mapping::metadata();
        $mapping = LH_Ttx_ADR_Mapping::get_all();
        $preview = array_slice($mapping, 0, 100, true);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Logistikkdokumenter', 'lh-ttx'); ?></h1>
            <?php $this->render_notices(); ?>

            <h2><?php esc_html_e('E-post', 'lh-ttx'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lh_ttx_save_logistics_settings">
                <?php wp_nonce_field('lh_ttx_save_logistics_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="lh-ttx-recipients"><?php esc_html_e('Mottakere', 'lh-ttx'); ?></label></th>
                        <td>
                            <input id="lh-ttx-recipients" name="recipients" type="text" class="regular-text" value="<?php echo esc_attr($recipients); ?>">
                            <p class="description"><?php esc_html_e('Én eller flere adresser, separert med komma.', 'lh-ttx'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Lagre mottakere', 'lh-ttx')); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('ADR-mapping', 'lh-ttx'); ?></h2>
            <p>
                <strong><?php esc_html_e('Fil:', 'lh-ttx'); ?></strong>
                <?php echo esc_html($meta['filename'] !== '' ? $meta['filename'] : '—'); ?><br>
                <strong><?php esc_html_e('Lastet opp:', 'lh-ttx'); ?></strong>
                <?php echo esc_html($meta['uploaded_at'] !== '' ? $meta['uploaded_at'] . ' UTC' : '—'); ?><br>
                <strong><?php esc_html_e('Produkter:', 'lh-ttx'); ?></strong>
                <?php echo esc_html((string)$meta['count']); ?>
            </p>

            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="lh_ttx_upload_adr_mapping">
                <?php wp_nonce_field('lh_ttx_upload_adr_mapping'); ?>
                <input type="file" name="adr_mapping" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                <?php submit_button(__('Last opp og erstatt mapping', 'lh-ttx'), 'primary', 'submit', false); ?>
            </form>

            <?php if ($mapping !== []): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px">
                    <input type="hidden" name="action" value="lh_ttx_clear_adr_mapping">
                    <?php wp_nonce_field('lh_ttx_clear_adr_mapping'); ?>
                    <?php submit_button(__('Slett mapping', 'lh-ttx'), 'delete', 'submit', false, ['onclick' => "return confirm('Slette ADR-mappingen?');"]); ?>
                </form>

                <h3><?php esc_html_e('Forhåndsvisning', 'lh-ttx'); ?></h3>
                <p class="description"><?php esc_html_e('Viser maksimalt de første 100 radene.', 'lh-ttx'); ?></p>
                <table class="widefat striped">
                    <thead><tr>
                        <th>SKU</th><th>Produktnavn</th><th>HS Code</th><th>UN-nr.</th><th>PSN</th><th>Klasse</th><th>Emb. gr.</th><th>Tunnelkode</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview as $sku => $row): ?>
                        <tr>
                            <td><?php echo esc_html($sku); ?></td>
                            <td><?php echo esc_html((string)($row['product_name'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($row['hs_code'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($row['un_number'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($row['psn'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($row['class_label'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($row['packing_group'] ?? '')); ?></td>
                            <td><?php echo esc_html((string)($row['tunnel_code'] ?? '')); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function read_rows(string $path): array {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $raw = $sheet->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();

        if (count($raw) < 2) throw new RuntimeException(__('Mappingfilen inneholder ingen datarader.', 'lh-ttx'));
        $headers = array_map(static fn($v) => trim((string)$v), array_shift($raw));
        if (!in_array('SKU / Produktnummer (Hos Lavendel)', $headers, true)) {
            throw new RuntimeException(__('Mappingfilen mangler kolonnen «SKU / Produktnummer (Hos Lavendel)».', 'lh-ttx'));
        }

        $rows = [];
        foreach ($raw as $values) {
            $row = [];
            foreach ($headers as $index => $header) {
                if ($header !== '') $row[$header] = $values[$index] ?? null;
            }
            if (array_filter($row, static fn($v) => $v !== null && trim((string)$v) !== '') !== []) $rows[] = $row;
        }
        return $rows;
    }

    private function sanitize_recipients(string $raw): array {
        $values = preg_split('/[,;\r\n]+/', $raw) ?: [];
        $valid = [];
        foreach ($values as $value) {
            $email = sanitize_email(trim($value));
            if ($email !== '' && is_email($email)) $valid[] = $email;
        }
        return array_values(array_unique($valid));
    }

    private function assert_access(string $nonce_action): void {
        if (!current_user_can(self::CAPABILITY)) wp_die(__('Ingen tilgang.', 'lh-ttx'));
        check_admin_referer($nonce_action);
    }

    private function redirect(array $args): void {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::PAGE_SLUG)));
        exit;
    }

    private function render_notices(): void {
        if (isset($_GET['settings-updated'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Mottakere lagret.', 'lh-ttx') . '</p></div>';
        if (isset($_GET['mapping-updated'])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(sprintf(__('Mapping importert: %d produkter, %d hoppet over, %d duplikater.', 'lh-ttx'), absint($_GET['imported'] ?? 0), absint($_GET['skipped'] ?? 0), absint($_GET['duplicates'] ?? 0))));
        }
        if (isset($_GET['mapping-cleared'])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('ADR-mappingen er slettet.', 'lh-ttx') . '</p></div>';
        if (isset($_GET['mapping-error'])) echo '<div class="notice notice-error"><p>' . esc_html(rawurldecode((string)wp_unslash($_GET['mapping-error']))) . '</p></div>';
    }
}