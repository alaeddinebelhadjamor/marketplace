# Checklist de parité — Marketplace Mytek v1 (Node + Angular) → v2 (Laravel + Vue)

Inventaire établi le 3 octobre 2026 à partir de :
- tout le code de l'ancien projet (`marketplace-backend/`, `marketplace-frontend/`, lecture des
  contrôleurs PHP du module `magento-module/`) ;
- le schéma réel de la base `mk_database_prod_restored` (MySQL 8.0.46, port 3307), lu en
  lecture seule ;
- les chapitres 2, 3 et 4 du rapport LaTeX.

Chaque ligne doit exister dans la v2 avec le même comportement. La colonne **v2** est cochée
à la fin de l'incrément qui la réalise : `[ ]` à faire, `[x]` fait et testé.
Les points où le rapport et le code se contredisent sont regroupés en **section 8**.

**État au 3 octobre 2026 : parité complète.** Toutes les lignes sont réalisées et couvertes par
des tests automatiques (128 tests Pest, 27 tests Vitest). Les routes qui appellent Magento ont
été testées avec Magento simulé ; elles restent à rejouer contre le Magento local, arrêté pendant
les essais. Les recommandations de la section 8 ont toutes été appliquées (détail dans
`notes-migration.md`).

---

## 1. Routes de l'API

L'ancien backend expose **33 routes** (et non 46 : les autres chiffres cités venaient
probablement d'un comptage incluant les routes du module Magento). Les chemins seront
conservés à l'identique dans la v2, sous le même préfixe `/api`.

Protections de la v1 :
- **JWT** : en-tête `Authorization: Bearer <jeton>`. Sans en-tête → **403** « Token manquant. » ;
  en-tête sans jeton → **403** « Token invalide. » ; jeton expiré ou faux → **401**
  « Token expiré ou invalide. ». Durée de vie 24 h.
- **Clé admin** : en-tête `x-admin-key` comparé en temps constant à `ADMIN_API_KEY`.
  Variable absente → **503** ; clé absente ou fausse → **401**
  « Clé d'administration manquante ou invalide. ».
- **Public** : aucune protection.

### 1.1 Généralités

| # | Méthode | Chemin | Protection | Comportement attendu | v2 |
|---|---|---|---|---|---|
| R01 | GET | `/` | public | 200 `{message}` de bienvenue | [x] |
| R02 | GET | `/metrics` | public | 200, format texte Prometheus : compteur de requêtes HTTP, histogramme de durée, métriques du processus, jauge `magento_product_views_total{product_sku,date}` | [x] |

### 1.2 Authentification et profil (vendeur)

| # | Méthode | Chemin | Protection | Comportement attendu | v2 |
|---|---|---|---|---|---|
| R03 | POST | `/api/auth/register` | public | Champs requis : firstname, lastname, email, password, shop_title, contact_number → sinon **400** « Champs obligatoires manquants : … » ; email mal formé → **400** « Adresse email invalide. » ; mot de passe < 6 → **400** ; email ou shop_title déjà pris → **409** « Email déjà utilisé. » / « Nom de boutique déjà utilisé. » ; liste blanche des champs (firstname, lastname, email, shop_title, company, contact_number, description, address, zipcode, governorate, has_patent, tax_id, logo) ; `status` forcé à 0 ; mot de passe haché bcrypt ; **201** « Inscription réussie ! En attente de validation. » | [x] |
| R04 | POST | `/api/auth/login` | public | `{identifier, password}` ; identifier = email **ou** shop_title ; inconnu → **404** « Compte introuvable. » ; mauvais mot de passe → **401** « Mot de passe incorrect. » ; status 0 → **403** « Compte en attente de validation. » ; status 2 → **403** « Compte refusé. » ; sinon **200** `{id, shop_title, accessToken}` | [x] |
| R05 | GET | `/api/profile` | JWT | Profil du vendeur sans `password_hash` ; vendeur supprimé → **404** « Utilisateur introuvable. » | [x] |
| R06 | PUT | `/api/profile/change-password` | JWT | `{currentPassword, newPassword}` ; champ manquant → **400** « Champs requis manquants. » ; ancien faux → **401** « Mot de passe actuel incorrect. » ; **200** « Mot de passe modifié avec succès. » | [x] |

### 1.3 Produits (pont vers Magento)

