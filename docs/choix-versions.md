# Choix des versions — Marketplace Mytek v2 (Laravel + Vue.js)

Recherche effectuée le 3 octobre 2026. Les versions npm et Packagist ont été lues directement
dans les registres officiels ce jour-là. Les politiques de support viennent des sites officiels
des projets, recoupées avec endoflife.date.

## 1. Backend : Laravel et PHP

### Laravel

| Version | PHP supporté | Sortie | Fin des correctifs | Fin des correctifs de sécurité | Dernière version |
|---|---|---|---|---|---|
| 11 | 8.2 – 8.4 | 12 mars 2024 | 3 sept. 2025 | 12 mars 2026 (fin de vie) | — |
| 12 | 8.2 – 8.5 | 24 févr. 2025 | 13 août 2026 (terminé) | 24 févr. 2027 | 12.69.3 |
| **13** | **8.3 – 8.5** | **17 mars 2026** | **T3 2027 (≈ 30 sept. 2027)** | **17 mars 2028** | **13.34.0 (29 sept. 2026)** |

Politique officielle : correctifs de bugs pendant 18 mois, correctifs de sécurité pendant 2 ans.
Laravel 12 ne reçoit déjà plus que des correctifs de sécurité. **Laravel 13 est donc la version
supportée le plus longtemps après la soutenance** : correctifs jusqu'à fin septembre 2027,
sécurité jusqu'en mars 2028. Laravel 14 est attendu vers le 1ᵉʳ trimestre 2027 ; il ne serait
pas encore éprouvé au moment de la soutenance.

### PHP

| Version | Sortie | Support actif jusqu'au | Sécurité jusqu'au | Dans WSL Ubuntu-22.04 |
|---|---|---|---|---|
| 8.3 | 23 nov. 2023 | 31 déc. 2025 (terminé) | 31 déc. 2027 | installé (8.3.33), version par défaut |
| **8.4** | **21 nov. 2024** | **31 déc. 2026** | **31 déc. 2028** | **installé (8.4.21), mise à jour 8.4.26 disponible** |
| 8.5 | 20 nov. 2025 | 31 déc. 2027 | 31 déc. 2029 | non installé, 8.5.11 disponible |

Les trois versions viennent du dépôt `ppa:ondrej/php`, déjà configuré dans WSL.

**Choix : PHP 8.4.** Il est déjà installé, il est supporté par Laravel 13 et il est **exigé**
par Pest 5 et PHPUnit 13 (PHP ≥ 8.4). PHP 8.5 serait possible, mais il est plus récent et
certains paquets de l'écosystème le testent moins. PHP 8.4 reste couvert en sécurité jusqu'à
fin 2028, bien après la soutenance.

