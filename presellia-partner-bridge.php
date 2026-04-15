<?php
/**
 * Plugin Name:       Presellia Partner Bridge
 * Plugin URI:        https://github.com/tedisun/presellia-partner-bridge
 * Description:       Portail de commande partenaire pour Presellia. Prix partenaires, authentification par token, éditeur de prix en masse, API MCP de diagnostic.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Tedisun SARL
 * Text Domain:       presellia-partner-bridge
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   10.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'PPB_VERSION',     '1.1.0' );
define( 'PPB_PLUGIN_FILE', __FILE__ );
define( 'PPB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PPB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Ces deux fichiers doivent être chargés hors plugins_loaded
// pour que register_activation_hook/deactivation_hook fonctionnent.
require_once PPB_PLUGIN_DIR . 'includes/class-ppb-activator.php';
require_once PPB_PLUGIN_DIR . 'includes/class-ppb-plugin.php';

register_activation_hook( PPB_PLUGIN_FILE,   [ 'PPB_Activator', 'activate' ] );
register_deactivation_hook( PPB_PLUGIN_FILE, [ 'PPB_Activator', 'deactivate' ] );

/**
 * Point d'entrée principal — initialise le plugin via le loader central.
 */
add_action( 'plugins_loaded', function (): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function (): void {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Presellia Partner Bridge</strong> nécessite WooCommerce pour fonctionner.';
            echo '</p></div>';
        } );
        return;
    }

    PPB_Plugin::instance();
} );

// Compatibilité WooCommerce HPOS.
add_action( 'before_woocommerce_init', function (): void {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            PPB_PLUGIN_FILE,
            true
        );
    }
} );
