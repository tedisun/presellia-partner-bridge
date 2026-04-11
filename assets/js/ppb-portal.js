/**
 * Presellia Partner Bridge — JS Portail
 * Gère : modale mdp, chargement catalogue, mini-panier, checkout.
 */
( function ( $ ) {
    'use strict';

    const cfg = window.ppbPortal || {};
    const i18n = cfg.i18n || {};

    // -------------------------------------------------------------------------
    // État local
    // -------------------------------------------------------------------------

    const cart = {}; // { "productId_variationId": { product_id, variation_id, name, partner_price, quantity } }

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    $( function () {
        if ( '1' === cfg.isAuth ) {
            loadCatalog();
        }

        bindAuthForm();
        bindToolbar();
        bindCartActions();
        bindCheckout();
    } );

    // -------------------------------------------------------------------------
    // Authentification
    // -------------------------------------------------------------------------

    function bindAuthForm() {
        const $input  = $( '#ppb-password-input' );
        const $btn    = $( '#ppb-password-submit' );
        const $error  = $( '#ppb-auth-error' );

        $btn.on( 'click', submitPassword );
        $input.on( 'keydown', function ( e ) {
            if ( 13 === e.which ) {
                submitPassword();
            }
        } );

        function submitPassword() {
            const password = $input.val().trim();
            if ( ! password ) return;

            $btn.prop( 'disabled', true ).text( '…' );
            $error.addClass( 'ppb-hidden' ).text( '' );

            $.post( cfg.ajaxUrl, {
                action:   'ppb_validate_password',
                nonce:    cfg.nonce,
                password: password,
            } )
            .done( function ( res ) {
                if ( res.success ) {
                    // Pose le cookie côté JS pour les navigateurs (l'auth PHP l'a déjà posé via setcookie).
                    const days    = parseInt( res.data.expires_days || cfg.tokenTtlDays, 10 );
                    const expires = new Date();
                    expires.setDate( expires.getDate() + days );
                    document.cookie = cfg.cookieName + '=' + res.data.token +
                        '; expires=' + expires.toUTCString() +
                        '; path=/; SameSite=Lax' +
                        ( location.protocol === 'https:' ? '; Secure' : '' );

                    $( '#ppb-auth-modal' ).addClass( 'ppb-hidden' );
                    $( '#ppb-content' ).removeClass( 'ppb-hidden' );
                    loadCatalog();
                } else {
                    $error.text( res.data.message || i18n.wrongPassword ).removeClass( 'ppb-hidden' );
                    $input.val( '' ).focus();
                    $btn.prop( 'disabled', false ).text( i18n.passwordSubmit );
                }
            } )
            .fail( function () {
                $error.text( i18n.wrongPassword ).removeClass( 'ppb-hidden' );
                $btn.prop( 'disabled', false ).text( i18n.passwordSubmit );
            } );
        }
    }

    // -------------------------------------------------------------------------
    // Catalogue
    // -------------------------------------------------------------------------

    function loadCatalog() {
        const $loading = $( '#ppb-catalog-loading' );
        const $table   = $( '#ppb-catalog-table' );
        const $empty   = $( '#ppb-catalog-empty' );
        const $tbody   = $( '#ppb-catalog-body' );

        $loading.show();
        $table.addClass( 'ppb-hidden' );
        $empty.addClass( 'ppb-hidden' );

        $.post( cfg.ajaxUrl, {
            action: 'ppb_load_catalog',
            nonce:  cfg.nonce,
        } )
        .done( function ( res ) {
            $loading.hide();

            if ( ! res.success || ! res.data.catalog || res.data.catalog.length === 0 ) {
                $empty.removeClass( 'ppb-hidden' );
                return;
            }

            $tbody.empty();
            renderCatalog( res.data.catalog, $tbody );
            $table.removeClass( 'ppb-hidden' );
            bindSearch();
        } )
        .fail( function () {
            $loading.hide();
            $empty.text( 'Erreur de chargement.' ).removeClass( 'ppb-hidden' );
        } );
    }

    function renderCatalog( products, $tbody ) {
        products.forEach( function ( product ) {
            if ( product.variations && product.variations.length ) {
                // Ligne parent (visuelle, non-interactive)
                const $parentRow = $( '<tr class="ppb-row-parent">' ).append(
                    $( '<td colspan="5">' ).html( '<strong>' + escHtml( product.name ) + '</strong>' )
                );
                $tbody.append( $parentRow );

                product.variations.forEach( function ( variation ) {
                    $tbody.append( buildProductRow( variation, product.name ) );
                } );
            } else {
                $tbody.append( buildProductRow( product, '' ) );
            }
        } );
    }

    function buildProductRow( product, parentName ) {
        const displayName = parentName
            ? escHtml( parentName ) + ' — <em>' + escHtml( product.attributes || product.name ) + '</em>'
            : escHtml( product.name );

        const publicPrice  = product.sale_price !== null ? product.sale_price : product.regular_price;
        const partnerPrice = product.partner_price;

        const $row = $( '<tr class="ppb-row-product">' ).attr( 'data-name', ( parentName + ' ' + product.name ).toLowerCase() );

        $row.append( $( '<td class="ppb-product-name">' ).html( displayName ) );
        $row.append( $( '<td class="ppb-col-num ppb-public-price">' ).text(
            publicPrice !== null ? formatPrice( publicPrice ) : '—'
        ) );

        const $partnerCell = $( '<td class="ppb-col-num ppb-col-partner">' );
        if ( partnerPrice !== null ) {
            $partnerCell.text( formatPrice( partnerPrice ) );
        } else {
            $partnerCell.html( '<em class="ppb-no-price">' + i18n.noPartnerPrice + '</em>' );
        }
        $row.append( $partnerCell );

        // Champ quantité
        const $qty = $( '<input type="number" class="ppb-qty-input" min="1" value="1" step="1">' )
            .attr( 'data-product-id', product.id )
            .attr( 'data-variation-id', product.variation_id || 0 );
        $row.append( $( '<td class="ppb-col-num">' ).append( $qty ) );

        // Bouton ajouter
        const $addBtn = $( '<button class="ppb-btn ppb-btn-add ppb-btn-sm">' )
            .text( i18n.addToCart )
            .attr( 'data-product-id', product.id )
            .attr( 'data-variation-id', product.variation_id || 0 )
            .attr( 'data-name', parentName ? parentName + ' — ' + ( product.attributes || product.name ) : product.name )
            .attr( 'data-partner-price', partnerPrice !== null ? partnerPrice : '' );

        if ( partnerPrice === null ) {
            $addBtn.prop( 'disabled', true ).attr( 'title', i18n.noPartnerPrice );
        }

        $row.append( $( '<td class="ppb-col-action">' ).append( $addBtn ) );

        return $row;
    }

    // -------------------------------------------------------------------------
    // Recherche
    // -------------------------------------------------------------------------

    function bindSearch() {
        $( '#ppb-search' ).on( 'input', function () {
            const q = $( this ).val().toLowerCase().trim();

            $( '#ppb-catalog-body tr.ppb-row-product' ).each( function () {
                const name = $( this ).data( 'name' ) || '';
                $( this ).toggle( q === '' || name.indexOf( q ) !== -1 );
            } );

            // Cache les lignes parent si toutes leurs variations sont cachées.
            $( '#ppb-catalog-body tr.ppb-row-parent' ).each( function () {
                const $siblings = $( this ).nextUntil( '.ppb-row-parent', '.ppb-row-product' );
                const anyVisible = $siblings.filter( ':visible' ).length > 0;
                $( this ).toggle( anyVisible );
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Toolbar (copier lien, déconnexion)
    // -------------------------------------------------------------------------

    function bindToolbar() {
        $( '#ppb-copy-link' ).on( 'click', function () {
            const token = getCookie( cfg.cookieName );
            if ( ! token ) return;

            const shareUrl = location.origin + location.pathname + '?t=' + token;
            copyToClipboard( shareUrl );

            const $btn = $( this );
            $btn.text( i18n.linkCopied );
            setTimeout( function () {
                $btn.html( '🔗 ' + i18n.shareLink );
            }, 2000 );
        } );

        $( '#ppb-logout' ).on( 'click', function () {
            $.post( cfg.ajaxUrl, {
                action: 'ppb_revoke_token',
                nonce:  cfg.nonce,
            } ).always( function () {
                document.cookie = cfg.cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
                location.reload();
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Mini-panier
    // -------------------------------------------------------------------------

    function bindCartActions() {
        $( document ).on( 'click', '.ppb-btn-add', function () {
            const $btn        = $( this );
            const productId   = parseInt( $btn.data( 'product-id' ), 10 );
            const variationId = parseInt( $btn.data( 'variation-id' ) || 0, 10 );
            const name        = $btn.data( 'name' );
            const price       = parseFloat( $btn.data( 'partner-price' ) );

            const $qtyInput = $( '[data-product-id="' + productId + '"][data-variation-id="' + variationId + '"].ppb-qty-input' );
            const quantity  = parseInt( $qtyInput.val() || 1, 10 );

            if ( isNaN( price ) || price <= 0 ) return;

            const key = productId + '_' + variationId;

            if ( cart[ key ] ) {
                cart[ key ].quantity += quantity;
            } else {
                cart[ key ] = {
                    product_id:    productId,
                    variation_id:  variationId,
                    name:          name,
                    partner_price: price,
                    quantity:      quantity,
                };
            }

            renderCart();
        } );

        $( document ).on( 'click', '.ppb-cart-remove', function () {
            const key = $( this ).data( 'key' );
            delete cart[ key ];
            renderCart();
        } );

        $( document ).on( 'change', '.ppb-cart-qty', function () {
            const key = $( this ).data( 'key' );
            const qty = parseInt( $( this ).val(), 10 );

            if ( cart[ key ] ) {
                if ( qty <= 0 ) {
                    delete cart[ key ];
                } else {
                    cart[ key ].quantity = qty;
                }
                renderCart();
            }
        } );
    }

    function renderCart() {
        const $cartSection = $( '#ppb-cart' );
        const $tbody       = $( '#ppb-cart-body' );
        const $total       = $( '#ppb-cart-total' );

        const keys = Object.keys( cart );

        if ( keys.length === 0 ) {
            $cartSection.addClass( 'ppb-hidden' );
            return;
        }

        $cartSection.removeClass( 'ppb-hidden' );
        $tbody.empty();

        let total = 0;

        keys.forEach( function ( key ) {
            const item     = cart[ key ];
            const subtotal = item.partner_price * item.quantity;
            total         += subtotal;

            const $row = $( '<tr>' )
                .append( $( '<td>' ).text( item.name ) )
                .append(
                    $( '<td class="ppb-col-num">' ).html(
                        '<input type="number" class="ppb-cart-qty ppb-qty-sm" min="1" value="' +
                        item.quantity + '" data-key="' + escAttr( key ) + '">'
                    )
                )
                .append( $( '<td class="ppb-col-num">' ).text( formatPrice( subtotal ) ) )
                .append(
                    $( '<td>' ).html(
                        '<button class="ppb-btn ppb-btn-remove ppb-cart-remove" data-key="' +
                        escAttr( key ) + '">' + i18n.removeFromCart + '</button>'
                    )
                );

            $tbody.append( $row );
        } );

        $total.text( formatPrice( total ) );
    }

    // -------------------------------------------------------------------------
    // Checkout
    // -------------------------------------------------------------------------

    function bindCheckout() {
        $( '#ppb-checkout-btn' ).on( 'click', function () {
            const $btn  = $( this );
            const keys  = Object.keys( cart );

            if ( keys.length === 0 ) {
                alert( i18n.cartEmpty );
                return;
            }

            $btn.prop( 'disabled', true ).text( i18n.checkingOut );

            const items = keys.map( function ( key ) {
                return {
                    product_id:   cart[ key ].product_id,
                    variation_id: cart[ key ].variation_id,
                    quantity:     cart[ key ].quantity,
                };
            } );

            $.post( cfg.ajaxUrl, {
                action: 'ppb_checkout',
                nonce:  cfg.nonce,
                items:  items,
            } )
            .done( function ( res ) {
                if ( res.success && res.data.checkout_url ) {
                    window.location.href = res.data.checkout_url;
                } else {
                    alert( ( res.data && res.data.message ) || 'Erreur.' );
                    $btn.prop( 'disabled', false ).text( i18n.checkout );
                }
            } )
            .fail( function () {
                alert( 'Erreur réseau. Réessayez.' );
                $btn.prop( 'disabled', false ).text( i18n.checkout );
            } );
        } );
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    function formatPrice( amount ) {
        return cfg.currency + ' ' + parseFloat( amount ).toLocaleString( 'fr-FR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        } );
    }

    function escHtml( str ) {
        return $( '<div>' ).text( str || '' ).html();
    }

    function escAttr( str ) {
        return String( str || '' ).replace( /"/g, '&quot;' );
    }

    function getCookie( name ) {
        const match = document.cookie.match( new RegExp( '(?:^|;\\s*)' + name + '=([^;]*)' ) );
        return match ? match[1] : null;
    }

    function copyToClipboard( text ) {
        if ( navigator.clipboard ) {
            navigator.clipboard.writeText( text );
        } else {
            const $tmp = $( '<textarea>' ).val( text ).appendTo( 'body' ).select();
            document.execCommand( 'copy' );
            $tmp.remove();
        }
    }

} )( jQuery );
