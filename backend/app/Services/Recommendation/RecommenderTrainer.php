<?php

namespace App\Services\Recommendation;

use App\Models\Order;
use RuntimeException;

/**
 * Entraîne le modèle ALS de production sur la table `orders` (partagée avec
 * la v1) et sauvegarde les facteurs latents dans le fichier lu au runtime
 * par RecommendationService. Port de scripts/train_recommender.js.
 */
class RecommenderTrainer
{
    public function train(): array
    {
        $rows = Order::query()->select(['order_id', 'sku', 'qty'])->get();

        if ($rows->isEmpty()) {
            throw new RuntimeException('Aucune commande en base : impossible d\'entraîner le modèle.');
        }

        $params = config('recommender.training');

        $result = Als::train(
            $rows->map(fn (Order $r) => ['basket_id' => $r->order_id, 'sku' => $r->sku, 'qty' => $r->qty])->all(),
            $params['k'],
            $params['iterations'],
            $params['lambda'],
            $params['alpha'],
            $params['seed'],
        );

        $output = [
            'trained_at' => now()->toIso8601String(),
            'algorithm' => 'implicit-ALS (Hu, Koren & Volinsky 2008)',
            'params' => $params,
            'n_baskets' => $result['n_baskets'],
            'n_items' => $result['n_items'],
            'n_interactions' => $rows->count(),
            'skus' => $result['skus'],
            'item_factors' => $result['item_factors'],
            // Nombre de paniers distincts par SKU : confiance du collaboratif au runtime.
            'item_support' => $rows->groupBy('sku')->map(fn ($lines) => $lines->pluck('order_id')->unique()->count())->all(),
        ];

        $path = base_path(config('recommender.models_path').'/item_factors.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($output));

        return $output;
    }
}
