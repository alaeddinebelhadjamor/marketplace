<?php

namespace App\Jobs;

use App\Services\Behavior\ProductViewsSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Job planifié du suivi comportemental (lecture du journal Apache de Magento). */
class SyncProductViewsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public array $backoff = [60];

    public int $uniqueFor = 900;

    public function handle(ProductViewsSyncService $sync): void
    {
        $sync->sync();
    }
}
