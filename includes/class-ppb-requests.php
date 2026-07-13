<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Demandes d'accès au portail partenaire — écrit/lit la table ppb_partner_requests.
 * Méthodes statiques pour usage simple partout dans le plugin (même modèle que PPB_Logger).
 */
class PPB_Requests {

    private const VALID_STATUSES = [ 'pending', 'approved', 'rejected' ];

    private static ?string $table = null;

    private static function table(): string {
        if ( null === self::$table ) {
            global $wpdb;
            self::$table = $wpdb->prefix . 'ppb_partner_requests';
        }

        return self::$table;
    }

    // -------------------------------------------------------------------------
    // Écriture
    // -------------------------------------------------------------------------

    /**
     * Enregistre une nouvelle demande d'accès, statut 'pending' par défaut.
     *
     * @param array{full_name: string, whatsapp: string, email: string, business?: string, message?: string} $data
     */
    public static function create( array $data ): int {
        global $wpdb;

        $wpdb->insert(
            self::table(),
            [
                'full_name'  => sanitize_text_field( $data['full_name'] ),
                'whatsapp'   => sanitize_text_field( $data['whatsapp'] ),
                'email'      => sanitize_email( $data['email'] ),
                'business'   => isset( $data['business'] ) ? sanitize_text_field( $data['business'] ) : null,
                'message'    => isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : null,
                'status'     => 'pending',
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Change le statut d'une demande (approve/reject) et note qui/quand.
     */
    public static function update_status( int $id, string $status, int $reviewer_id ): bool {
        if ( ! in_array( $status, self::VALID_STATUSES, true ) || 'pending' === $status ) {
            return false;
        }

        global $wpdb;

        $updated = $wpdb->update(
            self::table(),
            [
                'status'      => $status,
                'reviewed_at' => current_time( 'mysql' ),
                'reviewed_by' => $reviewer_id,
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        return false !== $updated;
    }

    // -------------------------------------------------------------------------
    // Lecture
    // -------------------------------------------------------------------------

    /**
     * Retourne les demandes les plus récentes, filtrables par statut.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function list( string $status = '', int $limit = 200 ): array {
        global $wpdb;

        $limit = min( max( 1, $limit ), 500 );

        if ( '' !== $status && in_array( $status, self::VALID_STATUSES, true ) ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . self::table() . ' WHERE status = %s ORDER BY id DESC LIMIT %d',
                    $status,
                    $limit
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ),
                ARRAY_A
            );
        }

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Compte les demandes en attente (badge admin).
     */
    public static function count_pending(): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE status = %s', 'pending' )
        );
    }
}
