<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PPB — GitHub Auto-Updater
 *
 * S'intègre au système de mises à jour WordPress pour vérifier les nouvelles
 * releases sur le dépôt public github.com/tedisun/presellia-partner-bridge.
 *
 * Fonctionnement :
 *  1. Toutes les 12 h WordPress vérifie les mises à jour des extensions.
 *  2. Cette classe intercepte cette vérification et appelle l'API GitHub Releases.
 *  3. Si un tag plus récent existe (ex. v1.1.0 > 1.0.0), WordPress affiche
 *     la notification dans Tableau de bord > Mises à jour.
 *  4. L'admin clique "Mettre à jour" — WordPress télécharge le ZIP depuis
 *     GitHub et installe automatiquement.
 *
 * Publier une nouvelle release :
 *  1. Incrémenter PPB_VERSION dans presellia-partner-bridge.php (ex. '1.1.1').
 *  2. Mettre à jour l'en-tête "Version:" du plugin (même fichier).
 *  3. Mettre à jour CHANGELOG.md.
 *  4. Pousser sur GitHub et créer une Release avec le tag v1.1.1.
 *     (Le GitHub Action génère automatiquement le ZIP propre.)
 *  5. Les sites WordPress reçoivent la mise à jour en moins de 12 h.
 */
class PPB_Updater {

    /** Propriétaire du dépôt GitHub. */
    const GITHUB_USER = 'tedisun';

    /** Nom du dépôt GitHub. */
    const GITHUB_REPO = 'presellia-partner-bridge';

    /** Slug WordPress du plugin (= nom du dossier). */
    const PLUGIN_SLUG = 'presellia-partner-bridge';

    /** Basename WordPress : dossier/fichier-principal.php */
    const PLUGIN_BASENAME = 'presellia-partner-bridge/presellia-partner-bridge.php';

    /** Clé du transient qui met en cache la réponse GitHub. */
    const TRANSIENT_KEY = 'ppb_update_data';

    /** Durée du cache en secondes (12 heures). */
    const CACHE_TTL = 43200;

    /** @var self|null */
    private static ?PPB_Updater $instance = null;

    private function __construct() {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_source_selection',             [ $this, 'fix_source_dir' ], 10, 4 );
        add_action( 'upgrader_process_complete',             [ $this, 'clear_cache' ], 10, 2 );
    }

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Vérification de mise à jour
    // -------------------------------------------------------------------------

    /**
     * Appelé par WordPress lors de la vérification des mises à jour.
     * Injecte les informations de mise à jour PPB dans le transient si une
     * version plus récente existe sur GitHub.
     */
    public function check_for_update( object $transient ): object {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }

        $latest_version = $this->parse_version( $release->tag_name );

        if ( version_compare( $latest_version, PPB_VERSION, '>' ) ) {
            $transient->response[ self::PLUGIN_BASENAME ] = $this->build_update_object( $release, $latest_version );
        } else {
            $transient->no_update[ self::PLUGIN_BASENAME ] = $this->build_no_update_object( $latest_version );
        }

