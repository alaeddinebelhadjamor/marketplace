# Notes de migration v1 → v2

Rédigé le 3 octobre 2026. Ce document sert de base à la mise à jour du rapport (chapitres 2 à 4).

## 1. Ce qui a changé

### Architecture

| Couche | v1 | v2 |
|---|---|---|
| Passerelle d'API | Node.js 22, Express 5, Sequelize | Laravel 13, PHP 8.4, Eloquent |
| Espace vendeur | Angular 21 | Vue 3.5, Vite 8, Pinia 4, Vue Router 5, Vuetify 4 |
| Graphiques | Chart.js | Apache ECharts |
| Authentification | JWT dans `sessionStorage` | Sanctum : cookie de session HttpOnly pour l'application Vue, jeton « Bearer » de 24 h pour les clients d'API |
| Tâches planifiées | `node-cron` dans le processus web | Scheduler Laravel + jobs en file d'attente (pilote base de données) |
| Temps réel | aucun (interrogation toutes les 30 s) | Laravel Reverb (WebSocket), repli automatique sur une interrogation toutes les 60 s |
| Exports Excel | SheetJS (navigateur et serveur) | PhpSpreadsheet, côté serveur uniquement |
| Tests | 24 tests Jest contre un serveur démarré | 128 tests Pest (base SQLite en mémoire, Magento simulé) + 27 tests Vitest |

Le module Magento `Mytek_Marketplace`, les tables existantes, OpenSearch, Prometheus et
Grafana ne sont pas modifiés. Les 33 routes de la v1 gardent les mêmes chemins.

### Base de données

Aucune table existante n'est modifiée. Nouvelles tables, créées par des migrations séparées :

| Table | Rôle |
|---|---|
| `sessions`, `password_reset_tokens`, `personal_access_tokens` | Sessions, réinitialisation du mot de passe, jetons d'API |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Cache et file d'attente (pas de Redis) |
| `activity_log` | Journal d'audit |
| `seller_two_factor` | Secrets 2FA et codes de secours, chiffrés |
| `order_magento_dates` | Date réelle de chaque commande Magento |
| `commission_rates`, `payout_statements` | Taux propres aux vendeurs et relevés mensuels |
| `product_imports` | Suivi des imports en masse |
| `metric_samples` | Compteurs Prometheus (PHP ne garde rien en mémoire entre deux requêtes) |

Ces tables utilisent la collation `utf8mb4_0900_ai_ci` des tables existantes. La collation par
défaut de Laravel provoquait une erreur « Illegal mix of collations » sur les jointures, détectée
au premier test sur la vraie base et corrigée.

### Décisions sur les écarts entre le rapport et la v1 (section 8 de la checklist)

| Point | Décision appliquée |
|---|---|
| C01 Pièces jointes dans Magento | Les chemins d'API sont identiques ; le module Magento continue d'appeler la v1 (port 3000) tant qu'il n'est pas modifié |
| C02 Prix et suppression | Boutons affichés dans « Mes produits », comme le décrit le rapport |
| C03 Dates de promotion | Champs « Début » et « Fin » ajoutés |
| C04 Profil | Lecture seule, comme la v1 |
| C05 Inscription | Mêmes champs obligatoires que l'écran v1, 24 gouvernorats |
| C06 Liste vide | Message différent pour un catalogue vide et une recherche sans résultat |
| C07 Plage de dates inversée | Message d'alerte côté écran, 400 côté API |
| C08 Routes `disabled-seller-products` et `produits-consultes` | Conservées, mais protégées par la clé admin |
| C09 Pièces jointes publiques | Accès réservé au vendeur propriétaire ou à la clé admin ; noms de fichiers contrôlés |
| C10 Supervision | Métriques aux noms neutres (`http_requests_total`…) et tableau Grafana dédié |
| C11 Message vide à la création | 400 |
| C12 Hash des mots de passe | Vérification par `password_verify`, compatible avec les hash `$2b$` de la v1 |
| C13 Vue admin Angular | Non reprise ; le back-office reste dans Magento |
| C14 Connexion 404 / 401 | Fusion en un seul 401 « Identifiant ou mot de passe incorrect. » (amélioration annoncée au tableau 2.3) |
| C15 Lien vers la fiche produit | Adresse du site configurable (`STOREFRONT_URL`) |
| C16 Clic sur une notification | Ouvre la notification dans le centre de support, onglet « Notifications » (historique ajouté) |

### Changements de comportement à connaître

- **Connexion** : un identifiant inconnu renvoie 401 au lieu de 404 (C14). Le test T3-03 du
  rapport doit être mis à jour.
