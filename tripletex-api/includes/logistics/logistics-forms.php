<?php
if (!defined('ABSPATH')) exit;

if (!defined('LH_TTX_OPT_LOGISTICS_RECIPIENTS')) {
    define(
        'LH_TTX_OPT_LOGISTICS_RECIPIENTS',
        'lh_ttx_logistics_recipients'
    );
}

/**
 * Coordinates synchronous generation and delivery of logistics documents.
 *
 * Expected dependencies:
 *
 * - ttx_orders_get(int $order_id)
 * - LH_Ttx_ADR_Mapping
 * - LH_Ttx_Spreadsheet_Generator
 * - LH_Ttx_Logger
 */
final class LH_Ttx_Logistics {

    /**
     * Fetch the Tripletex order, generate its spreadsheets, and email them.
     *
     * All failures are handled internally. The webhook caller can therefore
     * always return HTTP 200.
     */
    public function process_order(int $ttx_order_id): bool {
        $temporary_files = [];

        try {
            if ($ttx_order_id <= 0) {
                throw new InvalidArgumentException( 'Invalid Tripletex order ID.' );
            }

            LH_Ttx_Logger::info('Logistics processing started', [ 'ttx_order_id' => $ttx_order_id ]);

            $order = ttx_orders_get($ttx_order_id);

            if (is_wp_error($order)) {
                throw new RuntimeException(
                    'Could not fetch Tripletex order: '
                    . $order->get_error_message()
                );
            }

            if (!is_array($order) || empty($order)) {
                throw new UnexpectedValueException( 'Tripletex returned an invalid order payload.' );
            }

            $order_lines = isset($order['orderLines'])
                && is_array($order['orderLines']) ? $order['orderLines'] : [];

            $mapping   = LH_Ttx_ADR_Mapping::get_all();
            $adr_lines = LH_Ttx_ADR_Mapping::find_order_lines($order_lines);

            $temporary_files[] = $this->require_generated_file(
                LH_Ttx_Spreadsheet_Generator::create_nvit( $order, $mapping ),
                'NVIT'
            );

            $temporary_files[] = $this->require_generated_file(
                LH_Ttx_Spreadsheet_Generator::create_order_summary($order),
                'order summary'
            );

            if ($adr_lines !== []) {
                $temporary_files[] = $this->require_generated_file(
                    LH_Ttx_Spreadsheet_Generator::create_adr( $order, $adr_lines ),
                    'ADR'
                );
            }

            $sent = $this->send_success_email(
                $order,
                $ttx_order_id,
                $temporary_files,
                $adr_lines !== []
            );

            if (!$sent) {
                throw new RuntimeException( 'wp_mail() could not send the logistics email.' );
            }

            LH_Ttx_Logger::info('Logistics documents sent', [
                'ttx_order_id' => $ttx_order_id,
                'order_number' => $this->order_number( $order, $ttx_order_id ),
                'attachments'  => count($temporary_files),
                'adr_included' => $adr_lines !== [],
            ]);

            return true;

        } catch (Throwable $e) {
            LH_Ttx_Logger::error('Logistics processing failed', [
                'ttx_order_id' => $ttx_order_id,
                'message'      => $e->getMessage(),
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
            ]);

            $this->send_failure_email($ttx_order_id, $e);

            return false;

        } finally {
            $this->delete_temporary_files( $temporary_files, $ttx_order_id );
        }
    }

    /**
     * Validate a result returned by the spreadsheet generator.
     *
     * The generator may return either a file path or WP_Error.
     *
     * @param mixed $result
     */
    private function require_generated_file( $result, string $document_name ): string {
        if (is_wp_error($result)) {
            throw new RuntimeException(sprintf( '%s generation failed: %s', $document_name, $result->get_error_message() ) );
        }

        if (!is_string($result) || $result === ''|| !is_file($result) ) {
            throw new RuntimeException(sprintf( '%s generation did not return a valid file.', $document_name));
        }

        return $result;
    }

