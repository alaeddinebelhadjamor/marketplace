<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Mytek\Marketplace\Model\OrderStats;

class Export extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::dashboard';

    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly OrderStats $stats
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $rows = $this->stats->getExportRows();

        $out = fopen('php://temp', 'r+');
        // BOM UTF-8 pour un affichage correct des accents dans Excel
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map('strval', [
            __('Order'), __('SKU'), __('Product'), __('Quantity'), __('Unit price'),
            __('Line total'), __('Seller'), __('Seller email'), __('Date'),
        ]), ';');
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['order_id'],
                $r['sku'],
                $r['product_name'],
                $r['qty'],
                number_format((float)$r['price'], 2, '.', ''),
                number_format((float)$r['price'] * (int)$r['qty'], 2, '.', ''),
                $r['shop_title'] ?? '',
                $r['email'] ?? '',
                $r['processed_at'] ?? '',
            ], ';');
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $this->fileFactory->create(
            'marketplace_ventes_' . date('Ymd_His') . '.csv',
            $csv,
            DirectoryList::VAR_DIR,
            'text/csv; charset=UTF-8'
        );
    }
}
