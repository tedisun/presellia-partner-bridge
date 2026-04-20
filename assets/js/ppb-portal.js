/**
 * Presellia Partner Bridge — JS Portail
 * Gère : modale mdp, chargement catalogue, barre panier sticky, checkout.
 * Gère aussi : catalogue public [ppb_catalog] (init conditionnelle sur #ppb-catalog).
 */
( function ( $ ) {
    'use strict';

    const cfg = window.ppbPortal || {};
    const i18n = cfg.i18n || {};

    // -------------------------------------------------------------------------
    // État local
    // -------------------------------------------------------------------------

    const cart = {}; // { "productId_variationId": { product_id, variation_id, name, partner_price, quantity } }

    // URL de partage fournie par PHP (déjà authentifié) ou stockée après connexion AJAX.
    let shareUrl = cfg.shareUrl || '';

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------

    $( function () {
        if ( $( '#ppb-portal' ).length ) {
            if ( '1' === cfg.isAuth ) {
                loadCatalog();
            }
            bindAuthForm();
            bindToolbar();
            bindCartActions();
            bindCartToggle();
            bindTiersToggle();
            bindCheckout();
        }

        if ( $( '#ppb-catalog' ).length ) {
            loadPublicCatalog();
        }
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
                    shareUrl = res.data.share_url || '';

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
            maybeShowTiersBanner( res.data.catalog );
            bindSearch();
        } )
        .fail( function () {
            $loading.hide();
            $empty.text( 'Erreur de chargement.' ).removeClass( 'ppb-hidden' );
        } );
    }

    function maybeShowTiersBanner( products ) {
        // Affiche une bannière explicative uniquement si au moins un produit a des paliers.
        const hasTiers = products.some( function ( p ) {
            if ( p.tiers && p.tiers.length > 1 ) return true;
            if ( p.variations ) {
                return p.variations.some( function ( v ) { return v.tiers && v.tiers.length > 1; } );
            }
            return false;
        } );

        if ( ! hasTiers ) return;
        if ( $( '#ppb-tiers-banner' ).length ) return;

        $( '#ppb-catalog-table' ).before(
            $( '<div id="ppb-tiers-banner" class="ppb-tiers-banner">' ).html(
                '💡 <strong>Prix dégressifs disponibles.</strong> ' +
                'Certains produits proposent des remises automatiques selon la quantité commandée. ' +
                'Cliquez sur <em>▾ paliers</em> pour voir les seuils — le bon prix s\'applique ' +
                'automatiquement dans votre commande.'
            )
        );
    }

    function renderCatalog( products, $tbody ) {
        // Regrouper les produits par catégorie en conservant le menu_order WooCommerce.
        const categories = [];
        const byCategory = {};

        products.forEach( function ( product ) {
            const cat   = product.category || 'Autres';
            const order = product.category_order !== undefined ? product.category_order : 9999;
            if ( ! byCategory[ cat ] ) {
                byCategory[ cat ] = { products: [], order: order };
                categories.push( cat );
            }
            byCategory[ cat ].products.push( product );
        } );

        // Tri par menu_order WooCommerce (défini dans Produits → Catégories).
        categories.sort( function ( a, b ) {
            return byCategory[ a ].order - byCategory[ b ].order;
        } );

        categories.forEach( function ( cat ) {
            // En-tête de catégorie
            $tbody.append(
                $( '<tr class="ppb-row-category">' )
                    .attr( 'data-category', cat )
                    .append( $( '<td colspan="5">' ).text( cat ) )
            );

            byCategory[ cat ].products.forEach( function ( product ) {
                if ( product.variations && product.variations.length ) {
                    const $thumbCell = $( '<td class="ppb-col-thumb">' );
                    if ( product.thumbnail_url ) {
                        $thumbCell.html( '<img src="' + escAttr( product.thumbnail_url ) + '" class="ppb-product-thumb" alt="">' );
                    }

                    const $parentRow = $( '<tr class="ppb-row-parent">' )
                        .attr( 'data-category', cat )
                        .append( $thumbCell )
                        .append( $( '<td colspan="4">' ).html( '<strong>' + escHtml( product.name ) + '</strong>' ) );

                    $tbody.append( $parentRow );

                    product.variations.forEach( function ( variation ) {
                        $tbody.append( buildProductRow( variation, product.name, cat ) );
                    } );
                } else {
                    $tbody.append( buildProductRow( product, '', cat ) );
                }
            } );
        } );

        renderCategoryFilter( categories );
    }

    function buildProductRow( product, parentName, category ) {
        const rawName = parentName
            ? parentName + ' — ' + ( product.attributes || product.name )
            : product.name;

        // Nom affiché : lien vers la fiche produit si permalink disponible.
        let nameHtml;
        if ( parentName ) {
            nameHtml = escHtml( parentName ) + ' — <em>' + escHtml( product.attributes || product.name ) + '</em>';
        } else {
            nameHtml = escHtml( product.name );
        }
        if ( product.permalink ) {
            nameHtml = '<a href="' + escAttr( product.permalink ) + '" target="_blank" rel="noopener" class="ppb-product-link">' + nameHtml + '</a>';
        }

        // Affichage du stock si géré et disponible.
        if ( product.manage_stock && product.stock_qty !== null && product.stock_status !== 'outofstock' ) {
            const qty = parseInt( product.stock_qty, 10 );
            if ( qty >= 0 ) {
                nameHtml += ' <small class="ppb-stock-qty">' + qty + ' en stock</small>';
            }
        }

        const publicPrice  = product.sale_price !== null ? product.sale_price : product.regular_price;
        const partnerPrice = product.partner_price;

        const $row = $( '<tr class="ppb-row-product">' )
            .attr( 'data-name', rawName.toLowerCase() )
            .attr( 'data-category', category || '' );

        // Colonne miniature
        const $thumbCell = $( '<td class="ppb-col-thumb">' );
        if ( product.thumbnail_url ) {
            $thumbCell.html( '<img src="' + escAttr( product.thumbnail_url ) + '" class="ppb-product-thumb" alt="">' );
        }
        $row.append( $thumbCell );

        $row.append( $( '<td class="ppb-product-name">' ).html( nameHtml ) );

        // Colonne prix : prix partenaire + prix public barré + toggle paliers si définis.
        const $partnerCell = $( '<td class="ppb-col-num ppb-col-partner">' );
        if ( partnerPrice !== null ) {
            let html = '<span class="ppb-price-partner">' + formatPrice( partnerPrice ) + '</span>';
            const regularPrice = product.regular_price;
            if ( regularPrice !== null && regularPrice > partnerPrice ) {
                html = '<s class="ppb-price-public">' + formatPrice( regularPrice ) + '</s> ' + html;
            }
            // Bouton paliers si au moins 2 paliers définis.
            const tiers = product.tiers || [];
            if ( tiers.length > 1 ) {
                const rowKey = escAttr( String( product.id ) + '_' + String( product.variation_id || 0 ) );
                html += ' <button class="ppb-tiers-toggle" data-key="' + rowKey + '" data-tiers="' +
                    escAttr( JSON.stringify( tiers ) ) + '">▾ paliers</button>';
            }
            $partnerCell.html( html );
        } else {
            $partnerCell.html( '<em class="ppb-no-price">' + i18n.noPartnerPrice + '</em>' );
        }
        $row.append( $partnerCell );

        // Champ quantité
        const $qty = $( '<input type="number" class="ppb-qty-input" min="1" value="1" step="1">' )
            .attr( 'data-product-id', product.id )
            .attr( 'data-variation-id', product.variation_id || 0 );
        $row.append( $( '<td class="ppb-col-num">' ).append( $qty ) );

        // Bouton ajouter + gestion du stock
        const outOfStock  = product.stock_status === 'outofstock';
        const backorder   = product.stock_status === 'onbackorder';

        const $addBtn = $( '<button class="ppb-btn ppb-btn-add ppb-btn-sm">' )
            .text( i18n.addToCart )
            .attr( 'data-product-id', product.id )
            .attr( 'data-variation-id', product.variation_id || 0 )
            .attr( 'data-name', parentName ? parentName + ' — ' + ( product.attributes || product.name ) : product.name )
            .attr( 'data-partner-price', partnerPrice !== null ? partnerPrice : '' );

        if ( partnerPrice === null ) {
            $addBtn.prop( 'disabled', true ).attr( 'title', i18n.noPartnerPrice );
        } else if ( outOfStock ) {
            $addBtn.prop( 'disabled', true );
        }

        const $actionCell = $( '<td class="ppb-col-action">' );

        if ( outOfStock ) {
            $actionCell.append( $( '<span class="ppb-stock-badge ppb-stock-out">' ).text( 'Rupture' ) );
        } else if ( backorder ) {
            $actionCell.append( $( '<span class="ppb-stock-badge ppb-stock-backorder">' ).text( 'Sur commande' ) );
            $actionCell.append( $addBtn );
        } else {
            $actionCell.append( $addBtn );
        }

        $row.append( $actionCell );

        return $row;
    }

    // -------------------------------------------------------------------------
    // Recherche + filtre catégorie
    // -------------------------------------------------------------------------

    function bindSearch() {
        $( '#ppb-search' ).on( 'input', filterCatalog );
        bindTutorialVideo();
    }

    function bindTutorialVideo() {
        if ( ! cfg.tutorialVideoUrl ) return;

        // Injecte le bouton tutoriel dans la toolbar.
        const $toolbarActions = $( '.ppb-toolbar-actions' );
        if ( ! $toolbarActions.length ) return;

        const $btn = $( '<button type="button" class="ppb-btn ppb-btn-ghost ppb-tutorial-btn">' )
            .html( '📹 Tutoriel' );

        $toolbarActions.prepend( $btn );

        $btn.on( 'click', function () {
            let $panel = $( '#ppb-tutorial-panel' );

            if ( $panel.length ) {
                $panel.slideToggle( 200 );
                return;
            }

            // Construction du panneau avec iframe responsive.
            $panel = $( '<div id="ppb-tutorial-panel" class="ppb-tutorial-panel">' ).html(
                '<div class="ppb-tutorial-iframe-wrap">' +
                '<iframe src="' + escAttr( cfg.tutorialVideoUrl ) + '" ' +
                'frameborder="0" allowfullscreen loading="lazy" ' +
                'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture">' +
                '</iframe></div>'
            );

            $( '.ppb-toolbar' ).after( $panel );
            $panel.hide().slideDown( 200 );
        } );
    }

    function renderCategoryFilter( categories ) {
        if ( categories.length <= 1 ) return;

        const $select = $( '<select id="ppb-cat-filter" class="ppb-input ppb-cat-select">' )
            .append( $( '<option value="">' ).text( 'Toutes les catégories' ) );

        categories.forEach( function ( cat ) {
            $select.append( $( '<option>' ).val( cat ).text( cat ) );
        } );

        $select.on( 'change', filterCatalog );
        $select.insertBefore( '#ppb-search' );
    }

    function filterCatalog() {
        const q   = $( '#ppb-search' ).val().toLowerCase().trim();
        const cat = $( '#ppb-cat-filter' ).val() || '';

        // Produits
        $( '#ppb-catalog-body tr.ppb-row-product' ).each( function () {
            const name    = $( this ).data( 'name' ) || '';
            const rowCat  = $( this ).attr( 'data-category' ) || '';
            const matchQ  = q   === '' || name.indexOf( q ) !== -1;
            const matchC  = cat === '' || rowCat === cat;
            $( this ).toggle( matchQ && matchC );
        } );

        // Lignes parent (produits variables) : visible si au moins une variation visible.
        $( '#ppb-catalog-body tr.ppb-row-parent' ).each( function () {
            const $siblings = $( this ).nextUntil( '.ppb-row-parent, .ppb-row-category', '.ppb-row-product' );
            $( this ).toggle( $siblings.filter( ':visible' ).length > 0 );
        } );

        // En-têtes de catégorie : visible si au moins un produit visible dans cette catégorie.
        $( '#ppb-catalog-body tr.ppb-row-category' ).each( function () {
            const rowCat = $( this ).attr( 'data-category' ) || '';
            const $items = $( '#ppb-catalog-body tr[data-category="' + rowCat + '"]' )
                .not( '.ppb-row-category' );
            $( this ).toggle( $items.filter( ':visible' ).length > 0 );
        } );
    }

    // -------------------------------------------------------------------------
    // Toolbar (copier lien, déconnexion)
    // -------------------------------------------------------------------------

    function bindToolbar() {
        $( '#ppb-copy-link' ).on( 'click', function () {
            if ( ! shareUrl ) return;

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
    // Panier — actions (ajouter, retirer, changer qté)
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
            flashBar();
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

    // -------------------------------------------------------------------------
    // Paliers de quantité — toggle ligne détail dans le catalogue
    // -------------------------------------------------------------------------

    function bindTiersToggle() {
        $( document ).on( 'click', '.ppb-tiers-toggle', function () {
            const $btn  = $( this );
            const key   = $btn.data( 'key' );
            const tiers = $btn.data( 'tiers' );

            const $existingRow = $( '#ppb-tiers-row-' + key );

            if ( $existingRow.length ) {
                $existingRow.remove();
                $btn.text( '▾ paliers' );
                return;
            }

            $btn.text( '▴ paliers' );

            // Construction de la ligne de détail.
            let cells = '';
            tiers.forEach( function ( tier, i ) {
                const next  = tiers[ i + 1 ];
                const label = next ? tier.min + '–' + ( next.min - 1 ) : tier.min + '+';
                cells += '<span class="ppb-tier-chip">' +
                    '<span class="ppb-tier-qty">' + escHtml( label ) + '</span>' +
                    '<span class="ppb-tier-price-val">' + formatPrice( tier.price ) + '</span>' +
                    '</span>';
            } );

            const $tiersRow = $( '<tr id="ppb-tiers-row-' + key + '" class="ppb-tiers-info-row">' )
                .append( $( '<td colspan="5" class="ppb-tiers-info-cell">' ).html( cells ) );

            $btn.closest( 'tr' ).after( $tiersRow );
        } );
    }

    // -------------------------------------------------------------------------
    // Panier — toggle du panneau détail
    // -------------------------------------------------------------------------

    function bindCartToggle() {
        $( document ).on( 'click', '#ppb-cart-bar-toggle', function () {
            $( '#ppb-cart-panel' ).toggleClass( 'ppb-panel-open' );
        } );

        $( document ).on( 'click', '#ppb-cart-panel-close', function () {
            $( '#ppb-cart-panel' ).removeClass( 'ppb-panel-open' );
        } );

        // Ferme le panneau si on clique en dehors.
        $( document ).on( 'click', function ( e ) {
            if (
                ! $( e.target ).closest( '#ppb-cart-panel, #ppb-cart-bar-toggle' ).length &&
                $( '#ppb-cart-panel' ).hasClass( 'ppb-panel-open' )
            ) {
                $( '#ppb-cart-panel' ).removeClass( 'ppb-panel-open' );
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Panier — rendu (barre + panneau)
    // -------------------------------------------------------------------------

    function renderCart() {
        const $bar    = $( '#ppb-cart-bar' );
        const $panel  = $( '#ppb-cart-panel' );
        const $body   = $( '#ppb-cart-body' );
        const $label  = $( '#ppb-cart-bar-label' );
        const $total  = $( '#ppb-cart-bar-total' );
        const keys    = Object.keys( cart );

        if ( keys.length === 0 ) {
            $bar.addClass( 'ppb-hidden' );
            $panel.removeClass( 'ppb-panel-open' );
            $( 'body' ).removeClass( 'ppb-has-cart-bar' );
            return;
        }

        $bar.removeClass( 'ppb-hidden' );
        $( 'body' ).addClass( 'ppb-has-cart-bar' );
        $body.empty();

        let total    = 0;
        let totalQty = 0;

        keys.forEach( function ( key ) {
            const item     = cart[ key ];
            const subtotal = item.partner_price * item.quantity;
            total         += subtotal;
            totalQty      += item.quantity;

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
                        escAttr( key ) + '">×</button>'
                    )
                );

            $body.append( $row );
        } );

        const itemLabel = totalQty + ' article' + ( totalQty > 1 ? 's' : '' );
        $label.text( itemLabel );
        $total.text( formatPrice( total ) );
    }

    // Animation flash sur la barre quand un article est ajouté.
    function flashBar() {
        const $bar = $( '#ppb-cart-bar' );
        $bar.addClass( 'ppb-bar-flash' );
        setTimeout( function () {
            $bar.removeClass( 'ppb-bar-flash' );
        }, 400 );
    }

    // -------------------------------------------------------------------------
    // Checkout
    // -------------------------------------------------------------------------

    function bindCheckout() {
        $( document ).on( 'click', '#ppb-checkout-btn', function () {
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
    // Catalogue public [ppb_catalog]
    // -------------------------------------------------------------------------

    function loadPublicCatalog() {
        const $loading = $( '#ppb-public-loading' );
        const $table   = $( '#ppb-public-table' );
        const $empty   = $( '#ppb-public-empty' );
        const $tbody   = $( '#ppb-public-body' );

        $loading.show();
        $table.addClass( 'ppb-hidden' );
        $empty.addClass( 'ppb-hidden' );

        $.post( cfg.ajaxUrl, {
            action: 'ppb_load_public_catalog',
            nonce:  cfg.nonce,
        } )
        .done( function ( res ) {
            $loading.hide();

            if ( ! res.success || ! res.data.catalog || res.data.catalog.length === 0 ) {
                $empty.removeClass( 'ppb-hidden' );
                return;
            }

            $tbody.empty();
            renderPublicCatalog( res.data.catalog, $tbody );
            $table.removeClass( 'ppb-hidden' );
            bindPublicSearch();
        } )
        .fail( function () {
            $loading.hide();
            $empty.text( 'Erreur de chargement.' ).removeClass( 'ppb-hidden' );
        } );
    }

    function renderPublicCatalog( products, $tbody ) {
        const categories = [];
        const byCategory = {};

        products.forEach( function ( product ) {
            const cat   = product.category || 'Autres';
            const order = product.category_order !== undefined ? product.category_order : 9999;
            if ( ! byCategory[ cat ] ) {
                byCategory[ cat ] = { products: [], order: order };
                categories.push( cat );
            }
            byCategory[ cat ].products.push( product );
        } );

        categories.sort( function ( a, b ) {
            return byCategory[ a ].order - byCategory[ b ].order;
        } );

        categories.forEach( function ( cat ) {
            $tbody.append(
                $( '<tr class="ppb-row-category">' )
                    .attr( 'data-category', cat )
                    .append( $( '<td colspan="4">' ).text( cat ) )
            );

            byCategory[ cat ].products.forEach( function ( product ) {
                if ( product.variations && product.variations.length ) {
                    const $thumbCell = $( '<td class="ppb-col-thumb">' );
                    if ( product.thumbnail_url ) {
                        $thumbCell.html( '<img src="' + escAttr( product.thumbnail_url ) + '" class="ppb-product-thumb" alt="">' );
                    }
                    $tbody.append(
                        $( '<tr class="ppb-row-parent">' )
                            .attr( 'data-category', cat )
                            .append( $thumbCell )
                            .append( $( '<td colspan="3">' ).html( '<strong>' + escHtml( product.name ) + '</strong>' ) )
                    );
                    product.variations.forEach( function ( variation ) {
                        $tbody.append( buildPublicRow( variation, product.name, cat ) );
                    } );
                } else {
                    $tbody.append( buildPublicRow( product, '', cat ) );
                }
            } );
        } );

        renderPublicCategoryFilter( categories );
    }

    function buildPublicRow( product, parentName, category ) {
        const rawName = parentName
            ? parentName + ' — ' + ( product.attributes || product.name )
            : product.name;

        let nameHtml;
        if ( parentName ) {
            nameHtml = escHtml( parentName ) + ' — <em>' + escHtml( product.attributes || product.name ) + '</em>';
        } else {
            nameHtml = escHtml( product.name );
        }
        if ( product.permalink ) {
            nameHtml = '<a href="' + escAttr( product.permalink ) + '" target="_blank" rel="noopener" class="ppb-product-link">' + nameHtml + '</a>';
        }

        const outOfStock = product.stock_status === 'outofstock';
        const backorder  = product.stock_status === 'onbackorder';

        let stockHtml;
        if ( outOfStock ) {
            stockHtml = '<span class="ppb-stock-badge ppb-stock-out">Rupture</span>';
        } else if ( backorder ) {
            stockHtml = '<span class="ppb-stock-badge ppb-stock-backorder">Sur commande</span>';
        } else {
            let label = 'En stock';
            if ( product.manage_stock && product.stock_qty !== null ) {
                label += ' (' + parseInt( product.stock_qty, 10 ) + ')';
            }
            stockHtml = '<span class="ppb-stock-badge ppb-stock-in">' + escHtml( label ) + '</span>';
        }

        const regular = product.regular_price;
        const sale    = product.sale_price;
        let priceHtml;
        if ( sale !== null && sale < regular ) {
            priceHtml = '<s class="ppb-price-public-old">' + formatPrice( regular ) + '</s> ' +
                        '<span class="ppb-price-sale">' + formatPrice( sale ) + '</span>';
        } else if ( regular !== null ) {
            priceHtml = '<span class="ppb-price-regular">' + formatPrice( regular ) + '</span>';
        } else {
            priceHtml = '—';
        }

        return $( '<tr class="ppb-row-product">' )
            .attr( 'data-name', rawName.toLowerCase() )
            .attr( 'data-category', category || '' )
            .append(
                $( '<td class="ppb-col-thumb">' ).html(
                    product.thumbnail_url
                        ? '<img src="' + escAttr( product.thumbnail_url ) + '" class="ppb-product-thumb" alt="">'
                        : ''
                )
            )
            .append( $( '<td class="ppb-product-name">' ).html( nameHtml ) )
            .append( $( '<td class="ppb-col-num">' ).html( priceHtml ) )
            .append( $( '<td class="ppb-col-stock">' ).html( stockHtml ) );
    }

    function renderPublicCategoryFilter( categories ) {
        if ( categories.length <= 1 ) return;
        if ( $( '#ppb-public-cat-filter' ).length ) return;

        const $select = $( '<select id="ppb-public-cat-filter" class="ppb-input ppb-cat-select">' )
            .append( $( '<option value="">' ).text( 'Toutes les catégories' ) );

        categories.forEach( function ( cat ) {
            $select.append( $( '<option>' ).val( cat ).text( cat ) );
        } );

        $select.on( 'change', filterPublicCatalog );
        $select.insertBefore( '#ppb-public-search' );
    }

    function bindPublicSearch() {
        $( '#ppb-public-search' ).on( 'input', filterPublicCatalog );
    }

    function filterPublicCatalog() {
        const q   = $( '#ppb-public-search' ).val().toLowerCase().trim();
        const cat = $( '#ppb-public-cat-filter' ).val() || '';

        $( '#ppb-public-body tr.ppb-row-product' ).each( function () {
            const name   = $( this ).data( 'name' ) || '';
            const rowCat = $( this ).attr( 'data-category' ) || '';
            $( this ).toggle( ( q === '' || name.indexOf( q ) !== -1 ) && ( cat === '' || rowCat === cat ) );
        } );

        $( '#ppb-public-body tr.ppb-row-parent' ).each( function () {
            const $siblings = $( this ).nextUntil( '.ppb-row-parent, .ppb-row-category', '.ppb-row-product' );
            $( this ).toggle( $siblings.filter( ':visible' ).length > 0 );
        } );

        $( '#ppb-public-body tr.ppb-row-category' ).each( function () {
            const rowCat = $( this ).attr( 'data-category' ) || '';
            const $items = $( '#ppb-public-body tr[data-category="' + rowCat + '"]' ).not( '.ppb-row-category' );
            $( this ).toggle( $items.filter( ':visible' ).length > 0 );
        } );
    }

    // -------------------------------------------------------------------------
    // Utilitaires
    // -------------------------------------------------------------------------

    function formatPrice( amount ) {
        return parseFloat( amount ).toLocaleString( 'fr-FR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        } ) + '\u00a0' + cfg.currency;
    }

    function escHtml( str ) {
        return $( '<div>' ).text( str || '' ).html();
    }

    function escAttr( str ) {
        return String( str || '' ).replace( /"/g, '&quot;' );
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
