# Changelog — Presellia Partner Bridge

## [2.2.0] — 2026-04-20

### Modifié
- `[ppb_portal]` restauré sur la base exacte v1.9.0 (portail partenaire éprouvé : barre panier sticky, partage d'accès, déconnexion, paliers, tutoriel vidéo)
- `[ppb_catalog]` ajouté en surcouche propre : catalogue public accès libre, prix publics barrés si promo, badge stock coloré (En stock / Rupture / Sur commande + quantité), recherche instantanée + filtre catégorie
- `enqueue_scripts()` charge sur la page portail OU la page catalogue selon les réglages
- `ajax_load_public_catalog` : endpoint AJAX sans authentification pour le catalogue public
- CSS : ajout section catalogue public (`.ppb-public-catalog`, `.ppb-stock-in`, `.ppb-pub-price`, `.ppb-pub-price-sale`)
- Aucune régression sur le portail partenaire — code JS/PHP/CSS portal identique à v1.9.0

## [2.1.0] — 2026-04-20

### Ajouté
- Nouveau shortcode `[ppb_catalog]` : catalogue public accès libre (prix publics + stock + recherche + filtre catégorie), à placer sur une page dédiée séparée du portail partenaire
- Nouveau réglage `ppb_catalog_page_id` : page sur laquelle charger les scripts du catalogue public
- PPB Réglages : deux onglets admin **Portail Partenaire** et **Catalogue Public** pour des configurations indépendantes

### Modifié
- `[ppb_portal]` restauré à sa structure v1.x (espace revendeur seul, sans onglets) — plus simple, plus rapide
- `enqueue_scripts()` charge le JS/CSS sur la page portail OU la page catalogue selon les réglages
- Guide de démarrage mis à jour : nouvelle checklist (étape catalogue optionnelle), section "Comment fonctionne" restructurée pour les deux shortcodes
- `uninstall.php` : nettoyage de `ppb_catalog_page_id`, `ppb_access_request_url`, `ppb_tutorial_video_url` à la désinstallation

## [2.0.2] — 2026-04-19

### Modifié
- Guide de démarrage (`page-ppb-guide.php`) : description hero et section "Comment fonctionne le portail" mis à jour pour refléter l'architecture deux onglets de v2.0.0 (Catalogue public + Espace Revendeur).

## [2.0.1] — 2026-04-19

### Correctif
- Fix mise à jour via "Vérifier maintenant" : `wp_nonce_url()` HTML-encodait l'URL (`&amp;`) avant l'envoi JSON, causant un double-encodage côté JS et un échec de validation du nonce dans `update.php` ("Lien expiré"). Remplacé par `add_query_arg()` + `wp_create_nonce()` qui retourne une URL propre.

## [2.0.0] — 2026-04-19

### Catalogue public (service client)
- Le shortcode `[ppb_portal]` affiche désormais deux onglets : **Catalogue** (public, accès libre) et **Espace Revendeur** (protégé par mot de passe)
- Onglet Catalogue : tableau produits avec nom cliquable, prix public (barré si promo), badge stock coloré (En stock / Rupture / Sur commande + quantité si gestion stock active)
- Recherche instantanée client-side (sans rechargement) + filtre par catégorie dans l'onglet Catalogue
- Nouvel endpoint AJAX `ppb_load_public_catalog` (sans authentification) — utilise le même catalogue PPB que l'espace revendeur
- La barre panier est masquée automatiquement sur l'onglet Catalogue

## [1.9.0] — 2026-04-17

### Ajouté
- Lien "Demander l'accès" sous le formulaire de connexion — configurable dans WooCommerce → PPB Réglages (URL du formulaire de partenariat) ; masqué si vide
- Vidéo tutoriel activable via les réglages (URL YouTube/Loom/Vimeo) — bouton "📹 Tutoriel" dans la toolbar ; panel iframe 16:9 responsive toggleable
- Nom de produit cliquable dans le catalogue partenaire (nouvel onglet vers la fiche WooCommerce)
- Affichage du stock disponible dans le catalogue (`X en stock`) sur les produits avec gestion du stock activée

### Modifié
- `format_product()` : ajoute `permalink` dans la réponse catalogue
- Réglages : 2 nouveaux champs "Lien demande d'accès" et "Vidéo tutoriel"

## [1.8.0] — 2026-04-17

### Ajouté
- Bannière explicative prix dégressifs au-dessus du catalogue (affichée uniquement si au moins un produit/variation possède des paliers)
- Badge "Rupture" (rouge) sur les produits `outofstock` — bouton "Ajouter" désactivé
- Badge "Sur commande" (orange) sur les produits `onbackorder` — bouton "Ajouter" conservé

## [1.7.0] — 2026-04-16

### Ajouté
- **Prix dégressifs (paliers de quantité)** : nouvelle meta `_ppb_partner_tiers` JSON `[{min,price},...]`
- `PPB_Pricing::get_partner_tiers()`, `set_partner_tiers()`, `get_price_for_quantity()` — logique complète
- Éditeur de paliers dans le bulk admin : bouton "▾ Paliers" par ligne, tableau qté min / prix / supprimer, + ajouter
- Portail : bouton "▾ paliers" sur les produits avec plusieurs paliers → ligne de détail avec chips `1–4 · 4 500 CFA`
- Panier : `apply_partner_prices()` utilise `get_price_for_quantity()` avec la quantité réelle du cart

### Modifié
- `ajax_bulk_save_prices()` : traite aussi `tiers[]` POST en plus de `prices[]`
- `format_product()` : inclut `tiers` dans la réponse catalogue (portal + API)
- Synchronisation automatique : `set_partner_tiers()` met à jour `_ppb_partner_price` avec le premier palier

## [1.6.0] — 2026-04-16

### Modifié
- Catégories triées par `menu_order` WooCommerce (Produits → Catégories → champ Ordre) au lieu de l'ordre alphabétique
- Seules les catégories parentes (racine) sont utilisées pour le regroupement — si un produit est uniquement dans une sous-catégorie, le système remonte automatiquement au parent racine
- `get_catalog()` : ajoute `category_order` (menu_order WC) en plus de `category`

## [1.5.0] — 2026-04-16

### Modifié
- Prix public affiché en barré (`<s>`) directement à côté du prix partenaire — colonne "Prix public" séparée supprimée
- Format monnaie inversé : `4 500 CFA` au lieu de `CFA 4 500` (espace insécable entre chiffre et devise)
- Catégories triées alphabétiquement (`localeCompare fr`) au lieu de l'ordre de découverte
- Colspans mis à jour (colonnes passées de 6 à 5 après suppression de la colonne prix public)
- Responsive : seule la miniature est masquée sur ≤ 600 px (la colonne prix public n'existe plus)

## [1.4.0] — 2026-04-16

### Ajouté
- Catégorisation du catalogue : les produits sont regroupés par catégorie WooCommerce avec un en-tête visuel par section
- Filtre déroulant "Toutes les catégories" dans la toolbar (injecté dynamiquement, masqué si une seule catégorie)
- Recherche textuelle et filtre catégorie combinés : les en-têtes de section se masquent automatiquement si tous leurs produits sont filtrés

### Modifié
- `PPB_Pricing::get_catalog()` : ajoute le champ `category` (catégorie principale WooCommerce) sur chaque produit
- `renderCatalog()` JS : refactorisé pour grouper par catégorie avant le rendu
- `bindSearch()` → `filterCatalog()` : gestion unifiée recherche + filtre catégorie

## [1.3.0] — 2026-04-16

### Ajouté
- Miniatures produit dans le tableau catalogue : colonne image 40×40 px avec fallback sur l'image parent pour les variations
- Barre panier flottante fixée en bas du viewport (toujours visible pendant le scroll) : compteur articles + total + bouton Commander
- Panneau détail de la sélection en slide-up au-dessus de la barre (ouverture/fermeture au clic, fermeture en dehors)
- Animation flash sur la barre à chaque ajout d'article
- Espace bas de page automatique quand la barre est visible (`ppb-has-cart-bar`)

### Modifié
- `PPB_Pricing::format_product()` : ajoute `thumbnail_url` dans la réponse catalogue
- Responsive mobile : masque miniature et prix public sur écrans ≤ 600 px

## [1.2.0] — 2026-04-16

### Ajouté
- Page "Guide de démarrage" (WooCommerce → PPB Guide) : checklist dynamique basée sur l'état réel de la configuration, explication du flux complet, concepts clés, documentation des endpoints API
- Notice admin contextuelle sur les pages PPB si la configuration est incomplète (mot de passe manquant, page portail non sélectionnée)
- Bannière d'aide inline sur la page "Prix partenaires" expliquant comment utiliser l'éditeur
- Lien "Portail actif" sur la page de réglages quand le portail est configuré
- Styles CSS dédiés pour le guide (`.ppb-gs-*`, `.ppb-inline-help`)

## [1.1.0] — 2026-04-15

### Ajouté
- Auto-updater GitHub (`PPB_Updater`) : vérification des nouvelles releases toutes les 12 h, mise à jour en un clic depuis WordPress
- Bouton "Vérifier maintenant" dans WooCommerce → PPB Réglages → Mise à jour du plugin
- GitHub Actions workflow `.github/workflows/release.yml` : génération automatique du ZIP propre à chaque tag `vX.Y.Z`

### Corrigé
- Deux formulaires séparés partageant le même `OPTION_GROUP` → les réglages du portail (page, titre, logo, TTL) pouvaient être effacés après enregistrement ; fusionnés en un seul formulaire
- Bouton "Partager l'accès" inopérant : le cookie `httponly` empêchait JS de lire le token ; PHP injecte désormais l'URL de partage directement dans `ppbPortal`
- Champ durée de vie du token : `min` passé de 1 à 0 pour permettre les sessions sans expiration

## [1.0.0] — 2026-04-11

### Ajouté
- Portail partenaire via shortcode `[ppb_portal]` avec modale mot de passe → token → cookie
- Authentification par token (30-60 jours, partageable via `?t=TOKEN`)
- Application automatique des prix partenaires dans le panier WooCommerce (hook `woocommerce_before_calculate_totals`)
- Éditeur de prix en masse : tableau admin tous produits + variations, sauvegarde AJAX
- Metabox prix partenaire sur la fiche produit individuelle (produits simples et variations)
- Page de réglages : mot de passe, TTL token, logo, titre portail, clé API MCP
- API REST MCP (`/wp-json/ppb/v1/`) : status, logs, products, tokens
- Table `ppb_logs` pour les événements d'authentification et de commande
- Compatibilité WooCommerce HPOS déclarée
- CI/CD GitHub Actions : release automatique sur tag `vX.Y.Z`
