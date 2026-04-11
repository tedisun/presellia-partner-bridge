<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logger PPB — écrit dans la table ppb_logs.
 * Méthodes statiques pour usage simple partout dans le plugin.
 */
class PPB_Logger {

    private static ?string $table = null;

    private static function table(): string {
        if ( null === self::$table ) {
            global $wpdb;
            self::$table = $wpdb->prefix . 'ppb_logs';
        }

        return self::$table;
    }

    // -------------------------------------------------------------------------
    // Raccourcis par niveau
    // -------------------------------------------------------------------------

    public static function info( string $event, string $message, array $context = [] ): void {
        self::log( 'info', $event, $message, $context );
    }

    public static function warning( string $event, string $message, array $context = [] ): void {
        self::log( 'warning', $event, $message, $context );
    }

    public static function error( string $event, string $message, array $context = [] ): void {
        self::log( 'error', $event, $message, $context );
    }

    // -------------------------------------------------------------------------
    // Écriture
    // -------------------------------------------------------------------------

    public static function log( string $level, string $event, string $message, array $context = [] ): void {
        global $wpdb;

        $wpdb->insert(
            self::table(),
            [
                'level'      => substr( sanitize_key( $level ), 0, 10 ),
                'event'      => substr( sanitize_key( $event ), 0, 50 ),
                'message'    => sanitize_text_field( $message ),
                'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
                'ip'         => PPB_Auth::get_ip(),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    // -------------------------------------------------------------------------
    // Lecture (pour l'API MCP)
    // -------------------------------------------------------------------------

    /**
     * Retourne les N derniers logs, avec filtre optionnel par niveau ou event.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_recent( int $limit = 100, string $level = '', string $event = '' ): array {
        global $wpdb;

        $where  = '1=1';
        $params = [];

        if ( ! empty( $level ) ) {
            $where   .= ' AND level = %s';
            $params[] = sanitize_key( $level );
        }

        if ( ! empty( $event ) ) {
            $where   .= ' AND event = %s';
            $params[] = sanitize_key( $event );
        }

        $limit  = min( max( 1, $limit ), 500 );
        $params[] = $limit;

        $sql = "SELECT id, level, event, message, context, ip, created_at
                FROM " . self::table() . "
                WHERE {$where}
                ORDER BY id DESC
                LIMIT %d";

        if ( ! empty( array_slice( $params, 0, -1 ) ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $limit ), ARRAY_A );
        }

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Compte les entrées par niveau depuis N jours.
     *
     * @return array{info: int, warning: int, error: int}
     */
    public static function get_counts( int $days = 7 ): array {
        global $wpdb;

        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT level, COUNT(*) as cnt
                 FROM " . self::table() . "
                 WHERE created_at >= %s
                 GROUP BY level",
                $since
            ),
            ARRAY_A
        );

        $counts = [ 'info' => 0, 'warning' => 0, 'error' => 0 ];

        foreach ( (array) $rows as $row ) {
            if ( isset( $counts[ $row['level'] ] ) ) {
                $counts[ $row['level'] ] = (int) $row['cnt'];
            }
        }

        return $counts;
    }

    // -------------------------------------------------------------------------
    // Nettoyage
    // -------------------------------------------------------------------------

    /**
     * Supprime les logs plus vieux que N jours.
     * Appelé par un cron hebdomadaire.
     */
    public static function purge_old( int $days = 0 ): int {
        global $wpdb;

        if ( $days <= 0 ) {
            $days = (int) get_option( 'ppb_log_retention', 90 );
        }

        $before = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $deleted = (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM " . self::table() . " WHERE created_at < %s",
                $before
            )
        );

        return $deleted;
    }

    /**
     * Vide tous les logs.
     */
    public static function clear_all(): int {
        global $wpdb;

        return (int) $wpdb->query( "TRUNCATE TABLE " . self::table() );
    }
}
