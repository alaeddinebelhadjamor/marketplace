<?php

namespace App\Jobs;

use App\Services\Sync\OrderSyncService;
use App\Support\Metrics\MetricsRegistry;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Job planifié de synchronisation des commandes Magento.
 *
 * Avantage par rapport au cron de la v1 : en cas de panne de Magento, le job
 * est retenté automatiquement (3 essais, délais croissants), et un échec
 * définitif reste visible et rejouable (table failed_jobs, métrique dédiée).
 * ShouldBeUnique empêche deux synchronisations simultanées.
 */
class SyncDailyOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** Délais entre les tentatives, en secondes. */
    public array $backoff = [30, 120];

    public int $uniqueFor = 600;

    public function handle(OrderSyncService $sync): void
    {
        $sync->syncDay();
    }

    public function failed(?Throwable $e): void
    {
        $metrics = app(MetricsRegistry::class);
        $metrics->increment('marketplace_sync_runs_total', ['task' => 'orders', 'result' => 'failed']);
        $metrics->flush();
    }
}
