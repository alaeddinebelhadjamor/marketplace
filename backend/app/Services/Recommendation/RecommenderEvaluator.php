<?php

namespace App\Services\Recommendation;

use App\Models\Order;
use RuntimeException;

/**
 * Évaluation quantitative du moteur par séparation entraînement/test, port
 * de scripts/evaluate_recommender.js : compare, sur les mêmes cas de test,
 * ALS seul, hybride (mêmes poids qu'en production) et une base de référence
 * triviale (produits les plus vendus). Script d'évaluation manuel (pas de
 * planification automatique), comme en v1.
 */
class RecommenderEvaluator
{
    private const TOP_K = 10;

    private const TEST_RATIO = 0.2;

    private const SPLIT_SEED = 7;

    private const K_CANDIDATES = [4, 8, 12, 16];

    private const FIXED = ['iterations' => 15, 'lambda' => 0.1, 'alpha' => 40.0, 'seed' => 42];

    public function evaluate(): array
    {
        $contentModel = $this->loadContentModel();

        $rows = Order::query()->select(['order_id', 'sku', 'qty'])->get();
        if ($rows->isEmpty()) {
            throw new RuntimeException('Aucune commande en base.');
        }

        $baskets = [];
        foreach ($rows as $r) {
            $baskets[$r->order_id][] = $r;
        }

        $evaluableBaskets = array_filter($baskets, fn ($items) => count(array_unique(array_map(fn ($i) => $i->sku, $items))) >= 2);
        $nonEvaluableBaskets = array_filter($baskets, fn ($items) => count(array_unique(array_map(fn ($i) => $i->sku, $items))) < 2);

        $orderIds = array_keys($evaluableBaskets);
        mt_srand(self::SPLIT_SEED);
        for ($i = count($orderIds) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$orderIds[$i], $orderIds[$j]] = [$orderIds[$j], $orderIds[$i]];
        }

        $splitIdx = (int) floor(count($orderIds) * (1 - self::TEST_RATIO));
        $trainIds = array_slice($orderIds, 0, $splitIdx);
        $testIds = array_slice($orderIds, $splitIdx);

        $trainRows = [];
        foreach ($trainIds as $id) {
            foreach ($evaluableBaskets[$id] as $item) {
                $trainRows[] = ['basket_id' => $id, 'sku' => $item->sku, 'qty' => $item->qty];
            }
        }
        foreach ($nonEvaluableBaskets as $id => $items) {
            foreach ($items as $item) {
                $trainRows[] = ['basket_id' => $id, 'sku' => $item->sku, 'qty' => $item->qty];
            }
        }

