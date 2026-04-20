<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gère le rendu des pages portail partenaire et catalogue public.
 *
 * Shortcodes :
 *  - [ppb_portal]  → Espace Revendeur (protégé par mot de passe)
 *  - [ppb_catalog] → Catalogue public (accès libre, prix publics + stock)
 */
class PPB_Portal {

    public function __construct() {
        add_shortcode( 'ppb_portal',  [ $this, 'render_shortcode' ] );
        add_shortcode( 'ppb_catalog', [ $this, 'render_catalog_shortcode' ] );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // AJAX : chargement du catalogue partenaire.
        add_action( 'wp_ajax_nopriv_ppb_load_catalog', [ $this, 'ajax_load_catalog' ] );
        add_action( 'wp_ajax_ppb_load_catalog',        [ $this, 'ajax_load_catalog' ] );

        // AJAX : ajout au panier WC + redirection checkout.
        add_action( 'wp_ajax_nopriv_ppb_checkout', [ $this, 'ajax_checkout' ] );
        add_action( 'wp_ajax_ppb_checkout',        [ $this, 'ajax_checkout' ] );

        // AJAX : catalogue public (sans authentification).
        add_action( 'wp_ajax_nopriv_ppb_load_public_catalog', [ $this, 'ajax_load_public_catalog' ] );
        add_action( 'wp_ajax_ppb_load_public_catalog',        [ $this, 'ajax_load_public_catalog' ] );
    }

    // -------------------------------------------------------------------------
    // Assets front-end
    // -------------------------------------------------------------------------

