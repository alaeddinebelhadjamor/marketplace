<?php

/*
| Pipeline de suivi comportemental de bout en bout sur un journal de test.
*/

use App\Models\UserProductBehavior;
use App\Services\Behavior\ProductViewsSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** Écrit des lignes de journal datées de $visitedAt (deux heures plus tôt par défaut, fuseau +0100). */
function writeLog(string $file, array $paths, ?Carbon $visitedAt = null): void
{
    $date = ($visitedAt ?? now()->subHours(2))->copy()->setTimezone('+01:00')->format('d/M/Y:H:i:s O');
    $lines = array_map(fn ($p) => '10.0.0.1 - - ['.$date.'] "GET '.$p.' HTTP/1.1" 200 100 "-" "Mozilla/5.0"', $paths);
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

it('date chaque événement de l\'heure de la visite, pas de l\'heure de lecture du journal', function () {
    $visit = now()->subDay()->setTime(10, 15, 32);
    writeLog($this->log, ['/myteksearch/index/productsearch/?q=ecran', '/ecran-24.html'], $visit);

    app(ProductViewsSyncService::class)->sync($this->log, $this->offset);

    expect(UserProductBehavior::pluck('created_at')->map->format('Y-m-d H:i:s')->unique()->values()->all())
        ->toBe([$visit->copy()->utc()->format('Y-m-d H:i:s')]);
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
