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
 */
class PPB_Api {

    private const NAMESPACE = 'ppb/v1';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
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
}
