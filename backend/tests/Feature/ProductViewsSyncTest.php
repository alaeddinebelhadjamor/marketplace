<?php

/*
| Pipeline de suivi comportemental de bout en bout sur un journal de test.
*/

use App\Models\UserProductBehavior;
use App\Services\Behavior\ProductViewsSyncService;
use Illuminate\Support\Facades\Http;

function writeLog(string $file, array $paths): void
{
    $lines = array_map(fn ($p) => '10.0.0.1 - - [03/Oct/2026:10:00:00 +0100] "GET '.$p.' HTTP/1.1" 200 100 "-" "Mozilla/5.0"', $paths);
    file_put_contents($file, implode("\n", $lines)."\n", FILE_APPEND);
}

beforeEach(function () {
    $this->log = tempnam(sys_get_temp_dir(), 'log');
    $this->offset = sys_get_temp_dir().'/offset-'.uniqid().'.json';
    fakeMagento([
        'products?*' => function ($request) {
            return str_contains(urldecode($request->url()), '=ecran-24')
                ? Http::response(['items' => [['sku' => 'ECRAN-24']]])
                : Http::response(['items' => []]);
        },
        'categories/list*' => Http::response(['items' => [['id' => 42]]]),
    ]);
});

afterEach(fn () => @unlink($this->log) || @unlink($this->offset));

it('insère recherche, vue produit et vue catégorie, puis la jauge Prometheus', function () {
    writeLog($this->log, ['/myteksearch/index/productsearch/?q=ecran', '/ecran-24.html', '/informatique.html', '/checkout/cart.html']);

    $result = app(ProductViewsSyncService::class)->sync($this->log, $this->offset);

    expect($result)->inserted->toBe(3)->skipped->toBe(1);
    expect(UserProductBehavior::pluck('event_type')->sort()->values()->all())->toBe(['category_view', 'product_view', 'search']);
    expect(UserProductBehavior::where('event_type', 'product_view')->value('product_sku'))->toBe('ECRAN-24');
    expect(UserProductBehavior::where('event_type', 'category_view')->value('category_id'))->toBe(42);

    $this->get('/metrics')->assertSee('magento_product_views_total{product_sku="ECRAN-24"', false);
});

it('reprend à l\'offset sauvegardé : aucune ligne n\'est traitée deux fois', function () {
    writeLog($this->log, ['/ecran-24.html']);
    $sync = app(ProductViewsSyncService::class);
    $sync->sync($this->log, $this->offset);
    $sync->sync($this->log, $this->offset);
    writeLog($this->log, ['/ecran-24.html']);
    $sync->sync($this->log, $this->offset);

    expect(UserProductBehavior::count())->toBe(2);
});

it('ne fait rien si le journal est absent', function () {
    expect(app(ProductViewsSyncService::class)->sync('/chemin/absent.log', $this->offset))->inserted->toBe(0);
});
