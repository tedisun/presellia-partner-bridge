<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Classe principale PPB — charge et expose tous les modules.
 *
 * Singleton initialisé une seule fois sur `plugins_loaded`.
 * Centralise les require_once et l'instanciation de chaque module.
 *
 * -----------------------------------------------------------------
 * Comment ajouter un nouveau module :
 *  1. Créer le fichier (ex. includes/class-ppb-mymodule.php)
 *  2. Ajouter require_once dans load_dependencies()
 *  3. Instancier dans init_modules()
 *  4. Exposer via une méthode publique si d'autres modules en ont besoin
 * -----------------------------------------------------------------
 */
class PPB_Plugin {

    private static ?PPB_Plugin $instance = null;

    // Modules exposés publiquement (accès inter-modules si nécessaire).
    private PPB_Auth    $auth;
    private PPB_Pricing $pricing;
    private PPB_Portal  $portal;
    private PPB_Cron    $cron;

    // -------------------------------------------------------------------------
    // Singleton
    // -------------------------------------------------------------------------

    /**
     * Retourne l'unique instance du plugin.
     * Crée l'instance si elle n'existe pas encore.
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** Empêche l'instanciation directe. */
    private function __construct() {
        $this->load_dependencies();
        $this->init_modules();
    }

    // -------------------------------------------------------------------------
    // Chargement des dépendances
    // -------------------------------------------------------------------------

    private function load_dependencies(): void {
        // --- Modules core (toujours chargés) ---
        require_once PPB_PLUGIN_DIR . 'includes/class-ppb-logger.php';
        require_once PPB_PLUGIN_DIR . 'includes/class-ppb-auth.php';
        require_once PPB_PLUGIN_DIR . 'includes/class-ppb-pricing.php';
        require_once PPB_PLUGIN_DIR . 'includes/class-ppb-portal.php';
        require_once PPB_PLUGIN_DIR . 'includes/class-ppb-cron.php';

        // --- API REST (toujours chargée — s'enregistre via rest_api_init) ---
        require_once PPB_PLUGIN_DIR . 'includes/api/class-ppb-api.php';

        // --- Modules admin (admin + requêtes AJAX uniquement) ---
        if ( is_admin() ) {
            require_once PPB_PLUGIN_DIR . 'includes/class-ppb-updater.php';
            require_once PPB_PLUGIN_DIR . 'admin/class-ppb-settings.php';
            require_once PPB_PLUGIN_DIR . 'admin/class-ppb-admin.php';
        }
    }

    // -------------------------------------------------------------------------
    // Initialisation des modules
    // -------------------------------------------------------------------------

    private function init_modules(): void {
        // Core.
        $this->auth    = new PPB_Auth();
        $this->pricing = new PPB_Pricing();
        $this->portal  = new PPB_Portal();
        $this->cron    = new PPB_Cron();

        // API REST.
        new PPB_Api();

        // Admin (is_admin() inclut les requêtes AJAX — les handlers AJAX admin sont bien chargés).
        if ( is_admin() ) {
            PPB_Updater::get_instance();
            new PPB_Settings();
            new PPB_Admin();
        }
    }

    // -------------------------------------------------------------------------
    // Accesseurs de modules
    // -------------------------------------------------------------------------

    public function auth(): PPB_Auth {
        return $this->auth;
    }

    public function pricing(): PPB_Pricing {
        return $this->pricing;
    }

    public function portal(): PPB_Portal {
        return $this->portal;
    }

    public function cron(): PPB_Cron {
        return $this->cron;
    }
}