- **Limitation de débit** : 5 échecs de connexion par minute et par identifiant, puis 429.
- **Compte refusé après coup** : un vendeur dont le statut repasse à « refusé » perd l'accès
  immédiatement, même avec une session ouverte (v1 : jusqu'à expiration du JWT).
- **Données invalides** : toujours 400, avec la liste des champs en erreur dans `errors`.
- **Promotion** : la v2 sait retirer une promotion ; la v1 ignorait le retrait.
- **Synchronisation des commandes** : la v1 combinait les deux bornes de date dans un même
  groupe de filtres Magento, ce qui revient à un « OU » et ramenait toutes les commandes
  `complete`. La v2 utilise deux groupes (« ET »). Les lignes enfants des produits composés
  sont ignorées pour ne pas compter deux fois une vente.
- **Suivi comportemental** : le cache des slugs survit aux redémarrages (7 jours) et un échec
  est retenté après une heure ; les deux limites citées au chapitre 4 sont levées. Il est
  désactivé par défaut (`BEHAVIOR_SYNC_ENABLED`) pour ne pas doubler les événements tant que
  la v1 lit le même journal.
- **Dates des ventes** : filtres, graphiques et exports utilisent la date de la commande
  Magento si elle est connue, sinon la date de synchronisation (limite du chapitre 4 levée
  pour les nouvelles commandes).
- **Export Excel administrateur** : feuille « Par SKU », le prix moyen est calculé par unité
  vendue (la v1 divisait par le nombre de commandes) ; une colonne « Taux » apparaît car le
  taux peut varier par vendeur.
- **Page d'arrivée** : après connexion, le vendeur arrive sur le tableau de bord (v1 : « Mon compte »).

## 2. Nouvelles fonctionnalités

| Fonctionnalité | Où la voir | Éléments techniques |
|---|---|---|
| Notifications temps réel | Cloche, centre de support, pastille « temps réel » du menu | `SellerInboxUpdated` (ShouldBroadcast), canal privé `seller.{id}`, Laravel Echo |
| Jobs de synchronisation | `failed_jobs`, métriques `marketplace_sync_runs_total` | `SyncDailyOrdersJob` (3 essais, délais 30 s puis 120 s, ShouldBeUnique) |
| Double authentification | Mon compte → Double authentification | TOTP (Google2FA), QR code SVG, 8 codes de secours, jeton d'étape de 5 min |
| Mot de passe oublié | Lien sur l'écran de connexion | Password broker Laravel, lien vers l'application Vue |
| Tableau de bord avancé | Tableau de bord | `GET /api/dashboard`, comparaison à la période précédente, ECharts |
| Commissions et relevés PDF | Relevés de paiement | `CommissionService`, DomPDF, génération le 1er du mois |
| Import en masse | Import en masse | Job `ImportProductsJob`, 500 lignes, rapport Excel, notification de fin |
| Journal d'audit | Mon compte → Activité | spatie/laravel-activitylog |
| Sécurité HTTP | Toutes les réponses | Limiteurs `auth`, `api`, `imports` ; CSP, X-Frame-Options, nosniff… |
| Documentation OpenAPI | http://localhost:8010/docs/api | Scramble, générée depuis les Form Requests |
| Supervision enrichie | `/metrics`, `docs/supervision/` | Appels Magento et OpenSearch, jobs, synchronisations, vendeurs |
| Intégration continue | Onglet Actions du dépôt GitHub | Pint, Pest, Vitest, compilation Vite |

## 3. Résultats des tests (3 octobre 2026)

| Suite | Résultat |
|---|---|
| Pest (backend) | 128 tests réussis, 400 assertions, environ 5 s |
| Vitest (frontend) | 27 tests réussis |
| Compilation Vite | réussie |
| Test de fumée sur la base réelle (`scripts/verifier.sh`) | 14 vérifications réussies |
| Recette navigateur (connexion, 8 pages, plage de dates) | réussie, aucune erreur JavaScript |

Correspondance avec les 24 tests Jest de la v1 : tous ont un équivalent Pest
(fichiers `AuthTest`, `ValidationTest`, `SecurityTest`). Les vérifications manuelles du
chapitre 4 (T4-01 à T4-08) sont désormais automatisées (`AccessLogParserTest`,
`ProductViewsSyncTest`, `OrderSyncTest`, `ProductTest`).

## 4. Limites connues

- **Magento arrêté pendant les essais** : les fonctions catalogue (liste, ajout, prix,
  suppression, import) sont couvertes par des tests automatiques avec Magento simulé, mais
  n'ont pas été rejouées contre le Magento local, qui n'était pas démarré. À vérifier avant
  la soutenance avec `scripts/verifier.sh` une fois Magento lancé.
- **Pièces jointes dans l'admin Magento** : le module appelle toujours l'adresse de la v1
  (port 3000). Les fichiers envoyés via la v2 sont stockés par la v2 ; l'admin Magento ne les
  affichera qu'une fois le module pointé vers la v2 (modification non faite, le module étant
  hors périmètre).
- **Emails** : sans serveur SMTP, les emails de réinitialisation sont écrits dans
  `storage/logs/laravel.log` (`MAIL_MAILER=log`).
- **Serveur de développement** : l'API tourne avec le serveur PHP intégré (4 processus).
  En production, il faudrait Nginx + PHP-FPM et un superviseur pour la file, le planificateur
  et Reverb.
- **Index OpenSearch** : comme en v1, aucun index `opensearch_index_*` n'existe en local ; la
  liste des produits validés passe donc par le repli Magento.
- **Prometheus** : la cible de la v2 est fournie (`docs/supervision/prometheus-v2.yml`) mais
  n'a pas été ajoutée à la configuration Prometheus du poste, qui se trouve hors des deux projets.
