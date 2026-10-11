<?php

/*
| Entraînement du modèle ALS de production (RecommenderTrainer) sur la table
| orders : le fichier item_factors.json lu par RecommendationService doit
| être produit avec un vecteur par SKU commandé.
*/

use App\Models\Order;
use App\Services\Recommendation\RecommenderTrainer;
use Illuminate\Support\Facades\File;

it('entraîne le modèle ALS sur les commandes et écrit item_factors.json', function () {
    config([
        'recommender.models_path' => 'storage/framework/testing/recommender-trainer',
        'recommender.training.k' => 4,
    ]);
    $dir = base_path('storage/framework/testing/recommender-trainer');

    $line = fn ($order, $sku) => ['order_id' => $order, 'sku' => $sku, 'product_name' => $sku, 'qty' => 1, 'price' => 10, 'vendor_id' => 1];
    Order::insert([$line('O1', 'A'), $line('O1', 'B'), $line('O2', 'A'), $line('O2', 'B'), $line('O3', 'C')]);

    $output = app(RecommenderTrainer::class)->train();

    $saved = json_decode(file_get_contents($dir.'/item_factors.json'), true);
    expect($output['n_items'])->toBe(3)
        ->and($output['n_baskets'])->toBe(3)
        ->and($saved['skus'])->toHaveCount(3)
        ->and($saved['item_factors'][0])->toHaveCount(4);

    File::deleteDirectory($dir);
});

it('refuse l\'entraînement sans aucune commande', function () {
    app(RecommenderTrainer::class)->train();
})->throws(RuntimeException::class);
