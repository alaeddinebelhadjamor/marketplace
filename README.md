# Marketplace Mytek (Laravel + Vue.js)

[![CI](https://github.com/alaeddinebelhadjamor/marketplace/actions/workflows/ci.yml/badge.svg)](https://github.com/alaeddinebelhadjamor/marketplace/actions/workflows/ci.yml)

Marketplace multi-vendeurs adossée au site Magento 2.4.7 de Mytek (projet de fin d'études,
Master Génie Logiciel). Elle permet à des vendeurs tiers de proposer leurs produits sur mytek.tn,
sous le contrôle des équipes Mytek, sans modifier Magento ni l'ERP existant.

Le projet comprend trois briques :
- un **espace vendeur** (Vue.js) ;
- une **passerelle API** (Laravel), qui dialogue avec Magento par son API REST ;
- un **module Magento** (`Mytek_Marketplace`) pour le back-office de Mytek et la boutique.

| Couche | Technologie | Port |
|---|---|---|
| API | Laravel 13, PHP 8.4, Sanctum | 8010 |
| Espace vendeur | Vue 3.5, Vite 8, Pinia 4, Vue Router 5, Vuetify 4, ECharts | 5180 |
| Temps réel | Laravel Reverb (WebSocket) | 8091 |
| File d'attente et planificateur | Laravel Queue (pilote base de données), Scheduler | — |
| Base de données | MySQL 8, base `mk_database_prod_restored` | 3307 |
| Services | Magento 8080, OpenSearch 9201, Prometheus 9090, Grafana 3001 | — |
| Module Magento | `Mytek_Marketplace` (back-office administrateur/intégrateur, boutique), dans ce dépôt | — |
| Emails locaux | Mailpit (SMTP / interface web), pour voir les emails du module sans serveur réel | 1025 / 8025 |

## Fonctionnalités

**Espace vendeur**
- inscription, connexion, profil et mot de passe ;
- double authentification (TOTP), mot de passe oublié par email, session en cookie HttpOnly ;
- ajout de produits dans Magento, soumis à validation par Mytek ;
- import de produits en masse (Excel ou CSV), avec rapport d'erreurs ;
- produits validés (index OpenSearch, avec repli sur Magento) et en attente ;
- prix, promotion et suppression ;
- ventes avec filtres et export Excel ;
- tableau de bord : indicateurs comparés à la période précédente, séries, jours de la semaine ;
- relevés de paiement mensuels (chiffre d'affaires, commission, net à verser) en PDF ;
- réclamations avec pièces jointes et notifications en temps réel (Reverb) ;
- journal d'activité du compte.

**Back-office Mytek (module Magento)**
- gestion et validation des vendeurs ;
- traitement des réclamations ;
- commissions configurables par vendeur et génération des relevés ;
- envoi de notifications aux vendeurs ;
- rôles dédiés : Intégrateur Marketplace et Administrateur Marketplace.

**Boutique**
- recommandations « Vous aimerez aussi » (modèle hybride : filtrage collaboratif ALS et similarité de contenu) ;
- pages vendeurs et facette de recherche par gouvernorat.

**Transverse**
- synchronisation des commandes Magento en jobs de file d'attente, avec nouvelles tentatives automatiques ;
- suivi comportemental (vues de produits) pour la recommandation ;
- journal d'audit des actions sensibles ;
- limitation de débit et en-têtes de sécurité ;
- documentation OpenAPI automatique sur `/docs/api` ;
- supervision Prometheus et tableau de bord Grafana fourni ;
- intégration continue GitHub Actions.

## Organisation du dépôt

```
backend/         API Laravel (app/, routes/, database/, tests/)
frontend/        Application Vue (src/views, src/stores, src/services, tests/)
magento-module/  Module Magento Mytek_Marketplace (back-office et boutique)
docs/            Choix des versions, audit et notes du module Magento, supervision
scripts/         Démarrage, arrêt, vérification, miroir OneDrive,
                 déploiement et tests du module Magento
```

## Prérequis

Tout s'exécute dans **WSL Ubuntu-22.04** :
- PHP 8.4 avec les extensions mbstring, intl, bcmath, gd, zip, pdo_mysql, pdo_sqlite.
  PHP 8.3 reste la version par défaut du système (utilisée par Magento) : les commandes
  appellent explicitement `php8.4` ;
- Composer 2 ;
- Node.js 22 (installé avec nvm dans WSL) ;
- Docker, avec le conteneur `marketplace-mysql` (port 3307) ;
- Magento local sur le port 8080 et OpenSearch sur 9201 pour les fonctions catalogue.

Le code de travail est sur le disque natif de WSL : l'accès aux fichiers Windows depuis WSL
est environ 200 fois plus lent (mesure dans [docs/choix-versions.md](docs/choix-versions.md)).
Un dossier OneDrive reçoit une copie automatique du dépôt à chaque commit.

## Installation (une seule fois)

Dans WSL Ubuntu-22.04, à la racine du dépôt :

```bash
# Backend
cd backend
php8.4 $(command -v composer) install
cp .env.example .env               # puis renseigner la base, Magento et la clé admin
php8.4 artisan key:generate
php8.4 artisan reverb:install      # génère les clés Reverb si elles manquent
php8.4 artisan migrate             # crée uniquement les tables propres à la marketplace

# Frontend
cd ../frontend
npm ci
cp .env.example .env               # puis renseigner VITE_REVERB_APP_KEY (= REVERB_APP_KEY du backend)

# Copie automatique vers OneDrive à chaque commit (après un nouveau clonage)
cd .. && ln -sf ../../scripts/miroir-onedrive.sh .git/hooks/post-commit
```

`php artisan migrate` ne crée que des tables nouvelles et ne modifie aucune table existante.
Les commandes destructrices (`migrate:fresh`, `migrate:reset`, `db:wipe`) sont bloquées par
l'application en dehors des tests.

## Démarrage

À la racine du dépôt :

```bash
scripts/demarrer.sh        # MySQL (si arrêté), API, file d'attente, planificateur, Reverb, Vite
scripts/verifier.sh        # test de fumée de l'API (aucun secret affiché)
scripts/arreter.sh         # arrêt de tous les services
```

Un service seul : `scripts/demarrer.sh api|queue|scheduler|reverb|front`.
Les journaux sont dans `backend/storage/logs/<service>.log`.

| Adresse | Contenu |
|---|---|
| http://localhost:5180 | Espace vendeur |
| http://localhost:8010 | API (réponse JSON de bienvenue) |
| http://localhost:8010/docs/api | Documentation OpenAPI interactive |
| http://localhost:8010/metrics | Métriques Prometheus |
| http://localhost:8010/up | Santé de l'application |

## Supervision

Ajouter la cible de [docs/supervision/prometheus-v2.yml](docs/supervision/prometheus-v2.yml)
au fichier `prometheus.yml`, puis importer
[docs/supervision/grafana-marketplace-v2.json](docs/supervision/grafana-marketplace-v2.json)
dans Grafana (Dashboards, Import).

## Module Magento (Mytek_Marketplace)

Le back-office de la marketplace (administrateur et intégrateur) est un module Magento,
versionné dans ce dépôt (`magento-module/`) et déployé par copie dans le Magento local
(`~/magento`, port 8080). Documentation : [docs/magento-audit.md](docs/magento-audit.md)
(état du module, écarts, décisions) et [docs/notes-magento.md](docs/notes-magento.md)
(détail de chaque incrément, résultats des tests, recette).

### Déploiement et tests

```bash
scripts/magento-deploy.sh            # copie vers ~/magento, setup:upgrade, cache:flush
scripts/magento-deploy.sh --compile  # + setup:di:compile (mode production)
scripts/magento-tests.sh             # tests unitaires PHPUnit (framework de test de Magento)
```

`magento-deploy.sh` suppose PHPUnit 9.6 installé à part
(`mkdir -p ~/tools && curl -sSLo ~/tools/phpunit-9.6.phar https://phar.phpunit.de/phpunit-9.6.phar`),
Magento n'ayant pas ses dépendances de développement.

### Configuration (Stores > Configuration > Mytek > Marketplace)

| Champ | Rôle | Valeur par défaut |
|---|---|---|
| API base URL / Admin API key | Appels serveur à serveur vers l'API de la marketplace (pièces jointes, notifications, commissions) ; la clé est chiffrée en base, jamais renvoyée au navigateur | `http://localhost:8010` / — |
| Seller space URL | Lien « Espace Vendeur » de l'en-tête du site | `http://localhost:5180` |
| OpenSearch base URL / Index prefix | Index de la marketplace lu par l'espace vendeur (distinct de l'index natif de Magento) | `http://localhost:9201` / `opensearch_index` |

### Commandes et tâches planifiées

| Commande | Rôle | Cadence planifiée |
|---|---|---|
| `bin/magento mytek:marketplace:sync-seller-governorate` | Tient à jour l'attribut produit filtrable `seller_governorate` (facette recherche/catégories) | toutes les 15 min |
| `bin/magento mytek:marketplace:reindex-opensearch` | Reconstruit entièrement l'index marketplace (produits actifs portant un vendeur) | toutes les heures |

### Comptes et emails locaux

- Rôles ACL **Intégrateur Marketplace** et **Administrateur Marketplace** créés par data patch
  (`setup:upgrade`) ; créer ensuite les utilisateurs admin et leur affecter ces rôles
  (`bin/magento admin:user:create`).
- Emails au vendeur (validation, refus) envoyés via le `sendmail` système : en local, faire
  pointer `/usr/sbin/sendmail` vers `msmtp` relayant vers un conteneur Mailpit
  (`docker run -d --name mailpit -p 1025:1025 -p 8025:8025 axllent/mailpit:latest`), interface
  de consultation sur http://localhost:8025.

## Tests

```bash
cd backend && php8.4 artisan test           # 178 tests, 668 assertions : API, sécurité, jobs, exports, recommandation
cd frontend && npm test                     # 78 tests : composants, stores, règles
scripts/magento-tests.sh                    # tests unitaires du module Magento
```

Les tests du backend utilisent une base SQLite en mémoire, avec Magento et OpenSearch simulés :
ils ne touchent jamais à la base réelle. Les mêmes tests tournent sur GitHub Actions à chaque envoi.

## Variables d'environnement

Noms et rôles uniquement : les valeurs sont dans les fichiers `.env`, jamais versionnés.

**backend/.env**

| Variable | Rôle |
|---|---|
| `APP_KEY` | Clé de chiffrement de Laravel (sessions, secrets 2FA) |
| `APP_URL` | Adresse publique de l'API |
| `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `CORS_EXTRA_ORIGINS` | Origine de l'application Vue autorisée (cookie de session, CORS) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_COLLATION` | Base de données de la marketplace |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `CACHE_STORE`, `QUEUE_CONNECTION` | Sessions (24 h), cache et file d'attente en base |
| `ADMIN_API_KEY` | Clé des routes d'administration, en-tête `x-admin-key` |
| `MAGENTO_URL`, `MAGENTO_USER`, `MAGENTO_PASSWORD` | API REST Magento et compte de service |
| `STOREFRONT_URL` | Site public, pour ouvrir la fiche d'un produit |
| `OPENSEARCH_BASE_URL` | OpenSearch |
| `BEHAVIOR_SYNC_ENABLED` | Active le suivi comportemental (vues de produits) |
| `MAGENTO_ACCESS_LOG`, `MAGENTO_LOG_OFFSET_FILE` | Journal d'accès de Magento et position de lecture |
| `LEGACY_UPLOADS_DIR` | Dossier de pièces jointes existantes, relu en lecture seule (optionnel) |
| `CRON_ORDERS_SYNC`, `CRON_PRODUCT_VIEWS`, `SCHEDULE_TIMEZONE` | Cadence des tâches planifiées |
| `COMMISSION_RATE` | Taux de commission par défaut (0.05) |
| `BROADCAST_CONNECTION`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SERVER_PORT`, `REVERB_SCHEME` | Temps réel |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Envoi des emails (mot de passe oublié) ; `log` les écrit dans les journaux |

**frontend/.env**

| Variable | Rôle |
|---|---|
| `VITE_API_URL` | Adresse de l'API |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | Connexion WebSocket (clé publique Reverb) |

## Documentation du projet

- [docs/choix-versions.md](docs/choix-versions.md) : versions retenues et justification
- [docs/propositions-avancees.md](docs/propositions-avancees.md) : fonctionnalités proposées et retenues
- [docs/magento-audit.md](docs/magento-audit.md) : audit du module Magento, écarts, décisions
- [docs/notes-magento.md](docs/notes-magento.md) : incréments réalisés sur le module Magento, tests, recette
