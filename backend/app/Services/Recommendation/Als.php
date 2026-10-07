<?php

namespace App\Services\Recommendation;

/**
 * Port direct de l'algorithme ALS implicite développé pour la v1
 * (marketplace-backend/services/als.js), d'après Hu, Koren & Volinsky,
 * « Collaborative Filtering for Implicit Feedback Datasets » (2008) : même
 * solveur (élimination de Gauss à pivot partiel), même formule de confiance,
 * mêmes hyperparamètres par défaut. Seul le générateur pseudo-aléatoire
 * d'initialisation diffère (Mersenne Twister natif de PHP au lieu de
 * mulberry32 en JS) — un détail d'implémentation sans incidence sur
 * l'algorithme lui-même.
 */
class Als
{
    /**
     * Entraîne un modèle ALS sur une liste de lignes panier/produit.
     *
     * @param  list<array{basket_id: string, sku: string, qty: int}>  $rows
     * @return array{skus: list<string>, item_factors: list<list<float>>, n_baskets: int, n_items: int}
     */
    public static function train(array $rows, int $k = 8, int $iterations = 15, float $lambda = 0.1, float $alpha = 40.0, int $seed = 42): array
    {
        $basketIndex = [];
        $skuIndex = [];
        $indexToSku = [];

        $getBasket = static function (string $id) use (&$basketIndex): int {
            return $basketIndex[$id] ??= count($basketIndex);
        };
        $getSku = static function (string $sku) use (&$skuIndex, &$indexToSku): int {
            if (! isset($skuIndex[$sku])) {
                $skuIndex[$sku] = count($skuIndex);
                $indexToSku[] = $sku;
            }

            return $skuIndex[$sku];
        };

        $basketInteractions = [];
        foreach ($rows as $row) {
            $u = $getBasket((string) $row['basket_id']);
            $i = $getSku((string) $row['sku']);
            $qty = (int) $row['qty'];
            $confidence = 1 + $alpha * ($qty !== 0 ? $qty : 1);
            $basketInteractions[$u][] = ['item' => $i, 'confidence' => (float) $confidence];
        }

        $nBaskets = count($basketIndex);
        $nItems = count($skuIndex);

        $itemInteractions = array_fill(0, $nItems, []);
        for ($u = 0; $u < $nBaskets; $u++) {
            foreach ($basketInteractions[$u] ?? [] as $entry) {
                $itemInteractions[$entry['item']][] = ['item' => $u, 'confidence' => $entry['confidence']];
            }
        }
        for ($u = 0; $u < $nBaskets; $u++) {
            $basketInteractions[$u] ??= [];
        }

        mt_srand($seed);
        $randVec = static fn (int $n): array => array_map(
            static fn () => (mt_rand() / mt_getrandmax() - 0.5) * 0.1,
            range(1, $n)
        );

        $X = array_map(static fn () => $randVec($k), range(1, max($nBaskets, 1)));
        $Y = array_map(static fn () => $randVec($k), range(1, max($nItems, 1)));
        $X = array_slice($X, 0, $nBaskets);
        $Y = array_slice($Y, 0, $nItems);

        for ($iter = 1; $iter <= $iterations; $iter++) {
            $X = self::step($basketInteractions, $Y, $k, $lambda);
            $Y = self::step($itemInteractions, $X, $k, $lambda);
        }

        return ['skus' => $indexToSku, 'item_factors' => $Y, 'n_baskets' => $nBaskets, 'n_items' => $nItems];
    }

    /** @param  list<list<array{item:int,confidence:float}>>  $interactionsByRow */
    private static function step(array $interactionsByRow, array $Y, int $k, float $lambda): array
    {
        $YtY = self::gram($Y, $k);
        $nRows = count($interactionsByRow);
        $X = [];

        for ($u = 0; $u < $nRows; $u++) {
            $A = $YtY;
            for ($i = 0; $i < $k; $i++) {
                $A[$i][$i] += $lambda;
            }

            $b = array_fill(0, $k, 0.0);
            foreach ($interactionsByRow[$u] as $entry) {
                $y = $Y[$entry['item']];
                self::outerAddInPlace($A, $y, $entry['confidence'] - 1);
                for ($i = 0; $i < $k; $i++) {
                    $b[$i] += $entry['confidence'] * $y[$i];
                }
            }

            $X[$u] = self::solve($A, $b);
        }

        return $X;
    }

    private static function gram(array $Y, int $k): array
    {
        $G = array_fill(0, $k, array_fill(0, $k, 0.0));
        foreach ($Y as $y) {
            self::outerAddInPlace($G, $y, 1.0);
        }

        return $G;
    }

    private static function outerAddInPlace(array &$A, array $y, float $scale): void
    {
        $n = count($y);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $A[$i][$j] += $scale * $y[$i] * $y[$j];
            }
        }
    }

    /** Résolution de système linéaire par élimination de Gauss à pivot partiel. */
    private static function solve(array $A, array $b): array
    {
        $n = count($b);
        $M = [];
        foreach ($A as $i => $row) {
            $M[$i] = [...$row, $b[$i]];
        }

        for ($col = 0; $col < $n; $col++) {
            $pivot = $col;
            for ($r = $col + 1; $r < $n; $r++) {
                if (abs($M[$r][$col]) > abs($M[$pivot][$col])) {
                    $pivot = $r;
                }
            }
            [$M[$col], $M[$pivot]] = [$M[$pivot], $M[$col]];

            $diag = $M[$col][$col] ?: 1e-8;
            for ($c = $col; $c <= $n; $c++) {
                $M[$col][$c] /= $diag;
            }

            for ($r = 0; $r < $n; $r++) {
                if ($r === $col) {
                    continue;
                }
                $factor = $M[$r][$col];
                for ($c = $col; $c <= $n; $c++) {
                    $M[$r][$c] -= $factor * $M[$col][$c];
                }
            }
        }

        return array_map(static fn ($row) => $row[$n], $M);
    }
}
