<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Cron;

use Mytek\Marketplace\Model\Governorate\GovernorateSyncService;
use Psr\Log\LoggerInterface;

/** Tâche planifiée mytek_marketplace_sync_seller_governorate (etc/crontab.xml). */
class SyncSellerGovernorate
{
    public function __construct(
        private readonly GovernorateSyncService $syncService,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        $result = $this->syncService->sync();
        if ($result['updated'] > 0) {
            $this->logger->info('Mytek_Marketplace : seller_governorate synchronisé', $result);
        }
    }
}
