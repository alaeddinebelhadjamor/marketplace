# Marketplace Mytek v2 (Laravel + Vue.js)

[![CI](https://github.com/alaeddinebelhadjamor/marketplace/actions/workflows/ci.yml/badge.svg)](https://github.com/alaeddinebelhadjamor/marketplace/actions/workflows/ci.yml)

Nouvelle version de la marketplace multi-vendeurs adossée au site Magento 2.4.7 de Mytek
(projet de fin d'études, Master Génie Logiciel). Elle remplace la passerelle Node.js / Express
et l'espace vendeur Angular de la v1, avec les mêmes routes d'API, les mêmes tables et des
fonctionnalités avancées.

| Couche | Technologie | Port |
|---|---|---|
| API | Laravel 13, PHP 8.4, Sanctum | 8010 |
| Espace vendeur | Vue 3.5, Vite 8, Pinia 4, Vue Router 5, Vuetify 4, ECharts | 5180 |
| Temps réel | Laravel Reverb (WebSocket) | 8091 |
| File d'attente et planificateur | Laravel Queue (pilote base de données), Scheduler | — |
| Base de données | MySQL 8, base existante `mk_database_prod_restored` | 3307 |
| Services existants | Magento 8080, OpenSearch 9201, Prometheus 9090, Grafana 3001 | — |

Les ports 8000 et 5173 étant déjà utilisés sur le poste, la v2 utilise 8010 et 5180 : la v1
(3000 / 4200) et la v2 peuvent tourner en même temps.

## Fonctionnalités

**Parité avec la v1** (détail ligne par ligne dans [docs/checklist-parite.md](docs/checklist-parite.md)) :
inscription et connexion des vendeurs, profil et mot de passe, ajout de produits dans Magento,
produits validés (OpenSearch avec repli sur Magento) et en attente, prix, promotion et
suppression, ventes avec filtres et export Excel, statistiques, réclamations avec pièces
jointes, notifications, routes d'administration par clé, export Excel en 7 feuilles,
synchronisation des commandes Magento, suivi comportemental, métriques Prometheus.

**Nouveautés** (voir [docs/notes-migration.md](docs/notes-migration.md)) :
- notifications en temps réel (Reverb) à la place de l'interrogation toutes les 30 s ;
- synchronisations en jobs de file d'attente, avec nouvelles tentatives automatiques ;
- double authentification (TOTP), mot de passe oublié par email, session en cookie HttpOnly ;
- tableau de bord avancé : indicateurs comparés à la période précédente, séries, jours de la semaine ;
- commissions configurables par vendeur et relevés mensuels en PDF ;
- import de produits en masse (Excel ou CSV) avec rapport d'erreurs ;
- journal d'audit des actions sensibles ;
- limitation de débit et en-têtes de sécurité ;
- documentation OpenAPI automatique sur `/docs/api` ;
- supervision enrichie et tableau de bord Grafana fourni ;
- intégration continue GitHub Actions.

## Organisation du dépôt

```
backend/    API Laravel (app/, routes/, database/, tests/)
frontend/   Application Vue (src/views, src/stores, src/services, tests/)
docs/       Choix des versions, checklist de parité, propositions, notes de migration, supervision
scripts/    Démarrage, arrêt, vérification, import de la configuration v1, miroir OneDrive
```

## Prérequis

Tout s'exécute dans **WSL Ubuntu-22.04** (jamais Ubuntu-24.04) :
- PHP 8.4 avec les extensions mbstring, intl, bcmath, gd, zip, pdo_mysql, pdo_sqlite.
  PHP 8.3 reste la version par défaut du système : les commandes appellent explicitement `php8.4` ;
- Composer 2 ;
- Node.js 22 (installé avec nvm dans WSL) ;
- Docker, avec le conteneur existant `marketplace-mysql` (port 3307) ;
- Magento local sur le port 8080 et OpenSearch sur 9201 pour les fonctions catalogue.

Le code de travail est sur le disque natif de WSL (`~/projets/marketplace-v2`) : l'accès aux
fichiers Windows depuis WSL est environ 200 fois plus lent (mesure dans
[docs/choix-versions.md](docs/choix-versions.md)). Le dossier OneDrive
`Bureau\ala\marketplace-v2` reçoit une copie automatique à chaque commit.

## Installation (une seule fois)

Dans WSL Ubuntu-22.04 :

```bash
cd ~/projets/marketplace-v2

# Backend
cd backend
php8.4 $(command -v composer) install
cp .env.example .env
# Reprend les valeurs de la v1 (base, Magento, clé admin) sans les afficher :
php8.4 ../scripts/importer-env-v1.php "/mnt/c/Users/abelhadjamor/OneDrive - CELESTE/Bureau/ala/marketplace/marketplace-backend/.env" .env
php8.4 artisan key:generate
php8.4 artisan reverb:install      # génère les clés Reverb si elles manquent
php8.4 artisan migrate             # crée uniquement les nouvelles tables de la v2

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

## Démarrage, dans l'ordre

```bash
cd ~/projets/marketplace-v2
scripts/demarrer.sh        # MySQL (si arrêté), API, file d'attente, planificateur, Reverb, Vite
scripts/verifier.sh        # test de fumée de l'API (aucun secret affiché)
scripts/arreter.sh         # arrêt de tous les services de la v2
```

Un service seul : `scripts/demarrer.sh api|queue|scheduler|reverb|front`.
Les journaux sont dans `backend/storage/logs/<service>.log`.

Pour Magento, OpenSearch, Prometheus et Grafana, suivre le README de la v1 : la v2 les utilise
sans les modifier.

| Adresse | Contenu |
|---|---|
| http://localhost:5180 | Espace vendeur |
| http://localhost:8010 | API (réponse JSON de bienvenue) |
| http://localhost:8010/docs/api | Documentation OpenAPI interactive |
| http://localhost:8010/metrics | Métriques Prometheus |
| http://localhost:8010/up | Santé de l'application |

## Supervision

Ajouter la cible de [docs/supervision/prometheus-v2.yml](docs/supervision/prometheus-v2.yml)
au fichier `prometheus.yml` existant, puis importer
[docs/supervision/grafana-marketplace-v2.json](docs/supervision/grafana-marketplace-v2.json)
dans Grafana (Dashboards, Import).

## Tests

```bash
cd backend && php8.4 vendor/bin/pest        # 128 tests : API, sécurité, jobs, exports
cd frontend && npm test                      # 27 tests : composants, stores, règles
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
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_COLLATION` | Base marketplace existante |
| `SESSION_DRIVER`, `SESSION_LIFETIME`, `CACHE_STORE`, `QUEUE_CONNECTION` | Sessions (24 h), cache et file d'attente en base |
| `ADMIN_API_KEY` | Clé des routes d'administration, en-tête `x-admin-key` |
| `MAGENTO_URL`, `MAGENTO_USER`, `MAGENTO_PASSWORD` | API REST Magento et compte de service |
| `STOREFRONT_URL` | Site public, pour ouvrir la fiche d'un produit |
| `OPENSEARCH_BASE_URL` | OpenSearch |
| `BEHAVIOR_SYNC_ENABLED` | Active le suivi comportemental (à laisser à false tant que la v1 lit le même journal) |
| `MAGENTO_ACCESS_LOG`, `MAGENTO_LOG_OFFSET_FILE` | Journal Apache de Magento et position de lecture |
| `LEGACY_UPLOADS_DIR` | Dossier des pièces jointes de la v1, relu en lecture seule (optionnel) |
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
- [docs/checklist-parite.md](docs/checklist-parite.md) : inventaire de la v1 et état de la parité
- [docs/propositions-avancees.md](docs/propositions-avancees.md) : fonctionnalités proposées et retenues
- [docs/notes-migration.md](docs/notes-migration.md) : changements, résultats des tests, limites connues
