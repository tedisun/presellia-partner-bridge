<?php
/**
 * PPB — Page "Guide de démarrage"
 *
 * Explique le fonctionnement du plugin et guide l'admin dans la configuration.
 * Accessible via WooCommerce → PPB Guide.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// URLs utiles pour les liens dans la checklist.
$settings_url     = admin_url( 'admin.php?page=ppb-settings' );
$prices_url       = admin_url( 'admin.php?page=ppb-prices' );
$pages_url        = admin_url( 'edit.php?post_type=page' );
$new_page_url     = admin_url( 'post-new.php?post_type=page' );

// État de la configuration (pour la checklist dynamique).
$has_password     = (bool) get_option( 'ppb_portal_password_hash', '' );
$portal_page_id   = (int)  get_option( 'ppb_portal_page_id', 0 );
$portal_page_ok   = $portal_page_id > 0 && get_post( $portal_page_id );
$portal_url       = $portal_page_ok ? get_permalink( $portal_page_id ) : '';

// Vérifie si au moins un produit a un prix partenaire.
global $wpdb;
$has_prices = (bool) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value > 0 LIMIT 1",
        '_ppb_partner_price'
    )
);

$all_done = $has_password && $portal_page_ok && $has_prices;
?>
<div class="wrap ppb-guide-wrap">

    <h1><?php esc_html_e( 'Presellia Partner Bridge — Guide de démarrage', 'presellia-partner-bridge' ); ?></h1>

    <?php if ( $all_done ) : ?>
    <div class="notice notice-success inline ppb-gs-notice">
        <p>
            <strong><?php esc_html_e( 'Le portail est opérationnel.', 'presellia-partner-bridge' ); ?></strong>
            <?php if ( $portal_url ) : ?>
                <?php esc_html_e( 'Votre portail partenaire est accessible à l\'adresse : ', 'presellia-partner-bridge' ); ?>
                <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"><?php echo esc_html( $portal_url ); ?></a>
            <?php endif; ?>
        </p>
    </div>
    <?php else : ?>
    <div class="notice notice-warning inline ppb-gs-notice">
        <p>
            <strong><?php esc_html_e( 'Configuration incomplète.', 'presellia-partner-bridge' ); ?></strong>
            <?php esc_html_e( 'Suivez la checklist ci-dessous pour mettre en service le portail.', 'presellia-partner-bridge' ); ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="ppb-gs-hero">
        <h2><?php esc_html_e( 'Qu\'est-ce que Presellia Partner Bridge ?', 'presellia-partner-bridge' ); ?></h2>
        <p>
            <?php esc_html_e( 'PPB est un portail intégré à WooCommerce avec deux faces. L\'onglet Catalogue (accès libre) affiche les prix publics et le stock en temps réel — idéal pour votre service client ou vos clients réguliers qui veulent consulter la disponibilité sans se connecter. L\'onglet Espace Revendeur (protégé par mot de passe) donne aux partenaires l\'accès à leurs prix négociés et leur permet de composer et soumettre une commande directement vers le checkout WooCommerce.', 'presellia-partner-bridge' ); ?>
        </p>
    </div>

    <!-- Checklist de mise en route -->
    <div class="ppb-gs-card">
        <h2><?php esc_html_e( 'Checklist de mise en route', 'presellia-partner-bridge' ); ?></h2>
        <ul class="ppb-gs-checklist">

            <li class="<?php echo $has_password ? 'ppb-done' : 'ppb-todo'; ?>">
                <span class="ppb-check-icon"><?php echo $has_password ? '✓' : '○'; ?></span>
                <div>
                    <strong>
                        <a href="<?php echo esc_url( $settings_url ); ?>">
                            <?php esc_html_e( 'Définir le mot de passe partenaire', 'presellia-partner-bridge' ); ?>
                        </a>
                    </strong>
                    <p><?php esc_html_e( 'WooCommerce → PPB Réglages → section "Mot de passe partenaire". Ce mot de passe unique est partagé avec tous vos partenaires. Il n\'est jamais stocké en clair — seul son empreinte (hash) est conservée.', 'presellia-partner-bridge' ); ?></p>
                </div>
            </li>

            <li class="<?php echo $portal_page_ok ? 'ppb-done' : 'ppb-todo'; ?>">
                <span class="ppb-check-icon"><?php echo $portal_page_ok ? '✓' : '○'; ?></span>
                <div>
                    <strong>
                        <?php if ( $portal_page_ok ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $portal_page_id ) ); ?>">
                                <?php esc_html_e( 'Page portail créée et configurée', 'presellia-partner-bridge' ); ?>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( $new_page_url ); ?>">
                                <?php esc_html_e( 'Créer la page portail', 'presellia-partner-bridge' ); ?>
                            </a>
                        <?php endif; ?>
                    </strong>
                    <p>
                        <?php esc_html_e( 'Créez une nouvelle page WordPress, ajoutez-y le shortcode', 'presellia-partner-bridge' ); ?>
                        <code>[ppb_portal]</code>
                        <?php esc_html_e( '(ou le widget Shortcode dans Elementor), puis publiez-la. Ensuite, sélectionnez cette page dans', 'presellia-partner-bridge' ); ?>
                        <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'PPB Réglages → Page portail', 'presellia-partner-bridge' ); ?></a>.
                    </p>
                    <?php if ( ! $portal_page_ok ) : ?>
                    <p class="ppb-gs-tip">
                        💡 <?php esc_html_e( 'Vous utilisez Elementor ? Ajoutez un widget "Shortcode" sur la page et collez', 'presellia-partner-bridge' ); ?>
                        <code>[ppb_portal]</code> <?php esc_html_e( 'dedans.', 'presellia-partner-bridge' ); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </li>

            <li class="<?php echo $has_prices ? 'ppb-done' : 'ppb-todo'; ?>">
                <span class="ppb-check-icon"><?php echo $has_prices ? '✓' : '○'; ?></span>
                <div>
                    <strong>
                        <a href="<?php echo esc_url( $prices_url ); ?>">
                            <?php esc_html_e( 'Saisir les prix partenaires', 'presellia-partner-bridge' ); ?>
                        </a>
                    </strong>
                    <p>
                        <?php esc_html_e( 'WooCommerce → Prix partenaires. Remplissez la colonne "Prix partenaire" pour chaque produit, puis cliquez "Enregistrer tout". Sans prix partenaire, un produit apparaît dans le catalogue mais ne peut pas être commandé.', 'presellia-partner-bridge' ); ?>
                    </p>
                </div>
            </li>

            <li class="ppb-todo ppb-neutral">
                <span class="ppb-check-icon">→</span>
                <div>
                    <strong><?php esc_html_e( 'Partager l\'accès avec vos partenaires', 'presellia-partner-bridge' ); ?></strong>
                    <p>
                        <?php esc_html_e( 'Envoyez à chaque partenaire l\'URL du portail et le mot de passe. Lors de leur première connexion, ils pourront copier un lien d\'accès rapide (bouton "Partager l\'accès") qui les connectera automatiquement sans mot de passe sur leurs appareils habituels.', 'presellia-partner-bridge' ); ?>
                        <?php if ( $portal_url ) : ?>
                            <br><strong><?php esc_html_e( 'URL du portail :', 'presellia-partner-bridge' ); ?></strong>
                            <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"><?php echo esc_html( $portal_url ); ?></a>
                        <?php endif; ?>
                    </p>
                </div>
            </li>

        </ul>
    </div>

    <!-- Comment ça fonctionne -->
    <div class="ppb-gs-card">
        <h2><?php esc_html_e( 'Comment fonctionne le portail', 'presellia-partner-bridge' ); ?></h2>

        <h3 style="margin-top:0">📋 <?php esc_html_e( 'Onglet Catalogue (accès libre)', 'presellia-partner-bridge' ); ?></h3>
        <div class="ppb-gs-flow">

            <div class="ppb-gs-flow-step">
                <div class="ppb-gs-step-num">1</div>
                <div>
                    <strong><?php esc_html_e( 'Tout visiteur arrive sur l\'onglet Catalogue', 'presellia-partner-bridge' ); ?></strong>
                    <p><?php esc_html_e( 'Sans mot de passe ni connexion, le tableau affiche immédiatement les produits avec leurs prix publics (barré si promo) et un badge de stock coloré (En stock / Rupture / Sur commande).', 'presellia-partner-bridge' ); ?></p>
                </div>
            </div>

            <div class="ppb-gs-flow-step">
                <div class="ppb-gs-step-num">2</div>
                <div>
                    <strong><?php esc_html_e( 'Recherche et filtrage instantanés', 'presellia-partner-bridge' ); ?></strong>
                    <p><?php esc_html_e( 'La barre de recherche et le filtre par catégorie fonctionnent côté client (JavaScript) — aucun rechargement de page. Idéal pour le service client qui cherche rapidement un produit.', 'presellia-partner-bridge' ); ?></p>
                </div>
            </div>

        </div>

        <h3>🤝 <?php esc_html_e( 'Onglet Espace Revendeur (protégé)', 'presellia-partner-bridge' ); ?></h3>
        <div class="ppb-gs-flow">

            <div class="ppb-gs-flow-step">
                <div class="ppb-gs-step-num">3</div>
                <div>
                    <strong><?php esc_html_e( 'Le partenaire clique l\'onglet et saisit le mot de passe', 'presellia-partner-bridge' ); ?></strong>
                    <p><?php esc_html_e( 'Une modale lui demande le mot de passe partenaire que vous lui avez communiqué.', 'presellia-partner-bridge' ); ?></p>
                </div>
            </div>

            <div class="ppb-gs-flow-step">
                <div class="ppb-gs-step-num">4</div>
                <div>
                    <strong><?php esc_html_e( 'Un token est créé et stocké', 'presellia-partner-bridge' ); ?></strong>
                    <p>
                        <?php esc_html_e( 'WordPress génère un token de session (valide', 'presellia-partner-bridge' ); ?>
                        <?php echo esc_html( get_option( 'ppb_token_ttl', 30 ) ); ?>
                        <?php esc_html_e( 'jours). Le partenaire ne saisit plus le mot de passe tant que le token est valide.', 'presellia-partner-bridge' ); ?>
                    </p>
                </div>
            </div>

            <div class="ppb-gs-flow-step">
                <div class="ppb-gs-step-num">5</div>
                <div>
                    <strong><?php esc_html_e( 'Le catalogue affiche les prix partenaires', 'presellia-partner-bridge' ); ?></strong>
                    <p><?php esc_html_e( 'Chaque produit avec un prix partenaire défini est affiché avec ce prix. Les produits sans prix partenaire sont visibles mais non commandables.', 'presellia-partner-bridge' ); ?></p>
                </div>
            </div>

            <div class="ppb-gs-flow-step">
                <div class="ppb-gs-step-num">6</div>
                <div>
                    <strong><?php esc_html_e( 'Il compose sa commande et valide', 'presellia-partner-bridge' ); ?></strong>
                    <p><?php esc_html_e( 'Le mini-panier intégré permet d\'ajouter des quantités. En cliquant "Commander", il est redirigé vers le checkout WooCommerce. Les prix partenaires sont appliqués automatiquement grâce au cookie de session.', 'presellia-partner-bridge' ); ?></p>
                </div>
            </div>

            <div class="ppb-gs-flow-step">
                <div class="ppb-gs-step-num">7</div>
                <div>
                    <strong><?php esc_html_e( 'La commande WooCommerce est passée normalement', 'presellia-partner-bridge' ); ?></strong>
                    <p><?php esc_html_e( 'PPB ne modifie pas le checkout ni les emails WooCommerce. Vous gérez les commandes partenaires depuis WooCommerce → Commandes.', 'presellia-partner-bridge' ); ?></p>
                </div>
            </div>

        </div>
    </div>

    <!-- Concepts clés -->
    <div class="ppb-gs-card">
        <h2><?php esc_html_e( 'Concepts clés', 'presellia-partner-bridge' ); ?></h2>

        <h3><?php esc_html_e( 'Prix partenaire vs prix public', 'presellia-partner-bridge' ); ?></h3>
        <p><?php esc_html_e( 'Le prix partenaire est stocké sur chaque produit (meta _ppb_partner_price). Il remplace le prix WooCommerce uniquement dans le panier et au checkout, uniquement pour les sessions authentifiées PPB. Sur le reste du site (pages produit, boutique), les prix publics WooCommerce sont inchangés.', 'presellia-partner-bridge' ); ?></p>

        <h3><?php esc_html_e( 'Où saisir les prix ?', 'presellia-partner-bridge' ); ?></h3>
        <p>
            <?php esc_html_e( 'Trois endroits possibles :', 'presellia-partner-bridge' ); ?>
        </p>
        <ul class="ppb-gs-list">
            <li>
                <strong><a href="<?php echo esc_url( $prices_url ); ?>"><?php esc_html_e( 'WooCommerce → Prix partenaires', 'presellia-partner-bridge' ); ?></a></strong>
                — <?php esc_html_e( 'La méthode recommandée. Un tableau liste tous vos produits et variations. Saisissez les prix, cliquez "Enregistrer tout". Idéal pour configurer de nombreux produits en une seule session.', 'presellia-partner-bridge' ); ?>
            </li>
            <li>
                <strong><?php esc_html_e( 'Fiche produit → metabox latérale', 'presellia-partner-bridge' ); ?></strong>
                — <?php esc_html_e( 'Dans chaque fiche produit WooCommerce (admin), une metabox "Presellia Partner Bridge" dans la colonne de droite permet de saisir ou modifier le prix partenaire pour ce seul produit.', 'presellia-partner-bridge' ); ?>
            </li>
            <li>
                <strong><?php esc_html_e( 'Onglet Variations (produits variables)', 'presellia-partner-bridge' ); ?></strong>
                — <?php esc_html_e( 'Pour les produits avec variations (taille, couleur…), chaque variation a son propre champ "Prix partenaire PPB" dans l\'onglet Variations de la fiche produit.', 'presellia-partner-bridge' ); ?>
            </li>
        </ul>

        <h3><?php esc_html_e( 'Lien de partage rapide (?t=TOKEN)', 'presellia-partner-bridge' ); ?></h3>
        <p><?php esc_html_e( 'Une fois connecté, le partenaire voit le bouton "Partager l\'accès". Il génère une URL contenant son token (ex : https://votre-site.com/portail/?t=abc123…). En partageant cette URL sur WhatsApp, email ou autre, il permet à un collègue ou un autre appareil de se connecter directement sans saisir le mot de passe. Ce lien a la même durée de vie que le token.', 'presellia-partner-bridge' ); ?></p>

        <h3><?php esc_html_e( 'Durée de vie du token', 'presellia-partner-bridge' ); ?></h3>
        <p>
            <?php esc_html_e( 'Configurable dans PPB Réglages (actuellement :', 'presellia-partner-bridge' ); ?>
            <strong><?php echo esc_html( get_option( 'ppb_token_ttl', 30 ) ); ?></strong>
            <?php esc_html_e( 'jours). 0 = session permanente (ne demande jamais le mot de passe). Changer le mot de passe partenaire révoque immédiatement tous les tokens actifs.', 'presellia-partner-bridge' ); ?>
        </p>

        <h3><?php esc_html_e( 'API REST MCP (automatisation)', 'presellia-partner-bridge' ); ?></h3>
        <p>
            <?php esc_html_e( 'PPB expose une API REST sécurisée par clé API (header', 'presellia-partner-bridge' ); ?>
            <code>X-PPB-API-Key</code>).
            <?php esc_html_e( 'Elle permet à des outils d\'automatisation (n8n, Claude, scripts) de consulter les logs, les produits, les tokens et de mettre à jour les prix sans passer par l\'interface. Clé générée dans', 'presellia-partner-bridge' ); ?>
            <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'PPB Réglages → API MCP', 'presellia-partner-bridge' ); ?></a>.
        </p>
        <p><?php esc_html_e( 'Endpoints disponibles :', 'presellia-partner-bridge' ); ?></p>
        <table class="widefat ppb-gs-api-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Méthode', 'presellia-partner-bridge' ); ?></th>
                    <th><?php esc_html_e( 'Endpoint', 'presellia-partner-bridge' ); ?></th>
                    <th><?php esc_html_e( 'Description', 'presellia-partner-bridge' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr><td>GET</td> <td><code>/wp-json/ppb/v1/status</code></td>              <td><?php esc_html_e( 'Santé du plugin, compteurs', 'presellia-partner-bridge' ); ?></td></tr>
                <tr><td>GET</td> <td><code>/wp-json/ppb/v1/logs</code></td>                <td><?php esc_html_e( 'Derniers logs (params: limit, level, event)', 'presellia-partner-bridge' ); ?></td></tr>
                <tr><td>POST</td><td><code>/wp-json/ppb/v1/logs/clear</code></td>          <td><?php esc_html_e( 'Vide tous les logs', 'presellia-partner-bridge' ); ?></td></tr>
                <tr><td>GET</td> <td><code>/wp-json/ppb/v1/products</code></td>            <td><?php esc_html_e( 'Catalogue avec prix partenaires', 'presellia-partner-bridge' ); ?></td></tr>
                <tr><td>POST</td><td><code>/wp-json/ppb/v1/products/{id}/price</code></td> <td><?php esc_html_e( 'Met à jour le prix partenaire d\'un produit', 'presellia-partner-bridge' ); ?></td></tr>
                <tr><td>GET</td> <td><code>/wp-json/ppb/v1/tokens</code></td>              <td><?php esc_html_e( 'Infos tokens actifs', 'presellia-partner-bridge' ); ?></td></tr>
                <tr><td>POST</td><td><code>/wp-json/ppb/v1/tokens/revoke-all</code></td>   <td><?php esc_html_e( 'Révoque tous les tokens', 'presellia-partner-bridge' ); ?></td></tr>
            </tbody>
        </table>

    </div>

</div>
