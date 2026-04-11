<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gère l'authentification par mot de passe → token → cookie.
 *
 * Flux :
 *  1. Première visite → modale mot de passe
 *  2. AJAX ppb_validate_password → valide mdp → génère token → retourne au JS
 *  3. JS pose le cookie ppb_token (TTL configurable, défaut 30 jours)
 *  4. Visites suivantes → hook init lit le cookie → valide le token → set flag statique
 *  5. ?t=TOKEN dans l'URL → auto-authentification (partage WhatsApp)
 */
class PPB_Auth {

    /** Flag statique : true si le visiteur est authentifié pour cette requête. */
    private static bool $authenticated = false;

    /** Nom du cookie. */
    public const COOKIE_NAME = 'ppb_token';

    /** Préfixe des transients de tokens. */
    private const TRANSIENT_PREFIX = 'ppb_tok_';

    public function __construct() {
        add_action( 'init',          [ $this, 'check_authentication' ], 1 );
        add_action( 'wp_ajax_nopriv_ppb_validate_password', [ $this, 'ajax_validate_password' ] );
        add_action( 'wp_ajax_ppb_validate_password',        [ $this, 'ajax_validate_password' ] );
        add_action( 'wp_ajax_nopriv_ppb_revoke_token',      [ $this, 'ajax_revoke_token' ] );
        add_action( 'wp_ajax_ppb_revoke_token',             [ $this, 'ajax_revoke_token' ] );
    }

    // -------------------------------------------------------------------------
    // Vérification de l'authentification à chaque requête
    // -------------------------------------------------------------------------

