<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Console\Command;

use Magento\Framework\App\State;
use Mytek\Marketplace\Model\Governorate\GovernorateSyncService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** bin/magento mytek:marketplace:sync-seller-governorate */
class SyncSellerGovernorate extends Command
{
    public function __construct(private readonly GovernorateSyncService $syncService, private readonly State $appState)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mytek:marketplace:sync-seller-governorate');
        $this->setDescription(
            'Synchronise seller_governorate for products carrying a seller_id (amélioration B).'
        );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode('adminhtml');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area déjà définie (ex. appelée depuis un contexte qui l'a déjà fait) : sans effet.
        }

        $result = $this->syncService->sync();
        $output->writeln(sprintf(
            '<info>%d product(s) checked, %d updated.</info>',
            $result['checked'],
            $result['updated']
        ));
        return Command::SUCCESS;
    }
}
