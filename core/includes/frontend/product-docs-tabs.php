<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LavendelHygiene_ProductDocsTab {
    const META_KEY_DOCS = '_docs';

    public function __construct() {
        add_filter( 'woocommerce_product_tabs', [ $this, 'add_docs_tab' ] );

        add_filter( 'woocommerce_catalog_orderby', [ $this, 'add_alph_sorting_option' ], 20 );
        add_filter( 'woocommerce_get_catalog_ordering_args', [ $this, 'apply_alph_sorting_args' ], 20, 3 );
    }

    public function add_alph_sorting_option( array $options ): array {
        $options['title_asc'] = __( 'Sorter etter navn (A-Å)', 'lavendelhygiene' );
        return $options;
    }

    public function apply_alph_sorting_args( array $args, string $orderby, string $order ): array {
        if ( 'title_asc' === $orderby ) {
            $args['orderby']  = 'title';
            $args['order']    = 'ASC';
            $args['meta_key'] = '';
        }
        return $args;
    }

    /**
     * Read and normalize docs meta for a product.
     *
     * Expects `_docs` to contain a JSON object:
     * { "Document title": "https://example.com/file.pdf", ... }
     */
    protected function get_docs_for_product( $product ): array {
        if ( ! $product instanceof WC_Product ) { return []; }

        $raw = get_post_meta( $product->get_id(), self::META_KEY_DOCS, true );
        if ( empty( $raw ) ) { return []; }

        $decoded = json_decode( (string) $raw, true );
        if ( ! is_array( $decoded ) ) { return []; }

        $docs = [];

        foreach ( $decoded as $label => $url ) {
            $label = trim( (string) $label );
            $url   = trim( (string) $url );

            if ( ! $url || ! $label ) { continue; }

            $docs[ $label ] = $url;
        }
        return $docs;
    }

    /**
     * Add a "Dokumentasjon" tab if docs exist for the current product.
     */
    public function add_docs_tab( array $tabs ): array {
        // Only relevant on single product context (classic or block)
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $tabs;
        }

        global $product;
        if ( ! $product instanceof WC_Product ) { return $tabs; }

        $tabs['lavh_docs'] = [
            'title'    => __( 'Relaterte dokumenter', 'lavendelhygiene' ),
            'priority' => 40,
            'callback' => [ $this, 'render_docs_tab' ],
        ];
        return $tabs;
    }

    /**
     * Render the content of the Dokumentasjon tab.
     */
    public function render_docs_tab() {
        global $product;

        if ( ! $product instanceof WC_Product ) { return; }

        $docs        = $this->get_docs_for_product( $product );
        $contact_url = home_url( '/kontakt/' );

        // Reusable "missing docs" message (shown in both cases)
        $missing_docs_message = wp_kses_post(
            sprintf(
                __( 'Finner du ikke dokumentasjonen du leter etter? <a href="%s">Kontakt oss</a>, så kan vi sende den til deg.', 'lavendelhygiene' ),
                esc_url( $contact_url )
            )
        );

        if ( empty( $docs ) ) {
            echo '<p>' . $missing_docs_message . '</p>';
            return;
        }

        echo '<div class="lavh-product-docs">';
        echo '<ul class="lavh-product-docs__list">';

        foreach ( $docs as $label => $url ) {
            printf(
                '<li class="lavh-product-docs__item"><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
                esc_url( $url ),
                esc_html( $label )
            );
        }

        echo '</ul>';

        echo '<p class="lavh-product-docs__missing">' . $missing_docs_message . '</p>';

        echo '</div>';
    }
}