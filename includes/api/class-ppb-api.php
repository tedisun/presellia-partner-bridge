<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * API REST MCP — Presellia Partner Bridge
 *
 * Namespace : ppb/v1
 * Authentification : header X-PPB-API-Key (comparé en SHA256 à l'option ppb_api_key)
 *
 * Endpoints :
 *  GET  /ppb/v1/status              → santé du plugin, compteurs
 *  GET  /ppb/v1/logs                → derniers logs (param: limit, level, event)
 *  POST /ppb/v1/logs/clear          → vide les logs
 *  GET  /ppb/v1/products            → catalogue avec prix partenaires
 *  POST /ppb/v1/products/{id}/price → met à jour le prix partenaire d'un produit
 *  GET  /ppb/v1/tokens              → nombre de tokens actifs
 *  POST /ppb/v1/tokens/revoke-all   → révoque tous les tokens
 *  POST /ppb/v1/cache/clear         → vide le cache du catalogue (ppb_catalog_cache)
 *  POST /ppb/v1/portal/login        → authentifie un mot de passe portail, sans nonce (SPA headless)
 *  GET  /ppb/v1/portal/catalog      → catalogue avec prix partenaires, protégé par jeton (pas par clé API)
 *  POST /ppb/v1/portal/registration → demande d'accès portail (publique, limitée en débit) — file consultée dans Réglages PPB
 *  GET  /ppb/v1/portal/orders       → historique de commandes par email, protégé par jeton
 *  GET  /ppb/v1/portal/product/{id}/description → description produit (texte brut), protégé par jeton
 *
 * CORS : les routes /portal/* renvoient des headers Access-Control-Allow-Origin
 * restreints à l'origine configurée dans ppb_portal_cors_origin (réglages PPB).
 */
class PPB_Api {

    private const NAMESPACE = 'ppb/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_filter( 'rest_pre_serve_request', [ $this, 'add_cors_headers' ], 10, 4 );
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );

        register_rest_route( self::NAMESPACE, '/logs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_logs' ],
            'permission_callback' => [ $this, 'check_api_key' ],
            'args'                => [
                'limit' => [
                    'type'              => 'integer',
                    'default'           => 50,
                    'minimum'           => 1,
                    'maximum'           => 500,
                    'sanitize_callback' => 'absint',
                ],
                'level' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_key',
                    'enum'              => [ '', 'info', 'warning', 'error' ],
                ],
                'event' => [
                    'type'              => 'string',
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/logs/clear', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'clear_logs' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );

        register_rest_route( self::NAMESPACE, '/cache/clear', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'clear_catalog_cache' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );

        register_rest_route( self::NAMESPACE, '/products', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_products' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );

        register_rest_route( self::NAMESPACE, '/products/(?P<id>\d+)/price', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'update_product_price' ],
            'permission_callback' => [ $this, 'check_api_key' ],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
                'partner_price' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/tokens', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_tokens_info' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );

        register_rest_route( self::NAMESPACE, '/tokens/revoke-all', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'revoke_all_tokens' ],
            'permission_callback' => [ $this, 'check_api_key' ],
        ] );

        register_rest_route( self::NAMESPACE, '/checkout/prepare', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'prepare_checkout_session' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'partner_token' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'items' => [
                    'type'              => 'array',
                    'required'          => true,
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/portal/login', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'portal_login' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'password' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/portal/catalog', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'portal_catalog' ],
            'permission_callback' => [ $this, 'check_portal_token' ],
            'args'                => [
                'token' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/portal/registration', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'submit_registration' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'full_name' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'whatsapp' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'email' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                ],
                'business' => [
                    'type'              => 'string',
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'message' => [
                    'type'              => 'string',
                    'required'          => false,
                    'default'           => '',
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/portal/orders', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'portal_orders' ],
            'permission_callback' => [ $this, 'check_portal_token' ],
            'args'                => [
                'token' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'email' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_email',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/portal/product/(?P<id>\d+)/description', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'portal_product_description' ],
            'permission_callback' => [ $this, 'check_portal_token' ],
            'args'                => [
                'id' => [
                    'type'              => 'integer',
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                ],
                'token' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Authentification
    // -------------------------------------------------------------------------

    public function check_api_key( WP_REST_Request $request ): bool|WP_Error {
        $stored_key = get_option( 'ppb_api_key', '' );

        if ( empty( $stored_key ) ) {
            return new WP_Error(
                'ppb_no_api_key',
                __( 'Aucune clé API configurée. Générez une clé dans les réglages PPB.', 'presellia-partner-bridge' ),
                [ 'status' => 503 ]
            );
        }

        $provided_key = $request->get_header( 'X-PPB-API-Key' );

        if ( empty( $provided_key ) ) {
            return new WP_Error(
                'ppb_missing_key',
                __( 'Header X-PPB-API-Key manquant.', 'presellia-partner-bridge' ),
                [ 'status' => 401 ]
            );
        }

        // Comparaison en temps constant pour éviter les timing attacks.
        if ( ! hash_equals( hash( 'sha256', $stored_key ), hash( 'sha256', $provided_key ) ) ) {
            PPB_Logger::warning( 'api_auth_failed', 'Tentative d\'accès API avec une clé invalide', [ 'ip' => PPB_Auth::get_ip() ] );

            return new WP_Error(
                'ppb_invalid_key',
                __( 'Clé API invalide.', 'presellia-partner-bridge' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Permission callback pour /portal/catalog : exige un jeton partenaire
     * valide (pas la clé API admin) puisque cet endpoint expose les prix
     * partenaires à quiconque connaît un jeton de session portail.
     */
    public function check_portal_token( WP_REST_Request $request ): bool|WP_Error {
        $token = sanitize_text_field( (string) $request->get_param( 'token' ) );

        if ( ! PPB_Auth::is_valid_token( $token ) ) {
            return new WP_Error(
                'ppb_unauthorized',
                __( 'Jeton partenaire invalide ou expiré.', 'presellia-partner-bridge' ),
                [ 'status' => 401 ]
            );
        }

        return true;
    }

    /**
     * Ajoute les headers CORS sur les routes /portal/* uniquement, restreints
     * à l'origine configurée en admin (jamais '*' — ces routes exposent des
     * prix partenaires). Sans origine configurée, aucun header n'est ajouté :
     * le navigateur bloquera par défaut les appels cross-origin.
     */
    public function add_cors_headers( $served, $result, $request, $server ) {
        $route = $request->get_route();

        if ( ! str_starts_with( $route, '/' . self::NAMESPACE . '/portal/' ) ) {
            return $served;
        }

        $allowed_origin = get_option( 'ppb_portal_cors_origin', '' );
        $request_origin = $request->get_header( 'origin' );

        if ( $allowed_origin && $request_origin && $request_origin === $allowed_origin ) {
            header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $allowed_origin ) );
            header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
            header( 'Access-Control-Allow-Headers: Content-Type' );
            header( 'Vary: Origin' );
        }

        return $served;
    }

    // -------------------------------------------------------------------------
    // GET /status
    // -------------------------------------------------------------------------

    public function get_status(): WP_REST_Response {
        $log_counts = PPB_Logger::get_counts( 7 );

        return new WP_REST_Response( [
            'plugin_version'  => PPB_VERSION,
            'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
            'wp_version'      => get_bloginfo( 'version' ),
            'portal_page_id'  => (int) get_option( 'ppb_portal_page_id', 0 ),
            'portal_url'      => get_permalink( (int) get_option( 'ppb_portal_page_id', 0 ) ) ?: null,
            'has_password'    => (bool) get_option( 'ppb_portal_password_hash', '' ),
            'token_ttl_days'  => (int) get_option( 'ppb_token_ttl', 30 ),
            'active_tokens'   => PPB_Auth::count_active_tokens(),
            'logs_7d'         => $log_counts,
            'timestamp'       => current_time( 'c' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // GET /logs
    // -------------------------------------------------------------------------

    public function get_logs( WP_REST_Request $request ): WP_REST_Response {
        $limit = $request->get_param( 'limit' );
        $level = $request->get_param( 'level' );
        $event = $request->get_param( 'event' );

        $logs = PPB_Logger::get_recent( $limit, $level, $event );

        return new WP_REST_Response( [
            'count' => count( $logs ),
            'logs'  => $logs,
        ] );
    }

    // -------------------------------------------------------------------------
    // POST /logs/clear
    // -------------------------------------------------------------------------

    public function clear_logs(): WP_REST_Response {
        PPB_Logger::clear_all();

        return new WP_REST_Response( [
            'success' => true,
            'message' => __( 'Logs vidés.', 'presellia-partner-bridge' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // POST /cache/clear
    // -------------------------------------------------------------------------

    public function clear_catalog_cache(): WP_REST_Response {
        PPB_Pricing::clear_cache();

        PPB_Logger::info( 'api_cache_cleared', 'Cache du catalogue vidé manuellement via API', [] );

        return new WP_REST_Response( [
            'success' => true,
            'message' => __( 'Cache du catalogue vidé.', 'presellia-partner-bridge' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // GET /products
    // -------------------------------------------------------------------------

    public function get_products(): WP_REST_Response {
        $catalog = PPB_Pricing::get_catalog();

        return new WP_REST_Response( [
            'count'    => count( $catalog ),
            'products' => $catalog,
        ] );
    }

    // -------------------------------------------------------------------------
    // POST /products/{id}/price
    // -------------------------------------------------------------------------

    public function update_product_price( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $product_id    = (int) $request->get_param( 'id' );
        $partner_price = $request->get_param( 'partner_price' );

        $product = wc_get_product( $product_id );

        if ( ! $product ) {
            return new WP_Error(
                'ppb_product_not_found',
                __( 'Produit introuvable.', 'presellia-partner-bridge' ),
                [ 'status' => 404 ]
            );
        }

        PPB_Pricing::set_partner_price( $product_id, $partner_price );

        $new_price = PPB_Pricing::get_partner_price( $product_id );

        PPB_Logger::info(
            'api_price_updated',
            "Prix partenaire mis à jour via API pour le produit #{$product_id}",
            [ 'product_id' => $product_id, 'new_price' => $new_price ]
        );

        return new WP_REST_Response( [
            'success'       => true,
            'product_id'    => $product_id,
            'product_name'  => $product->get_name(),
            'partner_price' => $new_price !== '' ? (float) $new_price : null,
        ] );
    }

    // -------------------------------------------------------------------------
    // GET /tokens
    // -------------------------------------------------------------------------

    public function get_tokens_info(): WP_REST_Response {
        return new WP_REST_Response( [
            'active_tokens' => PPB_Auth::count_active_tokens(),
            'token_ttl_days' => (int) get_option( 'ppb_token_ttl', 30 ),
        ] );
    }

    // -------------------------------------------------------------------------
    // POST /tokens/revoke-all
    // -------------------------------------------------------------------------

    public function revoke_all_tokens(): WP_REST_Response {
        $count = PPB_Auth::revoke_all_tokens();

        return new WP_REST_Response( [
            'success'        => true,
            'revoked_count'  => $count,
            'message'        => sprintf(
                /* translators: %d = nombre de tokens révoqués */
                __( '%d token(s) révoqué(s).', 'presellia-partner-bridge' ),
                $count
            ),
        ] );
    }

    // -------------------------------------------------------------------------
    // POST /checkout/prepare
    // -------------------------------------------------------------------------

    public function prepare_checkout_session( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $token = sanitize_text_field( $request->get_param( 'partner_token' ) );
        $items = $request->get_param( 'items' );

        if ( ! PPB_Auth::is_valid_token( $token ) ) {
            return new WP_Error(
                'ppb_unauthorized',
                __( 'Jeton partenaire invalide ou expiré.', 'presellia-partner-bridge' ),
                [ 'status' => 401 ]
            );
        }

        if ( empty( $items ) || ! is_array( $items ) ) {
            return new WP_Error(
                'ppb_empty_cart',
                __( 'Le panier envoyé est vide ou invalide.', 'presellia-partner-bridge' ),
                [ 'status' => 400 ]
            );
        }

        $clean_items = [];
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            // Gère les deux formats : id/qty et product_id/quantity
            $product_id   = isset( $item['product_id'] )   ? absint( $item['product_id'] )   : ( isset( $item['id'] ) ? absint( $item['id'] ) : 0 );
            $variation_id = isset( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
            $quantity     = isset( $item['quantity'] )     ? absint( $item['quantity'] )     : ( isset( $item['qty'] ) ? absint( $item['qty'] ) : 1 );

            if ( $product_id > 0 && $quantity > 0 ) {
                $clean_items[] = [
                    'product_id'   => $product_id,
                    'variation_id' => $variation_id,
                    'quantity'     => $quantity,
                ];
            }
        }

        if ( empty( $clean_items ) ) {
            return new WP_Error(
                'ppb_invalid_items',
                __( 'Aucun produit valide trouvé dans le panier.', 'presellia-partner-bridge' ),
                [ 'status' => 400 ]
            );
        }

        $transfer_id = wp_generate_password( 32, false );
        $transient_key = 'ppb_xfer_' . md5( $transfer_id );

        $session_data = [
            'partner_token' => $token,
            'items'         => $clean_items,
            'created_at'    => time(),
        ];

        set_transient( $transient_key, $session_data, 15 * MINUTE_IN_SECONDS );

        $redirect_url = add_query_arg( 'sid', $transfer_id, home_url( '/ppb-transfer' ) );

        return new WP_REST_Response( [
            'success'      => true,
            'redirect_url' => $redirect_url,
        ] );
    }

    // -------------------------------------------------------------------------
    // POST /portal/login
    // -------------------------------------------------------------------------

    public function portal_login( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $password = (string) $request->get_param( 'password' );

        $token = PPB_Auth::authenticate_portal( $password );

        if ( false === $token ) {
            return new WP_Error(
                'ppb_invalid_password',
                __( 'Mot de passe incorrect.', 'presellia-partner-bridge' ),
                [ 'status' => 403 ]
            );
        }

        return new WP_REST_Response( [
            'token'        => $token,
            'expires_days' => (int) get_option( 'ppb_token_ttl', 30 ),
        ] );
    }

    // -------------------------------------------------------------------------
    // GET /portal/catalog
    // -------------------------------------------------------------------------

    public function portal_catalog(): WP_REST_Response {
        $catalog = PPB_Pricing::get_catalog();

        return new WP_REST_Response( [
            'count'    => count( $catalog ),
            'products' => $catalog,
        ] );
    }

    // -------------------------------------------------------------------------
    // POST /portal/registration
    // -------------------------------------------------------------------------

    /**
     * Reçoit une demande d'accès au portail (formulaire public, sans jeton).
     * N'accorde aucun accès automatiquement — alimente la file consultée dans
     * WooCommerce > PPB Réglages > Demandes d'accès, traitée manuellement par
     * l'équipe (le mot de passe reste partagé, jamais envoyé automatiquement).
     */
    public function submit_registration( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        if ( PPB_Rate_Limiter::is_limited( 'registration', 5, 15 * MINUTE_IN_SECONDS ) ) {
            return new WP_Error(
                'ppb_rate_limited',
                __( 'Trop de demandes envoyées depuis cette adresse. Réessayez plus tard.', 'presellia-partner-bridge' ),
                [ 'status' => 429 ]
            );
        }

        PPB_Rate_Limiter::register_attempt( 'registration', 15 * MINUTE_IN_SECONDS );

        $id = PPB_Requests::create( [
            'full_name' => (string) $request->get_param( 'full_name' ),
            'whatsapp'  => (string) $request->get_param( 'whatsapp' ),
            'email'     => (string) $request->get_param( 'email' ),
            'business'  => (string) $request->get_param( 'business' ),
            'message'   => (string) $request->get_param( 'message' ),
        ] );

        PPB_Logger::info(
            'registration_submitted',
            "Nouvelle demande d'accès portail reçue (#{$id})",
            [ 'ip' => PPB_Auth::get_ip() ]
        );

        return new WP_REST_Response( [ 'success' => true ] );
    }

    // -------------------------------------------------------------------------
    // GET /portal/orders
    // -------------------------------------------------------------------------

    /**
     * Historique de commandes en libre-service par email, gardé par jeton
     * partenaire (pas d'accès public) — évite le risque d'énumération d'emails
     * sans avoir besoin d'un rate-limiting dédié, puisqu'il faut déjà connaître
     * le mot de passe partagé pour obtenir un jeton valide.
     */
    public function portal_orders( WP_REST_Request $request ): WP_REST_Response {
        $email = (string) $request->get_param( 'email' );

        $orders = wc_get_orders( [
            'billing_email' => $email,
            'limit'          => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $summaries = [];
        foreach ( $orders as $order ) {
            $date          = $order->get_date_created();
            $summaries[] = [
                'id'     => $order->get_id(),
                'date'   => $date ? $date->date( 'Y-m-d' ) : '',
                'status' => $order->get_status(),
                'total'  => (float) $order->get_total(),
            ];
        }

        return new WP_REST_Response( [
            'count'  => count( $summaries ),
            'orders' => $summaries,
        ] );
    }

    // -------------------------------------------------------------------------
    // GET /portal/product/{id}/description
    // -------------------------------------------------------------------------

    public function portal_product_description( WP_REST_Request $request ): WP_REST_Response|WP_Error {
        $product_id = (int) $request->get_param( 'id' );
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            return new WP_Error(
                'ppb_product_not_found',
                __( 'Produit introuvable.', 'presellia-partner-bridge' ),
                [ 'status' => 404 ]
            );
        }

        $description = trim( wp_strip_all_tags( $product->get_description() ) );

        if ( '' === $description ) {
            $description = trim( wp_strip_all_tags( $product->get_short_description() ) );
        }

        if ( '' === $description ) {
            $description = __( 'Aucune description disponible pour ce produit pour le moment.', 'presellia-partner-bridge' );
        }

        return new WP_REST_Response( [
            'description' => $description,
        ] );
    }
}
