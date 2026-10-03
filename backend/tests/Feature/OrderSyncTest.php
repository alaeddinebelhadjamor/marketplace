<?php

/*
| Synchronisation des commandes (chapitre 4, tests T4-05 et T4-06 automatisés).
*/

use App\Jobs\SyncDailyOrdersJob;
use App\Models\Order;
use App\Models\OrderMagentoDate;
use App\Models\Reclamation;
use App\Models\Seller;
use App\Services\Sync\OrderSyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function magentoOrders(array $orders): array
{
    return ['items' => $orders, 'total_count' => count($orders)];
}

function magentoOrder(string $increment, array $items, string $createdAt = '2026-10-03 08:15:00'): array
{
    return ['increment_id' => $increment, 'created_at' => $createdAt, 'state' => 'complete', 'items' => $items];
}

function magentoItem(string $sku, ?int $sellerId, int $qty = 1, float $price = 100): array
{
    $item = ['sku' => $sku, 'name' => "Produit {$sku}", 'qty_ordered' => $qty, 'price' => $price];
    if ($sellerId !== null) {
        $item['custom_attributes'] = [['attribute_code' => 'seller_id', 'value' => (string) $sellerId]];
    }

    return $item;
}

it('rattache chaque ligne à son vendeur, y compris dans une commande multi-vendeurs (T4-06)', function () {
    $a = Seller::factory()->create();
    $b = Seller::factory()->create();
    fakeMagento([
        'orders*' => Http::response(magentoOrders([
            magentoOrder('100001', [magentoItem('A-1', $a->seller_id, 2, 50), magentoItem('B-1', null, 1, 80), magentoItem('MYTEK-1', null)]),
        ])),
        'products/B-1' => Http::response(magentoProduct('B-1', $b->seller_id)),
        'products/MYTEK-1' => Http::response(magentoProduct('MYTEK-1', null)),
    ]);

    $result = app(OrderSyncService::class)->syncDay();

    expect($result['inserted'])->toBe(2)
        ->and(Order::where('vendor_id', $a->seller_id)->first())->sku->toBe('A-1')->qty->toBe(2)
        ->and(Order::where('vendor_id', $b->seller_id)->first())->sku->toBe('B-1')
        ->and(OrderMagentoDate::find('100001')->ordered_at->format('Y-m-d H:i'))->toBe('2026-10-03 08:15');
});

it('est idempotente : rejouée, elle n\'insère rien et ne renotifie pas (T4-05)', function () {
    $a = Seller::factory()->create();
    fakeMagento(['orders*' => Http::response(magentoOrders([magentoOrder('1', [magentoItem('A', $a->seller_id)])]))]);

    $sync = app(OrderSyncService::class);
    $sync->syncDay();
    $second = $sync->syncDay();

    expect($second['inserted'])->toBe(0)
        ->and(Order::count())->toBe(1)
        ->and(Reclamation::where('type', 0)->count())->toBe(1);
});

it('envoie une seule notification groupée par vendeur', function () {
    $a = Seller::factory()->create();
    fakeMagento(['orders*' => Http::response(magentoOrders([
        magentoOrder('1', [magentoItem('A', $a->seller_id), magentoItem('B', $a->seller_id)]),
        magentoOrder('2', [magentoItem('C', $a->seller_id)]),
    ]))]);

    app(OrderSyncService::class)->syncDay();

    $notifications = Reclamation::with('messages')->where('vendeur_id', $a->seller_id)->where('type', 0)->get();
    expect($notifications)->toHaveCount(1)
        ->and($notifications->first()->messages->first()->message)->toBe("Félicitations ! Vous avez enregistré 3 nouvelles ventes aujourd'hui");
});

it('ignore les vendeurs non validés et les lignes enfants', function () {
    $pending = Seller::factory()->pending()->create();
    $ok = Seller::factory()->create();
    fakeMagento(['orders*' => Http::response(magentoOrders([magentoOrder('1', [
        magentoItem('P', $pending->seller_id),
        magentoItem('PARENT', $ok->seller_id),
        magentoItem('CHILD', $ok->seller_id) + ['parent_item_id' => 5],
    ])]))]);

    app(OrderSyncService::class)->syncDay();

    expect(Order::pluck('sku')->all())->toBe(['PARENT']);
});

it('filtre les commandes complete de la journée (filtres en ET)', function () {
    Seller::factory()->create();
    fakeMagento(['orders*' => Http::response(magentoOrders([]))]);

    app(OrderSyncService::class)->syncDay(Carbon::parse('2026-10-03'));

    Http::assertSent(function (Request $r) {
        $q = urldecode($r->url());

        return str_contains($q, 'filter_groups][1][filters][0][value]=2026-10-03 00:00:00')
            && str_contains($q, 'filter_groups][2][filters][0][value]=2026-10-03 23:59:59');
    });
});

it('fait échouer le job si Magento répond en erreur, pour qu\'il soit retenté', function () {
    Seller::factory()->create();
    fakeMagento(['orders*' => Http::response(['message' => 'down'], 500)]);

    expect(fn () => app(OrderSyncService::class)->syncDay())->toThrow(RuntimeException::class);
    expect((new SyncDailyOrdersJob)->tries)->toBe(3);
});
