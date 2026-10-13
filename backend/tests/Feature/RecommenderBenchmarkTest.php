<?php

use App\Services\Recommendation\Als;
use App\Services\Recommendation\RecommenderBenchmark;
use Illuminate\Support\Facades\DB;

// Jeu de paniers réduit et déterministe : deux familles de produits co-achetés
// (A* et B*), plus des paniers d'un seul produit.
function benchBaskets(): array
{
    $baskets = [];
    for ($i = 0; $i < 60; $i++) {
        $family = $i % 2 === 0 ? 'A' : 'B';
        $baskets["o$i"] = [$family.'1' => 1, $family.(2 + $i % 3) => 2];
    }
    $baskets['solo1'] = ['A1' => 1];
    $baskets['solo2'] = ['B9' => 3];

    return $baskets;
}

// Base SQLite temporaire au format `orders`, comme celle de marketplace:benchmark-public.
function benchDatabase(array $baskets): string
{
    $file = tempnam(sys_get_temp_dir(), 'bench').'.sqlite';
    touch($file);
    config(['database.connections.bench_test' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false]]);
    DB::purge('bench_test');
    DB::connection('bench_test')->statement('CREATE TABLE orders (order_id TEXT, sku TEXT, qty INTEGER)');
    foreach ($baskets as $id => $items) {
        foreach ($items as $sku => $qty) {
            DB::connection('bench_test')->table('orders')->insert(['order_id' => $id, 'sku' => $sku, 'qty' => $qty]);
        }
    }

    return $file;
}

// Matrice de contenu (float32) : similarité 1 dans une même famille, 0 sinon.
function benchSimilarity(array $skus): array
{
    $bin = '';
    foreach ($skus as $a) {
        foreach ($skus as $b) {
            $bin .= pack('g', $a[0] === $b[0] ? 1.0 : 0.0);
        }
    }
    $binFile = tempnam(sys_get_temp_dir(), 'sim');
    $skuFile = tempnam(sys_get_temp_dir(), 'simsku');
    file_put_contents($binFile, $bin);
    file_put_contents($skuFile, json_encode($skus));

    return [$binFile, $skuFile];
}

it('sépare les paniers en ensembles disjoints, de façon reproductible', function () {
    $bench = new RecommenderBenchmark('bench_test', null, null, 0.65, 0.35);
    $s = $bench->split(benchBaskets(), 7);

    $fit = array_keys($s['fit']);
    $val = array_keys($s['val']);
    $test = array_keys($s['test']);

    expect(array_intersect($val, $test))->toBe([])
        ->and(array_intersect($fit, $test))->toBe([])
        ->and(array_intersect($fit, $val))->toBe([])
        ->and($fit)->toContain('solo1', 'solo2')
        ->and($test)->not->toContain('solo1')
        ->and($s['stats']['baskets_test'])->toBe(12)
        ->and($s['stats']['baskets_single_sku'])->toBe(2)
        ->and($s['stats']['baskets_fit'] + $s['stats']['baskets_val'] + $s['stats']['baskets_test'])->toBe(60)
        ->and(array_keys($bench->split(benchBaskets(), 7)['test']))->toBe($test)
        ->and(array_keys($bench->split(benchBaskets(), 8)['test']))->not->toBe($test);
});

it('calcule des métriques bornées et une popularité qui exclut la graine', function () {
    $rows = [];
    foreach (benchBaskets() as $id => $items) {
        foreach ($items as $sku => $qty) {
            $rows[] = ['basket_id' => (string) $id, 'sku' => $sku, 'qty' => $qty];
        }
    }
    $trained = Als::train($rows, 4, 5, 0.1, 40.0, 42);
    $bench = new RecommenderBenchmark('bench_test', null, null, 0.65, 0.35);

    // A1 est le produit le plus vendu : sans exclusion de la graine, il occuperait le rang 1.
    $out = $bench->evaluateSets($trained, $rows, [['A1', 'A2'], ['A2', 'A1'], ['B1', 'INCONNU']]);

    expect($out['_pairs_total'])->toBe(6)
        ->and($out['popularity']['pairs'])->toBe(4); // 2 paniers évaluables x 2 paires ordonnées ; INCONNU écarte le 3e
    foreach (['als', 'content', 'hybrid', 'cooccurrence', 'popularity'] as $m) {
        foreach (['hit10', 'ndcg10', 'mrr', 'coverage10'] as $metric) {
            expect($out[$m][$metric])->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
        }
    }
    // A2 -> A1 : A1 est 1er en popularité ; A1 -> A2 : la graine A1 est retirée du classement.
    expect($out['popularity']['hit10'])->toEqual(1.0)
        ->and($out['cooccurrence']['hit10'])->toEqual(1.0)
        ->and($out['cooccurrence']['mrr'])->toBeGreaterThan(0.5);
});

