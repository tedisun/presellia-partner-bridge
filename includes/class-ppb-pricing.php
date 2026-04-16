<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moteur de prix partenaire PPB.
 *
 * Stockage : meta `_ppb_partner_price` sur chaque produit/variation.
 * Application : hook `woocommerce_before_calculate_totals` — remplace le prix
 * WooCommerce par le prix partenaire dès que le visiteur est authentifié PPB.
 *
 * Aucune dépendance à PriceFox.
 */
class PPB_Pricing {

    /** Nom de la meta produit. */
    public const META_KEY = '_ppb_partner_price';

    public function __construct() {
        // Application des prix dans le panier/checkout.
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_partner_prices' ], 20, 1 );

        // Invalide le cache de prix des variations lors d'une mise à jour de prix partenaire.
        add_filter( 'woocommerce_get_variation_prices_hash', [ $this, 'variation_prices_hash' ] );
    }

    // -------------------------------------------------------------------------
    // Application des prix dans le panier
    // -------------------------------------------------------------------------

    /**
     * Remplace le prix de chaque article du panier par le prix partenaire
     * si le visiteur est authentifié et si un prix partenaire est défini.
     */
    public function apply_partner_prices( WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        if ( ! PPB_Auth::is_authenticated() ) {
            return;
        }

        foreach ( $cart->get_cart() as $item ) {
            /** @var WC_Product $product */
            $product    = $item['data'];
            $lookup_id  = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];

            $partner_price = self::get_partner_price( $lookup_id );

            // Fallback sur le produit parent si la variation n'a pas de prix partenaire.
            if ( '' === $partner_price && ! empty( $item['variation_id'] ) ) {
                $partner_price = self::get_partner_price( (int) $item['product_id'] );
            }

            if ( '' !== $partner_price ) {
                $product->set_price( $partner_price );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Lecture / écriture de la meta
    // -------------------------------------------------------------------------

    /**
     * Retourne le prix partenaire d'un produit/variation, ou '' si non défini.
     */
    public static function get_partner_price( int $product_id ): string {
        $value = get_post_meta( $product_id, self::META_KEY, true );

        return ( is_numeric( $value ) && (float) $value > 0 )
            ? (string) (float) $value
            : '';
    }

    /**
     * Sauvegarde le prix partenaire. Passe '' pour effacer.
     */
    public static function set_partner_price( int $product_id, string $value ): void {
        if ( '' === $value || ! is_numeric( $value ) || (float) $value <= 0 ) {
            delete_post_meta( $product_id, self::META_KEY );
        } else {
            update_post_meta( $product_id, self::META_KEY, wc_format_decimal( $value ) );
        }
    }

    // -------------------------------------------------------------------------
    // Catalogue pour le portail et l'API
    // -------------------------------------------------------------------------

    /**
     * Retourne le catalogue complet : produits publiés avec leurs variations,
     * prix WC réguliers, prix promo, et prix partenaires.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function get_catalog(): array {
        $products_wc = wc_get_products( [
            'status'  => 'publish',
            'limit'   => -1,
            'orderby' => 'title',
            'order'   => 'ASC',
        ] );

        $catalog = [];

        foreach ( $products_wc as $product ) {
            /** @var WC_Product $product */
            $item = self::format_product( $product );

            // Catégorie parente WooCommerce — menu_order pour le tri personnalisé.
            // On ne remonte que les catégories racine (parent = 0).
            $terms          = get_the_terms( $product->get_id(), 'product_cat' );
            $cat_name       = '';
            $cat_menu_order = 9999;

            if ( $terms && ! is_wp_error( $terms ) ) {
                // 1. Cherche une catégorie parente directe parmi les termes du produit.
                foreach ( $terms as $term ) {
                    if ( 0 === (int) $term->parent ) {
                        $order = (int) get_term_meta( $term->term_id, 'order', true );
                        // Garde la catégorie parente avec le menu_order le plus bas.
                        if ( '' === $cat_name || $order < $cat_menu_order ) {
                            $cat_name       = $term->name;
                            $cat_menu_order = $order;
                        }
                    }
                }

                // 2. Si le produit n'est que dans des sous-catégories, remonte au parent racine.
                if ( '' === $cat_name ) {
                    $root = $terms[0];
                    while ( 0 !== (int) $root->parent ) {
                        $parent = get_term( (int) $root->parent, 'product_cat' );
                        if ( ! $parent || is_wp_error( $parent ) ) {
                            break;
                        }
                        $root = $parent;
                    }
                    $cat_name       = $root->name;
                    $cat_menu_order = (int) get_term_meta( $root->term_id, 'order', true );
                }
            }

            $item['category']       = $cat_name;
            $item['category_order'] = $cat_menu_order;

            if ( $product->is_type( 'variable' ) ) {
                /** @var WC_Product_Variable $product */
                $item['variations'] = [];

                foreach ( $product->get_available_variations() as $variation_data ) {
                    $variation = wc_get_product( $variation_data['variation_id'] );

                    if ( ! $variation instanceof WC_Product_Variation ) {
                        continue;
                    }

                    $item['variations'][] = self::format_product( $variation );
                }

                // N'inclut le produit variable que s'il a au moins une variation.
                if ( ! empty( $item['variations'] ) ) {
                    $catalog[] = $item;
                }
            } elseif ( $product->is_type( 'simple' ) ) {
                $catalog[] = $item;
            }
        }

        return $catalog;
    }

    /**
     * Formate un produit ou une variation pour le catalogue.
     *
     * @return array<string, mixed>
     */
    private static function format_product( WC_Product $product ): array {
        $id            = $product->get_id();
        $regular_price = $product->get_regular_price();
        $sale_price    = $product->get_sale_price();
        $partner_price = self::get_partner_price( $id );

        // Miniature : image de la variation si définie, sinon celle du produit parent.
        $image_id = $product->get_image_id();
        if ( ! $image_id && $product instanceof WC_Product_Variation ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent ) {
                $image_id = $parent->get_image_id();
            }
        }
        $thumbnail_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';

        $data = [
            'id'            => $id,
            'name'          => $product->get_name(),
            'sku'           => $product->get_sku(),
            'type'          => $product->get_type(),
            'regular_price' => $regular_price !== '' ? (float) $regular_price : null,
            'sale_price'    => $sale_price    !== '' ? (float) $sale_price    : null,
            'partner_price' => $partner_price !== '' ? (float) $partner_price : null,
            'stock_status'  => $product->get_stock_status(),
            'manage_stock'  => $product->managing_stock(),
            'stock_qty'     => $product->managing_stock() ? $product->get_stock_quantity() : null,
            'thumbnail_url' => $thumbnail_url ?: '',
        ];

        // Pour les variations : ajouter les attributs formatés.
        if ( $product instanceof WC_Product_Variation ) {
            $data['attributes'] = wc_get_formatted_variation( $product, true, false );
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Cache des variations
    // -------------------------------------------------------------------------

    /**
     * Invalide le cache WC des prix de variation quand l'utilisateur est authentifié PPB.
     */
    public function variation_prices_hash( array $hash ): array {
        if ( PPB_Auth::is_authenticated() ) {
            $hash[] = 'ppb_partner_1';
        }

        return $hash;
    }
}
