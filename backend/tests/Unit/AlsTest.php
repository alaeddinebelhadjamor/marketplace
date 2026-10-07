<?php

/*
| Modèle ALS implicite (App\Services\Recommendation\Als), port de la v1.
| Vérifie la forme du résultat et une propriété de bon sens de
| l'algorithme : deux produits achetés ensemble dans plusieurs paniers
| distincts doivent finir avec des facteurs latents plus proches (cosinus)
| qu'un produit jamais co-acheté avec eux.
*/

use App\Services\Recommendation\Als;

function cosineSim(array $a, array $b): float
{
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    foreach ($a as $i => $v) {
        $dot += $v * $b[$i];
        $normA += $v * $v;
        $normB += $b[$i] * $b[$i];
    }

    return $dot / (sqrt($normA) * sqrt($normB));
}

it('renvoie des facteurs latents de la bonne forme', function () {
    $rows = [
        ['basket_id' => '1', 'sku' => 'A', 'qty' => 1],
        ['basket_id' => '1', 'sku' => 'B', 'qty' => 1],
        ['basket_id' => '2', 'sku' => 'A', 'qty' => 2],
        ['basket_id' => '2', 'sku' => 'B', 'qty' => 1],
        ['basket_id' => '3', 'sku' => 'C', 'qty' => 1],
    ];

    $result = Als::train($rows, k: 4, iterations: 10, seed: 1);

    expect($result['n_baskets'])->toBe(3)
        ->and($result['n_items'])->toBe(3)
        ->and($result['skus'])->toBe(['A', 'B', 'C'])
        ->and($result['item_factors'])->toHaveCount(3);

    foreach ($result['item_factors'] as $vector) {
        expect($vector)->toHaveCount(4);
        foreach ($vector as $x) {
            expect(is_finite($x))->toBeTrue();
        }
    }
});

it('rapproche les facteurs de produits fréquemment co-achetés', function () {
    $rows = [];
    // A et B sont achetés ensemble dans 8 paniers distincts.
    for ($i = 1; $i <= 8; $i++) {
        $rows[] = ['basket_id' => "ab{$i}", 'sku' => 'A', 'qty' => 1];
        $rows[] = ['basket_id' => "ab{$i}", 'sku' => 'B', 'qty' => 1];
    }
    // C n'est jamais acheté avec A ni B.
    for ($i = 1; $i <= 8; $i++) {
        $rows[] = ['basket_id' => "c{$i}", 'sku' => 'C', 'qty' => 1];
    }

    $result = Als::train($rows, k: 6, iterations: 15, seed: 7);
    $bySku = array_combine($result['skus'], $result['item_factors']);

    $simAB = cosineSim($bySku['A'], $bySku['B']);
    $simAC = cosineSim($bySku['A'], $bySku['C']);

    expect($simAB)->toBeGreaterThan($simAC);
});

it('est déterministe pour une même graine', function () {
    $rows = [
        ['basket_id' => '1', 'sku' => 'A', 'qty' => 1],
        ['basket_id' => '1', 'sku' => 'B', 'qty' => 1],
        ['basket_id' => '2', 'sku' => 'B', 'qty' => 1],
        ['basket_id' => '2', 'sku' => 'C', 'qty' => 1],
    ];

    $r1 = Als::train($rows, seed: 99);
    $r2 = Als::train($rows, seed: 99);

    expect($r1['item_factors'])->toBe($r2['item_factors']);
});
