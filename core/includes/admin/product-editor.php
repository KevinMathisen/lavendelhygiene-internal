<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Admin: Modify lh product values  ---------- */

class LavendelHygiene_ProductMetaEditor {
    // Product meta keys
    const META_VOLUME_NOTICE  = 'show_volume_pricing_notice';
    const META_DOCS_JSON      = '_docs';

    public function __construct() {
        add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'render_panel' ] );

        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_fields' ] );

        add_action( 'admin_footer', [ $this, 'inline_admin_js' ] );
    }

    public function add_tab( $tabs ) {
        $tabs['lavh'] = [
            'label'    => __( 'Lavendel Hygiene', 'lavendelhygiene' ),
            'target'   => 'lavh_product_data',
            'class'    => [],
            'priority' => 80,
        ];
        return $tabs;
    }

    public function render_panel() {
        global $post;

        if ( ! $post || $post->post_type !== 'product' ) {
            return;
        }

        $product_id = (int) $post->ID;

        $vol_flag = (string) get_post_meta( $product_id, self::META_VOLUME_NOTICE, true );
        $docs_raw = (string) get_post_meta( $product_id, self::META_DOCS_JSON, true );

        $docs = [];
        if ( $docs_raw !== '' ) {
            $decoded = json_decode( $docs_raw, true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $label => $url ) {
                    $label = trim( (string) $label );
                    $url   = trim( (string) $url );
                    if ( $label !== '' && $url !== '' ) {
                        $docs[] = [ 'label' => $label, 'url' => $url ];
                    }
                }
            }
        }

        ?>
        <div id="lavh_product_data" class="panel woocommerce_options_panel hidden">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox( [
                    'id'          => self::META_VOLUME_NOTICE,
                    'label'       => __( 'Show volume pricing notice', 'lavendelhygiene' ),
                    'description' => __( 'Show the volume/terms notice on the product page when prices are visible.', 'lavendelhygiene' ),
                    'value'       => 'yes',
                    'cbvalue'     => 'yes',
                    'desc_tip'    => false,
                ] );
                ?>
            </div>

            <div class="options_group">
                <p class="form-field">
                    <label><?php esc_html_e( 'Documents (Relaterte dokumenter)', 'lavendelhygiene' ); ?></label>
                    <span class="description">
                        <?php esc_html_e( 'Add rows of Title + URL. Saved to _docs as JSON { "Title": "URL" }', 'lavendelhygiene' ); ?>
                    </span>
                </p>

                <table class="widefat striped" style="margin: 0 12px 12px; width: calc(100% - 24px);">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Title', 'lavendelhygiene' ); ?></th>
                            <th><?php esc_html_e( 'URL', 'lavendelhygiene' ); ?></th>
                            <th style="width:90px;"><?php esc_html_e( 'Remove', 'lavendelhygiene' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="lavh-docs-rows">
                        <?php if ( empty( $docs ) ) : ?>
                            <tr class="lavh-docs-row">
                                <td><input type="text" name="lavh_docs_label[]" value="" class="regular-text" /></td>
                                <td><input type="url"  name="lavh_docs_url[]" value="" class="regular-text" placeholder="https://..." /></td>
                                <td><button type="button" class="button lavh-docs-remove"><?php esc_html_e( 'Remove', 'lavendelhygiene' ); ?></button></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $docs as $row ) : ?>
                                <tr class="lavh-docs-row">
                                    <td><input type="text" name="lavh_docs_label[]" value="<?php echo esc_attr( $row['label'] ); ?>" class="regular-text" /></td>
                                    <td><input type="url"  name="lavh_docs_url[]" value="<?php echo esc_attr( $row['url'] ); ?>" class="regular-text" /></td>
                                    <td><button type="button" class="button lavh-docs-remove"><?php esc_html_e( 'Remove', 'lavendelhygiene' ); ?></button></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p style="margin: 0 12px 12px;">
                    <button type="button" class="button" id="lavh-docs-add"><?php esc_html_e( 'Add document', 'lavendelhygiene' ); ?></button>
                </p>
            </div>

            <?php wp_nonce_field( 'lavh_product_meta_save', 'lavh_product_meta_nonce' ); ?>
        </div>
        <?php
    }

    public function save_product_fields( WC_Product $product ) {
        if ( ! isset( $_POST['lavh_product_meta_nonce'] ) || ! wp_verify_nonce( $_POST['lavh_product_meta_nonce'], 'lavh_product_meta_save' ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $product->get_id() ) ) {
            return;
        }

        // volume notice checkbox (exactly yes/no)
        $vol = isset( $_POST[ self::META_VOLUME_NOTICE ] ) ? 'yes' : 'no';
        $product->update_meta_data( self::META_VOLUME_NOTICE, $vol );

        // docs rows => json object {label: url}
        $labels = isset( $_POST['lavh_docs_label'] ) ? (array) $_POST['lavh_docs_label'] : [];
        $urls   = isset( $_POST['lavh_docs_url'] ) ? (array) $_POST['lavh_docs_url'] : [];

        $docs_map = [];
        $count = max( count( $labels ), count( $urls ) );

        for ( $i = 0; $i < $count; $i++ ) {
            $label = isset( $labels[ $i ] ) ? sanitize_text_field( wp_unslash( $labels[ $i ] ) ) : '';
            $url   = isset( $urls[ $i ] ) ? esc_url_raw( wp_unslash( $urls[ $i ] ) ) : '';

            $label = trim( (string) $label );
            $url   = trim( (string) $url );

            if ( $label === '' || $url === '' ) {
                continue;
            }

            $docs_map[ $label ] = $url;
        }

        if ( empty( $docs_map ) ) {
            $product->delete_meta_data( self::META_DOCS_JSON );
        } else {
            $product->update_meta_data( self::META_DOCS_JSON, wp_json_encode( $docs_map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
        }
    }

    public function inline_admin_js() {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'post' || $screen->post_type !== 'product' ) {
            return;
        }
        ?>
        <script>
        (function(){
            function on(el, ev, cb){ if(el) el.addEventListener(ev, cb); }

            var addBtn = document.getElementById('lavh-docs-add');
            var tbody  = document.getElementById('lavh-docs-rows');

            function bindRemoveButtons(root){
                (root || document).querySelectorAll('.lavh-docs-remove').forEach(function(btn){
                    if (btn.dataset.bound) return;
                    btn.dataset.bound = '1';
                    btn.addEventListener('click', function(){
                        var tr = btn.closest('tr');
                        if (tr) tr.remove();
                    });
                });
            }

            on(addBtn, 'click', function(){
                if (!tbody) return;
                var tr = document.createElement('tr');
                tr.className = 'lavh-docs-row';
                tr.innerHTML = ''
                    + '<td><input type="text" name="lavh_docs_label[]" value="" class="regular-text" /></td>'
                    + '<td><input type="url" name="lavh_docs_url[]" value="" class="regular-text" placeholder="https://..." /></td>'
                    + '<td><button type="button" class="button lavh-docs-remove">Remove</button></td>';
                tbody.appendChild(tr);
                bindRemoveButtons(tr);
            });

            bindRemoveButtons(document);
        })();
        </script>
        <?php
    }
}