    public function enqueue_scripts(): void {
        $portal_page_id  = (int) get_option( 'ppb_portal_page_id',  0 );
        $catalog_page_id = (int) get_option( 'ppb_catalog_page_id', 0 );

        $is_portal  = $portal_page_id  > 0 && is_page( $portal_page_id );
        $is_catalog = $catalog_page_id > 0 && is_page( $catalog_page_id );

        if ( ! $is_portal && ! $is_catalog ) {
            return;
        }

        wp_enqueue_style(
            'ppb-portal',
            PPB_PLUGIN_URL . 'assets/css/ppb-portal.css',
            [],
            PPB_VERSION
        );

        wp_enqueue_script(
            'ppb-portal',
            PPB_PLUGIN_URL . 'assets/js/ppb-portal.js',
            [ 'jquery' ],
            PPB_VERSION,
            true
        );

        // URL de partage (avec token) pour les utilisateurs déjà authentifiés.
        $share_url = '';
        if ( $is_portal && PPB_Auth::is_authenticated() && ! empty( $_COOKIE[ PPB_Auth::COOKIE_NAME ] ) ) {
            $raw_token = sanitize_text_field( wp_unslash( $_COOKIE[ PPB_Auth::COOKIE_NAME ] ) );
            $share_url = add_query_arg( 't', $raw_token, get_permalink( $portal_page_id ) );
        }

        wp_localize_script( 'ppb-portal', 'ppbPortal', [
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'nonce'            => wp_create_nonce( 'ppb_portal_nonce' ),
            'isAuth'           => ( $is_portal && PPB_Auth::is_authenticated() ) ? '1' : '0',
            'cookieName'       => PPB_Auth::COOKIE_NAME,
            'shareUrl'         => $share_url,
            'tokenTtlDays'     => (int) get_option( 'ppb_token_ttl', 30 ),
            'currency'         => get_woocommerce_currency_symbol(),
            'checkoutUrl'      => wc_get_checkout_url(),
            'tutorialVideoUrl' => $is_portal ? esc_url( get_option( 'ppb_tutorial_video_url', '' ) ) : '',
            'i18n'             => [
                'passwordPlaceholder' => __( 'Mot de passe partenaire', 'presellia-partner-bridge' ),
                'passwordSubmit'      => __( 'Accéder', 'presellia-partner-bridge' ),
                'wrongPassword'       => __( 'Mot de passe incorrect. Réessayez.', 'presellia-partner-bridge' ),
                'loading'             => __( 'Chargement du catalogue…', 'presellia-partner-bridge' ),
                'noProducts'          => __( 'Aucun produit disponible.', 'presellia-partner-bridge' ),
                'addToCart'           => __( 'Ajouter', 'presellia-partner-bridge' ),
                'removeFromCart'      => __( '×', 'presellia-partner-bridge' ),
                'checkout'            => __( 'Commander', 'presellia-partner-bridge' ),
                'cartEmpty'           => __( 'Votre sélection est vide.', 'presellia-partner-bridge' ),
                'total'               => __( 'Total :', 'presellia-partner-bridge' ),
                'qty'                 => __( 'Qté', 'presellia-partner-bridge' ),
                'partnerPrice'        => __( 'Prix partenaire', 'presellia-partner-bridge' ),
                'publicPrice'         => __( 'Prix public', 'presellia-partner-bridge' ),
                'checkingOut'         => __( 'Redirection…', 'presellia-partner-bridge' ),
                'shareLink'           => __( 'Copier le lien d\'accès rapide', 'presellia-partner-bridge' ),
                'linkCopied'          => __( 'Lien copié !', 'presellia-partner-bridge' ),
                'logout'              => __( 'Se déconnecter', 'presellia-partner-bridge' ),
                'noPartnerPrice'      => __( 'Prix non défini', 'presellia-partner-bridge' ),
                'stockIn'             => __( 'En stock', 'presellia-partner-bridge' ),
                'stockOut'            => __( 'Rupture', 'presellia-partner-bridge' ),
                'stockOrder'          => __( 'Sur commande', 'presellia-partner-bridge' ),
                'priceFrom'           => __( 'À partir de', 'presellia-partner-bridge' ),
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Shortcode [ppb_portal] — Espace Revendeur
    // -------------------------------------------------------------------------

    public function render_shortcode(): string {
        $title    = esc_html( get_option( 'ppb_portal_title', 'Portail Partenaire' ) );
        $logo_url = esc_url( get_option( 'ppb_portal_logo_url', '' ) );
        $has_pwd  = (bool) get_option( 'ppb_portal_password_hash', '' );

        ob_start();
        ?>
        <div id="ppb-portal" class="ppb-portal">

            <!-- En-tête du portail -->
            <div class="ppb-portal-header">
                <?php if ( $logo_url ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $title ); ?>" class="ppb-portal-logo">
                <?php endif; ?>
                <h1 class="ppb-portal-title"><?php echo esc_html( $title ); ?></h1>
            </div>

            <?php if ( ! $has_pwd ) : ?>
                <!-- Configuration manquante -->
                <div class="ppb-notice ppb-notice-error">
                    <?php esc_html_e( 'Le portail n\'est pas encore configuré. Veuillez définir un mot de passe dans les réglages PPB.', 'presellia-partner-bridge' ); ?>
                </div>
            <?php else : ?>

                <!-- Modale mot de passe (cachée si déjà authentifié) -->
                <div id="ppb-auth-modal" class="ppb-auth-modal <?php echo PPB_Auth::is_authenticated() ? 'ppb-hidden' : ''; ?>">
                    <div class="ppb-auth-card">
                        <p class="ppb-auth-intro">
                            <?php esc_html_e( 'Espace réservé aux revendeurs partenaires.', 'presellia-partner-bridge' ); ?>
                        </p>
                        <div class="ppb-auth-form">
                            <input
                                type="password"
                                id="ppb-password-input"
                                class="ppb-input"
                                autocomplete="current-password"
                                placeholder="<?php esc_attr_e( 'Mot de passe partenaire', 'presellia-partner-bridge' ); ?>"
                            >
                            <button id="ppb-password-submit" class="ppb-btn ppb-btn-primary">
                                <?php esc_html_e( 'Accéder', 'presellia-partner-bridge' ); ?>
                            </button>
                        </div>
                        <p id="ppb-auth-error" class="ppb-auth-error ppb-hidden"></p>
                        <?php
                        $access_url = get_option( 'ppb_access_request_url', '' );
                        if ( $access_url ) :
                        ?>
                        <p class="ppb-auth-request">
                            <?php esc_html_e( 'Pas encore partenaire ?', 'presellia-partner-bridge' ); ?>
                            <a href="<?php echo esc_url( $access_url ); ?>">
                                <?php esc_html_e( 'Demander l\'accès', 'presellia-partner-bridge' ); ?>
                            </a>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Contenu du portail (visible une fois authentifié) -->
                <div id="ppb-content" class="<?php echo PPB_Auth::is_authenticated() ? '' : 'ppb-hidden'; ?>">

                    <!-- Barre d'actions -->
                    <div class="ppb-toolbar">
                        <input type="text" id="ppb-search" class="ppb-input ppb-search" placeholder="<?php esc_attr_e( 'Rechercher un produit…', 'presellia-partner-bridge' ); ?>">
                        <div class="ppb-toolbar-actions">
                            <button id="ppb-copy-link" class="ppb-btn ppb-btn-ghost" title="<?php esc_attr_e( 'Copier le lien d\'accès rapide', 'presellia-partner-bridge' ); ?>">
                                🔗 <?php esc_html_e( 'Partager l\'accès', 'presellia-partner-bridge' ); ?>
                            </button>
                            <button id="ppb-logout" class="ppb-btn ppb-btn-ghost">
                                <?php esc_html_e( 'Se déconnecter', 'presellia-partner-bridge' ); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Catalogue (chargé via AJAX) -->
                    <div id="ppb-catalog-wrapper">
                        <div id="ppb-catalog-loading" class="ppb-loading">
                            <span class="ppb-spinner"></span>
                            <?php esc_html_e( 'Chargement du catalogue…', 'presellia-partner-bridge' ); ?>
                        </div>
                        <table id="ppb-catalog-table" class="ppb-catalog-table ppb-hidden">
                            <thead>
                                <tr>
                                    <th class="ppb-col-thumb"></th>
                                    <th><?php esc_html_e( 'Produit', 'presellia-partner-bridge' ); ?></th>
                                    <th class="ppb-col-num ppb-col-partner"><?php esc_html_e( 'Prix partenaire', 'presellia-partner-bridge' ); ?></th>
                                    <th class="ppb-col-num"><?php esc_html_e( 'Qté', 'presellia-partner-bridge' ); ?></th>
                                    <th class="ppb-col-action"></th>
                                </tr>
                            </thead>
                            <tbody id="ppb-catalog-body"></tbody>
                        </table>
                        <p id="ppb-catalog-empty" class="ppb-empty-msg ppb-hidden">
                            <?php esc_html_e( 'Aucun produit disponible.', 'presellia-partner-bridge' ); ?>
                        </p>
                    </div>

                    <!-- Panneau détail de la sélection (slide-up au-dessus de la barre) -->
                    <div id="ppb-cart-panel" class="ppb-cart-panel">
                        <div class="ppb-cart-panel-header">
                            <span class="ppb-cart-panel-title"><?php esc_html_e( 'Ma sélection', 'presellia-partner-bridge' ); ?></span>
                            <button id="ppb-cart-panel-close" class="ppb-cart-panel-close" title="<?php esc_attr_e( 'Fermer', 'presellia-partner-bridge' ); ?>">×</button>
                        </div>
                        <div class="ppb-cart-panel-body">
                            <table class="ppb-cart-table">
                                <tbody id="ppb-cart-body"></tbody>
                            </table>
                        </div>
                    </div>

                </div><!-- #ppb-content -->

            <?php endif; ?>

        </div><!-- #ppb-portal -->

        <!-- Barre panier flottante (fixée en bas du viewport) -->
        <div id="ppb-cart-bar" class="ppb-cart-bar ppb-hidden">
            <button id="ppb-cart-bar-toggle" class="ppb-cart-bar-summary" title="<?php esc_attr_e( 'Voir la sélection', 'presellia-partner-bridge' ); ?>">
                <span class="ppb-cart-bar-icon">🛒</span>
                <strong id="ppb-cart-bar-label"></strong>
                <span class="ppb-cart-bar-sep">·</span>
                <span id="ppb-cart-bar-total"></span>
            </button>
            <button id="ppb-checkout-btn" class="ppb-btn ppb-btn-primary">
                <?php esc_html_e( 'Commander', 'presellia-partner-bridge' ); ?> →
            </button>
        </div>
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Shortcode [ppb_catalog] — Catalogue public
    // -------------------------------------------------------------------------

    public function render_catalog_shortcode(): string {
        ob_start();
        ?>
        <div id="ppb-catalog" class="ppb-portal">

            <div class="ppb-toolbar">
                <input type="text" id="ppb-public-search" class="ppb-input ppb-search"
                    placeholder="<?php esc_attr_e( 'Rechercher un produit…', 'presellia-partner-bridge' ); ?>">
            </div>

            <div id="ppb-public-catalog-wrapper">
                <div id="ppb-public-loading" class="ppb-loading">
                    <span class="ppb-spinner"></span>
                    <?php esc_html_e( 'Chargement du catalogue…', 'presellia-partner-bridge' ); ?>
                </div>
                <table id="ppb-public-table" class="ppb-catalog-table ppb-hidden">
                    <thead>
                        <tr>
                            <th class="ppb-col-thumb"></th>
                            <th><?php esc_html_e( 'Produit', 'presellia-partner-bridge' ); ?></th>
                            <th class="ppb-col-num"><?php esc_html_e( 'Prix public', 'presellia-partner-bridge' ); ?></th>
                            <th class="ppb-col-stock"><?php esc_html_e( 'Stock', 'presellia-partner-bridge' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="ppb-public-body"></tbody>
                </table>
                <p id="ppb-public-empty" class="ppb-empty-msg ppb-hidden">
                    <?php esc_html_e( 'Aucun produit disponible.', 'presellia-partner-bridge' ); ?>
                </p>
            </div>

        </div><!-- #ppb-catalog -->
        <?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // AJAX : chargement du catalogue partenaire
    // -------------------------------------------------------------------------

    public function ajax_load_catalog(): void {
        check_ajax_referer( 'ppb_portal_nonce', 'nonce' );

        if ( ! PPB_Auth::is_authenticated() ) {
            wp_send_json_error( [ 'message' => 'Non authentifié.' ], 403 );
        }

        $catalog = PPB_Pricing::get_catalog();

        PPB_Logger::info( 'catalog_loaded', 'Catalogue chargé', [ 'products' => count( $catalog ) ] );

        wp_send_json_success( [ 'catalog' => $catalog ] );
    }

    // -------------------------------------------------------------------------
    // AJAX : checkout — ajoute les items au panier WC et retourne l'URL
    // -------------------------------------------------------------------------

    public function ajax_checkout(): void {
        check_ajax_referer( 'ppb_portal_nonce', 'nonce' );

        if ( ! PPB_Auth::is_authenticated() ) {
            wp_send_json_error( [ 'message' => 'Non authentifié.' ], 403 );
        }

        $items = isset( $_POST['items'] ) ? (array) $_POST['items'] : [];

        if ( empty( $items ) ) {
            wp_send_json_error( [ 'message' => __( 'Sélection vide.', 'presellia-partner-bridge' ) ] );
        }

        // Vide le panier WC courant.
        WC()->cart->empty_cart();

        $added = 0;

        foreach ( $items as $item ) {
            $product_id   = isset( $item['product_id'] )   ? absint( $item['product_id'] )   : 0;
            $variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
            $quantity     = isset( $item['quantity'] )     ? absint( $item['quantity'] )     : 1;

            if ( $product_id <= 0 || $quantity <= 0 ) {
                continue;
            }

            $result = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );

            if ( $result ) {
                $added++;
            }
        }

        if ( 0 === $added ) {
            wp_send_json_error( [ 'message' => __( 'Impossible d\'ajouter les produits au panier.', 'presellia-partner-bridge' ) ] );
        }

        // Les prix partenaires sont appliqués automatiquement par PPB_Pricing::apply_partner_prices()
        // car le cookie PPB est actif sur ce domaine.

        PPB_Logger::info(
            'checkout_initiated',
            "Checkout initié : {$added} article(s)",
            [ 'items_count' => $added, 'ip' => PPB_Auth::get_ip() ]
        );

        wp_send_json_success( [
            'checkout_url' => wc_get_checkout_url(),
            'added'        => $added,
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX : catalogue public (sans authentification)
    // -------------------------------------------------------------------------

    public function ajax_load_public_catalog(): void {
        check_ajax_referer( 'ppb_portal_nonce', 'nonce' );

        $catalog = PPB_Pricing::get_catalog();

        wp_send_json_success( [ 'catalog' => $catalog ] );
    }
}
