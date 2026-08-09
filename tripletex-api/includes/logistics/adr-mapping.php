<?php
if (!defined('ABSPATH')) exit;

if (!defined('LH_TTX_OPT_ADR_MAPPING')) {
    define( 'LH_TTX_OPT_ADR_MAPPING', 'lh_ttx_adr_mapping' );
}

if (!defined('LH_TTX_OPT_ADR_MAPPING_FILENAME')) {
    define( 'LH_TTX_OPT_ADR_MAPPING_FILENAME', 'lh_ttx_adr_mapping_filename' );
}

if (!defined('LH_TTX_OPT_ADR_MAPPING_UPLOADED_AT')) {
    define( 'LH_TTX_OPT_ADR_MAPPING_UPLOADED_AT', 'lh_ttx_adr_mapping_uploaded_at' );
}

/**
 * Stores and queries the parsed ADR product mapping.
 *
 * The complete mapping is stored in one non-autoloaded WordPress option,
 * indexed by normalized Tripletex product number/SKU.
 */
final class LH_Ttx_ADR_Mapping {

    /**
     * Return the complete normalized mapping.
     *
     * @return array<string,array<string,string>>
     */
    public static function get_all(): array {
        $mapping = get_option(
            LH_TTX_OPT_ADR_MAPPING,
            []
        );

        return is_array($mapping)
            ? $mapping
            : [];
    }

    /**
     * Find a mapping entry using an exact normalized SKU.
     */
    public static function get_by_sku(
        string $sku
    ): ?array {
        $sku = self::normalize_sku($sku);

        if ($sku === '') {
            return null;
        }

        $mapping = self::get_all();
        $row     = $mapping[$sku] ?? null;

        return is_array($row)
            ? $row
            : null;
    }

    /**
     * Match Tripletex order lines against the mapping.
     *
     * The result contains the original order line, product data, and
     * normalized mapping entry. This is the input expected by the later
     * ADR spreadsheet generator.
     *
     * @return array<int,array{
     *     sku:string,
     *     order_line:array,
     *     product:array,
     *     mapping:array
     * }>
     */
    public static function find_order_lines(
        array $order_lines
    ): array {
        $mapping = self::get_all();

        if ($mapping === [] || $order_lines === []) {
            return [];
        }

        $matches = [];

        foreach ($order_lines as $order_line) {
            if (!is_array($order_line)) {
                continue;
            }

            $product = isset($order_line['product'])
                && is_array($order_line['product'])
                    ? $order_line['product']
                    : [];

            $sku = self::normalize_sku(
                (string) ($product['number'] ?? '')
            );

            if ($sku === '' || !isset($mapping[$sku]) || !is_array($mapping[$sku])) {
                continue;
            }

            $mapped_row = $mapping[$sku];

            $psn = trim((string) ($mapped_row['psn'] ?? ''));
            if (mb_strtolower($psn, 'UTF-8') === 'ikke relevant') continue;

            $matches[] = [
                'sku'        => $sku,
                'order_line' => $order_line,
                'product'    => $product,
                'mapping'    => $mapped_row,
            ];
        }

        return $matches;
    }