    /**
     * Vérifie le cookie ou le paramètre GET ?t= et valide le token.
     */
    public function check_authentication(): void {
        // Priorité 1 : paramètre GET ?t= (partage WhatsApp / nouveau device)
        if ( ! empty( $_GET['t'] ) ) {
            $token = sanitize_text_field( wp_unslash( $_GET['t'] ) );
            if ( $this->validate_token( $token ) ) {
                self::$authenticated = true;
                $this->set_cookie( $token );
                PPB_Logger::info( 'auth_token_get', 'Authentification via paramètre GET ?t=', [ 'ip' => self::get_ip() ] );
                // Nettoie l'URL pour retirer le token visible.
                $clean_url = remove_query_arg( 't' );
                wp_safe_redirect( $clean_url );
                exit;
            }
        }

        // Priorité 2 : cookie ppb_token
        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
            if ( $this->validate_token( $token ) ) {
                self::$authenticated = true;
            }
        }
    }

    /**
     * Retourne true si le visiteur est authentifié pour cette requête.
     */
    public static function is_authenticated(): bool {
        return self::$authenticated;
    }

    // -------------------------------------------------------------------------
    // AJAX : validation du mot de passe
    // -------------------------------------------------------------------------

    public function ajax_validate_password(): void {
        check_ajax_referer( 'ppb_portal_nonce', 'nonce' );

        $password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : '';

        if ( ! $this->check_password( $password ) ) {
            PPB_Logger::warning( 'auth_failed', 'Tentative de connexion échouée', [ 'ip' => self::get_ip() ] );
            wp_send_json_error( [ 'message' => __( 'Mot de passe incorrect.', 'presellia-partner-bridge' ) ], 403 );
        }

        $token = $this->generate_token();
        $this->store_token( $token );
        $this->set_cookie( $token );

        self::$authenticated = true;

        PPB_Logger::info( 'auth_success', 'Authentification réussie', [ 'ip' => self::get_ip() ] );

        wp_send_json_success( [
            'token'       => $token,
            'share_url'   => add_query_arg( 't', $token, get_permalink( get_option( 'ppb_portal_page_id' ) ) ),
            'expires_days' => (int) get_option( 'ppb_token_ttl', 30 ),
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX : révocation du token (déconnexion)
    // -------------------------------------------------------------------------

    public function ajax_revoke_token(): void {
        check_ajax_referer( 'ppb_portal_nonce', 'nonce' );

        if ( ! empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            $token = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
            $this->delete_token( $token );
        }

        setcookie( self::COOKIE_NAME, '', time() - 3600, '/', '', is_ssl(), true );
        self::$authenticated = false;

        PPB_Logger::info( 'auth_revoked', 'Token révoqué (déconnexion)', [ 'ip' => self::get_ip() ] );

        wp_send_json_success();
    }

    // -------------------------------------------------------------------------
    // Gestion des tokens
    // -------------------------------------------------------------------------

    /**
     * Génère un token aléatoire (64 caractères hex).
     */
    private function generate_token(): string {
        return bin2hex( random_bytes( 32 ) );
    }

    /**
     * Stocke un token en transient WP.
     * La clé du transient utilise un hash du token pour éviter de stocker le token en clair.
     */
    private function store_token( string $token ): void {
        $ttl_days = (int) get_option( 'ppb_token_ttl', 30 );
        $ttl_secs = $ttl_days * DAY_IN_SECONDS;

        set_transient(
            self::TRANSIENT_PREFIX . hash( 'sha256', $token ),
            [
                'created_at' => time(),
                'ip'         => self::get_ip(),
            ],
            $ttl_secs
        );
    }

    /**
     * Valide un token contre les transients stockés.
     */
    private function validate_token( string $token ): bool {
        if ( empty( $token ) || strlen( $token ) !== 64 ) {
            return false;
        }

        $data = get_transient( self::TRANSIENT_PREFIX . hash( 'sha256', $token ) );

        return false !== $data;
    }

    /**
     * Supprime un token.
     */
    private function delete_token( string $token ): void {
        delete_transient( self::TRANSIENT_PREFIX . hash( 'sha256', $token ) );
    }

    /**
     * Pose le cookie ppb_token dans le navigateur.
     */
    private function set_cookie( string $token ): void {
        $ttl_days = (int) get_option( 'ppb_token_ttl', 30 );
        $expires  = time() + $ttl_days * DAY_IN_SECONDS;

        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires'  => $expires,
                'path'     => '/',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Mot de passe
    // -------------------------------------------------------------------------

    /**
     * Vérifie le mot de passe entré contre le hash stocké.
     */
    private function check_password( string $input ): bool {
        if ( empty( $input ) ) {
            return false;
        }

        $stored_hash = get_option( 'ppb_portal_password_hash', '' );

        if ( empty( $stored_hash ) ) {
            return false;
        }

        return wp_check_password( $input, $stored_hash );
    }

    /**
     * Hash et sauvegarde un nouveau mot de passe.
     * Appelé depuis PPB_Settings.
     */
    public static function set_password( string $plain_password ): void {
        if ( empty( $plain_password ) ) {
            return;
        }

        update_option( 'ppb_portal_password_hash', wp_hash_password( $plain_password ) );
    }

    /**
     * Révoque tous les tokens actifs en vidant les transients correspondants.
     * Utilisé lors du changement de mot de passe.
     */
    public static function revoke_all_tokens(): int {
        global $wpdb;

        $like   = $wpdb->esc_like( '_transient_' . self::TRANSIENT_PREFIX ) . '%';
        $count  = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
        );

        $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
        );

        // Supprime aussi les entrées de timeout.
        $like_timeout = $wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%';
        $wpdb->query(
            $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout )
        );

        PPB_Logger::info( 'tokens_revoked_all', "Tous les tokens révoqués ({$count})", [] );

        return $count;
    }

    /**
     * Retourne le nombre de tokens actifs.
     */
    public static function count_active_tokens(): int {
        global $wpdb;

        $like = $wpdb->esc_like( '_transient_timeout_' . self::TRANSIENT_PREFIX ) . '%';

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                   AND option_value > %d",
                $like,
                time()
            )
        );
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    public static function get_ip(): string {
        $ip = '';

        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ip    = trim( $parts[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = wp_unslash( $_SERVER['REMOTE_ADDR'] );
        }

        return sanitize_text_field( $ip );
    }
}
