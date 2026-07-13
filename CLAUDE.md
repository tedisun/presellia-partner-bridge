# CLAUDE.md — Presellia Partner Bridge

> Instructions permanentes pour toutes les sessions futures sur ce projet.

---

## Identité du projet

| Champ | Valeur |
|---|---|
| Nom | Presellia Partner Bridge |
| Slug / repo | `presellia-partner-bridge` |
| Fichier principal | `presellia-partner-bridge.php` |
| Préfixe constantes/classes | `PPB_` |
| Préfixe options WP | `ppb_` |
| Meta prix partenaire | `_ppb_partner_price` |
| Namespace API REST | `ppb/v1` |
| Repo GitHub | `github.com/tedisun/presellia-partner-bridge` |

---

## Langue et commits

- Langue des commits : **français**
- Format : `type: description` (ex: `feat: ajout filtrage par catégorie`)
- Types : `feat`, `fix`, `chore`, `docs`, `refactor`
- **Ne jamais committer sans demande explicite**

---

## Versioning — séquence obligatoire

Avant chaque release, mettre à jour **deux endroits** dans `presellia-partner-bridge.php` :
1. En-tête : `* Version: X.X.X`
2. Constante : `define( 'PPB_VERSION', 'X.X.X' );`

Ces deux valeurs doivent toujours être identiques. Puis mettre à jour `CHANGELOG.md`.

Convention semver :
- `1.0.x` → patch (bug fix)
- `1.x.0` → minor (nouvelle feature)
- `x.0.0` → major (breaking change)

---

## Règles de base

- Toujours lire un fichier avant de le modifier
- PHP 8.0+ requis
- Pas de Composer / vendor
- Sécurité : nonces sur tous les formulaires et AJAX, `current_user_can()`, `sanitize_*`, `esc_*`
- HPOS : jamais d'accès direct à `wp_postmeta` pour les données de commande WC

---

## Modularité — règle fondamentale

**L'application doit rester modulaire pour faciliter les mises à jour, la maintenabilité et la scalabilité.**

- Chaque responsabilité fonctionnelle = une classe dans son propre fichier
- Le loader central `PPB_Plugin` est le seul endroit où les modules sont instanciés
- Pour ajouter un module : créer le fichier → ajouter `require_once` dans `PPB_Plugin::load_dependencies()` → instancier dans `PPB_Plugin::init_modules()`
- Les modules `admin/` sont chargés conditionnellement (`is_admin()`) pour ne pas alourdir les requêtes front-end
- Ne pas faire croître les classes existantes quand une nouvelle responsabilité émerge — créer un nouveau module

---

## Architecture

```
presellia-partner-bridge.php        → bootstrap : constantes, activation hooks, PPB_Plugin::instance()
includes/
  class-ppb-plugin.php              → loader singleton : require_once + instanciation de tous les modules
  class-ppb-activator.php           → CREATE TABLE ppb_logs/ppb_partner_requests, options par défaut, unschedule cron ; maybe_upgrade() rejoue les migrations sur plugins_loaded si ppb_db_version a changé (une mise à jour en place ne redéclenche jamais register_activation_hook)
  class-ppb-auth.php                → token/cookie, AJAX validate password/revoke, authenticate_portal() (REST headless, rate-limité)
  class-ppb-logger.php              → table ppb_logs, méthodes statiques info/warning/error
  class-ppb-requests.php            → table ppb_partner_requests (demandes d'accès portail headless), create/list/update_status
  class-ppb-rate-limiter.php        → limiteur de débit générique par IP/bucket (transients), utilisé par /portal/registration — indépendant du limiteur dédié au login dans PPB_Auth
  class-ppb-pricing.php             → meta _ppb_partner_price, hook wc_before_calculate_totals, get_catalog()
  class-ppb-portal.php              → shortcode [ppb_portal] (espace revendeur) + [ppb_catalog] (catalogue public), AJAX catalog + checkout
  class-ppb-cron.php                → cron ppb_weekly_cleanup : purge automatique des logs
  api/
    class-ppb-api.php               → REST endpoints ppb/v1/* (admin : X-PPB-API-Key ; portail headless /portal/* : token ou public selon la route, CORS scopé à ppb_portal_cors_origin)
admin/
  class-ppb-admin.php               → bulk price editor, metaboxes produit/variation
  class-ppb-settings.php            → réglages (onglets : Portail Partenaire / Catalogue Public / Demandes d'accès), dashboard rapide, gestion mdp/tokens/logs
assets/
  css/ppb-portal.css                → styles page portail [ppb_portal] et catalogue public [ppb_catalog]
  css/ppb-admin.css                 → styles pages admin
  js/ppb-portal.js                  → modale mdp, catalogue AJAX, mini-panier, checkout + catalogue public (init conditionnelle sur #ppb-portal / #ppb-catalog)
  js/ppb-admin.js                   → bulk save, generate API key, revoke tokens, purge logs
```

---

## Pièges à éviter

