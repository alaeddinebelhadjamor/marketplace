# Notes du module Magento `Mytek_Marketplace`

Rédigé au fil des incréments. Sert de base à la mise à jour du rapport (chapitres 2 à 4).
Audit initial et décisions : voir `docs/magento-audit.md`.

---

## Incrément 0 — mise en dépôt du module

Copie du module (version déployée) dans `magento-module/app/code/Mytek/Marketplace` : le dépôt
devient la source de vérité, versionnée. Outillage ajouté :
- `scripts/magento-deploy.sh` : copie vers `~/magento`, `setup:upgrade`, `cache:flush`.
- `scripts/magento-tests.sh` : tests unitaires PHPUnit 9.6 (phar autonome dans `~/tools`,
  Magento lui-même n'a pas ses dépendances de développement installées), avec l'amorce de
  tests de Magento et un chargeur de classes pointant sur le dépôt.

Aucune section du rapport concernée (travail d'infrastructure).

## Incrément 1 — configuration, relais des pièces jointes, correctifs

### Changements

- **Écart 6 du rapport (ch. 3 § 3.4.2, adresse des pièces jointes codée en dur)** : corrigé.
  Nouvelle section Stores > Configuration > Mytek > Marketplace (`etc/adminhtml/system.xml`) :
  adresse de l'API v2 et clé d'administration (champ `obscure`, chiffrée en base par
  `Magento\Config\Model\Config\Backend\Encrypted`, jamais renvoyée en clair au navigateur —
  vérifié : le champ s'affiche en `type="password"` et la valeur réelle n'apparaît jamais dans
  le HTML de la page). Les pièces jointes ne sont plus un lien direct vers la v1 : un nouveau
  contrôleur admin (`Controller\Adminhtml\Reclamation\Attachment`) vérifie que le fichier
  appartient bien à la réclamation consultée, puis relaie la demande à l'API v2 avec l'en-tête
  `x-admin-key` ajouté côté serveur. Vérifié en recette : HTTP 200, `Content-Type: application/pdf`.
- **Écart 7 (lien « Espace Vendeur » en dur vers `http://localhost:4200`)** : corrigé. Nouveau
  champ de configuration (adresse de l'espace vendeur), lu par un bloc dédié
  (`Block\VendorSpaceLink`) ; valeur par défaut `http://localhost:5180` (v2). Vérifié sur le
  storefront : le lien pointe bien vers la bonne adresse.
- **Écart 8 (onglet « Non vues » incohérent avec le compteur du tableau de bord)** : corrigé.
  `ReclamationRepository::getList(true)` ne retourne plus que les réclamations ouvertes
  (`type = 1`) non vues par l'admin, exactement ce que compte `countUnseen()`.
- **Écart 9 (réponse possible sur une réclamation déjà résolue par requête directe)** : corrigé.
  `Controller\Adminhtml\Reclamation\Reply` refuse désormais toute réponse si la réclamation est
  résolue (contrôle serveur, en plus du masquage déjà fait côté écran).
- **Écart 11 (textes en dur, sans `__()`)** : corrigé. Tous les textes des contrôleurs, blocs et
  modèles de page passent par `__()` (anglais en langue source, comme l'exige Magento) ;
  `i18n/fr_FR.csv` traduit l'ensemble (collecté avec `bin/magento i18n:collect-phrases`, aucune
  phrase manquante).
- **Écart 12 (lien « réclamations non lues » mal placé dans le tableau de bord)** : corrigé,
  sorti de la carte « Articles vendus » vers une ligne dédiée.
- **Écart 14 (recherche de vendeurs : jokers LIKE non neutralisés)** : corrigé.
  `SellerRepository::getList()` utilise désormais des valeurs liées et neutralise `%`/`_` dans
  la saisie (`escapeLike`), ce qui évite qu'une recherche contenant `%` ou `_` renvoie des
  résultats inattendus.

Écart 15 (lignes orphelines des tables v2 à la suppression d'un vendeur) : non traité ici,
documenté dans l'audit comme relevant de la v2.

### Décisions techniques

- Le module appelle l'API v2 en HTTP via `GuzzleHttp\ClientFactory` (déjà fourni par Magento,
  aucune dépendance ajoutée). La clé ne sort jamais du serveur Magento : tout accès aux pièces
  jointes passe par le contrôleur admin, jamais par un lien direct vers l'API.
- Nom de fichier des pièces jointes strictement contrôlé côté module
  (`AttachmentProxy::isValidFilename`, lettres/chiffres/points/tirets, aucun `..`) avant tout
  appel à l'API, en plus du contrôle déjà fait côté v2 (décision C09 de la checklist de parité).
- Type MIME renvoyé au navigateur limité à une liste blanche pour l'affichage en ligne (image,
  PDF, texte) ; tout le reste est forcé en téléchargement, pour ne jamais afficher un fichier
  HTML ou SVG dans le domaine de l'admin.

### Tests

33 tests unitaires PHPUnit (`scripts/magento-tests.sh`), 60 assertions : libellés de statut,
normalisation des chemins de pièces jointes (séparateurs Windows et Unix), détection d'une
réclamation résolue, lecture de la configuration (clé déchiffrée, délai minimal d'une seconde),
client API (en-tête `x-admin-key`, échec réseau converti en message lisible, envoi multipart),
validation des noms de fichiers et des types MIME, contrôle de propriété d'une pièce jointe.

### Recette manuelle (étapes, résultat attendu)

| # | Écran | Étapes | Résultat attendu | Statut |
|---|---|---|---|---|
| N1 | Configuration | Stores > Configuration > Mytek > Marketplace, renseigner l'adresse de l'API et la clé, Enregistrer | La page se recharge, le champ clé reste vide à l'affichage (jamais la valeur en clair) | Validé |
| N2 | Réclamation avec pièce jointe | Marketplace > Réclamations vendeurs > ouvrir une réclamation contenant une pièce jointe | Le fichier s'affiche ou se télécharge correctement (testé avec un PDF) | Validé |
| N3 | Pièce jointe d'une autre réclamation | Modifier l'URL du fichier pour pointer vers l'identifiant d'une autre réclamation | « Pièce jointe introuvable » | Validé (test unitaire `AttachmentProxyTest`) |
| N4 | Réclamation résolue | Répondre directement en POST à une réclamation déjà résolue (hors écran) | Message d'erreur, aucun message ajouté | Validé (test unitaire + contrôle manuel) |
| N5 | Lien Espace Vendeur | Page d'accueil du site, cliquer sur « Espace Vendeur » | Ouvre l'adresse configurée (v2, port 5180) | Validé |
| N6 | Liste des réclamations non vues | Comparer le badge du tableau de bord et le contenu de l'onglet « Non vues » | Même nombre, mêmes réclamations (ouvertes, non lues) | Validé |

### Limites connues à cette étape

- Le footer du storefront garde des liens de réseaux sociaux et de pages légales en `href="#"`
  (écart 12 bis, cosmétique) : non traité, reporté si une maquette définitive est fournie.
- Les rôles « Intégrateur » et « Administrateur » restent créés à la main dans ce Magento local
  (traité à l'incrément 2, data patch ACL).

## Incrément 2 — rôles ACL par data patch

### Changements

- **Écart 10 de l'audit (rôles Intégrateur/Administrateur créés à la main, non reproductibles)** :
  corrigé. Nouveau data patch `Setup\Patch\Data\CreateMarketplaceRoles`, qui reproduit par code
  les deux rôles « Intégrateur Marketplace » et « Administrateur Marketplace » (ch. 2 § 2.1 :
  « l'administrateur hérite des capacités de l'intégrateur et les étend »).
- Ressources accordées à l'intégrateur (ch. 3 § 3.4.2 : « consultation, modification,
  réclamations ») : catalogue (modération des produits, ch. 2 § 2.6.4), vendeurs (liste +
  modification, pas de validation ni de suppression), réclamations. Volontairement exclu :
  le tableau de bord Marketplace, qui porte des données financières réservées à l'administrateur
  (ch. 2 § 2.1 : « il... supervise les aspects financiers »).
- L'administrateur reçoit l'accès complet (`Magento_Backend::all`), comme le rôle natif
  « Administrators ».
- Le patch suit exactement la logique du contrôleur natif `Magento\User\...\SaveRole` (mêmes
  appels `setName`/`setRoleType`/`setUserType`, même `RulesFactory::saveRel`), pour rester
  cohérent avec le reste de Magento.
- **Idempotent** : si un rôle du même nom existe déjà (cas de ce Magento local, configuré à la
  main avant ce patch), ses ressources sont simplement remises à cet état, sans doublon ; les
  comptes déjà rattachés à ces rôles (`integrateur`, `admin.marketplace`) ne sont pas affectés.
- **Réversible** (`PatchRevertableInterface`) : `revert()` retire les ressources accordées par
  le patch, sans supprimer les rôles ni désolidariser les comptes qui y sont rattachés — les
  supprimer romprait leur rattachement, ce qui serait plus destructeur que la situation de
  départ.

### Recette (vérifiée en se connectant successivement avec un compte de chaque rôle)

| # | Compte | Écran | Résultat attendu | Obtenu |
|---|---|---|---|---|
| I1 | Intégrateur | Marketplace > Tableau de bord | 403 (refusé) | 403 |
| I2 | Intégrateur | Marketplace > Validation vendeurs | 403 (refusé) | 403 |
| I3 | Intégrateur | Marketplace > Vendeurs | 200 (autorisé) | 200 |
| I4 | Intégrateur | Catalogue > Produits | 200 (autorisé) | 200 |
| I5 | Intégrateur | Marketplace > Réclamations | 200 (autorisé) | 200 |
| I6 | Administrateur | Marketplace > Tableau de bord | 200 (autorisé) | 200 |
| I7 | Administrateur | Marketplace > Validation vendeurs | 200 (autorisé) | 200 |

Tous validés.

## Incrément 3 — écran admin « Envoyer une notification »

### Changements

- **Écart 3 de l'audit (aucun écran pour notifier un vendeur, cas 2.17)** : corrigé. Nouvel
  écran Marketplace > Envoyer une notification (ACL `Mytek_Marketplace::notifications`),
  accessible à l'intégrateur et à l'administrateur (décision X4 : l'intégrateur doit pouvoir
  le faire, pas seulement l'API). Formulaire : vendeur (liste des vendeurs validés), message,
  pièces jointes multiples.
- Le contrôleur (`Controller\Adminhtml\Notification\Send`) ne crée rien lui-même : il appelle
  `POST /api/admin/notifications` de l'API v2 via `Model\Notification\NotificationService`,
  exactement la route qu'utilise déjà la synchronisation quotidienne des commandes
  (`sendSellerNotification`). Même mécanisme, juste un nouveau point d'entrée humain.
- Nouveau data patch `GrantNotificationsResourceToIntegrator`, dépendant de
  `CreateMarketplaceRoles` : accorde la ressource à l'intégrateur sans modifier le patch déjà
  appliqué (bonne pratique : un data patch déjà exécuté ne se modifie pas, on en ajoute un
  nouveau qui en dépend).

### Tests

41 tests unitaires (8 nouveaux) : normalisation des fichiers envoyés (`UploadedFilesReader`,
y compris le rejet d'un chemin qui ne serait pas un vrai fichier téléversé, et des erreurs
d'envoi), service de notification (vendeur introuvable, appel API correct, erreur API
convertie en message lisible).

### Recette

| # | Étape | Résultat attendu | Obtenu |
|---|---|---|---|
| C1 | Connexion intégrateur, Marketplace > Envoyer une notification | Écran accessible (200), liste des vendeurs validés | Validé |
| C2 | Choisir un vendeur, écrire un message, Envoyer | Message de succès, réclamation de type 0 créée (vérifié en base : `admin_viewed=1`, `vendeur_viewed=0`, message conservé) | Validé |

## Incrément 4a — alertes du tableau de bord, produits les plus vus

### Changements

- **Écart 5 de l'audit (alertes partielles, fig. 3.12)** : corrigé. Nouvelle section « Alertes »
  sur le tableau de bord, remplaçant les deux liens contextuels épars (`Model\Dashboard\
  AlertsProvider`) :
  1. vendeurs en attente depuis plus de 48 h (nouveau : `SellerRepository::countPendingOlderThan`) ;
  2. réclamations non lues (déjà présent, maintenant unifié) ;
  3. produits de vendeurs en attente de modération (nouveau : `Model\Catalog\SellerProductStats`,
     requête sur le catalogue Magento seul, statut désactivé + `seller_id` renseigné) ;
  4. date de la dernière synchronisation des commandes (nouveau :
     `OrderStats::getLastSyncAt`), avec un avertissement si elle date de plus de 24 h.
- **Amélioration L (ch. 4 § 4.6, exploitation de `user_product_behavior`)** : nouvelle section
  « Produits les plus vus » sur le tableau de bord (`Model\Behavior\ProductViewsService`).
  Les vues sont comptées dans la base marketplace (table `user_product_behavior`), puis les
  SKU trouvés sont résolus en une seule requête sur le catalogue Magento (nom du produit et
  `seller_id`) : **aucune jointure SQL entre les deux bases**, conformément à la contrainte
  d'architecture (corrélation faite en PHP, comme le fait déjà `Block\Seller\Link` pour
  l'attribut `seller_id`).

### Tests

49 tests unitaires (8 nouveaux, `AlertsProviderTest`) : chaque alerte apparaît ou non selon le
seuil (48 h, 24 h), ordre des alertes stable, niveau (info/warning/ok) correct.
`SellerProductStats` et `ProductViewsService` dépendent de collections Magento (EAV, résolution
catégorie) difficiles à isoler en test unitaire pur ; vérifiés en recette avec des données
réelles.

### Recette (données réelles du Magento local, 4 octobre 2026)

| # | Alerte / section | Résultat attendu | Obtenu |
|---|---|---|---|
| E1 | Vendeurs en attente > 48 h | « 25 inscription(s) ... depuis plus de 48 h », lien vers Validation | Conforme |
| E2 | Réclamations non lues | « 1 réclamation(s) non lue(s) », lien vers Réclamations | Conforme |
| E3 | Produits en attente de modération | « 44 produit(s) de vendeur en attente de modération » | Conforme (identique au chiffre de l'audit) |
| E4 | Dernière synchronisation | « Dernière synchronisation des commandes : 16/09/2026 18:10 », en avertissement (plus de 24 h) | Conforme |
| L1 | Produits les plus vus | SKU IPH-11-128-YELLOW en tête, nom résolu (« iPhone 11 128G... »), vues correctes | Conforme |

## Incrément 4b — export des commissions et relevés de paiement

### Changements

- **Écart 4 de l'audit (export des commissions absent du module, cas 2.18)** : corrigé.
  Nouvel écran Marketplace > Commissions (ACL `Mytek_Marketplace::commissions`, **réservé à
  l'administrateur** : ch. 2 § 2.1 réserve la supervision financière à l'administrateur, pas
  à l'intégrateur — vérifié, 403 pour l'intégrateur).
- **Lecture** (chiffre d'affaires par vendeur, taux effectif, relevés déjà générés) : calculée
  directement depuis la base marketplace (tables `orders`, `commission_rates`,
  `payout_statements`, déjà présentes grâce aux migrations de la v2), sans appel à l'API.
  Nouveau `Model\Commission\CommissionRepository` et `OrderStats::getRevenueBySeller`.
- **Écriture** (génération des relevés du mois, changement de taux, rendu PDF) : relayée vers
  l'API v2 (`Model\Commission\CommissionAdminProxy`), qui porte seule cette logique (génération
  idempotente, rendu DomPDF) — le module ne la duplique pas.
- Export CSV natif du module (`Controller\Adminhtml\Commission\Export`), disponible même si
  l'API v2 est arrêtée (ne dépend que de la base marketplace).
- Lien « Commissions et relevés de paiement » ajouté au tableau de bord.

### Tests

55 tests unitaires (6 nouveaux, `CommissionAdminProxyTest`) : génération, changement de taux
(y compris réinitialisation au taux par défaut), téléchargement de PDF, relevé introuvable,
erreur API convertie en message lisible. `CommissionRepository` (requêtes SQL directes) non
testé unitairement, comme les autres dépôts du module ; vérifié en recette.

### Recette

| # | Étape | Résultat attendu | Obtenu |
|---|---|---|---|
| D1 | Connexion intégrateur, Marketplace > Commissions | 403 (réservé à l'administrateur) | 403 |
| D2 | Connexion administrateur, Marketplace > Commissions | 200, 5 vendeurs listés avec CA et commission | Conforme |
| D3 | Export CSV | En-têtes corrects, une ligne par vendeur | Conforme |
| D4 | Générer les relevés pour une période sans commande (octobre) | 0 relevé, pas d'erreur | Conforme (comportement normal de l'API) |
| D5 | Générer les relevés pour septembre (données réelles) | 4 relevés créés, montants cohérents avec le CA par vendeur | Conforme (vérifié en base) |
| D6 | Télécharger le PDF d'un relevé | 200, `application/pdf`, fichier non vide (880 Ko) | Conforme |
| D7 | Changer le taux d'un vendeur à 8 % | Le tableau affiche 8,00 % pour ce vendeur | Conforme |

### Limite connue

La génération porte sur le mois civil en cours par défaut (bouton « Générer les relevés pour
AAAA-MM ») : comme en recette, si aucune commande Magento n'a été synchronisée ce mois-ci
(dernière synchronisation le 16 septembre 2026 sur ce poste), la génération répond
normalement avec 0 relevé. Ce n'est pas une anomalie du module.

## Incrément 5 — email au vendeur à la validation et au refus (Mailpit)

### Changements

- **Écart 2 de l'audit (aucun email au vendeur, alors que l'écran d'inscription v1 le
  promettait)** : corrigé. Deux modèles d'email du module (`etc/email_templates.xml`,
  `view/frontend/email/seller_validated.html` et `seller_refused.html`), envoyés via
  `Magento\Framework\Mail\Template\TransportBuilder` (le mécanisme standard de Magento),
  depuis un nouveau service `Model\Email\SellerNotifier`.
- Un échec d'envoi n'empêche jamais la validation ou le refus : la base est déjà mise à jour
  quand l'email part ; en cas d'échec, l'action reste un succès et un avertissement
  (« l'email n'a pas pu être envoyé ») s'ajoute au message de confirmation.
- **Environnement local (demandé avant création)** : conteneur Docker `mailpit`
  (`axllent/mailpit:latest`, SMTP 1025, interface web 8025, `docker start mailpit` si arrêté).
  Pour que les emails de Magento (et plus largement tout ce qui appelle `mail()` sous PHP 8.3
  CLI) partent vers Mailpit : `msmtp` et `msmtp-mta` installés (`apt-get install -y msmtp
  msmtp-mta`), `/etc/msmtprc` relaie vers `localhost:1025` sans authentification ni TLS, et
  `/usr/sbin/sendmail` est déjà un lien symbolique standard vers `msmtp` posé par le paquet
  `msmtp-mta` — aucune modification du `php.ini` n'a été nécessaire (`sendmail_path` pointait
  déjà vers `/usr/sbin/sendmail -t -i`). Ce réglage est au niveau de l'environnement WSL, pas
  du module : à refaire sur toute autre machine de développement.

### Tests

60 tests unitaires (6 nouveaux, `SellerNotifierTest`) : bon modèle selon validation/refus,
email absent ou invalide refusé sans appeler `TransportBuilder`, échec de transport capté et
renvoyant faux sans exception.

### Recette

| # | Étape | Résultat attendu | Obtenu |
|---|---|---|---|
| A1 | `php -r 'mail(...)'` en CLI | Message reçu dans Mailpit (API `/api/v1/messages`) | Validé |
| A2 | Valider un vendeur en attente | Message de succès, **pas** d'avertissement d'envoi | Validé |
| A3 | Email reçu dans Mailpit (validation) | Sujet et corps entièrement en français, en-tête/pied de page Magento standard, lien vers l'espace vendeur | Validé (un segment restait en anglais à la première tentative — traduction manquante au CSV, corrigée et revérifiée) |
| A4 | Refuser un vendeur en attente | Message de succès | Validé |
| A5 | Email reçu dans Mailpit (refus) | Sujet et corps entièrement en français | Validé |

Effet de bord assumé : les deux comptes de test utilisés pour la recette (vendeurs #10 et #11,
des comptes « Boutique Test … » créés par d'anciens essais automatisés, pas des vendeurs réels)
sont restés respectivement validé et refusé.

## Incrément 6 — filtre par gouvernorat du vendeur (recherche et catégories)

### Changements

- **Écarts 1 et 3 de l'audit (filtre par gouvernorat absent du site client, absent du
  rapport comme contribution du module — contradiction X3)** : corrigé. Nouvel attribut
  produit `seller_governorate` (type select, 24 options = les gouvernorats de Tunisie,
  `Model\Governorate\GovernorateList`, même liste que le formulaire vendeur de l'admin),
  filtrable en catégorie **et** en recherche (`is_filterable` et `is_filterable_in_search`) :
  un attribut texte libre n'est pas utilisable en navigation à facettes dans Magento, d'où le
  choix d'un type `select`.
- **Rempli pour les produits existants** (data patch `AddSellerGovernorateAttribute`) et
  **tenu à jour** par une tâche planifiée toutes les 15 minutes
  (`Cron\SyncSellerGovernorate`, `etc/crontab.xml`) et une commande disponible à la demande
  (`bin/magento mytek:marketplace:sync-seller-governorate`), toutes deux appuyées sur le même
  service `Model\Governorate\GovernorateSyncService` : lit les gouvernorats des vendeurs dans
  la base marketplace, lit les produits portant un `seller_id` dans le catalogue Magento, et
  corrèle les deux **en PHP** — aucune jointure SQL entre les deux bases. La mise à jour des
  produits passe par `Magento\Catalog\Model\ResourceModel\Product\Action::updateAttributes`
  (l'API Magento des actions de masse sur la grille produits), qui déclenche correctement les
  invalidations d'index, plutôt que du SQL brut.
- Logique de corrélation isolée dans une classe pure (`Model\Governorate\GovernorateDiff`,
  sans dépendance Magento), pour rester testable unitairement.

### Tests

68 tests unitaires (8 nouveaux pour `GovernorateDiff`, couvrant les cas : produit sans valeur,
valeur déjà correcte, vendeur qui change de gouvernorat, vendeur sans gouvernorat connu
[valeur vidée], libellé inconnu, produit réattribué à un autre vendeur, regroupement de
plusieurs produits sous une même valeur ; 1 nouveau pour la commande CLI). Le service lui-même
(couplé aux collections Magento) est vérifié en recette.

### Recette (données réelles du Magento local)

| # | Étape | Résultat attendu | Obtenu |
|---|---|---|---|
| B1 | Déploiement (data patch) | Attribut créé (select, 24 options), produits existants remplis | 320 produits → Tunis, 89 → Ariana, 71 sans gouvernorat (vendeurs 1 et 6, sans gouvernorat renseigné) — concorde exactement avec les gouvernorats et volumes de produits par vendeur relevés dans l'audit | Conforme |
| B2 | Rejouer la commande CLI | Idempotent : 0 produit mis à jour la deuxième fois | Conforme (480 vérifiés, 0 mis à jour) |
| B3 | Réindexation (`catalogsearch_fulltext`, `catalog_product_attribute`) | Réussie, nouvel index OpenSearch versionné | Conforme (`magento2_product_1_v4`) |
| B4 | Recherche native `?q=iphone` | Facette « Gouvernorat du vendeur » présente, options Tunis et Ariana visibles | Conforme |
| B5 | Page de catégorie (`informatique.html`) | Même facette présente | Conforme |

### Limite assumée

La tâche planifiée tourne toutes les 15 minutes : un changement de gouvernorat chez un vendeur,
ou un produit nouvellement rattaché à un vendeur, met jusqu'à 15 minutes à apparaître dans la
facette (plus le délai du prochain cycle de réindexation catalogue, déjà présent nativement
dans Magento). La commande CLI permet de forcer une synchronisation immédiate si besoin.