it('lit la matrice de contenu et l\'utilise dans le classement', function () {
    [$bin, $skus] = benchSimilarity(['A1', 'A2', 'A3', 'A4', 'B1', 'B2', 'B3', 'B4', 'B9']);
    $bench = new RecommenderBenchmark('bench_test', $bin, $skus, 0.0, 1.0);

    $rows = [];
    foreach (benchBaskets() as $id => $items) {
        foreach ($items as $sku => $qty) {
            $rows[] = ['basket_id' => (string) $id, 'sku' => $sku, 'qty' => $qty];
        }
    }
    $out = $bench->evaluateSets(Als::train($rows, 4, 5, 0.1, 40.0, 42), $rows, [['B1', 'B2']]);

    // Contenu seul : les produits de la même famille sont en tête.
    expect($out['content']['hit10'])->toEqual(1.0)
        ->and($out['hybrid']['hit10'])->toEqual(1.0);
});

it('refuse une matrice de contenu de taille inattendue', function () {
    [$bin, $skus] = benchSimilarity(['A1', 'A2']);
    file_put_contents($skus, json_encode(['A1', 'A2', 'A3']));

    new RecommenderBenchmark('bench_test', $bin, $skus, 0.65, 0.35);
})->throws(RuntimeException::class, 'taille inattendue');

it('charge les paniers depuis la base passée au constructeur', function () {
    benchDatabase(benchBaskets());
    $baskets = (new RecommenderBenchmark('bench_test', null, null, 0.65, 0.35))->loadBaskets();

    expect($baskets)->toHaveCount(62)
        ->and($baskets['o0'])->toBe(['A1' => 1, 'A2' => 2]);
});

it('refuse une base de test vide', function () {
    benchDatabase([]);
    (new RecommenderBenchmark('bench_test', null, null, 0.65, 0.35))->loadBaskets();
})->throws(RuntimeException::class, 'Aucune commande');

it('choisit K sur la validation puis mesure le test, en mode binaire', function () {
    $messages = [];
    $bench = new RecommenderBenchmark('bench_test', null, null, 0.65, 0.35, function (string $m) use (&$messages) {
        $messages[] = $m;
    }, true);

    $r = $bench->runSeed(benchBaskets(), 7, [2, 4]);

    expect($r['seed'])->toBe(7)
        ->and([2, 4])->toContain($r['k_als'], $r['k_hybrid'])
        ->and(array_keys($r['validation_by_k']))->toBe([2, 4])
        ->and(array_keys($r['test']))->toBe(['als', 'hybrid', 'content', 'cooccurrence', 'popularity'])
        ->and($r['test_pairs_evaluable'])->toBeGreaterThan(0)
        ->and($r['test_pairs_total'])->toBeGreaterThanOrEqual($r['test_pairs_evaluable'])
        ->and($messages)->not->toBeEmpty();
});

it('exige une base SQLite existante pour la commande d\'évaluation publique', function () {
    $this->artisan('marketplace:benchmark-public')->assertFailed();
    $this->artisan('marketplace:benchmark-public', ['--db' => '/chemin/inexistant.sqlite'])->assertFailed();
});

it('écrit un résultat JSON par graine', function () {
    $db = benchDatabase(benchBaskets());
    $out = sys_get_temp_dir().'/bench_out_'.uniqid();

    $this->artisan('marketplace:benchmark-public', ['--db' => $db, '--seeds' => '7', '--ks' => '2', '--out' => $out])
        ->assertSuccessful();

    $json = json_decode((string) file_get_contents("$out/seed_7.json"), true);
    expect($json['seed'])->toBe(7)
        ->and($json['k_als'])->toBe(2)
        ->and($json)->toHaveKey('finished_at');
});