| # | Méthode | Chemin | Protection | Comportement attendu | v2 |
|---|---|---|---|---|---|
| R07 | POST | `/api/magento/product/add` | JWT | Corps `{product}` au format Magento ; sku, name, price requis → **400** ; SKU hors `^[A-Za-z0-9._-]{3,64}$` → **400** ; nom 2–255 → **400** ; prix ≤ 0 → **400** ; `seller_id` forcé à l'id du jeton ; `status` forcé à 2 ; SKU existant dans Magento → **409** « Un produit avec le même SKU existe déjà » ; erreur Magento « URL key … already exists » → **409** « Un produit avec le même nom existe déjà » ; **201** `{message, product}` | [x] |
| R08 | GET | `/api/magento/products` | JWT | Produits **validés** du vendeur, `page`, `pageSize` (20), `search` ; source OpenSearch (index `opensearch_index_*` le plus récent, filtre `seller_id`, wildcard sur `sku.keyword`/`name.keyword`) ; **repli sur l'API Magento** (status 1 + seller_id) si OpenSearch échoue ; réponse `{total, totalPages, page, pageSize, products[], source}` ; chaque produit `{sku, name, short_description, url_key, price, special_price, status, image}` | [x] |
| R09 | GET | `/api/magento/products/pending` | JWT | Produits **en attente** (status 2) du vendeur, via l'API Magento directe, mêmes paramètres et même format | [x] |
| R10 | PUT | `/api/magento/product/price` | JWT | `{sku, price, special_price?, special_from_date?, special_to_date?}` ; sku absent → **400** « SKU obligatoire » ; ni price ni special_price → **400** ; `seller_id` du produit ≠ jeton → **403** « Non autorisé à modifier ce produit » ; **200** « Prix mis à jour avec succès » | [x] |
| R11 | DELETE | `/api/magento/product/delete` | JWT | `{sku}` dans le corps ; absent → **400** ; produit d'un autre vendeur → **403** « Vous n'êtes pas autorisé » ; **200** « Produit supprimé avec succès » | [x] |
| R12 | GET | `/api/magento/products/url-key` | JWT | `?sku=` ; absent → **400** ; **200** `{sku, url_key}` | [x] |
| R13 | GET | `/api/magento/disabled-seller-products` | public | Tous les produits Magento désactivés ayant un seller_id, hors ceux listés dans `produits_consultes`, enrichis du shop_title et du nom du vendeur ; **200** `{total, products}` (voir C08) | [x] |
| R14 | POST | `/api/magento/produits-consultes` | public | `{id_produit}` requis → sinon **400** ; insertion idempotente (doublon ignoré) ; **201** | [x] |

### 1.4 Ventes

| # | Méthode | Chemin | Protection | Comportement attendu | v2 |
|---|---|---|---|---|---|
| R15 | GET | `/api/orders` | JWT | Lignes de `orders` du vendeur (`vendor_id` = jeton), triées par `processed_at` décroissant ; tableau brut | [x] |

### 1.5 Réclamations et notifications — côté vendeur

| # | Méthode | Chemin | Protection | Comportement attendu | v2 |
|---|---|---|---|---|---|
| R16 | POST | `/api/reclamations` | JWT | multipart `message` + `attachments[]` ; crée une réclamation type 1, `vendeur_viewed=1`, `admin_viewed=0`, un message `sender=1` et les pièces jointes ; **200** `{success, reclamation_id}` (voir C11 sur le message vide) | [x] |
| R17 | GET | `/api/reclamations/seller` | JWT | Toutes les réclamations du vendeur (types 0, 1, 2), triées par date décroissante | [x] |
| R18 | GET | `/api/reclamations/{reclamationId}/messages` | JWT | Messages avec pièces jointes (`attachments`), ordre chronologique ; réclamation d'un autre vendeur ou id invalide → **404** « Reclamation not found » | [x] |
| R19 | POST | `/api/reclamations/{reclamationId}/reply` | JWT | multipart ; pas à lui → **404** ; déjà résolue (type 2) → **409** « Reclamation already resolved » ; message vide → **400** « Message is required » ; repasse `admin_viewed=0` ; **200** | [x] |
| R20 | PUT | `/api/reclamations/{id}/seen-by-seller` | JWT | Pas à lui → **404** ; `vendeur_viewed=1` ; **200** | [x] |
| R21 | PUT | `/api/reclamations/{id}/resolve` | JWT | Pas à lui → **404** ; type ≠ 1 → **400** « Seules les réclamations ouvertes peuvent être résolues. » ; type → 2, `vendeur_viewed=1` ; **200** | [x] |

