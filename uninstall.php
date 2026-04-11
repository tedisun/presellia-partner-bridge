<?php
/**
 * Nettoyage complet lors de la désinstallation du plugin.
 * Supprime : table ppb_logs, toutes les options PPB, toutes les metas _ppb_partner_price.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Déprogramme le cron de purge.
wp_clear_scheduled_hook( 'ppb_weekly_cleanup' );

// Supprime la table de logs.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ppb_logs" );

// Supprime toutes les options PPB.
$options = [
    'ppb_portal_password_hash',
    'ppb_portal_page_id',
    'ppb_portal_title',
    'ppb_portal_logo_url',
    'ppb_token_ttl',
    'ppb_log_retention',
    'ppb_api_key',
    'ppb_db_version',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// Supprime tous les transients de tokens.
$like = $wpdb->esc_like( '_transient_ppb_tok_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

$like_timeout = $wpdb->esc_like( '_transient_timeout_ppb_tok_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) );

// Supprime toutes les metas produit PPB.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_ppb_partner_price'" );