    /**
     * Replace the stored mapping with parsed spreadsheet rows.
     *
     * The XLSX upload handler should provide associative rows where each
     * array key is the heading from the first spreadsheet row.
     *
     * Rows without an SKU are skipped. For duplicate SKUs, the last row
     * wins and the duplicate is logged.
     *
     * @return array{
     *     imported:int,
     *     skipped:int,
     *     duplicates:int
     * }
     */
    public static function replace_from_rows(
        array $rows,
        string $filename = ''
    ): array {
        $mapping    = [];
        $skipped    = 0;
        $duplicates = 0;

        // Source workbook row 1 contains headings.
        $row_number = 1;

        foreach ($rows as $row) {
            $row_number++;

            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            $normalized = self::normalize_row($row);
            $sku        = $normalized['sku'];

            if ($sku === '') {
                $skipped++;

                LH_Ttx_Logger::info(
                    'ADR mapping row skipped: missing SKU',
                    [
                        'row' => $row_number,
                    ]
                );

                continue;
            }

            if (isset($mapping[$sku])) {
                $duplicates++;

                LH_Ttx_Logger::info(
                    'ADR mapping duplicate SKU; last row wins',
                    [
                        'sku' => $sku,
                        'row' => $row_number,
                    ]
                );
            }

            $mapping[$sku] = $normalized;
        }

        self::store_option(
            LH_TTX_OPT_ADR_MAPPING,
            $mapping
        );

        self::store_option(
            LH_TTX_OPT_ADR_MAPPING_FILENAME,
            sanitize_file_name($filename)
        );

        self::store_option(
            LH_TTX_OPT_ADR_MAPPING_UPLOADED_AT,
            current_time('mysql', true)
        );

        LH_Ttx_Logger::info('ADR mapping replaced', [
            'filename'   => sanitize_file_name($filename),
            'imported'   => count($mapping),
            'skipped'    => $skipped,
            'duplicates' => $duplicates,
        ]);

        return [
            'imported'   => count($mapping),
            'skipped'    => $skipped,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Remove the parsed mapping and associated metadata.
     */
    public static function clear(): void {
        delete_option(LH_TTX_OPT_ADR_MAPPING);
        delete_option(
            LH_TTX_OPT_ADR_MAPPING_FILENAME
        );
        delete_option(
            LH_TTX_OPT_ADR_MAPPING_UPLOADED_AT
        );

        LH_Ttx_Logger::info('ADR mapping cleared');
    }

    /**
     * Return metadata for the future administration page.
     *
     * @return array{
     *     filename:string,
     *     uploaded_at:string,
     *     count:int
     * }
     */
    public static function metadata(): array {
        return [
            'filename' => (string) get_option(
                LH_TTX_OPT_ADR_MAPPING_FILENAME,
                ''
            ),
            'uploaded_at' => (string) get_option(
                LH_TTX_OPT_ADR_MAPPING_UPLOADED_AT,
                ''
            ),
            'count' => count(self::get_all()),
        ];
    }

    /**
     * Normalize SKU values for exact matching.
     */
    public static function normalize_sku(
        string $sku
    ): string {
        return strtoupper(trim($sku));
    }

    /**
     * Normalize one parsed spreadsheet row into the internal schema.
     *
     * @return array<string,string>
     */
    private static function normalize_row(
        array $row
    ): array {
        $lookup = self::normalize_row_headers($row);

        $sku = self::normalize_sku(
            self::value(
                $lookup,
                [
                    'SKU / Produktnummer (Hos Lavendel)',
                    'SKU',
                    'Produktnummer',
                ]
            )
        );

        return [
            'sku' => $sku,

            'product_name' => self::value(
                $lookup,
                [
                    'Produktnavn (Hos Lavendel)',
                    'Produktnavn',
                ]
            ),

            'hs_code' => self::value(
                $lookup,
                [
                    'HS Code',
                    'HS-kode',
                ]
            ),

            'internal_name' => self::value(
                $lookup,
                [
                    'Kersia Internal Product Name',
                ]
            ),

            'un_number' => self::value(
                $lookup,
                [
                    'UN.-Nr',
                    'UN.-Nr.',
                    'UN-Nr',
                    'UN Nr',
                ]
            ),

            'psn' => self::value(
                $lookup,
                [
                    'Varebetegnelse / PSN',
                ]
            ),

            'chemical_name' => self::value(
                $lookup,
                [
                    'Kjemisk/teknisk navn ved '
                    . 'N.O.S. / SP 274/318',

                    'Kjemisk / teknisk navn ved '
                    . 'N.O.S. / SP 274/318',
                ]
            ),

            'environmental' => self::value(
                $lookup,
                [
                    'Ev. miljøfare',
                    'Miljøfare',
                ]
            ),

            'class_label' => self::value(
                $lookup,
                [
                    'Klasse / faresedler',
                    'Klasse / fareseddel',
                ]
            ),

            'packing_group' => self::value(
                $lookup,
                [
                    'Emb. gr.',
                    'Emb.gr.',
                    'Emballasjegruppe',
                ]
            ),

            'tunnel_code' => self::value(
                $lookup,
                [
                    'Tunnelkode',
                ]
            ),

            'comment' => self::value(
                $lookup,
                [
                    'Merknad',
                    'Kommentar',
                ]
            ),
        ];
    }

    /**
     * Convert the spreadsheet headings into a case-insensitive,
     * whitespace-normalized lookup.
     *
     * @return array<string,mixed>
     */
    private static function normalize_row_headers(
        array $row
    ): array {
        $lookup = [];

        foreach ($row as $heading => $value) {
            $normalized_heading =
                self::normalize_heading(
                    (string) $heading
                );

            if ($normalized_heading !== '') {
                $lookup[$normalized_heading] = $value;
            }
        }

        return $lookup;
    }

    private static function normalize_heading(
        string $heading
    ): string {
        $heading = preg_replace(
            '/\s+/u',
            ' ',
            trim($heading)
        );

        return mb_strtolower(
            (string) $heading,
            'UTF-8'
        );
    }

    /**
     * Return the first value found for the provided headings.
     *
     * @param string[] $headings
     */
    private static function value(
        array $lookup,
        array $headings
    ): string {
        foreach ($headings as $heading) {
            $key = self::normalize_heading($heading);

            if (!array_key_exists($key, $lookup)) {
                continue;
            }

            $value = $lookup[$key];

            if ($value === null) {
                return '';
            }

            if (is_scalar($value)) {
                return trim((string) $value);
            }

            return '';
        }

        return '';
    }

    /**
     * Create options with autoload disabled, or update an existing option.
     *
     * @param mixed $value
     */
    private static function store_option(
        string $key,
        $value
    ): void {
        $created = add_option( $key, $value, '', 'no' );

        if (!$created) {
            update_option($key, $value);
        }
    }
}