### 1.6 Réclamations et notifications — côté administration (intégrations)

| # | Méthode | Chemin | Protection | Comportement attendu | v2 |
|---|---|---|---|---|---|
| R22 | GET | `/api/admin/reclamations` | clé admin | Toutes les réclamations avec firstname, lastname, shop_title du vendeur (jointure gauche) | [x] |
| R23 | GET | `/api/admin/reclamations/{id}` | clé admin | Réclamation avec `messages[].attachments[]` ; absente → **404** | [x] |
| R24 | POST | `/api/admin/reclamations/{id}/reply` | clé admin | multipart ; message `sender=0` ; repasse `vendeur_viewed=0` ; **200** | [x] |
| R25 | PUT | `/api/admin/reclamations/{id}/resolve` | clé admin | type → 2 ; **200** | [x] |
| R26 | POST | `/api/admin/notifications` | clé admin | multipart `seller_id`, `message`, `attachments[]` ; crée une réclamation type 0 (`vendeur_viewed=0`, `admin_viewed=1`) et un message `sender=0` ; **200** `{success, notification_id}` | [x] |
| R27 | PUT | `/api/admin/reclamations/{id}/seen` | clé admin | `admin_viewed=1` ; **200** | [x] |

### 1.7 Pièces jointes

| # | Méthode | Chemin | Protection | Comportement attendu | v2 |
|---|---|---|---|---|---|
| R28 | GET | `/api/attachments/view/{filename}` | public | Affiche le fichier en ligne, type MIME déduit de l'extension ; absent → **404** « File not found » (voir C01 et C09) | [x] |
| R29 | GET | `/api/attachments/download/{filename}` | public | Téléchargement forcé sous le nom d'origine ; absent → **404** | [x] |

