/**
 * Presellia Partner Bridge — JS Admin
 * Gère : bulk save des prix, génération clé API, révocation tokens, purge logs.
 */
( function ( $ ) {
    'use strict';

    const cfg = window.ppbAdmin || {};

    $( function () {
        bindBulkSave();
        bindTiersEditor();
        bindGenerateApiKey();
        bindRevokeAll();
        bindPurgeLogs();
        bindCheckUpdate();
    } );

    // -------------------------------------------------------------------------
    // Bulk save prix partenaires
    // -------------------------------------------------------------------------

    function bindBulkSave() {
        $( document ).on( 'click', '#ppb-save-all, .ppb-save-all-btn', function () {
            const $btn    = $( '#ppb-save-all, .ppb-save-all-btn' );
            const $status = $( '#ppb-save-status, .ppb-save-status-bottom' );

            // Collecte des prix plats.
            const prices = {};
            $( '.ppb-price-input' ).each( function () {
                const id  = $( this ).data( 'product-id' );
                const val = $( this ).val().trim();
                if ( id ) {
                    prices[ id ] = val;
                }
            } );

            // Collecte des paliers depuis chaque ligne éditeur.
            const tiers = {};
            $( '.ppb-tiers-row' ).each( function () {
                const id      = $( this ).data( 'for' );
                const tierArr = [];

                $( this ).find( '.ppb-tier-row' ).each( function () {
                    const min   = parseInt( $( this ).find( '.ppb-tier-min' ).val(), 10 );
                    const price = parseFloat( $( this ).find( '.ppb-tier-price' ).val() );
                    if ( min >= 1 && price > 0 ) {
                        tierArr.push( { min: min, price: price } );
                    }
                } );

                if ( id ) {
                    tiers[ id ] = JSON.stringify( tierArr );
                }
            } );

            $btn.prop( 'disabled', true ).text( cfg.i18n.saving );
            $status.text( '' );

            $.post( cfg.ajaxUrl, {
                action: 'ppb_bulk_save_prices',
                nonce:  cfg.nonce,
                prices: prices,
                tiers:  tiers,
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
    // Éditeur de paliers de quantité
    // -------------------------------------------------------------------------

    function bindTiersEditor() {
        // Toggle ouverture/fermeture de la ligne éditeur.
        $( document ).on( 'click', '.ppb-tiers-btn', function () {
            const id   = $( this ).data( 'product-id' );
            const $row = $( '.ppb-tiers-row[data-for="' + id + '"]' );
            $row.toggle();
        } );

        // Ajouter un palier vide.
        $( document ).on( 'click', '.ppb-tier-add', function () {
            const $tbody = $( this ).closest( '.ppb-tiers-editor' ).find( '.ppb-tiers-body' );
            $tbody.append( buildTierRow( '', '' ) );
            $tbody.find( '.ppb-tier-row:last .ppb-tier-min' ).focus();
        } );

        // Supprimer un palier.
        $( document ).on( 'click', '.ppb-tier-delete', function () {
            $( this ).closest( '.ppb-tier-row' ).remove();
        } );

        // Mise à jour du label du bouton après modification.
        $( document ).on( 'input', '.ppb-tier-min, .ppb-tier-price', function () {
            const $editor = $( this ).closest( '.ppb-tiers-row' );
            const id      = $editor.data( 'for' );
            const count   = $editor.find( '.ppb-tier-row' ).length;
            const $btn    = $( '.ppb-tiers-btn[data-product-id="' + id + '"]' );
            $btn.text( count > 0 ? '▾ Paliers (' + count + ')' : '▾ Paliers' );
        } );
    }

    function buildTierRow( min, price ) {
        return $( '<tr class="ppb-tier-row">' ).html(
            '<td><input type="number" class="ppb-tier-min" min="1" step="1" value="' + escHtml( String( min ) ) + '" style="width:70px" placeholder="1"></td>' +
            '<td><input type="number" class="ppb-tier-price" min="0" step="1" value="' + escHtml( String( price ) ) + '" style="width:90px" placeholder="0"></td>' +
            '<td><button type="button" class="ppb-tier-delete button-link" style="color:#c0392b">×</button></td>'
        );
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

    // -------------------------------------------------------------------------
    // Vérification des mises à jour GitHub
    // -------------------------------------------------------------------------

    function bindCheckUpdate() {
        $( '#ppb-check-update' ).on( 'click', function () {
            const $btn    = $( this );
            const $result = $( '#ppb-update-result' );

            $btn.prop( 'disabled', true ).text( '…' );
            $result.hide().html( '' );

            $.post( cfg.ajaxUrl, {
                action: 'ppb_check_update',
                nonce:  cfg.nonce,
            } )
            .done( function ( res ) {
                if ( ! res.success ) {
                    $result.html(
                        '<div class="notice notice-error inline" style="margin:0;"><p>' +
                        escHtml( res.data.message || 'Erreur inconnue.' ) +
                        '</p></div>'
                    ).show();
                    return;
                }

                const d = res.data;

                if ( ! d.has_update ) {
                    $result.html(
                        '<div class="notice notice-success inline" style="margin:0;"><p>' +
                        '✓ ' + escHtml( 'Version ' + d.current + ' — déjà à jour.' ) +
                        '</p></div>'
                    ).show();
                    return;
                }

                $result.html(
                    '<div class="notice notice-warning inline" style="margin:0; padding:12px 16px;">' +
                    '<p><strong>Mise à jour disponible : v' + escHtml( d.latest ) + '</strong>' +
                    ' (installée : v' + escHtml( d.current ) + ')</p>' +
                    '<p style="margin-bottom:0;">' +
                    '<a href="' + escHtml( d.update_url ) + '" class="button button-primary" style="margin-right:8px;">' +
                    'Mettre à jour maintenant' +
                    '</a>' +
                    '<a href="' + escHtml( d.changelog_url ) + '" target="_blank" rel="noopener noreferrer" class="button">' +
                    'Voir le changelog' +
                    '</a>' +
                    '</p></div>'
                ).show();
            } )
            .fail( function () {
                $result.html(
                    '<div class="notice notice-error inline" style="margin:0;"><p>Erreur réseau. Réessayez.</p></div>'
                ).show();
            } )
            .always( function () {
                $btn.prop( 'disabled', false ).text( 'Vérifier maintenant' );
            } );
        } );
    }

    function escHtml( str ) {
        return $( '<div>' ).text( String( str || '' ) ).html();
    }

} )( jQuery );
