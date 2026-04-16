# Changelog — Presellia Partner Bridge

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
