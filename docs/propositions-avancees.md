# Fonctionnalités avancées proposées pour la v2

Rédigé le 3 octobre 2026, après l'inventaire de parité (`checklist-parite.md`).

**Liste retenue : lot « Recommandé »**, soit les rangs 1 à 11 et les améliorations A, B (historique
des notifications) et C. Tout est réalisé et testé (voir `notes-migration.md`). Les rangs 12 à 16
ne sont pas réalisés.

## Hypothèses d'estimation

- Les temps sont en **jours de travail effectif**, tests automatisés et documentation compris.
- Ils supposent la parité déjà faite. La parité elle-même est estimée à **8 à 10 jours** :
  33 routes, 2 tâches planifiées, 14 écrans, 24 tests à reproduire.
- **Valeur jury** : ★★★ forte (démonstration visible, argument d'ingénierie clair),
  ★★ moyenne, ★ faible.
- **Effort** : S (≤ 1 jour), M (1,5 à 2,5 jours), L (3 jours et plus).
- Contraintes du poste prises en compte : pas de Redis, pas de droits admin, port 8080 pris
  par Magento, aucun index `opensearch_index_*` disponible en local.

## Classement

Le tableau est trié par rapport valeur / effort décroissant.

| Rang | Fonctionnalité | Valeur jury | Effort | Jours | Dépendances |
|---|---|---|---|---|---|
| 1 | Synchronisation des commandes en jobs de file d'attente | ★★★ | S | 1 | — |
| 2 | Documentation API automatique (OpenAPI) | ★★★ | S | 0,5 | — |
| 3 | Limitation de débit et en-têtes de sécurité | ★★ | S | 0,5 | — |
| 4 | Supervision enrichie : `/metrics` métier et tableau Grafana dédié | ★★★ | S | 1 | modification de la configuration Prometheus |
| 5 | Intégration continue GitHub Actions (tests à chaque envoi) | ★★★ | S | 0,5 | dépôt GitHub créé |
| 6 | Notifications temps réel pour le vendeur (Reverb) | ★★★ | M | 2 | port 8081 |
| 7 | Tableau de bord avancé : KPI, périodes, comparaison au mois précédent | ★★★ | M | 2 | — |
| 8 | Journal d'audit des actions sensibles | ★★ | S | 1 | — |
| 9 | Commissions et relevés de paiement vendeur en PDF | ★★★ | M | 2 | — |
| 10 | Import de produits en masse (Excel/CSV) avec rapport d'erreurs | ★★★ | M | 2,5 | rang 1 |
| 11 | Double authentification et réinitialisation du mot de passe par email | ★★★ | M | 2 | serveur mail de test |
| 12 | Messagerie temps réel vendeur ↔ support | ★★ | M | 1,5 | rangs 6 et 13 |
| 13 | Rôles et permissions (vendeur, support, admin) | ★★★ | L | 3,5 | — |
| 14 | Interface multilingue FR / EN / AR, mode sombre, responsive mobile | ★★ | M | 2,5 | — |
| 15 | Gestion du stock et alertes de rupture | ★★ | M | 2 | rang 6 conseillé |
| 16 | Recherche produits avancée à facettes via OpenSearch | ★★ | L | 3 | index à construire |

Améliorations plus petites issues de l'inventaire, à ajouter si tu le souhaites :

| Rang | Amélioration | Valeur jury | Effort | Jours |
|---|---|---|---|---|
| A | Date réelle de la commande Magento, dans une table annexe (lève une limite citée au ch. 4) | ★★ | S | 0,5 |
| B | Profil modifiable et historique des notifications (points C04 et C16) | ★ | S | 1 |
| C | Connexion qui répond 401 dans les deux cas d'échec (point C14, amélioration annoncée au tab. 2.3) | ★★ | S | 0,1 |

## Détail des propositions

### 1. Synchronisation des commandes en jobs de file d'attente
- **Intérêt métier.** Aujourd'hui, une panne Magento pendant la synchronisation perd silencieusement
  le cycle. En job, chaque échec est réessayé avec un délai croissant et les échecs définitifs
  restent consultables et rejouables.
- **Technologie.** Jobs Laravel, file `database`, Scheduler, attributs `#[Tries]` et `#[Backoff]`
  de Laravel 13, table `failed_jobs`.
- **Démonstration.** Couper Magento, montrer les nouvelles tentatives, le relancer, montrer la reprise.

### 2. Documentation API automatique
- **Intérêt.** Un intégrateur comprend l'API sans lire le code ; la documentation suit le code
  sans effort manuel.
- **Technologie.** Scramble : spécification OpenAPI générée depuis les Form Requests et les
  API Resources, page interactive sur `/docs/api`.

### 3. Limitation de débit et en-têtes de sécurité
- **Intérêt.** Bloque le test de mots de passe en rafale sur la connexion et l'abus des routes
  publiques ; ajoute les en-têtes CSP, HSTS, X-Frame-Options, Referrer-Policy.
- **Technologie.** `RateLimiter` de Laravel (ex. 5 tentatives par minute sur la connexion,
  réponse 429), middleware d'en-têtes.

### 4. Supervision enrichie
- **Intérêt.** Au-delà des requêtes HTTP : commandes synchronisées, jobs en échec, taille de
  la file, durée des appels Magento et OpenSearch. Le jury voit l'état métier, pas seulement
  l'état technique.
- **Technologie.** `promphp/prometheus_client_php` avec stockage APCu ou base, tableau de bord
  Grafana importable fourni dans `docs/`.
- **À valider.** Ajouter la cible `localhost:8000` au fichier de configuration de Prometheus,
  qui se trouve dans ton dossier Téléchargements, hors des deux projets.

### 5. Intégration continue
- **Intérêt.** Chaque envoi sur GitHub lance les tests Pest et Vitest ; le badge vert dans le
  README est une preuve simple de qualité.
- **Technologie.** GitHub Actions, PHP 8.4 et Node 22, base SQLite de test.

### 6. Notifications temps réel
- **Intérêt.** Remplace l'interrogation toutes les 30 secondes : le vendeur voit
  immédiatement une nouvelle vente, une réponse du support ou la validation d'un produit.
- **Technologie.** Laravel Reverb (serveur WebSocket officiel, sans Redis), Laravel Echo côté
  Vue, canaux privés par vendeur, événements diffusés depuis les jobs.
- **Contrainte.** Reverb utilise par défaut le port 8080, déjà pris par Magento : il sera
  configuré sur 8081.

### 7. Tableau de bord avancé
- **Intérêt.** CA, panier moyen, nombre de commandes, quantités, avec variation en % par
  rapport à la période précédente, filtres libres de dates, top produits, répartition par jour
  de la semaine.
- **Technologie.** Agrégations SQL côté Laravel (API Resource dédiée), ECharts côté Vue.
- **Limite.** Les dates reposent sur la date de synchronisation, sauf si l'amélioration A est retenue.

### 8. Journal d'audit
- **Intérêt.** Trace qui a fait quoi et quand : connexion, changement de mot de passe,
  modification de prix, suppression de produit, résolution de réclamation. Utile en cas de litige.
- **Technologie.** `spatie/laravel-activitylog`, nouvelle table créée par migration, écran de
  consultation si les rôles sont retenus.

### 9. Commissions et relevés PDF
- **Intérêt.** Le taux de 5 % est aujourd'hui codé en dur et le statut de paiement est toujours
  « En attente ». La v2 rend le taux configurable par vendeur, calcule un relevé mensuel
  (CA brut, commission, net à verser) et génère un PDF téléchargeable.
- **Technologie.** Nouvelles tables `commission_rates` et `payout_statements` par migration,
  DomPDF, job mensuel planifié.

### 10. Import de produits en masse
- **Intérêt.** Un vendeur avec 200 références ne les saisit pas une par une. Il dépose un
  fichier, la v2 valide chaque ligne (mêmes règles que l'ajout unitaire), crée les produits
  valides dans Magento en tâche de fond et produit un rapport d'erreurs téléchargeable.
- **Technologie.** `maatwebsite/excel`, jobs par lots, modèle de fichier fourni.

### 11. Double authentification et mot de passe oublié
- **Intérêt.** Protège les comptes vendeurs qui touchent aux prix et aux ventes.
- **Technologie.** Laravel Fortify : TOTP compatible Google Authenticator, codes de secours,
  lien de réinitialisation par email.
- **À valider.** Pour voir les emails en local, je propose un conteneur Docker **Mailpit**
  (interface web sur 8025, SMTP sur 1025). Sans lui, les emails sont écrits dans le journal
  Laravel.

### 12. Messagerie temps réel vendeur ↔ support
- **Intérêt.** La conversation d'une réclamation se met à jour en direct des deux côtés, avec
  indicateur de lecture.
- **Dépendances.** Reverb (6) et un rôle « support » dans la v2 (13). Sans le rôle 13, le support
  répond toujours dans l'admin Magento et seul le vendeur verrait le temps réel.

### 13. Rôles et permissions
- **Intérêt.** Un espace « support » et « admin » dans la v2 : traitement des réclamations,
  envoi de notifications, statistiques globales et export, avec des permissions fines.
- **Technologie.** `spatie/laravel-permission`, Policies Laravel, nouvelle table des
  utilisateurs internes par migration.
- **Attention.** Le rapport place le back-office dans Magento (ch. 3). Ce choix crée un second
  back-office pour une partie des tâches. Il faudra le justifier dans le rapport, par exemple
  comme un espace support plus léger que l'admin Magento.

### 14. Multilingue, mode sombre, responsive
- **Intérêt.** Arabe de droite à gauche, anglais pour les partenaires étrangers, confort visuel.
- **Technologie.** vue-i18n, Vuetify (thèmes et RTL natifs), traductions des messages de l'API.

### 15. Stock et alertes de rupture
- **Intérêt.** L'ajout de produit v1 fixe le stock à 10 000. La v2 laisse le vendeur saisir et
  modifier son stock, avec un seuil d'alerte et une notification en cas de rupture.
- **Technologie.** API Magento `stockItems`, job planifié de contrôle des seuils.

### 16. Recherche à facettes via OpenSearch
- **Intérêt.** Filtres par prix, statut, promotion, plage de dates, avec compteurs par facette.
- **Technologie.** Agrégations OpenSearch, index propre à la v2 alimenté par un job depuis
  l'API Magento.
- **Risque.** L'index `opensearch_index_*` utilisé par la v1 n'existe pas en local (la v1 se
  replie sur Magento). Il faut donc construire et maintenir un index dédié, d'où l'effort élevé.

## Lots suggérés

| Lot | Contenu | Jours après la parité |
|---|---|---|
| Essentiel | 1, 2, 3, 4, 5, 6, 7, C | ≈ 7,5 |
| Recommandé | Essentiel + 8, 9, 10, 11, A | ≈ 15,5 |
| Complet | Tout | ≈ 29 |

Ma recommandation : le lot **Recommandé**. Il couvre les trois axes qu'un jury de génie
logiciel regarde : architecture asynchrone et temps réel, sécurité, qualité outillée. Il reste
compatible avec le rapport, qui place le back-office dans Magento. Les rangs 12 à 16 sont
plus coûteux ou entrent en conflit avec l'architecture décrite au chapitre 3.
