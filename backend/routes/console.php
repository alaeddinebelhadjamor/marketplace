<?php

use App\Jobs\SyncDailyOrdersJob;
use App\Jobs\SyncProductViewsJob;
use App\Services\Behavior\ProductViewsSyncService;
use App\Services\Commission\CommissionService;
use App\Services\Sync\OrderSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tâches planifiées (lancées par « php artisan schedule:work »)
|--------------------------------------------------------------------------
| Les cadences gardent les variables de la v1 (CRON_ORDERS_SYNC,
| CRON_PRODUCT_VIEWS). Les traitements partent dans la file d'attente :
| nouvelles tentatives automatiques et échecs consultables.
*/

$timezone = config('marketplace.schedule.timezone');

Schedule::job(new SyncDailyOrdersJob)
    ->cron(config('marketplace.schedule.orders_sync'))
    ->timezone($timezone)
    ->name('sync-orders')
    ->withoutOverlapping();

Schedule::job(new SyncProductViewsJob)
    ->cron(config('marketplace.schedule.product_views'))
    ->timezone($timezone)
    ->name('sync-product-views')
    ->withoutOverlapping()
    ->when(fn () => (bool) config('marketplace.behavior.enabled'));

// Relevés de commission du mois écoulé, le 1er du mois à 02:00.
Schedule::call(fn () => app(CommissionService::class)->generateForPeriod(now()->subMonthNoOverflow()->format('Y-m')))
    ->monthlyOn(1, '02:00')
    ->timezone($timezone)
    ->name('payout-statements');

// Hygiène : jetons d'API expirés et jobs en échec anciens.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('queue:prune-failed --hours=720')->daily();

/*
| Commandes manuelles
*/

Artisan::command('marketplace:sync-orders {--date= : Jour à synchroniser (AAAA-MM-JJ), aujourd\'hui par défaut}', function (OrderSyncService $sync) {
    $day = $this->option('date') ? Carbon::parse($this->option('date'), 'UTC') : null;
    $result = $sync->syncDay($day);
    $this->info("Commandes lues : {$result['orders']}, lignes insérées : {$result['inserted']}, vendeurs notifiés : {$result['notified']}.");
})->purpose('Synchronise maintenant les commandes Magento complete du jour');

Artisan::command('marketplace:sync-product-views', function (ProductViewsSyncService $sync) {
    $result = $sync->sync();
    $this->info("Événements insérés : {$result['inserted']}, lignes ignorées : {$result['skipped']}, offset : {$result['offset']}.");
})->purpose('Lit maintenant le journal Apache de Magento (suivi comportemental)');

Artisan::command('marketplace:statements {period : Mois AAAA-MM}', function (CommissionService $commissions) {
    $count = $commissions->generateForPeriod($this->argument('period'));
    $this->info("{$count} relevé(s) générés pour {$this->argument('period')}.");
})->purpose('Génère les relevés de commission d\'un mois');
