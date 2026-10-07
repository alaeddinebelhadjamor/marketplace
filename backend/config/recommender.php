<?php

/*
|--------------------------------------------------------------------------
| Moteur de recommandation hybride (port de la v1, voir App\Services\Recommendation)
|--------------------------------------------------------------------------
| Mêmes pondérations et hyperparamètres que marketplace-backend/config/recommender.config.js,
| pour que le comportement du moteur reste comparable entre les deux versions.
*/

return [

    // Fusion collaboratif (ALS, achats réels) / sémantique (embeddings du nom produit).
    'w_collaborative' => (float) env('RECOMMENDER_W_COLLABORATIVE', 0.65),
    'w_content' => (float) env('RECOMMENDER_W_CONTENT', 0.35),

    // Taille du vivier de candidats = topK * pool_factor (pour permettre la diversité).
    'pool_factor' => (int) env('RECOMMENDER_POOL_FACTOR', 3),
    'diversity_epsilon' => (float) env('RECOMMENDER_DIVERSITY_EPSILON', 0.15),

    // Estimation du temps passé sur un produit (pas de tracking JS client, seulement
    // les logs serveur) : DEFAULT_DWELL_S = poids neutre (1.0).
    'min_dwell_s' => (int) env('RECOMMENDER_MIN_DWELL_S', 3),
    'max_dwell_s' => (int) env('RECOMMENDER_MAX_DWELL_S', 300),
    'default_dwell_s' => (int) env('RECOMMENDER_DEFAULT_DWELL_S', 30),

    // Fenêtre glissante et bornes de l'agrégation de comportement.
    'window_days' => (int) env('RECOMMENDER_WINDOW_DAYS', 30),
    'max_events' => (int) env('RECOMMENDER_MAX_EVENTS', 20000),

    // Durées de cache (secondes) : co-visite/popularité recalculées depuis
    // user_product_behavior, modèles vectoriels relus depuis le disque.
    'behavior_cache_ttl_seconds' => (int) env('RECOMMENDER_BEHAVIOR_CACHE_TTL', 300),
    'model_cache_ttl_seconds' => (int) env('RECOMMENDER_MODEL_CACHE_TTL', 1800),

    // Fichiers item_factors.json / content_embeddings.json, relatifs à la racine du projet backend.
    'models_path' => env('RECOMMENDER_MODELS_PATH', 'storage/app/private/recommender'),

    'training' => [
        'k' => (int) env('RECOMMENDER_TRAIN_K', 8),
        'iterations' => (int) env('RECOMMENDER_TRAIN_ITERATIONS', 15),
        'lambda' => (float) env('RECOMMENDER_TRAIN_LAMBDA', 0.1),
        'alpha' => (float) env('RECOMMENDER_TRAIN_ALPHA', 40.0),
        'seed' => (int) env('RECOMMENDER_TRAIN_SEED', 42),
    ],

    // Génération des embeddings de contenu : PHP n'exécute aucun modèle de
    // langage pré-entraîné lui-même. Un script Node autonome (voir
    // backend/scripts/recommender) fait l'inférence ; Laravel se contente de
    // l'orchestrer (php artisan marketplace:build-embeddings). Même logique
    // qu'un modèle PyTorch nécessitant un runtime Python : un choix
    // d'outillage pour l'inférence, pas une dépendance métier.
    'embeddings' => [
        'node_binary' => env('RECOMMENDER_NODE_BINARY', 'node'),
        'script_path' => env('RECOMMENDER_EMBEDDINGS_SCRIPT', base_path('scripts/recommender/build_content_embeddings.js')),
    ],
];