        $testPairs = [];
        foreach ($testIds as $id) {
            $skus = array_values(array_unique(array_map(fn ($i) => $i->sku, $evaluableBaskets[$id])));
            $n = count($skus);
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    if ($i !== $j) {
                        $testPairs[] = ['seed' => $skus[$i], 'target' => $skus[$j]];
                    }
                }
            }
        }

        $popCount = [];
        foreach ($trainRows as $row) {
            $popCount[$row['sku']] = ($popCount[$row['sku']] ?? 0) + 1;
        }
        arsort($popCount);
        $popularTopK = array_slice(array_keys($popCount), 0, self::TOP_K);

        $results = [];
        $best = null;

        foreach (self::K_CANDIDATES as $k) {
            $trained = Als::train($trainRows, $k, self::FIXED['iterations'], self::FIXED['lambda'], self::FIXED['alpha'], self::FIXED['seed']);
            $skuToIndex = array_flip($trained['skus']);

            $evaluable = 0;
            $alsHits = 0;
            $hybridHits = 0;
            $popHits = 0;

            foreach ($testPairs as $pair) {
                $seed = $pair['seed'];
                $target = $pair['target'];
                if (! isset($skuToIndex[$seed]) || ! isset($skuToIndex[$target])) {
                    continue;
                }
                $evaluable++;

                if (in_array($target, $this->alsTopK($skuToIndex, $trained['item_factors'], $trained['skus'], $seed, self::TOP_K), true)) {
                    $alsHits++;
                }
                if (in_array($target, $this->hybridTopK($skuToIndex, $trained['item_factors'], $trained['skus'], $contentModel, $seed, self::TOP_K), true)) {
                    $hybridHits++;
                }
                if (in_array($target, $popularTopK, true)) {
                    $popHits++;
                }
            }

            $row = [
                'k' => $k,
                'als_rate' => $evaluable ? $alsHits / $evaluable : 0.0,
                'hybrid_rate' => $evaluable ? $hybridHits / $evaluable : 0.0,
                'pop_rate' => $evaluable ? $popHits / $evaluable : 0.0,
                'evaluable' => $evaluable,
            ];
            $results[] = $row;

            if ($best === null || $row['hybrid_rate'] > $best['hybrid_rate']) {
                $best = $row;
            }
        }

        return [
            'rows' => $results,
            'best' => $best,
            'n_baskets' => count($baskets),
            'n_evaluable_baskets' => count($evaluableBaskets),
            'n_train_baskets' => count($trainIds),
            'n_test_baskets' => count($testIds),
            'n_test_pairs' => count($testPairs),
            'content_model_available' => $contentModel !== null,
        ];
    }

    private function alsTopK(array $skuToIndex, array $itemFactors, array $skus, string $seedSku, int $k): array
    {
        if (! isset($skuToIndex[$seedSku])) {
            return [];
        }
        $idx = $skuToIndex[$seedSku];
        $vec = $itemFactors[$idx];
        $scored = [];
        foreach ($itemFactors as $i => $other) {
            if ($i === $idx) {
                continue;
            }
            $scored[] = ['sku' => $skus[$i], 'score' => $this->cosine($vec, $other)];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_column(array_slice($scored, 0, $k), 'sku');
    }

    private function hybridTopK(array $skuToIndex, array $itemFactors, array $skus, ?array $contentModel, string $seedSku, int $k): array
    {
        if (! isset($skuToIndex[$seedSku])) {
            return [];
        }
        $idx = $skuToIndex[$seedSku];
        $vec = $itemFactors[$idx];
        $contentIdx = $contentModel['sku_to_index'][$seedSku] ?? null;
        $contentVec = $contentIdx !== null ? $contentModel['vectors'][$contentIdx] : null;

        $wCollaborative = (float) config('recommender.w_collaborative');
        $wContent = (float) config('recommender.w_content');

        $scored = [];
        foreach ($itemFactors as $i => $other) {
            if ($i === $idx) {
                continue;
            }
            $mfScore = $this->cosine($vec, $other);
            $contentScore = 0.0;
            if ($contentVec !== null) {
                $otherIdx = $contentModel['sku_to_index'][$skus[$i]] ?? null;
                if ($otherIdx !== null) {
                    $contentScore = $this->cosine($contentVec, $contentModel['vectors'][$otherIdx]);
                }
            }
            $scored[] = ['sku' => $skus[$i], 'score' => $wCollaborative * $mfScore + $wContent * $contentScore];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_column(array_slice($scored, 0, $k), 'sku');
    }

    private function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
            $na += $v * $v;
            $nb += $b[$i] * $b[$i];
        }

        return $dot / ((sqrt($na) * sqrt($nb)) ?: 1e-8);
    }

    private function loadContentModel(): ?array
    {
        $path = base_path(config('recommender.models_path').'/content_embeddings.json');
        if (! is_file($path)) {
            return null;
        }
        $parsed = json_decode((string) file_get_contents($path), true);
        if (! is_array($parsed) || ! isset($parsed['skus'], $parsed['embeddings'])) {
            return null;
        }

        return ['sku_to_index' => array_flip($parsed['skus']), 'vectors' => $parsed['embeddings']];
    }
}
