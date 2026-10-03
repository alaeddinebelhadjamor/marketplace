# Audit du module Magento `Mytek_Marketplace`

Rédigé le 3 octobre 2026, avant toute modification du code.

Sources lues :
- les 54 fichiers du module (PHP, XML, modèles de page, CSS), source en lecture seule
  (`Bureau/ala/marketplace/magento-module/app/code/Mytek/Marketplace`) ;
- la copie déployée dans `~/magento/app/code/Mytek/Marketplace` (comparée fichier par fichier) ;
- le rapport : `chapitre2.tex`, `chapitre3.tex`, `chapitre4.tex` ;
- la documentation de la v2 : `checklist-parite.md`, `notes-migration.md`, et le code de l'API
  qui concerne le module (pièces jointes, notifications, commissions) ;
- l'état réel des données, lu sans rien modifier : base Magento (port 3309), base marketplace
  (port 3307) et OpenSearch (port 9201).

Légende de l'état : **fait** (conforme au rapport), **partiel** (existe mais incomplet ou
défectueux), **absent** (rien dans le module), **natif** (assuré par Magento sans code du module,
comme le rapport le prévoit).

---

## 1. Inventaire du module

| Élément | Contenu |
|---|---|
| Déclaration | `registration.php`, `etc/module.xml` (dépend de Backend, Eav, Catalog) |
| Menu admin | `etc/adminhtml/menu.xml` : Marketplace › Tableau de bord, Vendeurs, Validation vendeurs, Réclamations vendeurs |
| ACL | `etc/acl.xml` : `marketplace`, `dashboard`, `sellers` (+ `sellers_edit`, `sellers_validate`, `sellers_delete`), `reclamations` |
| Contrôleurs admin (13) | `Dashboard/Index`, `Dashboard/Export` ; `Seller/Index`, `Pending`, `Edit`, `Save`, `Validate`, `Refuse`, `Delete` ; `Reclamation/Index`, `View`, `Reply`, `Resolve`. Les actions qui écrivent sont en POST (`HttpPostActionInterface`), avec clé de formulaire et `ADMIN_RESOURCE` |
| Contrôleur front (1) | `Seller/View` : page boutique `/marketplace/seller/view/id/{id}` |
| Modèles | `SellerRepository`, `ReclamationRepository`, `OrderStats`, tous trois sur la connexion `marketplace` |
| Blocs et modèles de page | 5 blocs admin, 2 blocs front, 9 fichiers `.phtml`, 9 fichiers de mise en page |
| Data patch | `AddSellerIdAttribute` : attribut produit `seller_id` |
| Front | `default.xml` (CSS Mytek, pied de page, lien « Espace Vendeur »), `catalog_product_view.xml` (bloc « Vendu par ») |
| Traductions | `i18n/fr_FR.csv` : 43 libellés du storefront uniquement ; les textes du module sont écrits en dur en français dans les modèles de page, sans `__()` |
| Tests | aucun |

Connexion à la base marketplace : `env.php` déclare bien `db/connection/marketplace`
(127.0.0.1:3307, base `mk_database_prod_restored`) et la ressource `marketplace_setup`.

**Différence entre la source et la copie déployée** : un seul fichier,
`Controller/Adminhtml/Seller/Delete.php`. Le message d'erreur déployé est plus précis
(« Suppression impossible (le vendeur a probablement des réclamations ou des commandes
liées) : … ») que celui de la source (« Une erreur est survenue lors de la suppression : … »).
→ **À trancher** : quelle version sert de base au dépôt ? Je propose la version déployée.

---

## 2. Fonctions décrites par le rapport, et leur état réel

### 2.1 Back-office (administrateur et intégrateur)