- **Mot de passe** : ne jamais stocker en clair. `wp_hash_password()` pour hasher, `wp_check_password()` pour vérifier. L'option `ppb_portal_password_hash` ne contient que le hash.
- **Token** : le transient est indexé par `hash('sha256', $token)`, jamais par le token brut.
- **Prix partenaire** : `PPB_Pricing::apply_partner_prices()` tourne sur `woocommerce_before_calculate_totals` priority 20. Si un autre plugin tourne sur 99+, nos prix peuvent être écrasés — augmenter la priorité si nécessaire.
- **Clé API REST** : comparaison en `hash_equals()` pour éviter les timing attacks.
- **Cookie** : `httponly: true` — ne jamais lire `ppb_token` en JS (utiliser le cookie natif PHP uniquement pour l'auth). Le JS pose le cookie uniquement après la première validation AJAX.
- **AJAX checkout** : vide le panier WC existant avant d'ajouter les nouveaux items (`WC()->cart->empty_cart()`). Informer l'utilisateur si besoin.

---

## API REST MCP — endpoints disponibles

| Méthode | Endpoint | Description |
|---|---|---|
| GET | `/wp-json/ppb/v1/status` | Santé du plugin, compteurs |
| GET | `/wp-json/ppb/v1/logs` | Derniers logs (params: limit, level, event) |
| POST | `/wp-json/ppb/v1/logs/clear` | Vide tous les logs |
| GET | `/wp-json/ppb/v1/products` | Catalogue avec prix partenaires |
| POST | `/wp-json/ppb/v1/products/{id}/price` | Met à jour le prix partenaire |
| GET | `/wp-json/ppb/v1/tokens` | Infos tokens actifs |
| POST | `/wp-json/ppb/v1/tokens/revoke-all` | Révoque tous les tokens |

Header d'authentification : `X-PPB-API-Key: {clé depuis les réglages PPB}`

---

## API REST portail headless — endpoints disponibles

Consommés par le repo séparé `presellia-partner-portal` (SPA React sur une autre origine). Auth par mot de passe partagé → token, pas de clé API admin.

**CORS — deux couches, ne pas confondre :**
1. WordPress core ajoute par défaut `Access-Control-Allow-Origin: <origine de la requête>` (reflétée) sur **toutes** les routes REST via `rest_send_cors_headers()` (`rest_api_init`) — donc une route non listée ci-dessous fonctionnerait quand même cross-origin, mais depuis **n'importe quelle origine**.
2. `PPB_Api::add_cors_headers()` (sur `rest_pre_serve_request`) **écrase** ce header par défaut, mais uniquement pour les routes sous `/ppb/v1/portal/*` — restreint alors à la seule origine configurée dans `ppb_portal_cors_origin`. `header()` remplace un header du même nom par défaut en PHP, d'où l'écrasement.

Conséquence pratique : toute route qui expose des données sensibles (prix partenaires, commandes, ou qui écrit en base comme `/registration`) doit être placée sous `/portal/` pour bénéficier de cette restriction — sinon elle reste accessible depuis n'importe quel site web via le défaut permissif de WP, pas bloquée. `/checkout/prepare` est le seul endpoint headless historique laissé hors `/portal/` (accepte un panier à préparer, ne renvoie aucune donnée partenaire sensible — risque jugé acceptable).

| Méthode | Endpoint | Auth |
|---|---|---|
| POST | `/wp-json/ppb/v1/portal/login` | Publique, rate-limitée (PPB_Auth) |
| GET | `/wp-json/ppb/v1/portal/catalog` | Token |
| POST | `/wp-json/ppb/v1/checkout/prepare` | Token (dans le body) — hors `/portal/`, CORS par défaut WP (voir ci-dessus) |
| POST | `/wp-json/ppb/v1/portal/registration` | Publique, rate-limitée (PPB_Rate_Limiter, bucket séparé du login) — file dans Réglages PPB > Demandes d'accès |
| GET | `/wp-json/ppb/v1/portal/orders` | Token — historique par email, pas d'endpoint public (évite l'énumération) |
| GET | `/wp-json/ppb/v1/portal/product/{id}/description` | Token |

---

## Mise en service (checklist)

1. Activer le plugin sur presellia.com
2. WooCommerce > PPB Réglages (onglet Portail Partenaire) → définir le mot de passe
3. WooCommerce > PPB Réglages → générer une clé API MCP
4. Créer une page WP, y coller `[ppb_portal]`, la publier → sélectionner dans PPB Réglages → Page portail
5. (Optionnel) Créer une page WP, y coller `[ppb_catalog]`, la publier → sélectionner dans PPB Réglages → Catalogue Public → Page catalogue
6. WooCommerce > Prix partenaires → saisir les prix partenaires pour chaque produit
7. Tester : visiter la page portail, entrer le mot de passe, vérifier le catalogue

---

## Google Sheets sync (futur)

Prévu en version future. La meta `_ppb_partner_price` est le point d'entrée :
- Import : lire la colonne "Prix partenaire" du sheet → `PPB_Pricing::set_partner_price($id, $price)`
- Export : `PPB_Pricing::get_catalog()` → écrire dans le sheet
- L'endpoint `POST /ppb/v1/products/{id}/price` permet déjà la mise à jour via MCP/webhook
