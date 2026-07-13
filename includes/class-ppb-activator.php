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
        self::create_requests_table();
        self::set_default_options();
        flush_rewrite_rules();
    }

    /**
     * Exécuté à chaque chargement (plugins_loaded) : si la version stockée en
     * base diffère de PPB_VERSION, rejoue les migrations (idempotentes —
     * dbDelta + CREATE TABLE IF NOT EXISTS). Nécessaire car une mise à jour en
     * place (releaser GitHub, pas de désactivation/réactivation) ne redéclenche
     * jamais register_activation_hook — sans ça, une table ajoutée dans une
     * nouvelle version n'existerait jamais sur un site déjà en production.
     */
    public static function maybe_upgrade(): void {
        if ( get_option( 'ppb_db_version' ) === PPB_VERSION ) {
            return;
        }

        self::create_logs_table();
        self::create_requests_table();

        update_option( 'ppb_db_version', PPB_VERSION );
    }

    /**
     * Déprogramme le cron et vide les règles de réécriture.
     */
    public static function deactivate(): void {
        // Déprogramme le cron de purge des logs.
        $timestamp = wp_next_scheduled( 'ppb_weekly_cleanup' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'ppb_weekly_cleanup' );
        }

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
     * Crée la table ppb_partner_requests (demandes d'accès portail).
     */
    private static function create_requests_table(): void {
        global $wpdb;

        $table            = $wpdb->prefix . 'ppb_partner_requests';
        $charset_collate  = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id           bigint(20)   NOT NULL AUTO_INCREMENT,
            full_name    varchar(190) NOT NULL DEFAULT '',
            whatsapp     varchar(50)  NOT NULL DEFAULT '',
            email        varchar(190) NOT NULL DEFAULT '',
            business     varchar(190) DEFAULT NULL,
            message      text,
            status       varchar(10)  NOT NULL DEFAULT 'pending',
            created_at   datetime     NOT NULL,
            reviewed_at  datetime     DEFAULT NULL,
            reviewed_by  bigint(20)   DEFAULT NULL,
            PRIMARY KEY (id),
            KEY status     (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
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
