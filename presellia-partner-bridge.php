<?php
/**
 * Plugin Name:       Presellia Partner Bridge
 * Plugin URI:        https://github.com/tedisun/presellia-partner-bridge
 * Description:       Portail de commande partenaire pour Presellia. Prix partenaires, authentification par token, éditeur de prix en masse, API MCP de diagnostic.
 * Version:           1.0.0
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

define( 'PPB_VERSION',     '1.0.0' );
define( 'PPB_PLUGIN_FILE', __FILE__ );
define( 'PPB_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'PPB_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

// Inclus immédiatement (hors plugins_loaded) pour que register_activation_hook fonctionne.
require_once PPB_PLUGIN_DIR . 'includes/class-ppb-activator.php';

register_activation_hook( PPB_PLUGIN_FILE,   [ 'PPB_Activator', 'activate' ] );
register_deactivation_hook( PPB_PLUGIN_FILE, [ 'PPB_Activator', 'deactivate' ] );

/**
 * Vérifie que WooCommerce est actif.
 */
function ppb_woocommerce_active(): bool {
    return class_exists( 'WooCommerce' );
}

/**
 * Notice admin si WooCommerce est absent.
 */
function ppb_missing_wc_notice(): void {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>Presellia Partner Bridge</strong> nécessite WooCommerce pour fonctionner.';
    echo '</p></div>';
}

/**
 * Charge le plugin.
 */
function ppb_load(): void {
    if ( ! ppb_woocommerce_active() ) {
        add_action( 'admin_notices', 'ppb_missing_wc_notice' );
        return;
    }

    require_once PPB_PLUGIN_DIR . 'includes/class-ppb-logger.php';
    require_once PPB_PLUGIN_DIR . 'includes/class-ppb-auth.php';
    require_once PPB_PLUGIN_DIR . 'includes/class-ppb-pricing.php';
    require_once PPB_PLUGIN_DIR . 'includes/class-ppb-portal.php';
    require_once PPB_PLUGIN_DIR . 'admin/class-ppb-settings.php';
    require_once PPB_PLUGIN_DIR . 'admin/class-ppb-admin.php';
    require_once PPB_PLUGIN_DIR . 'includes/api/class-ppb-api.php';

    new PPB_Auth();
    new PPB_Pricing();
    new PPB_Portal();
    new PPB_Settings();
    new PPB_Admin();
    new PPB_Api();
}

add_action( 'plugins_loaded', 'ppb_load' );

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
