<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Admin: Manage LH product properties ---------- */

class LavendelHygiene_AdminProductProperties {
    const PAGE_SLUG    = 'lavendelhygiene-product-properties';
    const NONCE_ACTION = 'lavh_product_properties';

    private $cache = [];

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );

        add_action( 'wp_ajax_lavh_search_property_products', [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_lavh_add_product_property', [ $this, 'ajax_add_property' ] );
        add_action( 'wp_ajax_lavh_remove_product_property', [ $this, 'ajax_remove_property' ] );
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'LH: Product properties', 'lavendelhygiene' ),
            __( 'LH: Product properties', 'lavendelhygiene' ),
            'manage_woocommerce',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'No permission.', 'lavendelhygiene' ) );
        }

        $tabs = $this->tabs();
        $tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
        if ( ! isset( $tabs[ $tab ] ) ) $tab = 'overview';
        ?>
        <div class="wrap lavh-product-properties">
            <h1><?php esc_html_e( 'Product properties', 'lavendelhygiene' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $key => $label ) : ?>
                    <a href="<?php echo esc_url( $this->tab_url( $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="lavh-property-content">
                <?php
                switch ( $tab ) {
                    case 'catalog_only':
                        $this->render_catalog_only_tab();
                        break;
                    case 'installation':
                        $this->render_editable_property_tab( 'installation' );
                        break;
                    case 'temporary_unavailable':
                        $this->render_temporary_unavailable_tab();
                        break;
                    case 'volume_notice':
                        $this->render_editable_property_tab( 'volume_notice' );
                        break;
                    default:
                        $this->render_overview_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
        $this->render_inline_assets( $tab );
    }

    private function tabs(): array {
        return [
            'overview'              => __( 'Overview', 'lavendelhygiene' ),
            'catalog_only'          => __( 'Catalog only', 'lavendelhygiene' ),
            'installation'          => __( 'Installation', 'lavendelhygiene' ),
            'temporary_unavailable' => __( 'Temporarily unavailable', 'lavendelhygiene' ),
            'volume_notice'         => __( 'Volume pricing notice', 'lavendelhygiene' ),
        ];
    }

    private function editable_properties(): array {
        return [
            'catalog_only' => [
                'label'       => __( 'Catalog only', 'lavendelhygiene' ),
                'meta_key'    => LavendelHygiene_ProductMetaEditor::META_CATALOG_ONLY,
                'description' => __( 'Products that hide price and cannot be purchased directly in the webshop.', 'lavendelhygiene' ),
            ],
            'installation' => [
                'label'       => __( 'Installation products', 'lavendelhygiene' ),
                'meta_key'    => LavendelHygiene_ProductMetaEditor::META_INSTALLATION,
                'description' => __( 'Products that use the installation notice and are treated as catalog-only (hide price and cannot be purchased).', 'lavendelhygiene' ),
            ],
            'volume_notice' => [
                'label'       => __( 'Volume pricing notice', 'lavendelhygiene' ),
                'meta_key'    => LavendelHygiene_ProductMetaEditor::META_VOLUME_NOTICE,
                'description' => __( 'Products that show the volume pricing notice when prices are visible.', 'lavendelhygiene' ),
            ],
        ];
    }

    private function tab_url( string $tab ): string {
        return add_query_arg( [
            'page' => self::PAGE_SLUG,
            'tab'  => $tab,
        ], admin_url( 'admin.php' ) );
    }

    private function render_overview_tab() {
        $manual_catalog_only = $this->get_manual_catalog_only_ids();
        $installation        = $this->get_property_product_ids( 'installation' );
        $zero_price          = $this->get_zero_price_product_ids();
        $temp_products       = $this->get_temporary_unavailable_product_ids();
        $temp_variations     = $this->get_temporary_unavailable_variation_ids();
        $volume_notice       = $this->get_property_product_ids( 'volume_notice' );
        $effective_catalog   = array_unique( array_merge( $manual_catalog_only, $installation, $zero_price ) );

        $rows = [
            [ __( 'Effective catalog-only products', 'lavendelhygiene' ), count( $effective_catalog ), 'catalog_only' ],
            [ __( 'Manually assigned catalog-only products', 'lavendelhygiene' ), count( $manual_catalog_only ), 'catalog_only' ],
            [ __( 'Installation products', 'lavendelhygiene' ), count( $installation ), 'installation' ],
            [ __( 'Zero-price products', 'lavendelhygiene' ), count( $zero_price ), 'catalog_only' ],
            [ __( 'Temporarily unavailable products', 'lavendelhygiene' ), count( $temp_products ), 'temporary_unavailable' ],
            [ __( 'Temporarily unavailable variations', 'lavendelhygiene' ), count( $temp_variations ), 'temporary_unavailable' ],
            [ __( 'Volume pricing notice', 'lavendelhygiene' ), count( $volume_notice ), 'volume_notice' ],
        ];
        ?>
        <h2><?php esc_html_e( 'Overview', 'lavendelhygiene' ); ?></h2>
        <p><?php esc_html_e( 'Summary of product properties currently used by the plugin.', 'lavendelhygiene' ); ?></p>

        <table class="widefat striped lavh-overview-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Property', 'lavendelhygiene' ); ?></th>
                    <th class="lavh-count-column"><?php esc_html_e( 'Products', 'lavendelhygiene' ); ?></th>
                    <th class="lavh-actions-column"><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row[0] ); ?></td>
                        <td><?php echo esc_html( (string) $row[1] ); ?></td>
                        <td><a href="<?php echo esc_url( $this->tab_url( $row[2] ) ); ?>"><?php esc_html_e( 'View', 'lavendelhygiene' ); ?></a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_catalog_only_tab() {
        $properties   = $this->editable_properties();
        $manual_ids   = $this->get_manual_catalog_only_ids();
        $install_ids  = $this->get_property_product_ids( 'installation' );
        $zero_ids     = $this->get_zero_price_product_ids();
        ?>
        <h2><?php esc_html_e( 'Catalog only', 'lavendelhygiene' ); ?></h2>
        <p><?php echo esc_html( $properties['catalog_only']['description'] ); ?></p>

        <?php $this->render_add_box( 'catalog_only' ); ?>

        <section class="lavh-property-section">
            <h3><?php esc_html_e( 'Manually assigned products', 'lavendelhygiene' ); ?></h3>
            <?php $this->render_product_table( $manual_ids, 'catalog_only', true ); ?>
        </section>

        <section class="lavh-property-section">
            <h3><?php esc_html_e( 'Catalog-only through installation', 'lavendelhygiene' ); ?></h3>
            <p><?php esc_html_e( 'These products are managed from the Installation tab.', 'lavendelhygiene' ); ?></p>
            <?php $this->render_product_table( $install_ids, 'installation', false, $this->tab_url( 'installation' ), __( 'Manage installation', 'lavendelhygiene' ) ); ?>
        </section>

        <section class="lavh-property-section">
            <h3><?php esc_html_e( 'Zero-price products', 'lavendelhygiene' ); ?></h3>
            <p><?php esc_html_e( 'Non-variable products with a price of zero are treated as catalog-only automatically. Installation products are excluded from this list.', 'lavendelhygiene' ); ?></p>
            <?php $this->render_zero_price_table( $zero_ids ); ?>
        </section>
        <?php
    }

    private function render_editable_property_tab( string $property ) {
        $properties = $this->editable_properties();
        if ( ! isset( $properties[ $property ] ) ) return;

        $config = $properties[ $property ];
        $ids    = $this->get_property_product_ids( $property );
        ?>
        <h2><?php echo esc_html( $config['label'] ); ?></h2>
        <p><?php echo esc_html( $config['description'] ); ?></p>

        <?php $this->render_add_box( $property ); ?>

        <section class="lavh-property-section">
            <?php $this->render_product_table( $ids, $property, true ); ?>
        </section>
        <?php
    }

    private function render_add_box( string $property ) {
        $properties = $this->editable_properties();
        if ( ! isset( $properties[ $property ] ) ) return;
        ?>
        <div class="lavh-property-add" data-property="<?php echo esc_attr( $property ); ?>">
            <label for="lavh-property-search"><strong><?php esc_html_e( 'Add product', 'lavendelhygiene' ); ?></strong></label>
            <div class="lavh-property-search-row">
                <div class="lavh-property-search-wrap">
                    <input type="search" id="lavh-property-search" class="regular-text" autocomplete="off" placeholder="<?php esc_attr_e( 'Search by product name or SKU', 'lavendelhygiene' ); ?>" />
                    <input type="hidden" id="lavh-property-product-id" value="" />
                    <div id="lavh-property-search-results" class="lavh-property-search-results" hidden></div>
                </div>
                <button type="button" class="button button-primary" id="lavh-property-add-button" disabled><?php esc_html_e( 'Add product', 'lavendelhygiene' ); ?></button>
            </div>
            <p class="description" id="lavh-property-selected-product"><?php esc_html_e( 'Search and select a parent product. Variations are not listed here.', 'lavendelhygiene' ); ?></p>
        </div>
        <?php
    }

    private function render_product_table( array $product_ids, string $property, bool $removable, string $secondary_url = '', string $secondary_label = '' ) {
        ?>
        <table class="widefat striped lavh-products-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Product', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'SKU', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'lavendelhygiene' ); ?></th>
                    <th class="lavh-actions-column"><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $product_ids ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No products found.', 'lavendelhygiene' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $product_ids as $product_id ) :
                        $product = wc_get_product( $product_id );
                        if ( ! $product || $product->is_type( 'variation' ) ) continue;

                        $edit_url = get_edit_post_link( $product_id, '' );
                        $status   = get_post_status_object( get_post_status( $product_id ) );
                        ?>
                        <tr>
                            <td>
                                <?php if ( $edit_url ) : ?>
                                    <a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $product->get_name() ); ?></strong></a>
                                <?php else : ?>
                                    <strong><?php echo esc_html( $product->get_name() ); ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $product->get_sku() !== '' ? esc_html( $product->get_sku() ) : '&mdash;'; ?></td>
                            <td><?php echo esc_html( ucfirst( $product->get_type() ) ); ?></td>
                            <td><?php echo esc_html( $status ? $status->label : get_post_status( $product_id ) ); ?></td>
                            <td>
                                <?php if ( $edit_url ) : ?><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'lavendelhygiene' ); ?></a><?php endif; ?>
                                <?php if ( $secondary_url ) : ?>
                                    <?php if ( $edit_url ) echo ' | '; ?>
                                    <a href="<?php echo esc_url( $secondary_url ); ?>"><?php echo esc_html( $secondary_label ); ?></a>
                                <?php endif; ?>
                                <?php if ( $removable ) : ?>
                                    <?php if ( $edit_url ) echo ' | '; ?>
                                    <button type="button" class="button-link-delete lavh-remove-property" data-product-id="<?php echo esc_attr( (string) $product_id ); ?>" data-property="<?php echo esc_attr( $property ); ?>" data-product-name="<?php echo esc_attr( $product->get_name() ); ?>"><?php esc_html_e( 'Remove', 'lavendelhygiene' ); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_zero_price_table( array $product_ids ) {
        ?>
        <table class="widefat striped lavh-products-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Product', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'SKU', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Price', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'lavendelhygiene' ); ?></th>
                    <th class="lavh-actions-column"><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $product_ids ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No zero-price products found.', 'lavendelhygiene' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $product_ids as $product_id ) :
                        $product = wc_get_product( $product_id );
                        if ( ! $product ) continue;

                        $edit_url = get_edit_post_link( $product_id, '' );
                        $status   = get_post_status_object( get_post_status( $product_id ) );
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $product->get_name() ); ?></strong></a></td>
                            <td><?php echo $product->get_sku() !== '' ? esc_html( $product->get_sku() ) : '&mdash;'; ?></td>
                            <td><?php echo wp_kses_post( wc_price( (float) $product->get_price() ) ); ?></td>
                            <td><?php echo esc_html( $status ? $status->label : get_post_status( $product_id ) ); ?></td>
                            <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit product price', 'lavendelhygiene' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_temporary_unavailable_tab() {
        $product_ids   = $this->get_temporary_unavailable_product_ids();
        $variation_ids = $this->get_temporary_unavailable_variation_ids();
        ?>
        <h2><?php esc_html_e( 'Temporarily unavailable', 'lavendelhygiene' ); ?></h2>
        <p><?php esc_html_e( 'This page is read-only. Edit the product to change temporary availability, message visibility, or the custom message.', 'lavendelhygiene' ); ?></p>

        <section class="lavh-property-section">
            <h3><?php esc_html_e( 'Products', 'lavendelhygiene' ); ?></h3>
            <?php $this->render_temporary_products_table( $product_ids ); ?>
        </section>

        <section class="lavh-property-section">
            <h3><?php esc_html_e( 'Variations', 'lavendelhygiene' ); ?></h3>
            <?php $this->render_temporary_variations_table( $variation_ids ); ?>
        </section>
        <?php
    }

    private function render_temporary_products_table( array $product_ids ) {
        ?>
        <table class="widefat striped lavh-products-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Product', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'SKU', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'lavendelhygiene' ); ?></th>
                    <th class="lavh-actions-column"><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $product_ids ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No temporarily unavailable products found.', 'lavendelhygiene' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $product_ids as $product_id ) :
                        $product = wc_get_product( $product_id );
                        if ( ! $product || $product->is_type( 'variation' ) ) continue;

                        $edit_url = get_edit_post_link( $product_id, '' );
                        $status   = get_post_status_object( get_post_status( $product_id ) );
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $product->get_name() ); ?></strong></a></td>
                            <td><?php echo $product->get_sku() !== '' ? esc_html( $product->get_sku() ) : '&mdash;'; ?></td>
                            <td><?php echo esc_html( ucfirst( $product->get_type() ) ); ?></td>
                            <td><?php echo esc_html( $status ? $status->label : get_post_status( $product_id ) ); ?></td>
                            <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit product', 'lavendelhygiene' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_temporary_variations_table( array $variation_ids ) {
        ?>
        <table class="widefat striped lavh-products-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Parent product', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Variation', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'SKU', 'lavendelhygiene' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'lavendelhygiene' ); ?></th>
                    <th class="lavh-actions-column"><?php esc_html_e( 'Actions', 'lavendelhygiene' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $variation_ids ) ) : ?>
                    <tr><td colspan="5"><?php esc_html_e( 'No temporarily unavailable variations found.', 'lavendelhygiene' ); ?></td></tr>
                <?php else : ?>
                    <?php foreach ( $variation_ids as $variation_id ) :
                        $variation = wc_get_product( $variation_id );
                        if ( ! $variation || ! $variation->is_type( 'variation' ) ) continue;

                        $parent_id = $variation->get_parent_id();
                        $parent    = wc_get_product( $parent_id );
                        if ( ! $parent ) continue;

                        $edit_url = get_edit_post_link( $parent_id, '' );
                        $status   = get_post_status_object( get_post_status( $variation_id ) );
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $parent->get_name() ); ?></strong></a></td>
                            <td><?php echo esc_html( $this->format_variation_attributes( $variation, $parent ) ); ?></td>
                            <td><?php echo $variation->get_sku() !== '' ? esc_html( $variation->get_sku() ) : '&mdash;'; ?></td>
                            <td><?php echo esc_html( $status ? $status->label : get_post_status( $variation_id ) ); ?></td>
                            <td><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit parent product', 'lavendelhygiene' ); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function format_variation_attributes( WC_Product_Variation $variation, WC_Product $parent ): string {
        $parts = [];

        foreach ( $variation->get_variation_attributes() as $name => $value ) {
            if ( $value === '' ) continue;

            $taxonomy = str_replace( 'attribute_', '', $name );
            $label    = wc_attribute_label( $taxonomy, $parent );
            $display  = $value;

            if ( taxonomy_exists( $taxonomy ) ) {
                $term = get_term_by( 'slug', $value, $taxonomy );
                if ( $term && ! is_wp_error( $term ) ) $display = $term->name;
            }

            $parts[] = $label . ': ' . $display;
        }

        return empty( $parts ) ? sprintf( __( 'Variation #%d', 'lavendelhygiene' ), $variation->get_id() ) : implode( ', ', $parts );
    }

    private function get_property_product_ids( string $property ): array {
        $cache_key = 'property_' . $property;
        if ( isset( $this->cache[ $cache_key ] ) ) return $this->cache[ $cache_key ];

        $properties = $this->editable_properties();
        if ( ! isset( $properties[ $property ] ) ) return [];

        $ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => $this->product_statuses(),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_key'       => $properties[ $property ]['meta_key'],
            'meta_value'     => 'yes',
        ] );

        return $this->cache[ $cache_key ] = array_map( 'intval', $ids );
    }

    private function get_manual_catalog_only_ids(): array {
        if ( isset( $this->cache['manual_catalog_only'] ) ) return $this->cache['manual_catalog_only'];

        $ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => $this->product_statuses(),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'   => LavendelHygiene_ProductMetaEditor::META_CATALOG_ONLY,
                    'value' => 'yes',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => LavendelHygiene_ProductMetaEditor::META_INSTALLATION,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => LavendelHygiene_ProductMetaEditor::META_INSTALLATION,
                        'value'   => 'yes',
                        'compare' => '!=',
                    ],
                ],
            ],
        ] );

        return $this->cache['manual_catalog_only'] = array_map( 'intval', $ids );
    }

    private function get_zero_price_product_ids(): array {
        if ( isset( $this->cache['zero_price'] ) ) return $this->cache['zero_price'];

        $candidate_ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => $this->product_statuses(),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'     => '_price',
                    'value'   => 0,
                    'compare' => '<=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ] );

        $ids = [];
        foreach ( $candidate_ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || $product->is_type( 'variable' ) ) continue;
            if ( $product->get_price() === '' || (float) $product->get_price() > 0 ) continue;
            if ( get_post_meta( $product_id, LavendelHygiene_ProductMetaEditor::META_INSTALLATION, true ) === 'yes' ) continue;
            $ids[] = (int) $product_id;
        }

        return $this->cache['zero_price'] = $ids;
    }

    private function get_temporary_unavailable_product_ids(): array {
        if ( isset( $this->cache['temp_products'] ) ) return $this->cache['temp_products'];

        $ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => $this->product_statuses(),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_key'       => LavendelHygiene_ProductMetaEditor::META_TEMP_UNAVAILABLE,
            'meta_value'     => 'yes',
        ] );

        return $this->cache['temp_products'] = array_map( 'intval', $ids );
    }

    private function get_temporary_unavailable_variation_ids(): array {
        if ( isset( $this->cache['temp_variations'] ) ) return $this->cache['temp_variations'];

        $ids = get_posts( [
            'post_type'      => 'product_variation',
            'post_status'    => $this->product_statuses(),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => LavendelHygiene_ProductMetaEditor::META_TEMP_UNAVAILABLE,
            'meta_value'     => 'yes',
        ] );

        return $this->cache['temp_variations'] = array_map( 'intval', $ids );
    }

    private function product_statuses(): array {
        return [ 'publish', 'draft', 'pending', 'private', 'future' ];
    }

    public function ajax_search_products() {
        $this->verify_ajax_request();

        $term     = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';
        $property = isset( $_POST['property'] ) ? sanitize_key( wp_unslash( $_POST['property'] ) ) : '';

        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( [ 'products' => [] ] );
        }

        $properties = $this->editable_properties();
        if ( ! isset( $properties[ $property ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid product property.', 'lavendelhygiene' ) ], 400 );
        }

        $common = [
            'post_type'      => 'product',
            'post_status'    => $this->product_statuses(),
            'posts_per_page' => 20,
            'fields'         => 'ids',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        $name_query = new WP_Query( array_merge( $common, [ 's' => $term ] ) );
        $sku_query  = new WP_Query( array_merge( $common, [
            'meta_query' => [
                [
                    'key'     => '_sku',
                    'value'   => $term,
                    'compare' => 'LIKE',
                ],
            ],
        ] ) );

        $ids      = array_slice( array_values( array_unique( array_merge( $name_query->posts, $sku_query->posts ) ) ), 0, 20 );
        $products = [];

        foreach ( $ids as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product || $product->is_type( 'variation' ) ) continue;

            $products[] = [
                'id'               => $product->get_id(),
                'name'             => $product->get_name(),
                'sku'              => $product->get_sku(),
                'type'             => $product->get_type(),
                'catalog_only'     => get_post_meta( $product->get_id(), LavendelHygiene_ProductMetaEditor::META_CATALOG_ONLY, true ) === 'yes',
                'installation'     => get_post_meta( $product->get_id(), LavendelHygiene_ProductMetaEditor::META_INSTALLATION, true ) === 'yes',
                'already_assigned' => get_post_meta( $product->get_id(), $properties[ $property ]['meta_key'], true ) === 'yes',
            ];
        }

        wp_send_json_success( [ 'products' => $products ] );
    }

    public function ajax_add_property() {
        $this->verify_ajax_request();

        $property   = isset( $_POST['property'] ) ? sanitize_key( wp_unslash( $_POST['property'] ) ) : '';
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $properties = $this->editable_properties();

        if ( ! isset( $properties[ $property ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid product property.', 'lavendelhygiene' ) ], 400 );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || $product->is_type( 'variation' ) ) {
            wp_send_json_error( [ 'message' => __( 'Select a valid parent product.', 'lavendelhygiene' ) ], 400 );
        }

        if ( $property === 'catalog_only' && get_post_meta( $product_id, LavendelHygiene_ProductMetaEditor::META_INSTALLATION, true ) === 'yes' ) {
            wp_send_json_error( [
                'message' => __( 'This product is an installation product. Manage it from the Installation tab.', 'lavendelhygiene' ),
                'tab_url' => $this->tab_url( 'installation' ),
            ], 409 );
        }

        $already_assigned = get_post_meta( $product_id, $properties[ $property ]['meta_key'], true ) === 'yes';
        if ( $already_assigned ) {
            wp_send_json_success( [ 'message' => __( 'The product already has this property.', 'lavendelhygiene' ) ] );
        }

        $moved_from_catalog_only = false;
        if ( $property === 'installation' ) {
            $moved_from_catalog_only = get_post_meta( $product_id, LavendelHygiene_ProductMetaEditor::META_CATALOG_ONLY, true ) === 'yes';
            update_post_meta( $product_id, LavendelHygiene_ProductMetaEditor::META_CATALOG_ONLY, 'no' );
        }

        update_post_meta( $product_id, $properties[ $property ]['meta_key'], 'yes' );

        $message = sprintf( __( 'Added “%s” to %s.', 'lavendelhygiene' ), $product->get_name(), $properties[ $property ]['label'] );
        if ( $moved_from_catalog_only ) {
            $message .= ' ' . __( 'The manual catalog-only property was removed.', 'lavendelhygiene' );
        }

        wp_send_json_success( [ 'message' => $message ] );
    }

    public function ajax_remove_property() {
        $this->verify_ajax_request();

        $property   = isset( $_POST['property'] ) ? sanitize_key( wp_unslash( $_POST['property'] ) ) : '';
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $properties = $this->editable_properties();

        if ( ! isset( $properties[ $property ] ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid product property.', 'lavendelhygiene' ) ], 400 );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product || $product->is_type( 'variation' ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid product.', 'lavendelhygiene' ) ], 400 );
        }

        update_post_meta( $product_id, $properties[ $property ]['meta_key'], 'no' );

        $message = sprintf( __( 'Removed “%s” from %s.', 'lavendelhygiene' ), $product->get_name(), $properties[ $property ]['label'] );
        if ( in_array( $property, [ 'catalog_only', 'installation' ], true ) && $this->is_zero_price_catalog_only( $product ) ) {
            $message .= ' ' . __( 'The product is still catalog-only because its price is zero.', 'lavendelhygiene' );
        }

        wp_send_json_success( [ 'message' => $message ] );
    }

    private function verify_ajax_request() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'No permission.', 'lavendelhygiene' ) ], 403 );
        }

        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
    }

    private function is_zero_price_catalog_only( WC_Product $product ): bool {
        if ( $product->is_type( 'variable' ) ) return false;

        $price = $product->get_price();
        return $price !== '' && (float) $price <= 0;
    }

    private function render_inline_assets( string $tab ) {
        $properties = $this->editable_properties();
        $property   = isset( $properties[ $tab ] ) ? $tab : '';
        $settings   = [
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
            'property' => $property,
            'strings'  => [
                'searching'          => __( 'Searching…', 'lavendelhygiene' ),
                'noResults'          => __( 'No matching products found.', 'lavendelhygiene' ),
                'selectProduct'      => __( 'Select a product from the search results.', 'lavendelhygiene' ),
                'requestFailed'      => __( 'The request failed. Please try again.', 'lavendelhygiene' ),
                'addConfirm'         => __( 'Add “%s” to this property?', 'lavendelhygiene' ),
                'addInstallation'    => __( 'Add “%s” to Installation? The manual catalog-only property will be removed.', 'lavendelhygiene' ),
                'removeConfirm'      => __( 'Remove “%s” from this property?', 'lavendelhygiene' ),
                'alreadyAssigned'    => __( 'Already assigned', 'lavendelhygiene' ),
                'openInstallation'   => __( 'Open the Installation tab?', 'lavendelhygiene' ),
            ],
        ];
        ?>
        <style>
            .lavh-product-properties .nav-tab-wrapper{margin-bottom:22px;}
            .lavh-property-content{max-width:1200px;}
            .lavh-property-section{margin-top:28px;}
            .lavh-property-add{position:relative;max-width:820px;margin:20px 0 28px;padding:16px;background:#fff;border:1px solid #c3c4c7;}
            .lavh-property-search-row{display:flex;gap:8px;align-items:flex-start;margin-top:8px;}
            .lavh-property-search-wrap{position:relative;flex:1;}
            .lavh-property-search-wrap input[type="search"]{width:100%;max-width:none;}
            .lavh-property-search-results{position:absolute;z-index:20;top:100%;left:0;right:0;max-height:280px;overflow:auto;background:#fff;border:1px solid #8c8f94;box-shadow:0 2px 8px rgba(0,0,0,.12);}
            .lavh-property-search-result{display:block;width:100%;padding:9px 12px;border:0;border-bottom:1px solid #dcdcde;background:#fff;text-align:left;cursor:pointer;}
            .lavh-property-search-result:last-child{border-bottom:0;}
            .lavh-property-search-result:hover,.lavh-property-search-result:focus{background:#f0f6fc;outline:0;}
            .lavh-property-search-result small{display:block;color:#646970;margin-top:2px;}
            .lavh-property-search-message{padding:10px 12px;color:#646970;}
            .lavh-products-table th,.lavh-products-table td{vertical-align:middle;}
            .lavh-actions-column{width:210px;}
            .lavh-count-column{width:100px;}
            .lavh-overview-table{max-width:850px;}
            .lavh-property-content h2{margin-top:0;}
            .lavh-property-content h3{margin-bottom:8px;}
            @media (max-width:782px){.lavh-property-search-row{display:block;}.lavh-property-search-row .button{margin-top:8px;}.lavh-actions-column{width:auto;}}
        </style>
        <script>
        (function(){
            var settings = <?php echo wp_json_encode( $settings ); ?>;
            var addBox = document.querySelector('.lavh-property-add');
            var searchInput = document.getElementById('lavh-property-search');
            var productIdInput = document.getElementById('lavh-property-product-id');
            var results = document.getElementById('lavh-property-search-results');
            var addButton = document.getElementById('lavh-property-add-button');
            var selectedText = document.getElementById('lavh-property-selected-product');
            var selectedProduct = null;
            var searchTimer = null;
            var requestNumber = 0;

            function request(action, data){
                var body = new URLSearchParams();
                body.set('action', action);
                body.set('nonce', settings.nonce);
                Object.keys(data || {}).forEach(function(key){ body.set(key, data[key]); });

                return fetch(settings.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString()
                }).then(function(response){
                    return response.json().catch(function(){ throw new Error(settings.strings.requestFailed); });
                });
            }

            function format(template, value){ return template.replace('%s', value); }

            function resetSelection(){
                selectedProduct = null;
                if (productIdInput) productIdInput.value = '';
                if (addButton) addButton.disabled = true;
            }

            function showResults(items){
                if (!results) return;
                results.innerHTML = '';
                results.hidden = false;

                if (!items.length) {
                    var empty = document.createElement('div');
                    empty.className = 'lavh-property-search-message';
                    empty.textContent = settings.strings.noResults;
                    results.appendChild(empty);
                    return;
                }

                items.forEach(function(product){
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'lavh-property-search-result';

                    var title = document.createElement('strong');
                    title.textContent = product.name + (product.sku ? ' — ' + product.sku : '');
                    button.appendChild(title);

                    var details = document.createElement('small');
                    var detailText = product.type.charAt(0).toUpperCase() + product.type.slice(1);
                    if (product.already_assigned) detailText += ' · ' + settings.strings.alreadyAssigned;
                    details.textContent = detailText;
                    button.appendChild(details);

                    button.addEventListener('click', function(){
                        selectedProduct = product;
                        productIdInput.value = product.id;
                        searchInput.value = product.name + (product.sku ? ' — ' + product.sku : '');
                        selectedText.textContent = searchInput.value;
                        addButton.disabled = false;
                        results.hidden = true;
                    });

                    results.appendChild(button);
                });
            }

            if (searchInput && addBox) {
                searchInput.addEventListener('input', function(){
                    resetSelection();
                    clearTimeout(searchTimer);
                    var term = searchInput.value.trim();

                    if (term.length < 2) {
                        results.hidden = true;
                        results.innerHTML = '';
                        return;
                    }

                    searchTimer = setTimeout(function(){
                        var currentRequest = ++requestNumber;
                        results.hidden = false;
                        results.innerHTML = '<div class="lavh-property-search-message">' + settings.strings.searching + '</div>';

                        request('lavh_search_property_products', {term: term, property: settings.property}).then(function(response){
                            if (currentRequest !== requestNumber) return;
                            if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : settings.strings.requestFailed);
                            showResults(response.data.products || []);
                        }).catch(function(error){
                            if (currentRequest !== requestNumber) return;
                            results.innerHTML = '';
                            var message = document.createElement('div');
                            message.className = 'lavh-property-search-message';
                            message.textContent = error.message || settings.strings.requestFailed;
                            results.appendChild(message);
                        });
                    }, 250);
                });

                addButton.addEventListener('click', function(){
                    if (!selectedProduct) {
                        window.alert(settings.strings.selectProduct);
                        return;
                    }

                    var confirmText = format(settings.strings.addConfirm, selectedProduct.name);
                    if (settings.property === 'installation' && selectedProduct.catalog_only) {
                        confirmText = format(settings.strings.addInstallation, selectedProduct.name);
                    }
                    if (!window.confirm(confirmText)) return;

                    addButton.disabled = true;
                    request('lavh_add_product_property', {product_id: selectedProduct.id, property: settings.property}).then(function(response){
                        if (!response.success) {
                            var message = response.data && response.data.message ? response.data.message : settings.strings.requestFailed;
                            if (response.data && response.data.tab_url) {
                                if (window.confirm(message + '\n\n' + settings.strings.openInstallation)) window.location.href = response.data.tab_url;
                                else addButton.disabled = false;
                                return;
                            }
                            throw new Error(message);
                        }
                        window.alert(response.data.message);
                        window.location.reload();
                    }).catch(function(error){
                        window.alert(error.message || settings.strings.requestFailed);
                        addButton.disabled = false;
                    });
                });

                document.addEventListener('click', function(event){
                    if (!addBox.contains(event.target)) results.hidden = true;
                });
            }

            document.querySelectorAll('.lavh-remove-property').forEach(function(button){
                button.addEventListener('click', function(){
                    var name = button.dataset.productName || '';
                    if (!window.confirm(format(settings.strings.removeConfirm, name))) return;

                    button.disabled = true;
                    request('lavh_remove_product_property', {
                        product_id: button.dataset.productId,
                        property: button.dataset.property
                    }).then(function(response){
                        if (!response.success) throw new Error(response.data && response.data.message ? response.data.message : settings.strings.requestFailed);
                        window.alert(response.data.message);
                        window.location.reload();
                    }).catch(function(error){
                        window.alert(error.message || settings.strings.requestFailed);
                        button.disabled = false;
                    });
                });
            });
        })();
        </script>
        <?php
    }
}