<?php

namespace App\Services\Recommendation;

use App\Models\Order;
use RuntimeException;

/**
 * SIMULATION (aucune écriture en base) : génère un volume croissant de
 * commandes synthétiques pour estimer si/à partir de quel volume l'ALS
 * dépasserait une simple base de popularité. Port de
 * scripts/simulate_data_scaling.js — mêmes volumes, même construction
 * (popularité Zipf + affinité de marque) sur le catalogue réel de
 * marketplace-v2. Les résultats sont une estimation, pas une mesure de
 * performance réelle (données synthétiques).
 */
class RecommenderScalingSimulator
{
    private const VOLUMES = [367, 1000, 3000, 10000, 30000];

    private const TOP_K = 10;

    private const TEST_RATIO = 0.2;

    private const SEED = 123;

    private const ALS = ['k' => 8, 'iterations' => 15, 'lambda' => 0.1, 'alpha' => 40.0, 'seed' => 42];

    public function simulate(): array
    {
        $rows = Order::query()->selectRaw('sku, MIN(product_name) as product_name')->groupBy('sku')->get();

        if ($rows->isEmpty()) {
            throw new RuntimeException('Aucun produit en base pour servir de catalogue de référence.');
        }

        $catalog = $rows->map(fn ($r) => ['sku' => $r->sku, 'brand' => $this->extractBrand($r->product_name)])->all();

        $results = [];
        foreach (self::VOLUMES as $volume) {
            mt_srand(self::SEED + $volume);
            $baskets = $this->generateSyntheticBaskets($catalog, $volume);

            $evaluableBaskets = array_values(array_filter($baskets, fn ($items) => count(array_unique(array_column($items, 'sku'))) >= 2));
            $nonEvaluable = array_values(array_filter($baskets, fn ($items) => count(array_unique(array_column($items, 'sku'))) < 2));

            for ($i = count($evaluableBaskets) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$evaluableBaskets[$i], $evaluableBaskets[$j]] = [$evaluableBaskets[$j], $evaluableBaskets[$i]];
            }

            $splitIdx = (int) floor(count($evaluableBaskets) * (1 - self::TEST_RATIO));
            $trainBaskets = array_slice($evaluableBaskets, 0, $splitIdx);
            $testBaskets = array_slice($evaluableBaskets, $splitIdx);

            $trainRows = [];
            foreach ([...$trainBaskets, ...$nonEvaluable] as $basket) {
                foreach ($basket as $row) {
                    $trainRows[] = $row;
                }
            }

            $testPairs = [];
            foreach ($testBaskets as $items) {
                $skus = array_values(array_unique(array_column($items, 'sku')));
                $n = count($skus);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = 0; $j < $n; $j++) {
                        if ($i !== $j) {
                            $testPairs[] = ['seed' => $skus[$i], 'target' => $skus[$j]];
                        }
                    }
                }
            }

            $results[] = array_merge(['volume' => $volume], $this->evaluate($trainRows, $testPairs));
        }

        return $results;
    }

    private function evaluate(array $trainRows, array $testPairs): array
    {
        $trained = Als::train($trainRows, self::ALS['k'], self::ALS['iterations'], self::ALS['lambda'], self::ALS['alpha'], self::ALS['seed']);
        $skuToIndex = array_flip($trained['skus']);

        $popCount = [];
        foreach ($trainRows as $row) {
            $popCount[$row['sku']] = ($popCount[$row['sku']] ?? 0) + 1;
        }
        arsort($popCount);
        $popularTopK = array_slice(array_keys($popCount), 0, self::TOP_K);

        $evaluable = 0;
        $hits = 0;
        $popHits = 0;

        foreach ($testPairs as $pair) {
            $seed = $pair['seed'];
            $target = $pair['target'];
            if (! isset($skuToIndex[$seed]) || ! isset($skuToIndex[$target])) {
                continue;
            }
            $evaluable++;
            if (in_array($target, $this->topK($skuToIndex, $trained['item_factors'], $trained['skus'], $seed, self::TOP_K), true)) {
                $hits++;
            }
            if (in_array($target, $popularTopK, true)) {
                $popHits++;
            }
        }

        return [
            'hit_rate' => $evaluable ? $hits / $evaluable : 0.0,
            'pop_rate' => $evaluable ? $popHits / $evaluable : 0.0,
            'evaluable' => $evaluable,
        ];
    }

    private function topK(array $skuToIndex, array $itemFactors, array $skus, string $seedSku, int $k): array
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

    private function extractBrand(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'INCONNU';
        }
        $parts = preg_split('/\s+/', $name);

        return mb_strtoupper($parts[0] ?? 'INCONNU');
    }

    /** @return list<list<array{basket_id:string,sku:string,qty:int}>> */
    private function generateSyntheticBaskets(array $catalog, int $nBaskets): array
    {
        $ranked = $catalog;
        shuffle($ranked);
        $n = count($ranked);

        $weights = [];
        $totalWeight = 0.0;
        foreach ($ranked as $i => $_) {
            $w = 1 / ($i + 1);
            $weights[] = $w;
            $totalWeight += $w;
        }

        $byBrand = [];
        foreach ($catalog as $p) {
            $byBrand[$p['brand']][] = $p;
        }

        $pickWeighted = function () use ($ranked, $weights, $totalWeight) {
            $r = (mt_rand() / mt_getrandmax()) * $totalWeight;
            foreach ($weights as $i => $w) {
                $r -= $w;
                if ($r <= 0) {
                    return $ranked[$i];
                }
            }

            return $ranked[count($ranked) - 1];
        };

        $baskets = [];
        for ($b = 0; $b < $nBaskets; $b++) {
            $size = 1 + (int) floor((mt_rand() / mt_getrandmax()) * 4);
            $items = [$pickWeighted()];

            while (count($items) < $size) {
                if ((mt_rand() / mt_getrandmax()) < 0.5) {
                    $ref = $items[mt_rand(0, count($items) - 1)];
                    $pool = $byBrand[$ref['brand']] ?? $ranked;
                    $next = $pool[mt_rand(0, count($pool) - 1)];
                } else {
                    $next = $pickWeighted();
                }

                $already = false;
                foreach ($items as $it) {
                    if ($it['sku'] === $next['sku']) {
                        $already = true;
                        break;
                    }
                }
                if (! $already) {
                    $items[] = $next;
                } elseif (count($items) >= $n) {
                    break;
                }
            }

            $basketId = "SIM-{$b}";
            $baskets[] = array_map(fn ($p) => ['basket_id' => $basketId, 'sku' => $p['sku'], 'qty' => 1], $items);
        }

        return $baskets;
    }
}
