<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gère l'activation et la désactivation du plugin.
 */
class PPB_Activator {

    /**
     * Crée la table de logs et les options par défaut.
     */
    public static function activate(): void {
        self::create_logs_table();
        self::set_default_options();
        flush_rewrite_rules();
    }

    /**
     * Vide les règles de réécriture.
     */
    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Crée la table ppb_logs.
     */
    private static function create_logs_table(): void {
        global $wpdb;

        $table      = $wpdb->prefix . 'ppb_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            level       varchar(10)  NOT NULL DEFAULT 'info',
            event       varchar(50)  NOT NULL DEFAULT '',
            message     text         NOT NULL,
            context     longtext,
            ip          varchar(45)  DEFAULT NULL,
            created_at  datetime     NOT NULL,
            PRIMARY KEY (id),
            KEY level      (level),
            KEY event      (event),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'ppb_db_version', PPB_VERSION );
    }

    /**
     * Initialise les options si elles n'existent pas encore.
     */
    private static function set_default_options(): void {
        if ( false === get_option( 'ppb_token_ttl' ) ) {
            update_option( 'ppb_token_ttl', 30 ); // jours
        }

        if ( false === get_option( 'ppb_log_retention' ) ) {
            update_option( 'ppb_log_retention', 90 ); // jours
        }
    }
}