Règles d'envoi des pièces jointes (v1) : nom de fichier `horodatage-aléatoire.extension`,
extension déduite du nom ou, à défaut, du type MIME (jpg, png, gif, webp, pdf, txt, doc, docx).
Aucune limite de taille ni de type. Le chemin stocké en base est relatif
(`uploads/reclamations/…`, avec `\` sous Windows) : la v2 doit relire les deux séparateurs.

### 1.8 Statistiques et export (administration)

| # | Méthode | Chemin | Protection | Comportement attendu | v2 |
|---|---|---|---|---|---|
| R30 | GET | `/api/stats/sellers` | clé admin | `{success, count, data}` sans `password_hash` | [x] |
| R31 | GET | `/api/stats/orders` | clé admin | `{success, count, data}` toutes les commandes | [x] |
| R32 | GET | `/api/stats/all` | clé admin | `{success, sellersCount, ordersCount, sellers, orders}` | [x] |
| R33 | GET | `/api/export/excel` | clé admin | `from`, `to`, `sellers=all\|1,2`, `sheets=all\|resume,vendeur,date,sku,commission,croise,detail` ; aucune commande → **404** « Aucune commande trouvée pour cette période. » ; classeur `.xlsx` de 7 feuilles stylées (résumé, vendeurs, par date, par SKU, commissions à 5 %, croisé vendeur × date, détail groupé par commande) ; comptage des commandes en `order_id` distinct ; CA = Σ prix × quantité ; nom `marketplace_<from ou date>.xlsx` | [x] |

Route **retirée** à ne pas recréer : `GET /api/magento/token` doit répondre **404**
(test de sécurité existant).

---

## 2. Modèles et tables

Toutes ces tables existent déjà : la v2 les utilise **sans migration ni ALTER**.
Les modèles Eloquent pointent sur les noms de tables et de colonnes existants.

| Modèle v1 (Sequelize) | Table | Clé | Points d'attention pour Eloquent | v2 |
|---|---|---|---|---|
| Seller | `marketplace_seller` | `seller_id` (int unsigned, auto) | timestamps `created_at`/`updated_at` ; `email` unique en base ; `shop_title` unique **seulement côté application** (aucun index) ; `status` 0 en attente, 1 validé, 2 refusé ; `password_hash` au format bcrypt `$2b$` coût 8 (voir C12) ; 1 vendeur a un hash vide | [x] |
| Order | `orders` | composite (`order_id`, `sku`) | pas de timestamps ; `vendor_id` sans clé étrangère ; `processed_at` = date de synchronisation | [x] |
| ProduitConsulte | `produits_consultes` | `id_produit` | une seule colonne, pas de timestamps | [x] |
| Reclamation | `reclamations` | `id` (bigint) | type 0 notification, 1 ouverte, 2 résolue ; FK `vendeur_id` → seller **ON DELETE CASCADE** | [x] |
| ReclamationMessage | `reclamation_messages` | `id` | `sender` 0 admin, 1 vendeur ; seulement `created_at` ; FK cascade | [x] |
| ReclamationAttachment | `reclamation_attachments` | `id` | `file_path` (500), `file_type` (50) ; seulement `created_at` ; FK cascade | [x] |
| (SQL direct) | `user_product_behavior` | `id` | alimentée par le suivi comportemental ; `event_type` enum (product_view, category_view, search, add_to_cart) ; `created_at` par défaut | [x] |

Relations à reproduire : Seller 1–n Reclamation, Reclamation 1–n Message (`messages`),
Message 1–n Attachment (`attachments`), Reclamation n–1 Seller.

Données présentes au 3 octobre 2026 : 32 vendeurs (25 en attente, 6 validés, 1 refusé),
367 lignes de commande, 11 réclamations, 12 messages, 0 pièce jointe, 27 événements de
comportement.

Les tables techniques Laravel (sessions, cache, jobs, jetons…) seront créées par des
**migrations séparées**, sans toucher aux tables ci-dessus.

---

## 3. Tâches planifiées et services externes

| # | Élément v1 | Comportement à reproduire | Équivalent v2 | v2 |
|---|---|---|---|---|
| T01 | Cron `syncDailyOrders` (`CRON_ORDERS_SYNC`, défaut chaque minute, fuseau Europe/Paris) | Vendeurs validés ; commandes Magento `state=complete` dont `updated_at` est dans la journée, toutes pages ; vendeur de chaque ligne via `seller_id` de la ligne puis, à défaut, de la fiche produit ; insertion idempotente sur (`order_id`, `sku`) avec `processed_at = maintenant` ; **une** notification groupée par vendeur (réclamation type 0 + message `sender=0` « Félicitations ! Vous avez enregistré N nouvelle(s) vente(s) aujourd'hui ») | Scheduler + Job | [x] |
| T02 | Cron `syncProductViews` (`CRON_PRODUCT_VIEWS`) | Lecture incrémentale du journal Apache (`MAGENTO_ACCESS_LOG`) depuis l'offset (`MAGENTO_LOG_OFFSET_FILE`) ; classement search / product_view / category_view ; exclusion des pages techniques et des sous-dossiers ; résolution du slug par l'API Magento avec cache ; insertion dans `user_product_behavior` ; mise à jour de la jauge Prometheus | Scheduler + Job | [x] |
| S01 | `magentoAuth.service` | Jeton admin Magento obtenu par `POST /rest/V1/integration/admin/token`, mis en cache 1 h, jamais renvoyé au client | Service + cache Laravel | [x] |
| S02 | `opensearch.service` | Résout l'index `opensearch_index_*` le plus récent par `_cat/indices`, puis `_search` | Service | [x] |
| S03 | Appels REST Magento | `GET/POST/PUT/DELETE /rest/V1/products`, `GET /rest/V1/orders`, `GET /rest/V1/categories/list` | Service Magento | [x] |
| S04 | Prometheus | Scrape de `/metrics` (aujourd'hui sur le port 3000) | `/metrics` sur le port 8000 (voir C10) | [x] |

Écarts connus de la v1 à **garder documentés** (le rapport les décrit comme limites) :
le cache des slugs mémorise définitivement les échecs, et `processed_at` contient la date
de synchronisation et non la date de commande.

---

## 4. Écrans Angular (14 composants)

| # | Écran / composant | Fonctionnalités à reproduire | v2 |
|---|---|---|---|
| E01 | App (racine) | Barre de navigation + contenu + pied de page | [x] |
| E02 | Navbar | Bandeau « Espace réservé aux vendeurs partenaires » et contact ; logo Mytek ; boutons Se connecter / Devenir vendeur si déconnecté ; Dashboard / Déconnexion si connecté ; **cloche** de notifications : compte des réclamations type 0 non vues, **rafraîchi toutes les 30 s** ; menu déroulant avec le dernier message et la date de chaque notification ; ouverture du menu = tout marquer comme vu ; fermeture au clic extérieur | [x] |
| E03 | Footer | Présentation, liens rapides, contact | [x] |
| E04 | Home | Page d'accueil publique : accroche, 3 avantages, 3 étapes, boutons vers inscription et connexion | [x] |
| E05 | Login | Identifiant (email ou boutique) + mot de passe, bouton désactivé si vide ; message d'erreur de l'API ou « Impossible de se connecter au serveur. » ; succès → espace vendeur | [x] |
| E06 | Register | Prénom, nom, email, mot de passe ≥ 6, téléphone, boutique, adresse, code postal, gouvernorat (liste), case patente ; matricule fiscal requis seulement si patente cochée ; bouton désactivé tant que le formulaire est invalide ; message de succès « Bienvenue chez Mytek ! … sous 48h » à la place du formulaire ; erreur de l'API affichée (voir C05) | [x] |
| E07 | Dashboard layout + garde | Accès réservé aux connectés, sinon redirection vers la connexion ; page par défaut « Mon Compte » | [x] |
| E08 | Sidebar | Menu : Mon Compte, Mes Produits, Ajout Produit, Liste Ventes, Statistiques, Réclamations ; badge des réclamations ouvertes non vues, masqué sur la page Réclamations et rafraîchi en la quittant | [x] |
| E09 | Account | Onglet profil (prénom, nom, email, téléphone, boutique, adresse, gouvernorat, code postal, patente, matricule si patente) ; onglet mot de passe : actuel, nouveau ≥ 6, confirmation, **blocage si les deux ne concordent pas** ; messages masqués après 2 s (voir C04) | [x] |
| E10 | Products | Onglets « Produits Validés » / « Produits en Attente » ; recherche (Entrée ou bouton) ; pagination en haut et en bas avec première, précédente, numéros avec « … », suivante, dernière, « Page x / y » ; carte produit : image, nom, SKU, description courte nettoyée, prix et prix barré si promotion, badge de statut ; clic sur un produit validé → ouvre la fiche sur le site Magento ; clic sur un produit en attente → fenêtre « Produit en attente de validation » ; « Aucun produit trouvé. » (voir C02, C03, C06) | [x] |
| E11 | Add-product | SKU (motif contrôlé, message dédié), nom, prix, poids, description courte, description, image principale obligatoire, images secondaires multiples ; produit envoyé avec `attribute_set_id 4`, `type_id simple`, `visibility 4`, stock 10000 ; succès → formulaire et fichiers réinitialisés ; erreurs 409 affichées | [x] |
| E12 | Sales | Filtre SKU en direct, plage de dates du / au ; tableau groupé par SKU (quantité totale, prix unitaire, total) ; clic → fenêtre du détail des commandes du SKU ; **export Excel** côté client (SKU, produit, commande, quantité, prix, total, date, ligne TOTAL) nommé `commandes_<du>_au_<au>.xlsx`, désactivé si vide (voir C07) | [x] |
| E13 | Statistics | Périodes 7 jours / 30 jours / année ; KPI volume, revenus, commandes distinctes ; courbe des ventes globales ; comparaison de **5 SKU au maximum** avec message au-delà ; top 5 des meilleures ventes ; « Aucune donnée disponible » | [x] |
| E14 | Reclamations | Liste des réclamations ouvertes avec aperçu du dernier message ; création avec message obligatoire et pièces jointes multiples (sans doublon de nom, retrait possible) ; conversation avec noms « Vous » / « Support Mytek », pièces jointes à voir ou télécharger, défilement en bas ; réponse avec pièces jointes ; fenêtre « Problème résolu ? » ; marquage vu à l'ouverture ; notifications toast | [x] |

Partie **non reprise** proposée : la vue « admin » du composant Réclamations, activée par
`sessionStorage['user-role']`, n'est jamais activable dans la v1 (aucun code n'écrit cette clé).
Le traitement humain des réclamations se fait dans le module Magento (voir C13).

---

## 5. Cas d'utilisation du rapport (chapitre 2, § 2.2)

| # | Cas d'utilisation (tableau) | Nominal | Exceptions à reproduire | Couvert par | v2 |
|---|---|---|---|---|---|
| U01 | S'inscrire (tab. 2.2) | Formulaire → POST register → contrôles → liste blanche → statut en attente → 201 → message d'attente | 400 avec le champ en cause ; 409 email ou boutique | R03, E06 | [x] |
| U02 | Se connecter (tab. 2.3) | Email ou boutique + mot de passe → 200 → tableau de bord | 404 inconnu ; 401 mauvais mot de passe ; 403 en attente ou refusé (voir C14) | R04, E05 | [x] |
| U03 | Consulter et modifier son profil (tab. 2.4) | Onglet profil, onglet mot de passe, confirmation | 401 ancien mot de passe faux ; blocage si confirmation différente ; 401/403 jeton absent ou expiré → invitation à se reconnecter | R05, R06, E09 | [x] |
| U04 | Ajouter un produit (tab. 2.5) | Formulaire → POST → validation → produit désactivé → 201 → confirmation | 409 SKU ; 409 nom (clé d'URL) ; 400 champ manquant ou mal formé, champ mis en évidence | R07, E11 | [x] |
| U05 | Consulter la liste de ses produits (tab. 2.6) | Distinction validés / en attente ; recherche | Liste vide avec invitation à soumettre un premier article ; « aucun produit trouvé » et pagination réinitialisée ; repli Magento sans erreur visible si OpenSearch absent | R08, R09, E10 | [x] |
| U06 | Modifier le prix et la promotion (tab. 2.7) | Fenêtre de modification → prix, promotion **et ses dates** → PUT → contrôle seller_id → 200 → liste rafraîchie | 403 autre vendeur ; 400 référence absente ou aucun prix | R10, E10 | [x] |
| U07 | Supprimer un produit (tab. 2.8) | « Supprimer » + confirmation → DELETE → contrôle → 200 | 403 autre vendeur ; 400 référence absente | R11, E10 | [x] |
| U08 | Consulter l'historique de ses ventes (tab. 2.9) | Filtre SKU ou période → résultats → export Excel | Tableau vide et aucun fichier exporté ; date de début > date de fin → invitation à corriger | R15, E12 | [x] |
| U09 | Consulter ses statistiques (tab. 2.10) | Ventes globales, filtre de période, ventes par produit, meilleures ventes | Zéros et graphiques vides ; refus au-delà de 5 références avec message | R15, E13 | [x] |
| U10 | Soumettre et suivre une réclamation (tab. 2.11) | Création avec fichiers, historique, nouveau message, « Problème résolu » → PUT resolve → type 2 | 404 réclamation d'un autre ; 409 réponse à une réclamation résolue ; 400 message vide | R16–R21, E14 | [x] |
| U11 | Valider et enrichir un produit (tab. 2.12) | Écrans natifs Magento | Échec d'enregistrement Magento ; indexation différée | Magento natif, **hors v2** | — |
| U12 | Consulter la liste des vendeurs (tab. 2.13) | Module Magento | « Aucun vendeur trouvé » ; session expirée | Module Magento, **hors v2** ; R30 côté API | [x] |
| U13 | Valider ou refuser un vendeur (tab. 2.14) | Module Magento, statut 1 ou 2 | Refusé → connexion 403 « Compte refusé » | Module Magento ; effet vérifié par R04 | [x] |
| U14 | Supprimer un vendeur (tab. 2.15) | Module Magento ; cascade sur les réclamations ; ventes conservées | Vendeur introuvable | Module Magento ; la v2 doit supporter les ventes orphelines | [x] |
| U15 | Traiter une réclamation (tab. 2.16) | Module Magento : liste, conversation, réponse, résolution | Formulaire masqué si résolue ; message vide refusé ; réclamation introuvable | Module Magento + R22–R25, R27 ; pièces jointes servies par R28 (voir C01) | [x] |
| U16 | Envoyer une notification à un vendeur (tab. 2.17) | POST admin/notifications ou synchronisation → réclamation type 0 → badge (30 s) → lue à l'ouverture | 401 clé absente ou fausse ; 503 clé non configurée | R26, T01, E02 | [x] |
| U17 | Tableau de bord global et export (tab. 2.18) | Module Magento + export Excel de la passerelle | 404 « Aucune commande trouvée pour cette période » ; 401 ; 503 | R30–R33 | [x] |
| U18 | Cas du client (§ 2.2.3) | Storefront Magento natif | Gérés par Magento | **hors v2** | — |

Besoins non fonctionnels (tab. 2.19) : charte Mytek (rouge `#c70a0a`), recherche < 3 s
(mesure à refaire sur la v2, comme le tableau 3.4), responsive ordinateur / tablette / mobile,
authentification et autorisation par rôle, séparation des responsabilités.

Parcours de bout en bout à rejouer (tab. 3.2, T3-08) : inscription → validation dans le module
→ soumission produit → activation → commande → synchronisation et notification.

Vérifications du chapitre 4 à automatiser dans la v2 (le rapport les dit manuelles) :
T4-01 à T4-04 (classement des lignes de journal), T4-05 et T4-06 (idempotence et rattachement
multi-vendeurs), T4-07 et T4-08 (recherche partielle et isolation par vendeur).

---

## 6. Les 24 tests Jest à reproduire en Pest

| Fichier v1 | Test | Attendu | v2 |
|---|---|---|---|
| auth | inscription nouvel email | 201 | [x] |
| auth | email déjà utilisé | 409, message contient « Email » | [x] |
| auth | boutique déjà utilisée | 409, message contient « boutique » | [x] |
| auth | identifiant inconnu | 404 | [x] |
| auth | mot de passe incorrect | 401 | [x] |
| auth | compte en attente | 403 | [x] |
| auth | `GET /` | 200 | [x] |
| auth | `GET /metrics` | 200 et compteur de requêtes présent | [x] |
| validation | email manquant | 400, message contient « email » | [x] |
| validation | email mal formé | 400 | [x] |
| validation | mot de passe trop court | 400 | [x] |
| validation | ajout produit sans jeton | 403 | [x] |
| security | stats sans clé | 401 | [x] |
| security | stats avec mauvaise clé | 401 | [x] |
| security | export sans clé | 401 | [x] |
| security | admin/reclamations sans clé | 401 | [x] |
| security | stats avec bonne clé | 200, sans `password_hash` | [x] |
| security | `GET /api/magento/token` | 404 | [x] |
| security | lire les messages d'une réclamation étrangère | 404 | [x] |
| security | répondre à une réclamation étrangère | 404 | [x] |
| security | résoudre une réclamation étrangère | 404 | [x] |
| security | résoudre sans jeton | 403 | [x] |
| security | résoudre sa propre réclamation | 200, type 2 | [x] |
| security | `status` et `seller_id` forcés à l'inscription | 201 puis connexion 403 | [x] |

Différence de méthode : les tests Jest visent un serveur démarré et une vraie base. Les tests
Pest tourneront sur une base de test isolée (SQLite en mémoire ou base MySQL dédiée), avec
Magento et OpenSearch simulés, sans dépendre d'un compte vendeur existant.

---

## 7. Configuration

Variables de la v1 à retrouver dans la v2 (noms et rôles, jamais les valeurs) :
`PORT`, `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS` (→ `DB_PASSWORD` en Laravel), `DB_NAME`
(→ `DB_DATABASE`), `JWT_SECRET` (remplacé par la session Sanctum), `ADMIN_API_KEY`,
`MAGENTO_URL`, `MAGENTO_USER`, `MAGENTO_PASSWORD`, `OPENSEARCH_BASE_URL`, `MAGENTO_ACCESS_LOG`,
`MAGENTO_LOG_OFFSET_FILE`, `CRON_ORDERS_SYNC`, `CRON_PRODUCT_VIEWS`.
Non reprises car inutilisées dans la v1 : `TWILIO_*`, `MAGENTO_ACCESS_TOKEN`, `DB_DIALECT`.
Nouvelles côté v2 : taux de commission (5 % codé en dur en v1), URL publique du site Magento
pour ouvrir les fiches produit (codée en dur `http://localhost/` en v1), origines CORS
(codées en dur en v1), URL de l'API côté Vite.

---

## 8. Contradictions et décisions à prendre

Pour chaque point, je donne ma recommandation. Rien ne sera tranché sans ta réponse.

| # | Sujet | Rapport | Ancien code | Recommandation |
|---|---|---|---|---|
| C01 | Pièces jointes dans le module Magento | Les pièces jointes sont « servies par la passerelle » (ch. 3) | Le module Magento contient l'adresse **en dur** `http://localhost:3000/api/attachments/view/`. On ne peut pas modifier le module et la v2 tourne sur le port 8000 | Garder les mêmes chemins d'API dans la v2. Documenter que l'admin Magento affiche les pièces jointes via le port 3000 tant que le module n'est pas modifié. Alternative : faire tourner la v2 sur le port 3000 le jour où l'ancienne version est arrêtée |
| C02 | Modifier le prix et supprimer un produit | Fonctions décrites (tab. 2.7 et 2.8) et présentes au diagramme de cas d'utilisation | API présente, mais **boutons commentés** dans l'écran Angular : le vendeur ne peut pas les utiliser | Les afficher dans la v2, conformément au rapport |
| C03 | Dates de la promotion | Le vendeur saisit « une promotion et ses dates » (tab. 2.7) | L'API accepte les dates mais l'écran n'a pas de champ de date | Ajouter les deux champs de date |
| C04 | Modifier son profil | Titre « Consulter et **modifier** son profil » (tab. 2.4), scénario limité au mot de passe | Profil en lecture seule, seul le mot de passe change | Garder la lecture seule pour la parité. La modification du profil peut être ajoutée comme fonctionnalité à l'étape 3 |
| C05 | Champs obligatoires à l'inscription | 6 champs obligatoires (tab. 2.2) | L'écran exige en plus adresse, code postal et gouvernorat ; la liste ne propose que 4 gouvernorats | Garder les mêmes champs obligatoires que l'écran v1 et proposer les 24 gouvernorats |
| C06 | Liste de produits vide | Message invitant à soumettre un premier article (tab. 2.6) | « Aucun produit trouvé. » dans tous les cas | Suivre le rapport : message différent pour un catalogue vide et pour une recherche sans résultat |
| C07 | Plage de dates inversée dans les ventes | Le vendeur est « invité à corriger la plage » (tab. 2.9) | Aucune alerte, la liste est simplement vide | Suivre le rapport : message d'alerte |
| C08 | Routes publiques `disabled-seller-products` et `produits-consultes` | Non mentionnées | Publiques, sans appelant trouvé dans le code ; la première expose les noms des vendeurs et leurs produits non publiés | Les reprendre à l'identique pour la parité, mais protégées par la clé admin. À confirmer : sais-tu quel outil les appelle ? |
| C09 | Pièces jointes publiques | « Contrôle de propriété pour les réclamations » (ch. 3) | `view` et `download` sans authentification ; un nom de fichier encodé avec `..%2F` peut sortir du dossier (lecture de fichiers du serveur) | Dans la v2 : refuser tout nom contenant un chemin, et n'autoriser que le vendeur propriétaire ou la clé admin. Exception à garder pour l'admin Magento (C01) : à trancher avec toi |
| C10 | Supervision | Prometheus collecte la passerelle (ch. 4) | Prometheus vise le port 3000 et Grafana interroge les métriques `nodejs_http_requests_total` et `nodejs_http_duration_seconds` | Exposer dans la v2 des métriques aux noms neutres (`http_requests_total`, `http_request_duration_seconds`) et ajouter une cible 8000 dans Prometheus avec un tableau de bord Grafana dédié. Cela demande de modifier la configuration Prometheus et Grafana, situées hors des deux projets |
| C11 | Message vide à la création d'une réclamation | « Message vide : 400 » (tab. 2.11) | Seul l'écran bloque ; l'API accepte un message vide à la création | 400 aussi à la création |
| C12 | Format des mots de passe | « Mots de passe hachés avec bcrypt » | Hash `$2b$` de bcryptjs, coût 8 | Testé le 3 octobre : `password_verify` de PHP 8.4 accepte les hash `$2b$`, et bcryptjs accepte les hash `$2y$` produits par PHP. Mais le contrôle d'algorithme de Laravel classe `$2b$` comme « inconnu » et refuserait la connexion. La v2 désactive donc ce contrôle (option de vérification bcrypt de Laravel) et écrit des hash standard `$2y$`, lisibles par les deux versions. Ce n'est pas une contradiction avec le rapport, seulement une contrainte technique à connaître |
| C13 | Vue admin des réclamations dans l'espace vendeur | Le back-office est dans Magento (ch. 3) | Vue admin Angular présente mais jamais activable | Ne pas la reprendre, sauf si tu retiens les « rôles et permissions » à l'étape 3 |
| C14 | Connexion : 404 ou 401 | « Amélioration prévue : fusionner 404 et 401 » (tab. 2.3) ; tests T3-03 attendant 404 | 404 puis 401 | Garder 404 / 401 pour la parité stricte, et faire la fusion en 401 comme amélioration de sécurité si tu la retiens |
| C15 | Ouverture de la fiche produit | Lien vers la fiche publiée sur mytek.tn (ch. 2) | Ouvre `http://localhost/<url_key>.html`, adresse en dur qui ne correspond pas au Magento local sur 8080 | Adresse configurable par variable d'environnement |
| C16 | Clic sur une notification | Le vendeur « la marque comme lue en l'ouvrant » (tab. 2.17) | Le clic sur une notification ne mène nulle part ; les notifications ne sont pas listées dans la page Réclamations | Garder le comportement v1 ; un historique des notifications peut être proposé à l'étape 3 |
