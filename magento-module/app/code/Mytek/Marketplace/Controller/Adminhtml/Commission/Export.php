<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Commission;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Mytek\Marketplace\Model\Commission\CommissionRepository;
use Mytek\Marketplace\Model\OrderStats;

/**
 * Export des commissions (tab. 2.18, écart 4 de l'audit : absent du module). Calculé en PHP à
 * partir du chiffre d'affaires par vendeur (table orders) et des taux (table commission_rates,
 * même base marketplace) : aucun appel à l'API nécessaire pour un export en lecture seule.
 */
class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::commissions';

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly OrderStats $stats,
        private readonly CommissionRepository $commissions
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $rows = $this->stats->getRevenueBySeller();
        $rates = $this->commissions->getRates(array_map('intval', array_column($rows, 'vendor_id')));

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
        fputcsv($out, array_map('strval', [
            __('Seller'), __('Shop'), __('Email'), __('Orders'), __('Revenue'), __('Rate'), __('Commission'),
        ]), ';');
        foreach ($rows as $r) {
            $vendorId = (int)$r['vendor_id'];
            $revenue = (float)$r['revenue'];
            $rate = $rates[$vendorId] ?? CommissionRepository::DEFAULT_RATE;
            fputcsv($out, [
                trim(($r['firstname'] ?? '') . ' ' . ($r['lastname'] ?? '')) ?: "#$vendorId",
                $r['shop_title'] ?? '',
                $r['email'] ?? '',
                (int)$r['orders'],
                number_format($revenue, 2, '.', ''),
                number_format($rate * 100, 2, '.', '') . '%',
                number_format($revenue * $rate, 2, '.', ''),
            ], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->fileFactory->create(
            'marketplace_commissions_' . date('Ymd_His') . '.csv',
            $csv,
            DirectoryList::VAR_DIR,
            'text/csv; charset=UTF-8'
        );
    }
}
