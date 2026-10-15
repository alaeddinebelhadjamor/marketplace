<?php

/*
| Démarrage à froid du moteur hybride : confiance du collaboratif selon le
| nombre de paniers (item_support) et signal catégorie (item_categories.json,
| construit depuis Magento par CategoryMapBuilder).
*/

use App\Services\Recommendation\CategoryMapBuilder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const COLD_START_DIR = 'storage/framework/testing/recommender-cold-start';

beforeEach(function () {
    config([
        'recommender.models_path' => COLD_START_DIR,
        'recommender.diversity_epsilon' => 0.0,
        'recommender.w_collaborative' => 0.65,
        'recommender.w_content' => 0.35,
        'recommender.w_category' => 0.6,
        'recommender.support_shrinkage' => 5,
    ]);
    File::ensureDirectoryExists(base_path(COLD_START_DIR));
});

afterEach(fn () => File::deleteDirectory(base_path(COLD_START_DIR)));

function writeColdStartModel(string $file, array $data): void
{
    file_put_contents(base_path(COLD_START_DIR.'/'.$file), json_encode($data));
}

it('privilégie la catégorie quand le produit a trop peu d\'achats pour le collaboratif', function () {
    fakeMagento(['products/*' => fn (Request $request) => Http::response(magentoProduct(basename(parse_url($request->url(), PHP_URL_PATH)), 1))]);

    // Collaboratif : MONTRE est très proche de COUVERTS, mais n'a été achetée qu'une fois.
    writeColdStartModel('item_factors.json', [
        'skus' => ['MONTRE', 'COUVERTS', 'ECOUTEURS'],
        'item_factors' => [[1, 0], [1, 0.05], [0.2, 1]],
        'item_support' => ['MONTRE' => 1, 'COUVERTS' => 1, 'ECOUTEURS' => 1],
    ]);
    // Catégories : Téléphonie (10) > Objets connectés (11) / Audio (12) ; Maison (20) > Cuisine (21).
    writeColdStartModel('item_categories.json', ['categories' => [
        'MONTRE' => [10, 11], 'ECOUTEURS' => [10, 12], 'COUVERTS' => [20, 21],
    ]]);

    $response = $this->getJson('/api/recommendations?sku=MONTRE&top_k=1')->assertOk();

    expect($response->json('results.0.sku'))->toBe('ECOUTEURS')
        ->and($response->json('results.0.detail.collaborative_confidence'))->toEqual(0.167)
        ->and($response->json('results.0.detail.category'))->toEqual(1 / 3);
});

it('fait confiance au collaboratif quand le produit a beaucoup d\'achats', function () {
    fakeMagento(['products/*' => fn (Request $request) => Http::response(magentoProduct(basename(parse_url($request->url(), PHP_URL_PATH)), 1))]);

    writeColdStartModel('item_factors.json', [
        'skus' => ['MONTRE', 'COUVERTS', 'ECOUTEURS'],
        'item_factors' => [[1, 0], [1, 0.05], [0.2, 1]],
        'item_support' => ['MONTRE' => 200, 'COUVERTS' => 150, 'ECOUTEURS' => 3],
    ]);
    writeColdStartModel('item_categories.json', ['categories' => [
        'MONTRE' => [10, 11], 'ECOUTEURS' => [10, 12], 'COUVERTS' => [20, 21],
    ]]);

    $response = $this->getJson('/api/recommendations?sku=MONTRE&top_k=1')->assertOk();

    expect($response->json('results.0.sku'))->toBe('COUVERTS');
});

it('recommande par catégorie un produit jamais commandé', function () {
    fakeMagento(['products/*' => fn (Request $request) => Http::response(magentoProduct(basename(parse_url($request->url(), PHP_URL_PATH)), 1))]);

    writeColdStartModel('item_categories.json', ['categories' => [
        'PC-1' => [3, 4, 5], 'PC-2' => [3, 4, 5], 'PC-GAMER' => [3, 4, 6], 'FRIGO' => [30, 31],
    ]]);

    $response = $this->getJson('/api/recommendations?sku=PC-1&top_k=5')->assertOk();

    expect(array_column($response->json('results'), 'sku'))->toBe(['PC-2', 'PC-GAMER']);
});

it('construit la carte des catégories (ancêtres inclus, racines techniques exclues)', function () {
    fakeMagento([
        'categories' => Http::response(['id' => 2, 'level' => 1, 'children_data' => [
            ['id' => 3, 'level' => 2, 'children_data' => [
                ['id' => 4, 'level' => 3, 'children_data' => []],
            ]],
        ]]),
        'products*' => Http::response(['total_count' => 3, 'items' => [
            ['sku' => 'A', 'extension_attributes' => ['category_links' => [['category_id' => '4']]]],
            ['sku' => 'B', 'extension_attributes' => ['category_links' => [['category_id' => '3']]]],
            ['sku' => 'SANS-CAT', 'extension_attributes' => []],
        ]]),
    ]);

    $result = app(CategoryMapBuilder::class)->build();
    $saved = json_decode(file_get_contents(base_path(COLD_START_DIR.'/item_categories.json')), true);

    expect($result)->toBe(['n_items' => 2, 'n_categories' => 2])
        ->and($saved['categories'])->toBe(['A' => [3, 4], 'B' => [3]]);
});
