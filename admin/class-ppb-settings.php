<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Page de réglages PPB.
 *
 * Sections :
 *  - Portail partenaire (mot de passe, page assignée, TTL token)
 *  - API MCP (clé API pour les endpoints REST de diagnostic)
 *  - Logs (rétention, actions de purge)
 */
class PPB_Settings {

    private const MENU_SLUG    = 'ppb-settings';
    private const OPTION_GROUP = 'ppb_options';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // AJAX : génération d'une nouvelle clé API.
        add_action( 'wp_ajax_ppb_generate_api_key', [ $this, 'ajax_generate_api_key' ] );

        // AJAX : révoquer tous les tokens.
        add_action( 'wp_ajax_ppb_admin_revoke_all', [ $this, 'ajax_revoke_all_tokens' ] );

        // AJAX : purger les logs.
        add_action( 'wp_ajax_ppb_purge_logs', [ $this, 'ajax_purge_logs' ] );

        // AJAX : vérifier les mises à jour GitHub.
        add_action( 'wp_ajax_ppb_check_update', [ $this, 'ajax_check_update' ] );
    }

    // -------------------------------------------------------------------------
    // Menu admin
    // -------------------------------------------------------------------------

    public function register_menu(): void {
        add_submenu_page(
            'woocommerce',
            __( 'PPB — Réglages', 'presellia-partner-bridge' ),
            __( 'PPB Réglages', 'presellia-partner-bridge' ),
            'manage_woocommerce',
            self::MENU_SLUG,
            [ $this, 'render_settings_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Enregistrement des settings WP
    // -------------------------------------------------------------------------

    public function register_settings(): void {
        register_setting( self::OPTION_GROUP, 'ppb_portal_page_id',   [ 'sanitize_callback' => 'absint' ] );
        register_setting( self::OPTION_GROUP, 'ppb_token_ttl',        [ 'sanitize_callback' => 'absint' ] );
        register_setting( self::OPTION_GROUP, 'ppb_log_retention',    [ 'sanitize_callback' => 'absint' ] );
        register_setting( self::OPTION_GROUP, 'ppb_portal_title',     [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( self::OPTION_GROUP, 'ppb_portal_logo_url',  [ 'sanitize_callback' => 'esc_url_raw' ] );
    }

    // -------------------------------------------------------------------------
    // Rendu de la page
    // -------------------------------------------------------------------------

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Accès refusé.', 'presellia-partner-bridge' ) );
        }

        // Traitement du formulaire mot de passe (hors Settings API car on ne stocke jamais le mdp en clair).
        $password_updated = false;
        $password_error   = '';

        if (
            isset( $_POST['ppb_action'] ) &&
            'set_password' === $_POST['ppb_action'] &&
            isset( $_POST['ppb_settings_nonce'] ) &&
            wp_verify_nonce( sanitize_key( $_POST['ppb_settings_nonce'] ), 'ppb_settings' )
        ) {
            $new_password = isset( $_POST['ppb_new_password'] ) ? wp_unslash( $_POST['ppb_new_password'] ) : '';

            if ( strlen( $new_password ) < 6 ) {
                $password_error = __( 'Le mot de passe doit contenir au moins 6 caractères.', 'presellia-partner-bridge' );
            } else {
                PPB_Auth::set_password( $new_password );
                $revoked = PPB_Auth::revoke_all_tokens();
                $password_updated = true;

                PPB_Logger::info(
                    'password_changed',
                    "Mot de passe changé, {$revoked} tokens révoqués",
                    []
                );
            }
        }

        $active_tokens = PPB_Auth::count_active_tokens();
        $log_counts    = PPB_Logger::get_counts( 7 );
        $api_key       = get_option( 'ppb_api_key', '' );
        $has_password  = (bool) get_option( 'ppb_portal_password_hash', '' );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Presellia Partner Bridge — Réglages', 'presellia-partner-bridge' ); ?></h1>

            <?php if ( $password_updated ) : ?>
                <div class="notice notice-success"><p><?php esc_html_e( 'Mot de passe mis à jour. Tous les tokens actifs ont été révoqués.', 'presellia-partner-bridge' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $password_error ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $password_error ); ?></p></div>
            <?php endif; ?>

            <?php settings_errors( self::OPTION_GROUP ); ?>

            <!-- Tableau de bord rapide -->
            <div class="ppb-dashboard-cards">
                <div class="ppb-card">
                    <span class="ppb-card-value"><?php echo esc_html( $active_tokens ); ?></span>
                    <span class="ppb-card-label"><?php esc_html_e( 'Tokens actifs', 'presellia-partner-bridge' ); ?></span>
                </div>
                <div class="ppb-card ppb-card-info">
                    <span class="ppb-card-value"><?php echo esc_html( $log_counts['info'] ); ?></span>
                    <span class="ppb-card-label"><?php esc_html_e( 'Logs info (7j)', 'presellia-partner-bridge' ); ?></span>
                </div>
                <div class="ppb-card ppb-card-warning">
                    <span class="ppb-card-value"><?php echo esc_html( $log_counts['warning'] ); ?></span>
                    <span class="ppb-card-label"><?php esc_html_e( 'Avertissements (7j)', 'presellia-partner-bridge' ); ?></span>
                </div>
                <div class="ppb-card ppb-card-error">
                    <span class="ppb-card-value"><?php echo esc_html( $log_counts['error'] ); ?></span>
                    <span class="ppb-card-label"><?php esc_html_e( 'Erreurs (7j)', 'presellia-partner-bridge' ); ?></span>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields( self::OPTION_GROUP );

                $pages = get_pages( [ 'post_status' => 'publish' ] );
                ?>

                <h2><?php esc_html_e( 'Portail partenaire', 'presellia-partner-bridge' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Page portail', 'presellia-partner-bridge' ); ?></th>
                        <td>
                            <select name="ppb_portal_page_id">
                                <option value="0"><?php esc_html_e( '— Sélectionner une page —', 'presellia-partner-bridge' ); ?></option>
                                <?php foreach ( $pages as $page ) : ?>
                                    <option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( get_option( 'ppb_portal_page_id' ), $page->ID ); ?>>
                                        <?php echo esc_html( $page->post_title ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Page sur laquelle vous avez placé le shortcode [ppb_portal].', 'presellia-partner-bridge' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Titre du portail', 'presellia-partner-bridge' ); ?></th>
                        <td>
                            <input type="text" name="ppb_portal_title" value="<?php echo esc_attr( get_option( 'ppb_portal_title', 'Portail Partenaire' ) ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Logo (URL)', 'presellia-partner-bridge' ); ?></th>
                        <td>
                            <input type="url" name="ppb_portal_logo_url" value="<?php echo esc_attr( get_option( 'ppb_portal_logo_url', '' ) ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'Affiché en haut du portail. Laisser vide pour utiliser le logo du site.', 'presellia-partner-bridge' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Durée de vie du token', 'presellia-partner-bridge' ); ?></th>
                        <td>
                            <input type="number" name="ppb_token_ttl" value="<?php echo esc_attr( get_option( 'ppb_token_ttl', 30 ) ); ?>" min="0" max="365" style="width:80px"> <?php esc_html_e( 'jours (0 = pas d\'expiration)', 'presellia-partner-bridge' ); ?>
                            <p class="description"><?php esc_html_e( 'Durée avant qu\'un partenaire doive re-saisir le mot de passe.', 'presellia-partner-bridge' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Logs', 'presellia-partner-bridge' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Rétention des logs', 'presellia-partner-bridge' ); ?></th>
                        <td>
                            <input type="number" name="ppb_log_retention" value="<?php echo esc_attr( get_option( 'ppb_log_retention', 90 ) ); ?>" min="7" max="365" style="width:80px"> <?php esc_html_e( 'jours', 'presellia-partner-bridge' ); ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Enregistrer les réglages', 'presellia-partner-bridge' ) ); ?>
            </form>

            <!-- Formulaire mot de passe (séparé) -->
            <h2><?php esc_html_e( 'Mot de passe partenaire', 'presellia-partner-bridge' ); ?></h2>
            <p>
                <?php if ( $has_password ) : ?>
                    <span class="ppb-badge-ok">✓ <?php esc_html_e( 'Mot de passe défini', 'presellia-partner-bridge' ); ?></span>
                <?php else : ?>
                    <span class="ppb-badge-warn">⚠ <?php esc_html_e( 'Aucun mot de passe défini — le portail est inaccessible', 'presellia-partner-bridge' ); ?></span>
                <?php endif; ?>
            </p>
            <form method="post">
                <?php wp_nonce_field( 'ppb_settings', 'ppb_settings_nonce' ); ?>
                <input type="hidden" name="ppb_action" value="set_password">
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Nouveau mot de passe', 'presellia-partner-bridge' ); ?></th>
                        <td>
                            <input type="password" name="ppb_new_password" class="regular-text" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Minimum 6 caractères', 'presellia-partner-bridge' ); ?>">
                            <p class="description"><?php esc_html_e( 'Changer le mot de passe révoque automatiquement tous les tokens actifs.', 'presellia-partner-bridge' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Définir le mot de passe', 'presellia-partner-bridge' ), 'secondary' ); ?>
            </form>

            <!-- Tokens actifs -->
            <h2><?php esc_html_e( 'Tokens actifs', 'presellia-partner-bridge' ); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %d = nombre de tokens */
                    esc_html__( '%d token(s) actif(s) en ce moment.', 'presellia-partner-bridge' ),
                    esc_html( $active_tokens )
                );
                ?>
            </p>
            <button id="ppb-revoke-all" class="button button-secondary">
                <?php esc_html_e( 'Révoquer tous les tokens', 'presellia-partner-bridge' ); ?>
            </button>
            <span id="ppb-revoke-status"></span>

            <!-- Clé API MCP -->
            <h2><?php esc_html_e( 'API MCP — Clé d\'accès', 'presellia-partner-bridge' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Utilisée pour protéger les endpoints REST de diagnostic (/wp-json/ppb/v1/). Ne partagez pas cette clé.', 'presellia-partner-bridge' ); ?></p>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Clé API', 'presellia-partner-bridge' ); ?></th>
                    <td>
                        <input type="text" id="ppb-api-key-display" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" readonly>
                        <button type="button" id="ppb-gen-api-key" class="button">
                            <?php esc_html_e( 'Générer une nouvelle clé', 'presellia-partner-bridge' ); ?>
                        </button>
                        <span id="ppb-api-key-status"></span>
                    </td>
                </tr>
            </table>

            <!-- Actions logs -->
            <h2><?php esc_html_e( 'Actions sur les logs', 'presellia-partner-bridge' ); ?></h2>
            <button id="ppb-purge-logs" class="button button-link-delete">
                <?php esc_html_e( 'Vider tous les logs maintenant', 'presellia-partner-bridge' ); ?>
            </button>
            <span id="ppb-purge-status"></span>

            <!-- Mise à jour -->
            <h2><?php esc_html_e( 'Mise à jour du plugin', 'presellia-partner-bridge' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Version installée', 'presellia-partner-bridge' ); ?></th>
                    <td>
                        <strong><?php echo esc_html( PPB_VERSION ); ?></strong>
                        &nbsp;—&nbsp;
                        <a href="https://github.com/tedisun/presellia-partner-bridge/releases" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Voir les releases GitHub', 'presellia-partner-bridge' ); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Vérifier les mises à jour', 'presellia-partner-bridge' ); ?></th>
                    <td>
                        <button type="button" id="ppb-check-update" class="button">
                            <?php esc_html_e( 'Vérifier maintenant', 'presellia-partner-bridge' ); ?>
                        </button>
                        <div id="ppb-update-result" style="margin-top:12px; display:none;"></div>
                    </td>
                </tr>
            </table>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX actions admin
    // -------------------------------------------------------------------------

    public function ajax_generate_api_key(): void {
        check_ajax_referer( 'ppb_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $key = 'ppb_' . bin2hex( random_bytes( 24 ) );
        update_option( 'ppb_api_key', $key );

        PPB_Logger::info( 'api_key_generated', 'Nouvelle clé API MCP générée', [] );

        wp_send_json_success( [ 'key' => $key ] );
    }

    public function ajax_revoke_all_tokens(): void {
        check_ajax_referer( 'ppb_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        $count = PPB_Auth::revoke_all_tokens();

        wp_send_json_success( [
            'count'   => $count,
            'message' => sprintf(
                /* translators: %d = nombre de tokens */
                __( '%d token(s) révoqué(s).', 'presellia-partner-bridge' ),
                $count
            ),
        ] );
    }

    public function ajax_purge_logs(): void {
        check_ajax_referer( 'ppb_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [], 403 );
        }

        PPB_Logger::clear_all();

        wp_send_json_success( [ 'message' => __( 'Logs vidés.', 'presellia-partner-bridge' ) ] );
    }

    public function ajax_check_update(): void {
        check_ajax_referer( 'ppb_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'presellia-partner-bridge' ) ], 403 );
        }

        $status = PPB_Updater::get_instance()->fetch_update_status();

        if ( ! empty( $status['error'] ) ) {
            wp_send_json_error( [ 'message' => $status['message'] ?? __( 'Erreur inconnue.', 'presellia-partner-bridge' ) ] );
        }

        wp_send_json_success( $status );
    }
}
