<?php

/*
| Jobs planifiés SyncDailyOrdersJob et SyncProductViewsJob : exécution du job
| lui-même (et non plus seulement du service qu'il enveloppe), reprise,
| propriétés de file d'attente et activation par BEHAVIOR_SYNC_ENABLED.
| Magento est simulé (Http::fake) : aucun appel réseau réel.
*/

use App\Jobs\SyncDailyOrdersJob;
use App\Jobs\SyncProductViewsJob;
use App\Models\Order;
use App\Models\Reclamation;
use App\Models\Seller;
use App\Models\UserProductBehavior;
use App\Services\Behavior\ProductViewsSyncService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function jobOrder(string $increment, array $items): array
{
    return [
        'increment_id' => $increment,
        'created_at' => now('UTC')->format('Y-m-d H:i:s'),
        'state' => 'complete',
        'items' => $items,
    ];
}

function jobItem(string $sku, int $sellerId, int $qty = 1): array
{
    return [
        'sku' => $sku,
        'name' => "Produit {$sku}",
        'qty_ordered' => $qty,
        'price' => 100,
        'custom_attributes' => [['attribute_code' => 'seller_id', 'value' => (string) $sellerId]],
    ];
}

function jobAppendLog(string $file, array $paths, bool $terminate = true): void
{
    $lines = array_map(fn ($p) => '10.0.0.1 - - [03/Oct/2026:10:00:00 +0100] "GET '.$p.' HTTP/1.1" 200 100 "-" "Mozilla/5.0"', $paths);
    file_put_contents($file, implode("\n", $lines).($terminate ? "\n" : ''), FILE_APPEND);
}

/* ---------------------------------------------------------------------------
| SyncDailyOrdersJob
--------------------------------------------------------------------------- */

it('SyncDailyOrdersJob : exécuté, il rattache les lignes au vendeur et notifie une fois par vendeur', function () {
    $a = Seller::factory()->create();
    $b = Seller::factory()->create();
    fakeMagento(['orders*' => Http::response(['total_count' => 2, 'items' => [
        jobOrder('1', [jobItem('A-1', $a->seller_id, 2), jobItem('A-2', $a->seller_id)]),
        jobOrder('2', [jobItem('B-1', $b->seller_id)]),
    ]])]);

    (new SyncDailyOrdersJob)->handle(app(OrderSyncService::class));

    expect(Order::where('vendor_id', $a->seller_id)->count())->toBe(2)
        ->and(Order::where('vendor_id', $b->seller_id)->count())->toBe(1)
        ->and(Reclamation::where('type', 0)->where('vendeur_id', $a->seller_id)->count())->toBe(1)
        ->and(Reclamation::where('type', 0)->where('vendeur_id', $b->seller_id)->count())->toBe(1);
});

it('SyncDailyOrdersJob : rejoué sur la même journée, il ne duplique ni ligne ni notification', function () {
    $a = Seller::factory()->create();
    fakeMagento(['orders*' => Http::response(['total_count' => 1, 'items' => [jobOrder('1', [jobItem('A-1', $a->seller_id)])]])]);

    $job = new SyncDailyOrdersJob;
    $job->handle(app(OrderSyncService::class));
    $job->handle(app(OrderSyncService::class));

    expect(Order::count())->toBe(1)
        ->and(Reclamation::where('type', 0)->count())->toBe(1);
});

it('SyncDailyOrdersJob : si Magento est en erreur, le job échoue (pour être retenté) et compte l\'échec définitif', function () {
    Seller::factory()->create();
    fakeMagento(['orders*' => Http::response(['message' => 'down'], 500)]);

    $job = new SyncDailyOrdersJob;
    $thrown = null;
    try {
        $job->handle(app(OrderSyncService::class));
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and(Order::count())->toBe(0);

    // Après l'épuisement des tentatives, la file appelle failed().
    $job->failed($thrown);
    $this->get('/metrics')->assertSee('marketplace_sync_runs_total{result="failed",task="orders"}', false);
});

it('SyncDailyOrdersJob : 3 tentatives, délais de 30 puis 120 s, exécution unique', function () {
    $job = new SyncDailyOrdersJob;

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120])
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job)->toBeInstanceOf(ShouldQueue::class);
});

it('SyncDailyOrdersJob : est mis en file d\'attente par la répartition', function () {
    Queue::fake();

    SyncDailyOrdersJob::dispatch();

    Queue::assertPushed(SyncDailyOrdersJob::class, 1);
});

/* ---------------------------------------------------------------------------
| SyncProductViewsJob
--------------------------------------------------------------------------- */