Points d'installation, à faire en root dans WSL (pas besoin des droits admin Windows) :
- extensions manquantes pour PHP 8.4 : `zip`, `intl`, `bcmath`, `gd`, `sqlite3`
  (zip et gd pour l'import Excel et les PDF, sqlite3 pour les tests en mémoire) ;
- PHP 8.3 reste la version par défaut du système pour ne pas casser d'autres projets :
  le nouveau projet appellera explicitement `php8.4`.

L'alternative conteneur Docker PHP 8.4 n'est pas nécessaire, puisque PHP 8.4 s'installe
nativement dans Ubuntu-22.04.

### Paquets Laravel retenus (versions stables au 03/10/2026)

| Rôle | Paquet | Version | Exigence |
|---|---|---|---|
| Framework | laravel/framework | 13.34.0 | PHP ^8.3 |
| Authentification API/SPA | laravel/sanctum | 4.3.3 | Laravel 11–13 |
| 2FA et mot de passe oublié (backend sans vues) | laravel/fortify | 1.40.0 | Laravel 11–13 |
| WebSockets temps réel | laravel/reverb | 1.12.0 | Laravel 10–13 |
| Rôles et permissions | spatie/laravel-permission | 8.3.0 | PHP ^8.3 |
| Journal d'audit | spatie/laravel-activitylog | 5.1.1 | PHP ^8.4, Laravel 12–13 |
| Import/export Excel | maatwebsite/excel | 4.0.3 | Laravel 12–13 |
| Documentation OpenAPI automatique | dedoc/scramble | 0.13.47 | PHP ^8.1 |
| Client OpenSearch | opensearch-project/opensearch-php | 2.7.1 | PHP ^8.2 |
| Métriques Prometheus | promphp/prometheus_client_php | 2.15.1 | PHP ^8.2 |
| Tests | pestphp/pest + pest-plugin-laravel | 5.3.0 / 5.0.1 | PHP ^8.4 |

Les paquets liés aux fonctionnalités avancées (Reverb, permissions, audit, Excel, OpenAPI)
ne seront installés que si la fonctionnalité correspondante est retenue à l'étape 3.

## 2. Frontend : Vue.js 3 et outillage

| Paquet | Dernière stable | Publiée le | Remarque |
|---|---|---|---|
| vue | **3.5.43** | 17 sept. 2026 | 3.6 encore en release candidate (3.6.0-rc.10) : non retenue |
| vite | **8.3.2** | 1ᵉʳ oct. 2026 | Node ^20.19 ou ≥ 22.12 |
| @vitejs/plugin-vue | 6.0.9 | — | plugin officiel Vue pour Vite |
| vue-router | **5.3.1** | 2 sept. 2026 | compatible Vite 7–8 et Pinia 3–4 |
| pinia | **4.0.3** | 12 août 2026 | Vue ^3.5.11 |
| vue-i18n | 11.4.13 | — | interface FR / EN / AR |
| axios | 1.20.0 | — | client HTTP |
| laravel-echo + pusher-js | 2.5.0 / 8.6.0 | — | client WebSocket pour Reverb |
| vitest | **5.0.3** | 30 sept. 2026 | Node ^22.12 ou ^24 |
| @vue/test-utils | 2.5.1 | — | montage des composants en test |

Node.js : Node 22.14 est installé sous Windows et satisfait Vite 8 et Vitest 5.
Node 22 est en maintenance jusqu'au 30 avril 2027 ; Node 24 (LTS, sécurité jusqu'au
30 avril 2028) est l'alternative si la soutenance a lieu après avril 2027.

### Bibliothèque d'interface

| Critère | Vuetify 4.2.3 | PrimeVue 5.0.2 | Quasar 2.34.0 |
|---|---|---|---|
| Style | Material Design (comme Angular Material de la v1) | Thèmes « styled » ou « unstyled », plusieurs presets | Material Design |
| Intégration Vite | plugin officiel `vite-plugin-vuetify` | simple plugin Vue | conçu pour sa propre CLI ; le plugin Vite existe mais est secondaire |
| Tableaux de données | `v-data-table` et `v-data-table-server` : tri, pagination serveur, sélection | DataTable très riche : filtres par colonne, export CSV, colonnes figées | QTable correct |
| Mode sombre | thèmes clair/sombre natifs | natif via les design tokens | natif |
| Arabe (RTL) | support RTL natif et locale `ar` | RTL partiel selon les composants | RTL natif |
| Composants | ≈ 80 | ≈ 90 | ≈ 70, plus outillage mobile/desktop |
| Courbe d'apprentissage | faible | faible | plus forte (framework complet) |

**Choix : Vuetify 4.** Il reproduit le Material Design de la version Angular, ce qui garde
les captures d'écran du rapport cohérentes. Il gère nativement le mode sombre et l'arabe de
droite à gauche, deux fonctionnalités avancées envisagées. Il s'intègre à Vite par un plugin
officiel. PrimeVue est un bon second choix pour ses tableaux, mais son support RTL est moins
complet. Quasar impose sa propre CLI, ce qui alourdit le projet sans gain ici.

### Bibliothèque de graphiques

| Critère | vue-chartjs 5.3.4 (+ Chart.js 4.5.1) | vue-echarts 8.3.1 (+ ECharts 6.1.0) |
|---|---|---|
| Continuité avec la v1 | même moteur Chart.js que l'Angular | nouveau moteur |
| Types de graphiques | courbes, barres, secteurs, radar | les mêmes, plus cartes de chaleur, jauges, entonnoirs, zoom temporel |
| Tableau de bord avancé | comparaison de périodes à coder à la main | `dataZoom`, superposition de séries et thème sombre natifs |
| Poids | léger | plus lourd, mais importable module par module |

