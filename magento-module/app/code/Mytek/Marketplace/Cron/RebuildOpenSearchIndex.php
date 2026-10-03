<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Cron;

use Mytek\Marketplace\Model\Search\MarketplaceIndexer;
use Psr\Log\LoggerInterface;

/**
 * Filet de sécurité horaire (etc/crontab.xml) : reconstruit l'index au complet, pour rattraper
 * tout ce que l'observateur incrémental (ReindexProductOnSave) aurait pu manquer — import en
 * masse contournant l'enregistrement normal du produit, ou OpenSearch injoignable au moment
 * d'une mise à jour incrémentale.
 */
class RebuildOpenSearchIndex
{
    public function __construct(
        private readonly MarketplaceIndexer $indexer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $result = $this->indexer->rebuildFull();
            $this->logger->info('Mytek_Marketplace : index OpenSearch reconstruit', $result);
        } catch (\Throwable $e) {
            $this->logger->error('Mytek_Marketplace : échec de la reconstruction de l\'index OpenSearch', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
