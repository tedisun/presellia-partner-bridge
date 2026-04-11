# Changelog — Presellia Partner Bridge

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
