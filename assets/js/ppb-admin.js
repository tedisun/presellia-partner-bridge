/**
 * Presellia Partner Bridge — JS Admin
 * Gère : bulk save des prix, génération clé API, révocation tokens, purge logs.
 */
( function ( $ ) {
    'use strict';

    const cfg = window.ppbAdmin || {};

    $( function () {
        bindBulkSave();
        bindGenerateApiKey();
        bindRevokeAll();
        bindPurgeLogs();
    } );

    // -------------------------------------------------------------------------
    // Bulk save prix partenaires
    // -------------------------------------------------------------------------

    function bindBulkSave() {
        $( document ).on( 'click', '#ppb-save-all, .ppb-save-all-btn', function () {
            const $btn    = $( '#ppb-save-all, .ppb-save-all-btn' );
            const $status = $( '#ppb-save-status, .ppb-save-status-bottom' );

            const prices = {};
            $( '.ppb-price-input' ).each( function () {
                const id  = $( this ).data( 'product-id' );
                const val = $( this ).val().trim();
                if ( id ) {
                    prices[ id ] = val;
                }
            } );

            $btn.prop( 'disabled', true ).text( cfg.i18n.saving );
            $status.text( '' );

            $.post( cfg.ajaxUrl, {
                action: 'ppb_bulk_save_prices',
                nonce:  cfg.nonce,
                prices: prices,
            } )
            .done( function ( res ) {
                if ( res.success ) {
                    $status.css( 'color', 'green' ).text( res.data.message || cfg.i18n.saved );
                } else {
                    $status.css( 'color', 'red' ).text( cfg.i18n.error );
                }
            } )
            .fail( function () {
                $status.css( 'color', 'red' ).text( cfg.i18n.error );
            } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( cfg.i18n.saved ? cfg.i18n.saved : 'Enregistrer tout' );
                setTimeout( function () { $status.text( '' ); }, 4000 );
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Génération clé API
    // -------------------------------------------------------------------------

    function bindGenerateApiKey() {
        $( '#ppb-gen-api-key' ).on( 'click', function () {
            if ( ! confirm( 'Générer une nouvelle clé API ? L\'ancienne sera révoquée.' ) ) return;

            const $btn    = $( this );
            const $status = $( '#ppb-api-key-status' );

            $btn.prop( 'disabled', true );

            $.post( cfg.ajaxUrl, {
                action: 'ppb_generate_api_key',
                nonce:  cfg.nonce,
            } )
            .done( function ( res ) {
                if ( res.success ) {
                    $( '#ppb-api-key-display' ).val( res.data.key );
                    $status.css( 'color', 'green' ).text( '✓ Nouvelle clé générée.' );
                    setTimeout( function () { $status.text( '' ); }, 4000 );
                }
            } )
            .always( function () {
                $btn.prop( 'disabled', false );
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Révoquer tous les tokens
    // -------------------------------------------------------------------------

    function bindRevokeAll() {
        $( '#ppb-revoke-all' ).on( 'click', function () {
            if ( ! confirm( 'Révoquer tous les tokens ? Les partenaires devront re-saisir le mot de passe.' ) ) return;

            const $btn    = $( this );
            const $status = $( '#ppb-revoke-status' );

            $btn.prop( 'disabled', true );

            $.post( cfg.ajaxUrl, {
                action: 'ppb_admin_revoke_all',
                nonce:  cfg.nonce,
            } )
            .done( function ( res ) {
                if ( res.success ) {
                    $status.css( 'color', 'green' ).text( res.data.message );
                    setTimeout( function () { location.reload(); }, 1500 );
                }
            } )
            .always( function () {
                $btn.prop( 'disabled', false );
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Purge logs
    // -------------------------------------------------------------------------

    function bindPurgeLogs() {
        $( '#ppb-purge-logs' ).on( 'click', function () {
            if ( ! confirm( 'Vider définitivement tous les logs ?' ) ) return;

            const $btn    = $( this );
            const $status = $( '#ppb-purge-status' );

            $btn.prop( 'disabled', true );

            $.post( cfg.ajaxUrl, {
                action: 'ppb_purge_logs',
                nonce:  cfg.nonce,
            } )
            .done( function ( res ) {
                if ( res.success ) {
                    $status.css( 'color', 'green' ).text( res.data.message );
                    setTimeout( function () { $status.text( '' ); }, 3000 );
                }
            } )
            .always( function () {
                $btn.prop( 'disabled', false );
            } );
        } );
    }

} )( jQuery );