**Choix : ECharts via vue-echarts.** Le tableau de bord avancé (filtres de période,
comparaison avec le mois précédent, mode sombre) est nettement plus simple à réaliser avec.
vue-chartjs reste le choix si l'on veut seulement la parité stricte avec la v1.

## 3. Authentification

| Option | Principe | Adapté ici ? |
|---|---|---|
| **Laravel Sanctum** (officiel) | mode SPA : cookie de session HttpOnly + protection CSRF ; mode jetons : jetons d'API révocables stockés en base | **oui** |
| Laravel Passport (officiel) | serveur OAuth2 complet | non : OAuth2 est disproportionné, pas de clients tiers |
| tymon/jwt-auth (tiers) | JWT comme la v1 | non : paquet tiers, jetons non révocables sans liste noire |

**Choix : Sanctum en mode SPA.** Le frontend Vue (port 5173) et l'API (port 8000) sont sur
le même domaine `localhost`, ce qui permet l'authentification par cookie HttpOnly. Le jeton
n'est plus stocké dans le `localStorage` comme le JWT de la v1, ce qui le protège du vol par
XSS. Le mode jetons de Sanctum servira aux appels machine à machine, à la place de la clé
admin de la v1. Fortify, du même éditeur, ajoute la double authentification (TOTP) et la
réinitialisation du mot de passe par email, sans imposer de vues.

## 4. Tests

| Côté | Outil | Version | Pourquoi |
|---|---|---|---|
| Laravel | **Pest 5** (repose sur PHPUnit 13) | 5.3.0 | syntaxe concise proche de Jest, utilisée dans la v1 ; plugin Laravel officiel ; reste compatible avec les classes PHPUnit |
| Vue | **Vitest 5** + @vue/test-utils + jsdom | 5.0.3 | intégré à Vite, même configuration, API compatible Jest |

## 5. Contrainte d'environnement détectée : lenteur des fichiers sous /mnt/c

Mesure faite dans WSL Ubuntu-22.04, sur 2000 petits fichiers PHP :

| Emplacement | Écriture | Lecture par PHP | Suppression |
|---|---|---|---|
| Dossier OneDrive, via /mnt/c | 14,3 s | 16,6 s | 4,2 s |
| Dossier Windows hors OneDrive, via /mnt/c | 15,4 s | 19,6 s | 3,4 s |
| Disque natif WSL (/home) | 0,2 – 0,6 s | 0,08 s | 0,05 s |

La lenteur vient du pont de fichiers WSL ↔ Windows, pas de OneDrive seul : PHP lit les fichiers
environ 200 fois plus lentement sous /mnt/c. Une requête Laravel charge plusieurs centaines de
fichiers de `vendor/` et `app/`, soit plusieurs secondes par requête et des `composer install`
de plusieurs dizaines de minutes. Synchroniser `vendor/` et `node_modules/` (des dizaines de
milliers de fichiers) chargerait aussi inutilement OneDrive.

**Solution retenue (validée le 3 octobre 2026) :**
- le dépôt git de travail vit sur le disque natif WSL, dans `~/projets/marketplace-v2`.
  PHP 8.4, Composer et Node 22 (installé dans WSL via nvm, sans droits admin) y tournent vite ;
- le dossier OneDrive `Bureau\ala\marketplace-v2` reste le dossier officiel du projet.
  Un hook git `post-commit` y recopie automatiquement, à chaque commit, les sources, `docs/`,
  le README et l'historique git, sans `vendor/`, `node_modules/`, `.env` ni `storage/logs/` ;
- les serveurs (Laravel sur 8000, Vite sur 5173) tournent dans WSL et restent accessibles
  depuis le navigateur Windows sur `localhost`. Le pare-feu qui bloque `node.exe` ne
  s'applique pas, car Node tourne dans WSL ;
- pour éditer le code dans VS Code, on ouvre le dossier WSL avec l'extension « WSL »,
  ou par le chemin `\\wsl.localhost\Ubuntu-22.04\home\...`.

