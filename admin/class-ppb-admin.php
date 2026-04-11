<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface admin PPB.
 *
 * - Éditeur de prix en masse (tous les produits + variations sur une page)
 * - AJAX bulk save
 * - Metabox sur la fiche produit individuelle
 */
class PPB_Admin {

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // AJAX bulk save.
        add_action( 'wp_ajax_ppb_bulk_save_prices', [ $this, 'ajax_bulk_save_prices' ] );

        // Metabox sur la fiche produit individuelle.
        add_action( 'add_meta_boxes',                           [ $this, 'add_product_metabox' ] );
        add_action( 'save_post_product',                        [ $this, 'save_product_metabox' ] );
        add_action( 'woocommerce_save_product_variation',       [ $this, 'save_variation_field' ], 10, 2 );
        add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_field' ], 10, 3 );
    }

    // -------------------------------------------------------------------------
    // Menu admin
    // -------------------------------------------------------------------------

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Prix partenaires', 'presellia-partner-bridge' ),
            __( 'Prix partenaires', 'presellia-partner-bridge' ),
            'manage_woocommerce',
            'ppb-prices',
            [ $this, 'render_bulk_editor' ]
        );
    }

    // -------------------------------------------------------------------------
    // Scripts admin
    // -------------------------------------------------------------------------

    public function enqueue_scripts( string $hook ): void {
        $allowed_hooks = [ 'woocommerce_page_ppb-prices', 'woocommerce_page_ppb-settings' ];

        if ( ! in_array( $hook, $allowed_hooks, true ) && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'ppb-admin',
            PPB_PLUGIN_URL . 'assets/js/ppb-admin.js',
            [ 'jquery' ],
            PPB_VERSION,
            true
        );

        wp_localize_script( 'ppb-admin', 'ppbAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ppb_admin_nonce' ),
            'i18n'    => [
                'saving'  => __( 'Enregistrement...', 'presellia-partner-bridge' ),
                'saved'   => __( 'Enregistré !', 'presellia-partner-bridge' ),
                'error'   => __( 'Erreur lors de la sauvegarde.', 'presellia-partner-bridge' ),
                'confirm' => __( 'Enregistrer tous les prix partenaires ?', 'presellia-partner-bridge' ),
            ],
        ] );

        wp_enqueue_style(
            'ppb-admin',
            PPB_PLUGIN_URL . 'assets/css/ppb-admin.css',
            [],
            PPB_VERSION
        );
    }

    // -------------------------------------------------------------------------
    // Éditeur de prix en masse
    // -------------------------------------------------------------------------

    public function render_bulk_editor(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'presellia-partner-bridge' ) );
        }

        $catalog = PPB_Pricing::get_catalog();

        ?>
        <div class="wrap ppb-bulk-editor">
            <h1><?php esc_html_e( 'Prix partenaires — Édition en masse', 'presellia-partner-bridge' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Les prix WC et promo sont affichés à titre informatif. Seul le champ "Prix partenaire" est modifiable.', 'presellia-partner-bridge' ); ?>
            </p>

            <div id="ppb-save-bar" class="ppb-save-bar">
                <button id="ppb-save-all" class="button button-primary button-large">
                    <?php esc_html_e( 'Enregistrer tout', 'presellia-partner-bridge' ); ?>
                </button>
                <span id="ppb-save-status"></span>
            </div>

            <table class="widefat striped ppb-price-table" id="ppb-price-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Produit / Variation', 'presellia-partner-bridge' ); ?></th>
                        <th><?php esc_html_e( 'SKU', 'presellia-partner-bridge' ); ?></th>
                        <th class="ppb-col-price"><?php esc_html_e( 'Prix WC', 'presellia-partner-bridge' ); ?></th>
                        <th class="ppb-col-price"><?php esc_html_e( 'Prix promo', 'presellia-partner-bridge' ); ?></th>
                        <th class="ppb-col-price ppb-col-partner"><?php esc_html_e( 'Prix partenaire', 'presellia-partner-bridge' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $catalog ) ) : ?>
                        <tr>
                            <td colspan="5">
                                <?php esc_html_e( 'Aucun produit publié trouvé.', 'presellia-partner-bridge' ); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $catalog as $product ) : ?>
                            <?php $this->render_product_row( $product ); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <div class="ppb-save-bar ppb-save-bar-bottom">
                <button class="button button-primary button-large ppb-save-all-btn">
                    <?php esc_html_e( 'Enregistrer tout', 'presellia-partner-bridge' ); ?>
                </button>
                <span class="ppb-save-status-bottom"></span>
            </div>
        </div>
        <?php
    }

    /**
     * Affiche une ligne produit (et ses variations si applicable).
     *
     * @param array<string, mixed> $product
     */
    private function render_product_row( array $product ): void {
        $has_variations = ! empty( $product['variations'] );
        $row_class      = $has_variations ? 'ppb-row-parent' : 'ppb-row-simple';

        ?>
        <tr class="<?php echo esc_attr( $row_class ); ?>">
            <td class="ppb-product-name">
                <strong><?php echo esc_html( $product['name'] ); ?></strong>
                <?php if ( $has_variations ) : ?>
                    <span class="ppb-badge-variable"><?php esc_html_e( 'Variable', 'presellia-partner-bridge' ); ?></span>
                <?php endif; ?>
            </td>
            <td><?php echo esc_html( $product['sku'] ); ?></td>
            <td class="ppb-col-price">
                <?php echo $product['regular_price'] !== null ? esc_html( wc_price( $product['regular_price'] ) ) : '—'; ?>
            </td>
            <td class="ppb-col-price">
                <?php echo $product['sale_price'] !== null ? esc_html( wc_price( $product['sale_price'] ) ) : '—'; ?>
            </td>
            <td class="ppb-col-partner">
                <?php if ( ! $has_variations ) : ?>
                    <input
                        type="number"
                        class="ppb-price-input"
                        data-product-id="<?php echo esc_attr( $product['id'] ); ?>"
                        value="<?php echo esc_attr( $product['partner_price'] ?? '' ); ?>"
                        step="1"
                        min="0"
                        placeholder="—"
                    >
                <?php else : ?>
                    <span class="ppb-see-variations"><?php esc_html_e( 'Voir variations ↓', 'presellia-partner-bridge' ); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        // Lignes de variations.
        if ( $has_variations ) {
            foreach ( $product['variations'] as $variation ) {
                ?>
                <tr class="ppb-row-variation">
                    <td class="ppb-variation-name">
                        &nbsp;&nbsp;&nbsp;↳ <?php echo esc_html( $variation['attributes'] ?? $variation['name'] ); ?>
                    </td>
                    <td><?php echo esc_html( $variation['sku'] ); ?></td>
                    <td class="ppb-col-price">
                        <?php echo $variation['regular_price'] !== null ? esc_html( wc_price( $variation['regular_price'] ) ) : '—'; ?>
                    </td>
                    <td class="ppb-col-price">
                        <?php echo $variation['sale_price'] !== null ? esc_html( wc_price( $variation['sale_price'] ) ) : '—'; ?>
                    </td>
                    <td class="ppb-col-partner">
                        <input
                            type="number"
                            class="ppb-price-input"
                            data-product-id="<?php echo esc_attr( $variation['id'] ); ?>"
                            value="<?php echo esc_attr( $variation['partner_price'] ?? '' ); ?>"
                            step="1"
                            min="0"
                            placeholder="—"
                        >
                    </td>
                </tr>
                <?php
            }
        }
    }

    // -------------------------------------------------------------------------
    // AJAX : sauvegarde en masse
    // -------------------------------------------------------------------------

    public function ajax_bulk_save_prices(): void {
        check_ajax_referer( 'ppb_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
        }

        $prices = isset( $_POST['prices'] ) ? (array) $_POST['prices'] : [];
        $saved  = 0;

        foreach ( $prices as $product_id => $price ) {
            $product_id = (int) $product_id;
            $price      = sanitize_text_field( wp_unslash( (string) $price ) );

            if ( $product_id <= 0 ) {
                continue;
            }

            PPB_Pricing::set_partner_price( $product_id, $price );
            $saved++;
        }

        PPB_Logger::info(
            'bulk_prices_saved',
            "Bulk save : {$saved} prix partenaires mis à jour",
            [ 'count' => $saved ]
        );

        wp_send_json_success( [
            'saved'   => $saved,
            'message' => sprintf(
                /* translators: %d = nombre de prix mis à jour */
                __( '%d prix mis à jour.', 'presellia-partner-bridge' ),
                $saved
            ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Metabox fiche produit individuelle
    // -------------------------------------------------------------------------

    public function add_product_metabox(): void {
        add_meta_box(
            'ppb_partner_price',
            __( 'Presellia Partner Bridge — Prix partenaire', 'presellia-partner-bridge' ),
            [ $this, 'render_product_metabox' ],
            'product',
            'side',
            'default'
        );
    }

    public function render_product_metabox( WP_Post $post ): void {
        wp_nonce_field( 'ppb_save_product_meta', 'ppb_product_nonce' );

        $product = wc_get_product( $post->ID );

        if ( ! $product || $product->is_type( 'variable' ) ) {
            echo '<p>' . esc_html__( 'Pour les produits variables, éditez le prix par variation ci-dessous dans l\'onglet Variations.', 'presellia-partner-bridge' ) . '</p>';
            return;
        }

        $partner_price = PPB_Pricing::get_partner_price( $post->ID );

        ?>
        <p>
            <label for="ppb_partner_price_input">
                <?php esc_html_e( 'Prix partenaire', 'presellia-partner-bridge' ); ?>
                (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)
            </label><br>
            <input
                type="number"
                id="ppb_partner_price_input"
                name="ppb_partner_price"
                value="<?php echo esc_attr( $partner_price ); ?>"
                step="1"
                min="0"
                style="width:100%"
                placeholder="<?php esc_attr_e( 'Laisser vide = prix public', 'presellia-partner-bridge' ); ?>"
            >
        </p>
        <?php
    }

    public function save_product_metabox( int $post_id ): void {
        if (
            ! isset( $_POST['ppb_product_nonce'] ) ||
            ! wp_verify_nonce( sanitize_key( $_POST['ppb_product_nonce'] ), 'ppb_save_product_meta' )
        ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $price = isset( $_POST['ppb_partner_price'] )
            ? sanitize_text_field( wp_unslash( $_POST['ppb_partner_price'] ) )
            : '';

        PPB_Pricing::set_partner_price( $post_id, $price );
    }

    // -------------------------------------------------------------------------
    // Champ prix partenaire sur les variations
    // -------------------------------------------------------------------------

    public function render_variation_field( int $loop, array $variation_data, WP_Post $variation ): void {
        $partner_price = PPB_Pricing::get_partner_price( $variation->ID );
        ?>
        <div class="form-row form-row-full ppb-variation-price-row">
            <label>
                <?php esc_html_e( 'Prix partenaire PPB', 'presellia-partner-bridge' ); ?>
                (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)
            </label>
            <input
                type="number"
                name="ppb_variation_price[<?php echo esc_attr( $loop ); ?>]"
                data-variation-id="<?php echo esc_attr( $variation->ID ); ?>"
                value="<?php echo esc_attr( $partner_price ); ?>"
                step="1"
                min="0"
                placeholder="<?php esc_attr_e( 'Laisser vide = prix public', 'presellia-partner-bridge' ); ?>"
                class="short ppb-variation-price-input"
            >
        </div>
        <?php
    }

    public function save_variation_field( int $variation_id, int $loop ): void {
        if ( ! isset( $_POST['ppb_variation_price'][ $loop ] ) ) {
            return;
        }

        $price = sanitize_text_field( wp_unslash( $_POST['ppb_variation_price'][ $loop ] ) );
        PPB_Pricing::set_partner_price( $variation_id, $price );
    }
}
