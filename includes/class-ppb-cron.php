<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Module cron PPB — purge hebdomadaire des logs expirés.
 *
 * Événement WP-Cron : `ppb_weekly_cleanup`
 * Fréquence        : weekly (natif WP)
 * Action           : supprime les entrées ppb_logs plus vieilles que ppb_log_retention jours
 */
class PPB_Cron {

    /** Nom de l'événement cron WP. */
    public const EVENT = 'ppb_weekly_cleanup';

    public function __construct() {
        add_action( self::EVENT, [ $this, 'run_cleanup' ] );
        $this->maybe_schedule();
    }

    // -------------------------------------------------------------------------
    // Planification
    // -------------------------------------------------------------------------

    /**
     * Planifie l'événement récurrent s'il n'existe pas déjà.
     */
    private function maybe_schedule(): void {
        if ( ! wp_next_scheduled( self::EVENT ) ) {
            wp_schedule_event( time(), 'weekly', self::EVENT );
        }
    }

    /**
     * Déprogramme l'événement cron.
     * Appelé lors de la désactivation du plugin (depuis PPB_Activator).
     */
    public static function unschedule(): void {
        $timestamp = wp_next_scheduled( self::EVENT );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::EVENT );
        }
    }

    // -------------------------------------------------------------------------
    // Exécution
    // -------------------------------------------------------------------------

    /**
     * Lance la purge des logs et journalise le résultat.
     */
    public function run_cleanup(): void {
        $deleted = PPB_Logger::purge_old();

        PPB_Logger::info(
            'cron_cleanup',
            "Purge automatique des logs : {$deleted} entrée(s) supprimée(s)",
            [ 'deleted' => $deleted ]
        );
    }
}