    /**
     * @param string[] $attachments
     */
    private function send_success_email( array $order, int $ttx_order_id, array $attachments, bool $adr_included ): bool {
        $recipients   = $this->get_recipients();
        $order_number = $this->order_number($order, $ttx_order_id);
        $customer = trim((string) ($order['customer']['name'] ?? ''));

        if ($recipients === []) {
            LH_Ttx_Logger::error(
                'Logistics email not sent: no valid recipient configured',
                [ 'ttx_order_id' => $ttx_order_id ]
            );
            return false;
        }

        $subject = sprintf( 'NVIT og ADR dokumenter – ordre %s', $order_number);

        $body = '<p>Logistikkdokumentene er generert.</p>';
        $body .= '<p>';
        $body .= '<strong>Ordre:</strong> '
            . esc_html($order_number)
            . '<br>';

        $body .= '<strong>Tripletex-ID:</strong> '
            . esc_html((string) $ttx_order_id);

        if ($customer !== '') {
            $body .= '<br><strong>Kunde:</strong> '
                . esc_html($customer);
        }

        $body .= '<br><strong>ADR:</strong> '
            . ($adr_included ? 'Ja' : 'Nei');

        $body .= '</p>';
        $body .= '<p>Se vedlagte Excel-filer.</p>';

        $sent = wp_mail(
            $recipients,
            $subject,
            $body,
            ['Content-Type: text/html; charset=UTF-8'],
            $attachments
        );

        if (!$sent) {
            LH_Ttx_Logger::error(
                'Logistics success email failed',
                [ 'ttx_order_id' => $ttx_order_id, 'recipients'   => $recipients ]
            );
        }

        return (bool) $sent;
    }

    private function send_failure_email( int $ttx_order_id, Throwable $error ): void {
        $recipients = $this->get_recipients();

        if ($recipients === []) {
            LH_Ttx_Logger::error(
                'Logistics failure email not sent: '
                . 'no valid recipient configured',
                [ 'ttx_order_id' => $ttx_order_id ]
            );
            return;
        }

        $subject = sprintf(
            'Logistikkdokumenter kunne ikke genereres '
            . '– Tripletex %d',
            $ttx_order_id
        );

        $body = '<p>Logistikkdokumentene kunne ikke '
            . 'genereres automatisk.</p>';

        $body .= '<p>';
        $body .= '<strong>Tripletex ordre-ID:</strong> '
            . esc_html((string) $ttx_order_id)
            . '<br>';

        $body .= 'Feil: '
            . esc_html($error->getMessage());

        $body .= '</p>';
        $body .= '<p>Dokumentene må opprettes manuelt '
            . 'for denne ordren.</p>';

        $sent = wp_mail( $recipients, $subject, $body, ['Content-Type: text/html; charset=UTF-8'] );

        if ($sent) {
            LH_Ttx_Logger::info(
                'Logistics failure email sent',
                [ 'ttx_order_id' => $ttx_order_id, ]
            );
            return;
        }

        LH_Ttx_Logger::error(
            'Logistics failure email failed',
            [ 'ttx_order_id' => $ttx_order_id, 'recipients'   => $recipients ]
        );
    }

    /**
     * Read one or more configured recipients.
     *
     * The option may contain:
     *
     * - A single email address.
     * - A comma-separated string.
     * - A semicolon-separated string.
     * - An array of email addresses.
     *
     * The WordPress administrator email is used as a fallback.
     *
     * @return string[]
     */
    private function get_recipients(): array {
        $configured = get_option( LH_TTX_OPT_LOGISTICS_RECIPIENTS, '' );

        $values = is_array($configured)
            ? $configured
            : preg_split( '/[,;\r\n]+/', (string) $configured );

        $recipients = [];

        foreach ((array) $values as $value) {
            $email = sanitize_email(trim((string) $value));

            if ($email !== '' && is_email($email)) {
                $recipients[] = $email;
            }
        }

        $recipients = array_values( array_unique($recipients) );

        if ($recipients !== []) {
            return $recipients;
        }

        $admin_email = sanitize_email( (string) get_option('admin_email', ''));

        return is_email($admin_email) ? [$admin_email] : [];
    }

    private function order_number( array $order, int $ttx_order_id ): string {
        $number = trim( (string) ($order['number'] ?? '') );

        return $number !== '' ? $number : (string) $ttx_order_id;
    }

    /**
     * @param string[] $files
     */
    private function delete_temporary_files( array $files, int $ttx_order_id ): void {
        foreach (array_unique($files) as $file) {
            if ( !is_string($file) || $file === '' || !is_file($file) ) {
                continue;
            }

            if (!@unlink($file)) {
                LH_Ttx_Logger::error(
                    'Could not delete temporary logistics file',
                    [ 'ttx_order_id' => $ttx_order_id, 'filename' => basename($file) ]
                );
            }
        }
    }
}