describe('SyncProductViewsJob', function () {
    beforeEach(function () {
        $this->log = tempnam(sys_get_temp_dir(), 'log');
        $this->offset = sys_get_temp_dir().'/offset-'.uniqid().'.json';
        config(['marketplace.behavior.log_file' => $this->log, 'marketplace.behavior.offset_file' => $this->offset]);
        fakeMagento([
            'products?*' => Http::response(['items' => [['sku' => 'ECRAN-24']]]),
            'categories/list*' => Http::response(['items' => [['id' => 42]]]),
        ]);
    });

    afterEach(function () {
        @unlink($this->log);
        @unlink($this->offset);
    });

    it('lit le journal configuré, insère les événements et sauvegarde l\'offset', function () {
        jobAppendLog($this->log, ['/myteksearch/index/productsearch/?q=ecran', '/ecran-24.html', '/checkout/cart.html']);

        (new SyncProductViewsJob)->handle(app(ProductViewsSyncService::class));

        expect(UserProductBehavior::count())->toBe(2)
            ->and(UserProductBehavior::where('event_type', 'search')->value('search_query'))->toBe('ecran')
            ->and(UserProductBehavior::where('event_type', 'product_view')->value('product_sku'))->toBe('ECRAN-24')
            ->and(json_decode(file_get_contents($this->offset), true)['offset'])->toBe(filesize($this->log));
    });

    it('ne traite pas une ligne incomplète et la reprend une fois terminée', function () {
        jobAppendLog($this->log, ['/ecran-24.html']);
        jobAppendLog($this->log, ['/ecran-24.html'], terminate: false); // écriture en cours : pas de saut de ligne

        $job = new SyncProductViewsJob;
        $job->handle(app(ProductViewsSyncService::class));

        $complete = strlen(file_get_contents($this->log)) - strlen(strrchr(file_get_contents($this->log), "\n")) + 1;
        expect(UserProductBehavior::count())->toBe(1)
            ->and(json_decode(file_get_contents($this->offset), true)['offset'])->toBe($complete);

        file_put_contents($this->log, "\n", FILE_APPEND); // la ligne se termine
        $job->handle(app(ProductViewsSyncService::class));

        expect(UserProductBehavior::count())->toBe(2);
    });

    it('repart du début si le journal a été remplacé (rotation)', function () {
        jobAppendLog($this->log, ['/ecran-24.html', '/ecran-24.html']);
        $job = new SyncProductViewsJob;
        $job->handle(app(ProductViewsSyncService::class));
        expect(UserProductBehavior::count())->toBe(2);

        file_put_contents($this->log, ''); // rotation : nouveau journal, plus court que l'offset
        jobAppendLog($this->log, ['/ecran-24.html']);
        $job->handle(app(ProductViewsSyncService::class));

        expect(UserProductBehavior::count())->toBe(3)
            ->and(json_decode(file_get_contents($this->offset), true)['offset'])->toBe(filesize($this->log));
    });

    it('ne lève pas d\'exception et laisse l\'offset intact si le journal est absent', function () {
        config(['marketplace.behavior.log_file' => '/chemin/absent.log']);

        (new SyncProductViewsJob)->handle(app(ProductViewsSyncService::class));

        expect(UserProductBehavior::count())->toBe(0)
            ->and(is_file($this->offset))->toBeFalse();
    });

    it('n\'a pas de clé unique : un offset perdu fait relire (et dupliquer) les événements', function () {
        jobAppendLog($this->log, ['/ecran-24.html']);
        $job = new SyncProductViewsJob;
        $job->handle(app(ProductViewsSyncService::class));

        unlink($this->offset); // plantage entre l'insertion et l'écriture de l'offset
        $job->handle(app(ProductViewsSyncService::class));

        expect(UserProductBehavior::count())->toBe(2);
    });

    it('a 2 tentatives, un délai de 60 s et une exécution unique', function () {
        $job = new SyncProductViewsJob;

        expect($job->tries)->toBe(2)
            ->and($job->backoff)->toBe([60])
            ->and($job)->toBeInstanceOf(ShouldBeUnique::class);
    });

    it('n\'est planifié que si BEHAVIOR_SYNC_ENABLED vaut true', function () {
        Artisan::call('list'); // charge routes/console.php, qui déclare les tâches planifiées
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'sync-product-views');
        expect($event)->not->toBeNull();

        config(['marketplace.behavior.enabled' => false]);
        expect($event->filtersPass(app()))->toBeFalse();

        config(['marketplace.behavior.enabled' => true]);
        expect($event->filtersPass(app()))->toBeTrue();
    });
});
