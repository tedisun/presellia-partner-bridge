<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Limiteur de débit générique par IP, à base de transients WP.
 * Chaque appelant fournit un "bucket" (ex. 'registration') pour que ses
 * tentatives ne partagent pas le même compteur qu'un autre usage (ex. login).
 */
class PPB_Rate_Limiter {

    private const TRANSIENT_PREFIX = 'ppb_rl2_';

    /**
     * true si l'IP courante a dépassé $max_attempts pour ce bucket sur $window_seconds.
     */
    public static function is_limited( string $bucket, int $max_attempts, int $window_seconds ): bool {
        $attempts = (int) get_transient( self::key( $bucket ) );

        return $attempts >= $max_attempts;
    }

    /**
     * Incrémente le compteur de tentatives pour l'IP courante sur ce bucket.
     */
    public static function register_attempt( string $bucket, int $window_seconds ): void {
        $key      = self::key( $bucket );
        $attempts = (int) get_transient( $key );

        set_transient( $key, $attempts + 1, $window_seconds );
    }

    private static function key( string $bucket ): string {
        return self::TRANSIENT_PREFIX . sanitize_key( $bucket ) . '_' . md5( PPB_Auth::get_ip() );
    }
}