| # | Fonction | Section du rapport | État | Preuve | Remarque |
|---|---|---|---|---|---|
| B01 | Menu « Marketplace » dans l'admin | ch. 2 § 2.9 ; ch. 3 § 3.4.1 | fait | `etc/adminhtml/menu.xml` | |
| B02 | Indicateurs : commandes, vendeurs actifs, revenu, articles vendus | ch. 3 § 3.4.1 ; tab. 2.18 | fait | `Block/Adminhtml/Dashboard.php`, `Model/OrderStats.php::getKpis` | |
| B03 | Meilleurs vendeurs (par revenu ou par commandes) | ch. 3 § 3.4.1 | fait | `OrderStats::getTopSellers`, `dashboard/index.phtml` | |
| B04 | Export CSV des données de synthèse | tab. 2.18 ; ch. 3 § 3.4.1 | fait | `Controller/Adminhtml/Dashboard/Export.php` | lignes de vente détaillées, sans filtre de période ni de vendeur |
| B05 | Export des **commissions** | ch. 2 § 2.2.2 ; tab. 2.18 (post-condition) | **absent** du module | aucune occurrence de « commission » dans le module | existe seulement dans l'API : `GET /api/export/excel` (feuille commissions), et dans la v2 les tables `commission_rates` (0 ligne) et `payout_statements` (0 ligne) |
| B06 | « Alertes » du tableau de bord | légende de la fig. 3.12 | **partiel** | `dashboard/index.phtml` l. 22-26 et 35-39 | deux liens contextuels seulement : « N inscription(s) en attente » et « N réclamation(s) non lue(s) » (ce dernier placé, par erreur, sous la carte « Articles vendus »). Pas de zone d'alertes, pas d'ancienneté (48 h), pas de produits en attente, pas de date de dernière synchronisation |
| B07 | Liste des vendeurs, recherche, filtre par statut | tab. 2.13 ; ch. 3 § 3.4.2 ; fig. 3.13b | fait | `Seller/Index.php`, `Block/Adminhtml/Seller/Grid.php`, `seller/grid.phtml` | pas de pagination (32 vendeurs aujourd'hui) |
| B08 | Modifier un vendeur | ch. 3 § 3.4.2 ; fig. 3.14a | fait | `Seller/Edit.php`, `Seller/Save.php`, `Block/Adminhtml/Seller/Form.php` | champs obligatoires et format d'email contrôlés ; 24 gouvernorats |
| B09 | Écran « Validation vendeurs » | tab. 2.14 ; fig. 3.13a | fait | `Seller/Pending.php` (grille filtrée sur le statut 0) | |
| B10 | Valider (1) ou refuser (2), ressource `sellers_validate` | tab. 2.14 ; ch. 2 § 2.7 | fait | `Seller/Validate.php`, `Seller/Refuse.php` | un vendeur refusé peut être validé plus tard, comme le dit § 2.7 |
| B11 | Prévenir le vendeur de la décision | (promesse de l'écran d'inscription, voir écart 2) | **absent** | aucun `TransportBuilder`, aucun `email_templates.xml` | |
| B12 | Supprimer un vendeur, ressource `sellers_delete` | tab. 2.15 | fait | `Seller/Delete.php` (POST, clé de formulaire, confirmation) | voir la différence source / déployé ci-dessus |
| B13 | Sort des produits d'un vendeur supprimé | tab. 2.15 (post-condition et exception) | conforme : **non traité** | — | le rapport prévoit un traitement manuel par l'intégrateur |
| B14 | Liste des réclamations avec indicateur « non lu » | tab. 2.16 ; ch. 3 § 3.4.2 ; fig. 3.14b | **partiel** | `Reclamation/Index.php`, `Block/Adminhtml/Reclamation/Listing.php` | **anomalie** : l'onglet « Non vues » filtre seulement `admin_viewed = 0` (types 1 et 2), alors que le compteur ne compte que les réclamations ouvertes (type 1). En base : le badge affiche 2, l'onglet liste 11 lignes (9 réclamations déjà résolues) |
| B15 | Conversation avec pièces jointes « servies par la passerelle » | tab. 2.16 ; ch. 3 § 3.4.2 | **partiel** | `Block/Adminhtml/Reclamation/View.php` l. 16 | adresse en dur `http://localhost:3000/api/attachments/view/` (v1 Node). La v2 (port 8010) exige désormais la clé `x-admin-key` pour lire une pièce jointe (`AttachmentController`, décision C09) : un simple lien depuis le navigateur ne peut pas fonctionner, même avec la bonne adresse. Aucune pièce jointe en base aujourd'hui |
| B16 | Répondre au vendeur | tab. 2.16 | fait | `Reclamation/Reply.php`, `ReclamationRepository::reply` | |
| B17 | Refuser une réponse sur une réclamation résolue | tab. 2.16 (exception) | **partiel** | `reclamation/view.phtml` l. 54 | le formulaire est masqué, mais `Reply.php` ne contrôle pas le type : une requête POST directe ajoute un message à une réclamation résolue |
| B18 | Marquer une réclamation résolue | tab. 2.16 | fait | `Reclamation/Resolve.php` | |
| B19 | Envoyer une notification à un vendeur | tab. 2.17 ; ch. 2 § 2.2.2 | **absent** du module | — | seulement `POST /api/admin/notifications` (clé `x-admin-key`), que l'intégrateur ne peut pas utiliser depuis l'admin |
| B20 | Rôles intégrateur et administrateur | ch. 2 § 2.1 ; ch. 3 § 3.4.2 | **partiel** | `etc/acl.xml` | les ressources existent. Les rôles « Intégrateur Marketplace » (id 3) et « Administrateur Marketplace » (id 4) existent dans la base Magento locale, avec un utilisateur chacun, mais ont été **créés à la main** : rien dans le module ne permet de les recréer sur un autre Magento. L'intégrateur a : catalogue, produits, catégories, stock, vendeurs, modification, réclamations ; pas le tableau de bord ni la validation ni la suppression. L'administrateur a `Magento_Backend::all` |
| B21 | Attribut produit `seller_id` | ch. 2 § 2.5 ; ch. 3 § 3.4 | fait | `Setup/Patch/Data/AddSellerIdAttribute.php` | attribut 137, filtrable dans la grille produits ; 480 produits sur 480 portent un vendeur (vendeurs 1, 2, 3, 4, 6) |
| B22 | Modération des produits (liste des désactivés, enrichissement, activation) | tab. 2.12 ; ch. 2 § 2.6.4 ; ch. 3 § 3.4.3 ; fig. 2.11, 3.15 | natif | écrans du catalogue Magento + colonne `seller_id` | conforme au rapport (« aucun écran n'a été développé »). 44 produits de vendeurs sont désactivés aujourd'hui. Pas de motif de refus possible |
| B23 | Indexation OpenSearch à l'activation d'un produit | tab. 2.12 ; fig. 2.11 ; ch. 2 § 2.7 | natif | index `magento2_product_1_v3` (436 documents) | voir contradiction X1 : ce n'est pas l'index lu par l'espace vendeur |
| B24 | Seconde connexion à la base marketplace | ch. 2 § 2.9 ; ch. 3 § 3.4.2 | fait | `SellerRepository::CONNECTION`, `env.php` | |

### 2.2 Front-office client

| # | Fonction | Section du rapport | État | Preuve | Remarque |
|---|---|---|---|---|---|
| F01 | Lien « Espace Vendeur » dans l'en-tête | ch. 3 § 3.3 et § 3.5 ; fig. 3.7 | **partiel** | `view/frontend/templates/html/mytek_vendor_link.phtml` | adresse en dur `http://localhost:4200` (application Angular v1). L'espace vendeur v2 est sur `http://localhost:5180` : le lien est mort dès que la v1 est arrêtée |
| F02 | Charte graphique Mytek | tab. 2.19 | fait | `web/css/mytek-real.css`, `default.xml`, `html/mytek_footer.phtml` | |
| F03 | « Vendu par » sur la fiche produit, avec lien vers la boutique | ch. 3 § 3.5.3 ; fig. 3.19 | fait | `Block/Seller/Link.php`, `catalog_product_view.xml` | n'apparaît que si le vendeur est validé |
| F04 | Page boutique d'un vendeur | ch. 2 § 2.2.3 ; ch. 3 § 3.5.3 ; fig. 3.20 | fait | `Controller/Seller/View.php`, `Block/Seller/View.php` | filtre de prix, tri ; 404 si vendeur non validé |
| F05 | Recherche par mot-clé, filtres prix et catégorie | ch. 2 § 2.2.3 ; ch. 3 § 3.5.2 | natif | navigation à facettes Magento | en local, pas de module `myteksearch` : la recherche est la recherche native de Magento sur OpenSearch |
| F06 | Filtre par **gouvernorat** | ch. 2 § 2.2.3 ; ch. 3 § 3.5.2 | **absent** | aucun attribut produit de gouvernorat ; `seller_id` n'est pas filtrable côté client | **donnée manquante** : 29 vendeurs sur 32 n'ont pas de gouvernorat (2 Tunis, 1 Ariana) |

### 2.3 Chapitre 4 (recherche et comportement)

| # | Fonction | Section du rapport | État | Remarque |
|---|---|---|---|---|
| R01 | Création et alimentation de l'index `opensearch_index_*` | ch. 4 § 4.1 | **absent** | ni dans le module, ni dans la v1, ni dans la v2. Le rapport le suppose « vraisemblablement dans un module côté Magento » : ce n'est pas le cas. OpenSearch ne contient que l'index natif de Magento. L'espace vendeur passe donc toujours par le repli sur l'API Magento |
| R02 | Unification des recherches client et vendeur | ch. 4 § 4.1 | **absent** | présenté par le rapport comme une limite assumée |
| R03 | Exploitation de `user_product_behavior` | ch. 4 § 4.2.3 et § 4.6 | **absent** du module | 31 événements en base (30 vues produit, 1 vue catégorie) ; seule une jauge Prometheus les exploite |

---

## 3. Les six écarts annoncés : confirmation

| # | Écart | Verdict | Détail |
|---|---|---|---|
| 1 | Filtre client par gouvernorat absent | **confirmé** | voir F06. Le module ne crée aucun attribut ; et même avec un attribut, 29 vendeurs sur 32 n'ont pas de gouvernorat : la facette n'aurait aujourd'hui que deux valeurs |
| 2 | Aucun email au vendeur après validation ou refus | **confirmé, avec une nuance** | aucun envoi dans le module (B11). La promesse ne vient pas du rapport (tab. 2.2 et 2.14 ne parlent pas d'email) mais de l'écran d'inscription **v1** (Angular, `register.html` : « vous recevrez un email de confirmation »). L'écran **v2** (Vue) a retiré cette phrase. Magento n'a aucune configuration SMTP en local |
| 3 | Aucun écran pour envoyer une notification | **confirmé** | voir B19 |
| 4 | Export des commissions absent du module | **confirmé** | voir B05 |
| 5 | « Alertes » du tableau de bord absentes | **partiellement confirmé** | voir B06 : deux liens d'alerte existent déjà, mais pas de véritable zone d'alertes |
| 6 | Adresse des pièces jointes en dur vers `http://localhost:3000` | **confirmé, et aggravé** | voir B15 : corriger l'adresse ne suffit pas, la v2 exige la clé admin |

## 4. Écarts supplémentaires trouvés

| # | Écart | Gravité | Preuve |
|---|---|---|---|
| 7 | Lien « Espace Vendeur » en dur vers `http://localhost:4200` (Angular v1) | moyenne : lien mort avec la v2 | F01 |
| 8 | Onglet « Non vues » des réclamations incohérent avec le compteur | faible : anomalie visible | B14 |
| 9 | Réponse possible à une réclamation résolue par requête POST directe | faible : contrôle côté écran uniquement | B17 |
| 10 | Rôles intégrateur et administrateur créés à la main, non reproductibles | moyenne pour le déploiement | B20 |
| 11 | Textes du module en dur, sans `__()`, et `fr_FR.csv` limité au storefront | faible : qualité | modèles de page |
| 12 | Lien « réclamations non lues » placé sous la carte « Articles vendus » ; liens des réseaux sociaux et pages légales du pied de page en `href="#"` | cosmétique | `dashboard/index.phtml` l. 35 ; `html/mytek_footer.phtml` |
| 13 | Aucun test automatique du module | moyenne pour le jury | — |
| 14 | Recherche de vendeurs : la valeur saisie est bien échappée (`quote`), mais insérée dans la chaîne de la clause `where` | faible : pas d'injection possible, mais style à corriger (requête liée) | `SellerRepository::getList` l. 41-42 |
| 15 | Suppression d'un vendeur : les lignes des nouvelles tables v2 (`commission_rates`, `payout_statements`, `seller_two_factor`) n'ont pas de clé étrangère et restent orphelines | faible | schéma de la base marketplace |

## 5. Contradictions à trancher (je ne choisis pas seul)

| # | Contradiction | Où | Question |
|---|---|---|---|
| X1 | Le rapport dit que l'activation d'un produit « l'indexe dans OpenSearch » et le rend trouvable (tab. 2.12, fig. 2.11) ; ch. 4 § 4.1 dit que l'espace vendeur lit l'index `opensearch_index_*`. En réalité, Magento indexe dans `magento2_product_1_v3`, et `opensearch_index_*` n'existe pas | ch. 2, ch. 4 | Faut-il créer l'index `opensearch_index_*` (amélioration J), ou plutôt faire lire l'index natif de Magento par la v2 (plus simple, mais modifie la v2) ? |
| X2 | Ch. 4 § 4.1, première phrase : « la recherche ne s'appuie pas sur le moteur natif de Magento mais sur OpenSearch » ; même section, 3e paragraphe, et ch. 2 § 2.6.3 : la recherche client passe par un module Magento natif | ch. 4 | Simple correction de rédaction du rapport, à noter dans `notes-magento.md` ? |
| X3 | Ch. 2 § 2.2.3 dit que les cas du client sont « le comportement natif du storefront, non développé dans ce projet », mais le même paragraphe et ch. 3 § 3.5.2 citent le filtre par gouvernorat, qui n'existe pas nativement | ch. 2, ch. 3 | Le filtre par gouvernorat devient-il une contribution du module (amélioration B), ou faut-il le retirer du rapport ? |
| X4 | Tab. 2.17 : l'intégrateur « peut adresser une notification », mais le seul moyen décrit est l'API avec la clé `x-admin-key` | ch. 2 | L'écran C règle ce point ; confirmes-tu que l'intégrateur doit y avoir accès (nouvelle ressource ACL) ? |
| X5 | Ch. 3 § 3.4.3 : « Aucun écran n'a été développé » pour la modération des produits | ch. 3 | Si l'amélioration F est retenue, cette phrase du rapport devra changer |
| X6 | Tab. 2.15 : les produits d'un vendeur supprimé restent en ligne et sont traités à la main | ch. 2 | Si l'amélioration H est retenue, la post-condition et l'exception du tableau 2.15 devront changer |
| X7 | Tab. 2.18 : export « des commissions » demandé, mais le texte du ch. 3 § 3.4.4 attribue les commissions (5 %) à l'export de la passerelle, et la v2 permet des taux par vendeur | ch. 2, ch. 3 | Le taux de référence pour le module est-il celui de la v2 (5 % par défaut + `commission_rates`) ? |

## 6. Constats d'environnement utiles pour la suite

- Magento répond sur `http://localhost:8080` (mode développeur), OpenSearch et les deux bases
  MySQL sont démarrés, l'API v2 répond sur le port 8010.
- **PHPUnit n'est pas installé dans `~/magento`** : `phpunit/phpunit ^9.5` figure dans
  `require-dev`, mais `vendor/bin/phpunit` n'existe pas (dépendances de développement absentes).
  Proposition : installer seulement PHPUnit 9.6 en dépendance de développement dans `~/magento`
  (`composer require --dev phpunit/phpunit:^9.6`, ce qui ajoute un paquet sans modifier le code
  de Magento), ou bien une archive `phpunit.phar` hors du dépôt. Je te demanderai avant.
- Aucun envoi d'email configuré dans Magento (pas de `system/smtp/*`), aucun conteneur Mailpit.
- Données au 3 octobre : 32 vendeurs (25 en attente, le plus ancien depuis le 16 septembre ;
  6 validés ; 1 refusé), 12 réclamations dont 2 ouvertes non lues, 0 pièce jointe,
  44 produits de vendeurs désactivés, dernière synchronisation des commandes le
  16 septembre 2026 à 18:10.

## 7. Points à valider avant l'étape 2

1. Les verdicts des six écarts (section 3), en particulier la nuance sur l'écart 5 (alertes partielles).
2. L'ajout des écarts 7 à 15 au périmètre.
3. Les contradictions X1 à X7.
4. La version de `Delete.php` à garder (je propose la version déployée).

---

## 8. Décisions (3 octobre 2026)

Prises avec une règle unique : **le rapport fait foi**. Une fonction qu'il décrit et que le code
n'a pas est réalisée ; une amélioration qui le contredit est écartée ou signalée.

### 8.1 Points de validation

| Point | Décision |
|---|---|
| Verdicts des six écarts | Validés tels quels, y compris le 5 « partiel » : les deux liens existants sont repris dans la nouvelle zone d'alertes |
| Écarts 7 à 15 | Tous retenus, sauf le 15 (lignes orphelines des tables v2) : il relève de la v2, il est seulement documenté |
| `Delete.php` | La version déployée sert de base (message plus précis) |
| X1 Index OpenSearch | Suivre le rapport : le module crée et alimente `opensearch_index_<horodatage>`, que la v2 lit déjà sans modification. L'index ne contient que les produits actifs, car la v2 n'y filtre que `seller_id` |
| X2 Rédaction ch. 4 § 4.1 | Correction de rédaction, notée dans `notes-magento.md` |
| X3 Gouvernorat | Le filtre devient une contribution du module (attribut produit filtrable) ; le rapport devra dire qu'il n'est pas natif |
| X4 Notification par l'intégrateur | Nouvelle ressource ACL `Mytek_Marketplace::notifications`, accordée au rôle Intégrateur |
| X5 et X6 | Les améliorations F et H sont écartées : elles contredisent ch. 3 § 3.4.3 et le tableau 2.15 |
| X7 Commissions | Taux par défaut configurable dans le module (5 %, comme `COMMISSION_RATE` de la v2) et taux par vendeur lus dans `commission_rates` |

### 8.2 Améliorations retenues, dans l'ordre de réalisation

| Ordre | Amélioration | Base dans le rapport | Effort estimé |
|---|---|---|---|
| 0 | Mise en dépôt du module, outillage de tests | — | 0,5 j |
| 1 | G : adresses configurables (API v2, espace vendeur), pièces jointes via un contrôleur admin qui ajoute la clé ; correctifs 8, 9, 11, 14 | ch. 3 § 3.4.2 et § 3.5 | 1 j |
| 2 | I : rôles Intégrateur et Administrateur créés par data patch | ch. 2 § 2.1 ; ch. 3 § 3.4.2 | 0,5 j |
| 3 | C : écran « Envoyer une notification » | tab. 2.17 | 1 j |
| 4 | E, D, L : alertes, export des commissions et relevés, produits les plus vus | fig. 3.12 ; tab. 2.18 ; ch. 4 § 4.6 | 1,5 j |
| 5 | A : email au vendeur après validation ou refus (Mailpit en local) | promesse de l'écran d'inscription v1 ; tab. 2.14 | 1 j |
| 6 | B : filtre par gouvernorat du vendeur | ch. 2 § 2.2.3 ; ch. 3 § 3.5.2 | 1,5 j |
| 7 | J : index OpenSearch de la marketplace | ch. 2 tab. 2.12 ; ch. 4 § 4.1 | 1,5 j |

Écartées : **F** (file de modération) et **H** (désactivation automatique), qui contredisent
le rapport ; **K** (unification des recherches), présentée par le rapport comme une limite assumée.

### 8.3 Choix techniques

- **Notifications** : l'écran appelle `POST /api/admin/notifications` de la v2 côté serveur, avec
  la clé `x-admin-key`, au lieu d'écrire directement en base. Les pièces jointes sont ainsi
  stockées là où la v2 les sert, et la notification temps réel du vendeur part normalement.
- **Clé admin** : stockée chiffrée dans la configuration Magento (champ « obscure »), jamais
  écrite dans le HTML ; les pièces jointes et les relevés PDF passent par des contrôleurs admin
  qui relaient la requête.
- **Nouvelles tables** : aucune. Les tables utiles (`commission_rates`, `payout_statements`,
  `order_magento_dates`) existent déjà grâce aux migrations de la v2.
- **Langue** : textes du module en anglais dans `__()` et traduits dans `i18n/fr_FR.csv`, comme le
  veut Magento. Les trois comptes admin locaux passent en langue d'interface `fr_FR` pour que
  l'admin reste en français.
