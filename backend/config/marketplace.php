<?php

/*
|--------------------------------------------------------------------------
| Configuration métier de la marketplace Mytek (v2)
|--------------------------------------------------------------------------
| Toutes les valeurs viennent des variables d'environnement : aucun hôte,
| port ni secret n'est codé en dur dans le code applicatif.
*/

return [

    // Clé partagée des routes d'administration (en-tête x-admin-key).
    // Absente : les routes répondent 503 (jamais d'accès ouvert par défaut).
    'admin_api_key' => env('ADMIN_API_KEY'),

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5180'),

    // Adresse publique du site Magento, pour ouvrir la fiche d'un produit validé.
    'storefront_url' => rtrim((string) env('STOREFRONT_URL', 'http://localhost:8080'), '/'),

    'magento' => [
        'url' => rtrim((string) env('MAGENTO_URL', 'http://localhost:8080'), '/'),
        'user' => env('MAGENTO_USER'),
        'password' => env('MAGENTO_PASSWORD'),
        // Durée de mise en cache du jeton administrateur Magento (secondes).
        'token_ttl' => (int) env('MAGENTO_TOKEN_TTL', 3600),
        'timeout' => (int) env('MAGENTO_TIMEOUT', 15),
    ],

    'opensearch' => [
        'url' => rtrim((string) env('OPENSEARCH_BASE_URL', 'http://localhost:9201'), '/'),
        'index_pattern' => env('OPENSEARCH_INDEX_PATTERN', 'opensearch_index_*'),
        'timeout' => (int) env('OPENSEARCH_TIMEOUT', 5),
    ],

    // Taux de commission par défaut (5 % dans la v1, codé en dur).
    'commission_rate' => (float) env('COMMISSION_RATE', 0.05),

    'schedule' => [
        'orders_sync' => env('CRON_ORDERS_SYNC', '* * * * *'),
        'product_views' => env('CRON_PRODUCT_VIEWS', '* * * * *'),
        'timezone' => env('SCHEDULE_TIMEZONE', 'Europe/Paris'),
    ],

    // Suivi comportemental : lecture incrémentale du journal Apache de Magento.
    // Désactivé par défaut tant que la v1 tourne : deux lecteurs du même journal
    // inséreraient chaque événement deux fois.
    'behavior' => [
        'enabled' => (bool) env('BEHAVIOR_SYNC_ENABLED', false),
        'log_file' => env('MAGENTO_ACCESS_LOG'),
        'offset_file' => env('MAGENTO_LOG_OFFSET_FILE', 'storage/app/private/magento_log_offset.json'),
    ],

    'attachments' => [
        // Dossier des nouvelles pièces jointes (disque "local" = storage/app/private).
        'directory' => 'reclamations',
        // Dossier des pièces jointes de la v1, relu en lecture seule si présent.
        'legacy_directory' => env('LEGACY_UPLOADS_DIR'),
        'max_files' => 10,
        'max_kb' => 10240,
        'mimes' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'wav', 'mp3'],
    ],

    'products' => [
        'sku_pattern' => '/^[A-Za-z0-9._-]{3,64}$/',
        'page_size' => 20,
        'max_page_size' => 100,
    ],

    'statistics' => [
        'max_tracked_skus' => 5,
    ],
];
