<?php
if (!defined('ABSPATH')) exit;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class LH_Ttx_Spreadsheet_Generator {
    public static function create_nvit(array $order, array $mapping = []) {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('NVIT');

            $headers = [
                'FRAKTBREVNUMMER', 'VAREBESKRIVELSE', 'HS-KODE', 'NETTOVEKT',
                'BRUTTOVEKT', 'ANTALL KOLLI', 'VERDI', 'KUNDEREFERANSE', 'VALUTA',
            ];
            self::write_headers($sheet, $headers);

            $row = 2;
            foreach (self::order_lines($order) as $line) {
                $product = self::product($line);
                $sku = trim((string)($product['number'] ?? ''));
                $name = trim((string)($product['name'] ?? ''));
                $count = self::number($line['count'] ?? 0);
                $unit_weight_kg = self::weight_to_kg(
                    self::number($product['weight'] ?? 0),
                    (string)($product['weightUnit'] ?? '')
                );
                $total_weight_kg = $unit_weight_kg * $count;
                $unit_price = self::number($line['unitPriceExcludingVatCurrency'] ?? 0);
                $hs_code = self::hs_code((string)($product['hsnCode'] ?? ''));

                if ($hs_code === '' && $sku !== '') {
                    $mapped = $mapping[LH_Ttx_ADR_Mapping::normalize_sku($sku)] ?? null;
                    if (is_array($mapped)) $hs_code = self::hs_code((string)($mapped['hs_code'] ?? ''));
                }

                self::text($sheet, "A{$row}", '');
                self::text($sheet, "B{$row}", trim($sku . ' ' . $name));
                self::text($sheet, "C{$row}", $hs_code);
                self::numeric($sheet, "D{$row}", $total_weight_kg);
                self::numeric($sheet, "E{$row}", $total_weight_kg);
                self::numeric($sheet, "F{$row}", $count);
                self::numeric($sheet, "G{$row}", $unit_price * $count);
                self::text($sheet, "H{$row}", (string)($order['customer']['name'] ?? ''));
                self::text($sheet, "I{$row}", 'NOK');
                $row++;
            }

            self::format_table($sheet, count($headers), max(2, $row - 1));
            $sheet->getStyle("D2:G" . max(2, $row - 1))->getNumberFormat()->setFormatCode('0.00');
            return self::save($spreadsheet, 'NVIT-' . self::order_number($order) . '.xlsx');
        } catch (Throwable $e) {
            LH_Ttx_Logger::error('NVIT spreadsheet generation failed', ['message' => $e->getMessage()]);
            return new WP_Error('lh_ttx_nvit_failed', $e->getMessage());
        }
    }

    public static function create_order_summary(array $order) {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Ordre');

            $address = is_array($order['deliveryAddress'] ?? null) ? $order['deliveryAddress'] : [];
            $country = is_array($address['country'] ?? null) ? $address['country'] : [];
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];

            $details = [
                ['Ordrenummer', self::order_number($order)],
                ['Tripletex ordre-ID', (string)($order['id'] ?? '')],
                ['Ordredato', (string)($order['orderDate'] ?? '')],
                ['Leveringsdato', (string)($order['deliveryDate'] ?? '')],
                ['Kunde', (string)($customer['name'] ?? '')],
                ['Organisasjonsnummer', (string)($customer['organizationNumber'] ?? '')],
                ['Kontakt Epost', (string)($order['receiverEmail'] ?? '')],
                ['Adresse 1', (string)($address['addressLine1'] ?? '')],
                ['Adresse 2', (string)($address['addressLine2'] ?? '')],
                ['Postnummer', (string)($address['postalCode'] ?? '')],
                ['Sted', (string)($address['city'] ?? '')],
                ['Land', (string)($country['isoAlpha2Code'] ?? '')],
                ['Leveringskommentar', (string)($order['deliveryComment'] ?? '')],
            ];

            $r = 1;
            foreach ($details as [$label, $value]) {
                self::text($sheet, "A{$r}", $label);
                self::text($sheet, "B{$r}", $value);
                $r++;
            }
            $sheet->getStyle('A1:A' . ($r - 1))->getFont()->setBold(true);

            $r++;
            $headers = ['SKU', 'Produkt', 'Antall', 'Enhetsvekt kg', 'Totalvekt kg', 'Enhetspris eks. MVA', 'Linjeverdi', 'HS-kode', 'ADR'];
            foreach ($headers as $index => $header) {
                self::text($sheet, self::column($index + 1) . $r, $header);
            }
            self::style_header_range($sheet, 'A' . $r . ':I' . $r);
            $r++;

            foreach (self::order_lines($order) as $line) {
                $product = self::product($line);
                $sku = trim((string)($product['number'] ?? ''));
                $count = self::number($line['count'] ?? 0);
                $unit_weight = self::weight_to_kg(self::number($product['weight'] ?? 0), (string)($product['weightUnit'] ?? ''));
                $unit_price = self::number($line['unitPriceExcludingVatCurrency'] ?? 0);

                self::text($sheet, "A{$r}", $sku);
                self::text($sheet, "B{$r}", (string)($product['name'] ?? ''));
                self::numeric($sheet, "C{$r}", $count);
                self::numeric($sheet, "D{$r}", $unit_weight);
                self::numeric($sheet, "E{$r}", $unit_weight * $count);
                self::numeric($sheet, "F{$r}", $unit_price);
                self::numeric($sheet, "G{$r}", $unit_price * $count);
                self::text($sheet, "H{$r}", self::hs_code((string)($product['hsnCode'] ?? '')));
                self::text($sheet, "I{$r}", LH_Ttx_ADR_Mapping::get_by_sku($sku) ? 'Ja' : 'Nei');
                $r++;
            }

            self::format_table($sheet, 9, max(1, $r - 1));
            $sheet->getStyle('C1:G' . max(1, $r - 1))->getNumberFormat()->setFormatCode('0.00');
            return self::save($spreadsheet, 'Ordregrunnlag-' . self::order_number($order) . '.xlsx');
        } catch (Throwable $e) {
            LH_Ttx_Logger::error('Order summary spreadsheet generation failed', ['message' => $e->getMessage()]);
            return new WP_Error('lh_ttx_order_summary_failed', $e->getMessage());
        }
    }

    public static function create_adr(array $order, array $adr_lines) {
        try {
            $spreadsheet = new Spreadsheet();

            // --- Sheet 1: ADR lines ---
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('ADR');

            $headers = [
                'Antall / type kolli', 'UN-nr.', 'Varebetegnelse / PSN', 'Kjemisk / teknisk navn',
                'Miljøfare', 'Klasse / fareseddel', 'Emb.gr. / tunnelkode', 'Mengde (kg)',
            ];

            self::write_headers($sheet, $headers);

            $package_counts = [];
            $total_weight_kg = 0.0;
            $row = 2;

            foreach ($adr_lines as $match) {
                $line    = (array) ($match['order_line'] ?? []);
                $product = (array) ($match['product'] ?? []);
                $mapping = (array) ($match['mapping'] ?? []);

                $count = self::number($line['count'] ?? 0);

                $unit_weight_kg = self::weight_to_kg(
                    self::number($product['weight'] ?? 0),
                    (string) ($product['weightUnit'] ?? '')
                );

                $line_weight_kg = $unit_weight_kg * $count;
                $package_type   = self::detect_package_type( (string) ($product['name'] ?? '') );

                $package_counts[$package_type] = ($package_counts[$package_type] ?? 0) + $count;

                $total_weight_kg += $line_weight_kg;

                $packing_tunnel = implode(' / ', array_filter([
                    trim((string) ($mapping['packing_group'] ?? '')),
                    trim((string) ($mapping['tunnel_code'] ?? '')),
                ]));

                self::text($sheet, "A{$row}", self::package_description($count, (string) ($product['name'] ?? '')) );
                self::text($sheet, "B{$row}", (string) ($mapping['un_number'] ?? ''));
                self::text($sheet, "C{$row}", (string) ($mapping['psn'] ?? ''));
                self::text($sheet, "D{$row}", (string) ($mapping['chemical_name'] ?? ''));
                self::text($sheet, "E{$row}", (string) ($mapping['environmental'] ?? ''));
                self::text($sheet, "F{$row}", (string) ($mapping['class_label'] ?? ''));
                self::text($sheet, "G{$row}", $packing_tunnel);
                self::numeric($sheet, "H{$row}", $line_weight_kg);

                $row++;
            }

            self::format_table($sheet, count($headers), max(2, $row - 1));

            // --- Sheet 2: General info ---
            $info = $spreadsheet->createSheet();
            $info->setTitle('Generell info');

            $customer = (array) ($order['customer'] ?? []);
            $address  = (array) ($order['deliveryAddress'] ?? []);

            self::text($info, 'A1', 'Antall og type kolli');
            self::text($info, 'B1', self::package_summary($package_counts));

            self::text($info, 'A2', 'Bruttovekt (kg)');
            self::numeric($info, 'B2', $total_weight_kg);

            self::text($info, 'A3', 'Nettovekt (kg)');
            self::numeric($info, 'B3', $total_weight_kg);

            self::text($info, 'A5', 'Mottaker');
            self::text($info, 'B5', (string) ($customer['name'] ?? ''));

            self::text($info, 'A6', 'Adresse / kontakt');
            self::text($info, 'B6', self::recipient_address_contact($address));

            $info->getStyle('A1:A6')->getFont()->setBold(true);
            $info->getStyle('B2:B3')->getNumberFormat()->setFormatCode('0.00');
            $info->getStyle('A1:B6')->getAlignment()->setWrapText(true);

            $info->getColumnDimension('A')->setWidth(26);
            $info->getColumnDimension('B')->setWidth(60);

            $spreadsheet->setActiveSheetIndex(
                $spreadsheet->getIndex($info)
            );

            return self::save($spreadsheet, 'ADR-' . self::order_number($order) . '.xlsx');
        } catch (Throwable $e) {
            LH_Ttx_Logger::error('ADR spreadsheet generation failed', ['message' => $e->getMessage()]);
            return new WP_Error('lh_ttx_adr_failed', $e->getMessage());
        }
    }

    private static function order_lines(array $order): array {
        return is_array($order['orderLines'] ?? null) ? $order['orderLines'] : [];
    }

    private static function product(array $line): array {
        return is_array($line['product'] ?? null) ? $line['product'] : [];
    }

    private static function number($value): float {
        if (is_string($value)) $value = str_replace(',', '.', trim($value));
        return is_numeric($value) ? (float)$value : 0.0;
    }

    private static function weight_to_kg(float $weight, string $unit): float {
        $unit = strtoupper(trim($unit));
        return match ($unit) {
            '', 'KG', 'KILOGRAM', 'KILOGRAMS' => $weight,
            'G', 'GRAM', 'GRAMS' => $weight / 1000,
            'MG', 'MILLIGRAM', 'MILLIGRAMS' => $weight / 1000000,
            'T', 'TON', 'TONNE', 'TONNES' => $weight * 1000,
            default => throw new RuntimeException('Unsupported Tripletex weight unit: ' . $unit),
        };
    }

    private static function hs_code(string $value): string {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        return strlen($digits) >= 6 ? substr($digits, 0, 6) : '';
    }

    private static function order_number(array $order): string {
        $number = trim((string)($order['number'] ?? ''));
        if ($number === '') $number = (string)($order['id'] ?? 'ukjent');
        return sanitize_file_name($number);
    }

    private static function save(Spreadsheet $spreadsheet, string $filename): string {
        $placeholder = wp_tempnam($filename);
        if (!$placeholder) throw new RuntimeException('Could not create a temporary file.');
        $path = $placeholder . '.xlsx';
        @unlink($placeholder);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        return $path;
    }

    private static function write_headers($sheet, array $headers): void {
        foreach ($headers as $index => $header) self::text($sheet, self::column($index + 1) . '1', $header);
        self::style_header_range($sheet, 'A1:' . self::column(count($headers)) . '1');
    }

    private static function style_header_range($sheet, string $range): void {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAD3');
        $sheet->getStyle($range)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    }

    private static function format_table($sheet, int $columns, int $last_row): void {
        $last_column = self::column($columns);
        $sheet->getStyle("A1:{$last_column}{$last_row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:{$last_column}{$last_row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        for ($i = 1; $i <= $columns; $i++) $sheet->getColumnDimension(self::column($i))->setAutoSize(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last_column}1");
    }

    private static function text($sheet, string $cell, string $value): void {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private static function numeric($sheet, string $cell, float $value): void {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
    }

    private static function column(int $number): string {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($number);
    }

    private static function detect_package_type(string $product_name): string {
        $name = mb_strtolower($product_name, 'UTF-8');

        if (str_contains($name, 'tønne')) return 'tønne';
        if (str_contains($name, 'container')) return 'container';

        return 'kanne';
    }

    private static function package_description( float $count, string $product_name ): string {
        return self::format_package_count(
            $count,
            self::detect_package_type($product_name)
        );
    }

    private static function package_summary(array $counts): string {
        $parts = [];

        foreach (['kanne', 'tønne', 'container'] as $type) {
            $count = self::number($counts[$type] ?? 0);

            if ($count > 0) {
                $parts[] = self::format_package_count($count, $type);
            }
        }

        return implode(', ', $parts);
    }

    private static function format_package_count( float $count, string $type ): string {
        $count_text = self::format_count($count);

        $label = match ($type) {
            'tønne'     => $count == 1 ? 'tønne' : 'tønner',
            'container' => $count == 1 ? 'container' : 'containere',
            default     => $count == 1 ? 'kanne' : 'kanner',
        };

        return "{$count_text} {$label}";
    }

    private static function format_count(float $count): string {
        if (floor($count) === $count) {
            return (string) (int) $count;
        }

        return rtrim( rtrim(number_format($count, 3, '.', ''), '0'), '.' );
    }

    private static function recipient_address_contact( array $address ): string {
        $lines = array_filter([
            trim((string) ($address['addressLine1'] ?? '')),
            trim((string) ($address['addressLine2'] ?? '')),
            trim( (string) ($address['postalCode'] ?? '') . ' ' . (string) ($address['city'] ?? '') ),
            trim( (string) ( $address['country']['isoAlpha2Code'] ?? '') ),
        ]);

        return implode("\n", $lines);
    }
}