Alternatives écartées :
- tout laisser sous OneDrive : plusieurs secondes par requête, comme mesuré ci-dessus ;
- conteneur Docker avec le code monté depuis /mnt/c : même pont de fichiers, même lenteur ;
- seulement `vendor/` dans WSL : le code de `app/`, `config/` et `routes/` resterait lent.

## 6. Recommandation (réutilisable dans le rapport)

> **Justification des choix technologiques.** La nouvelle version de la marketplace repose sur
> Laravel 13 et PHP 8.4 côté serveur, et sur Vue.js 3.5 côté client. Laravel 13, sorti le
> 17 mars 2026, est la version majeure la plus récente : il reçoit des correctifs jusqu'au
> troisième trimestre 2027 et des correctifs de sécurité jusqu'au 17 mars 2028, ce qui couvre
> largement la période de soutenance et de maintenance. Le choix de PHP rapproche aussi la
> marketplace de Magento 2, lui-même écrit en PHP, ce qui unifie le langage de l'écosystème
> Mytek. PHP 8.4 est supporté en sécurité jusqu'à fin 2028 et il est exigé par Pest 5, le
> framework de tests retenu. Laravel apporte nativement les briques nécessaires au projet :
> validation par Form Requests, autorisation par Policies, files d'attente et planificateur
> pour la synchronisation des commandes Magento, diffusion d'événements en temps réel avec
> Reverb. L'authentification utilise Laravel Sanctum en mode SPA : le jeton de session est
> stocké dans un cookie HttpOnly protégé contre la falsification de requêtes, ce qui est plus
> sûr qu'un JWT conservé dans le stockage du navigateur. Fortify y ajoute la double
> authentification et la réinitialisation du mot de passe. Côté client, Vue.js 3 avec la
> Composition API, Vue Router 5 et Pinia 4 offre une architecture plus légère qu'Angular,
> outillée par Vite 8 pour un démarrage et un rechargement quasi instantanés. Vuetify 4 a été
> préféré à PrimeVue et Quasar, car il conserve le Material Design de la première version et
> gère nativement le mode sombre et l'écriture de droite à gauche nécessaire à l'arabe.
> Apache ECharts remplace Chart.js pour construire un tableau de bord plus riche, avec
> filtres de période et comparaisons. Enfin, les tests reposent sur Pest côté serveur et
> Vitest côté client, deux outils dont la syntaxe proche de Jest facilite la reprise des
> scénarios de test de la première version.

## Sources

- Laravel, notes de version et politique de support 13.x : https://laravel.com/docs/13.x/releases
- Laravel, cycle de vie : https://endoflife.date/laravel
- PHP, branches supportées : https://www.php.net/supported-versions.php et https://endoflife.date/php
- Node.js, calendrier des versions : https://nodejs.org/en/about/previous-releases et https://endoflife.date/nodejs
- Paquets PHP (versions et exigences) : https://packagist.org/packages/laravel/framework,
  https://packagist.org/packages/laravel/sanctum, https://packagist.org/packages/laravel/fortify,
  https://packagist.org/packages/laravel/reverb, https://packagist.org/packages/pestphp/pest
- Laravel Sanctum : https://laravel.com/docs/13.x/sanctum
- Laravel Passport : https://laravel.com/docs/13.x/passport
- Vue.js : https://vuejs.org et https://www.npmjs.com/package/vue
- Vite : https://vite.dev ; Vue Router : https://router.vuejs.org ; Pinia : https://pinia.vuejs.org
- Vuetify : https://vuetifyjs.com ; PrimeVue : https://primevue.org ; Quasar : https://quasar.dev
- vue-chartjs : https://vue-chartjs.org ; Apache ECharts : https://echarts.apache.org ; vue-echarts : https://github.com/ecomfe/vue-echarts
- Pest : https://pestphp.com ; Vitest : https://vitest.dev ; Vue Test Utils : https://test-utils.vuejs.org
- Paquet PHP pour Ubuntu (dépôt ondrej) : https://launchpad.net/~ondrej/+archive/ubuntu/php