        return $transient;
    }

    /**
     * Fournit les informations du plugin pour la modale "Voir les détails" dans l'admin WP.
     */
    public function plugin_info( $result, string $action, object $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== self::PLUGIN_SLUG ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        $latest_version = $this->parse_version( $release->tag_name );

        $info               = new stdClass();
        $info->name         = 'Presellia Partner Bridge';
        $info->slug         = self::PLUGIN_SLUG;
        $info->version      = $latest_version;
        $info->author       = '<a href="https://tedisun.com">Tedisun SARL</a>';
        $info->homepage     = 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;
        $info->requires     = '6.0';
        $info->requires_php = '8.0';
        $info->last_updated = $release->published_at ?? '';
        $info->download_link = $this->get_download_url( $release );
        $info->sections     = [
            'description' => 'Portail de commande partenaire pour WooCommerce. Prix partenaires, authentification par token, éditeur de prix en masse, API REST de diagnostic.',
            'changelog'   => $this->format_changelog( $release->body ?? '' ),
        ];

        return $info;
    }

    /**
     * Corrige le nom du dossier après extraction du ZIP.
     *
     * GitHub génère des ZIPs avec un dossier nommé "presellia-partner-bridge-1.1.0"
     * mais WordPress attend "presellia-partner-bridge". Ce filtre renomme le dossier.
     */
    public function fix_source_dir( string $source, string $remote_source, $upgrader, array $hook_extra = [] ): string {
        global $wp_filesystem;

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== self::PLUGIN_BASENAME ) {
            return $source;
        }

        $expected = trailingslashit( $remote_source ) . self::PLUGIN_SLUG . '/';

        if ( $source === $expected ) {
            return $source;
        }

        if ( $wp_filesystem->move( $source, $expected ) ) {
            return $expected;
        }

        return $source;
    }

    /**
     * Vide le cache des releases après une mise à jour réussie.
     */
    public function clear_cache( WP_Upgrader $upgrader, array $hook_extra ): void {
        if (
            isset( $hook_extra['action'], $hook_extra['type'], $hook_extra['plugins'] ) &&
            'update' === $hook_extra['action'] &&
            'plugin' === $hook_extra['type'] &&
            in_array( self::PLUGIN_BASENAME, $hook_extra['plugins'], true )
        ) {
            delete_transient( self::TRANSIENT_KEY );
        }
    }

    // -------------------------------------------------------------------------
    // API GitHub
    // -------------------------------------------------------------------------

    /**
     * Récupère la dernière release GitHub.
     * Le résultat est mis en cache dans un transient pour CACHE_TTL secondes.
     *
     * @return object|null  Objet release GitHub décodé, ou null en cas d'erreur.
     */
    private function get_latest_release(): ?object {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( false !== $cached ) {
            return $cached ?: null;
        }

        $url      = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_USER,
            self::GITHUB_REPO
        );
        $response = wp_remote_get( $url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'PPB-Updater/' . PPB_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
            ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            set_transient( self::TRANSIENT_KEY, false, 300 );
            return null;
        }

        $body    = wp_remote_retrieve_body( $response );
        $release = json_decode( $body );

        if ( empty( $release->tag_name ) ) {
            set_transient( self::TRANSIENT_KEY, false, 300 );
            return null;
        }

        set_transient( self::TRANSIENT_KEY, $release, self::CACHE_TTL );
        return $release;
    }

    /**
     * Détermine la meilleure URL de téléchargement pour la release.
     *
     * Préfère un asset nommé "presellia-partner-bridge.zip" attaché à la release.
     * Fallback sur l'archive source générée par GitHub (nécessite le renommage du dossier).
     */
    private function get_download_url( object $release ): string {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( $asset->name === 'presellia-partner-bridge.zip' && ! empty( $asset->browser_download_url ) ) {
                    return $asset->browser_download_url;
                }
            }
        }

        return sprintf(
            'https://github.com/%s/%s/archive/refs/tags/%s.zip',
            self::GITHUB_USER,
            self::GITHUB_REPO,
            $release->tag_name
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Supprime le "v" préfixe du tag : "v1.2.0" → "1.2.0".
     */
    private function parse_version( string $tag ): string {
        return ltrim( $tag, 'vV' );
    }

    /**
     * Construit l'objet de mise à jour attendu par WordPress dans le transient.
     */
    private function build_update_object( object $release, string $version ): object {
        return (object) [
            'id'            => self::GITHUB_USER . '/' . self::GITHUB_REPO,
            'slug'          => self::PLUGIN_SLUG,
            'plugin'        => self::PLUGIN_BASENAME,
            'new_version'   => $version,
            'url'           => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
            'package'       => $this->get_download_url( $release ),
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'requires'      => '6.0',
            'tested'        => get_bloginfo( 'version' ),
            'requires_php'  => '8.0',
            'compatibility' => new stdClass(),
        ];
    }

    /**
     * Construit l'objet "pas de mise à jour" attendu par WordPress.
     */
    private function build_no_update_object( string $version ): object {
        return (object) [
            'id'           => self::GITHUB_USER . '/' . self::GITHUB_REPO,
            'slug'         => self::PLUGIN_SLUG,
            'plugin'       => self::PLUGIN_BASENAME,
            'new_version'  => $version,
            'url'          => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
            'package'      => '',
            'icons'        => [],
            'banners'      => [],
            'requires'     => '6.0',
            'requires_php' => '8.0',
        ];
    }

    /**
     * Formate le corps de la release GitHub (Markdown) en HTML simple pour la section changelog.
     */
    private function format_changelog( string $body ): string {
        if ( empty( $body ) ) {
            return '<p>' . esc_html__( 'Voir les notes de version sur GitHub.', 'presellia-partner-bridge' ) . '</p>';
        }

        $body = esc_html( $body );
        $body = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $body );
        $body = preg_replace( '/^## (.+)$/m',  '<h3>$1</h3>', $body );
        $body = preg_replace( '/^# (.+)$/m',   '<h2>$1</h2>', $body );
        $body = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $body );
        $body = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $body );
        $body = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $body );
        $body = nl2br( $body );

        return $body;
    }

    // -------------------------------------------------------------------------
    // Action admin : vérification forcée
    // -------------------------------------------------------------------------

    /**
     * Vide le cache pour forcer une nouvelle vérification GitHub.
     * Appelé depuis la page de réglages PPB.
     */
    public static function force_check(): void {
        delete_transient( self::TRANSIENT_KEY );
        delete_site_transient( 'update_plugins' );
        wp_update_plugins();
    }

    /**
     * Récupère un statut de mise à jour frais depuis GitHub.
     * Utilisé par le bouton AJAX "Vérifier les mises à jour" dans les réglages.
     *
     * @return array{
     *   error: bool,
     *   current?: string,
     *   latest?: string,
     *   has_update?: bool,
     *   update_url?: string,
     *   changelog_url?: string,
     *   message?: string
     * }
     */
    public function fetch_update_status(): array {
        delete_transient( self::TRANSIENT_KEY );

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return [
                'error'   => true,
                'message' => __( 'Impossible de contacter GitHub. Vérifiez votre connexion et réessayez.', 'presellia-partner-bridge' ),
            ];
        }

        $latest     = $this->parse_version( $release->tag_name );
        $current    = PPB_VERSION;
        $has_update = version_compare( $latest, $current, '>' );

        $result = [
            'error'      => false,
            'current'    => $current,
            'latest'     => $latest,
            'has_update' => $has_update,
        ];

        if ( $has_update ) {
            // Injecte dans le transient WP pour que le lien de mise à jour fonctionne immédiatement.
            $site_transient = get_site_transient( 'update_plugins' );
            if ( ! is_object( $site_transient ) ) {
                $site_transient = new stdClass();
            }
            if ( ! isset( $site_transient->checked ) ) {
                $site_transient->checked = [];
            }
            $site_transient->checked[ self::PLUGIN_BASENAME ]  = $current;
            $site_transient->response[ self::PLUGIN_BASENAME ] = $this->build_update_object( $release, $latest );
            set_site_transient( 'update_plugins', $site_transient );

            $result['update_url'] = wp_nonce_url(
                admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( self::PLUGIN_BASENAME ) ),
                'upgrade-plugin_' . self::PLUGIN_BASENAME
            );
            $result['changelog_url'] = 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO . '/releases/tag/' . $release->tag_name;
        }

        return $result;
    }
}
