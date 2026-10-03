<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Console\Command;

use Magento\Framework\App\State;
use Mytek\Marketplace\Model\Search\MarketplaceIndexer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** bin/magento mytek:marketplace:reindex-opensearch */
class ReindexOpenSearch extends Command
{
    public function __construct(private readonly MarketplaceIndexer $indexer, private readonly State $appState)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mytek:marketplace:reindex-opensearch');
        $this->setDescription(
            'Rebuilds the marketplace OpenSearch index read by the seller space (amélioration J).'
        );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area déjà définie : sans effet.
        }

        $result = $this->indexer->rebuildFull();
        $output->writeln(sprintf('<info>Index "%s" built with %d product(s).</info>', $result['index'], $result['count']));
        return Command::SUCCESS;
    }
}
