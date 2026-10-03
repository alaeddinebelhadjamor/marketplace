<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Adminhtml\Commission;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Mytek\Marketplace\Model\Commission\CommissionRepository;
use Mytek\Marketplace\Model\OrderStats;

class Index extends Template
{
    public function __construct(
        Context $context,
        private readonly OrderStats $stats,
        private readonly CommissionRepository $commissions,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** Vendeurs ayant au moins une vente, avec leur taux effectif et la commission calculée. */
    public function getSellerCommissions(): array
    {
        $rows = $this->stats->getRevenueBySeller();
        $rates = $this->commissions->getRates(array_map('intval', array_column($rows, 'vendor_id')));
        foreach ($rows as &$row) {
            $vendorId = (int)$row['vendor_id'];
            $row['rate'] = $rates[$vendorId] ?? CommissionRepository::DEFAULT_RATE;
            $row['commission'] = (float)$row['revenue'] * $row['rate'];
        }
        return $rows;
    }

    public function getStatements(): array
    {
        return $this->commissions->getStatements();
    }

    public function getCurrentPeriod(): string
    {
        return date('Y-m');
    }

    public function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' DT';
    }

    public function ratePercent(float $rate): string
    {
        return number_format($rate * 100, 2, ',', ' ') . ' %';
    }

    public function statusLabel(string $status): string
    {
        return $status === 'paid' ? (string)__('Paid') : (string)__('Pending');
    }

    public function statusClass(string $status): string
    {
        return $status === 'paid' ? 'mk-badge mk-badge--ok' : 'mk-badge mk-badge--pending';
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('mytek_marketplace/commission/export');
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('mytek_marketplace/commission/generate');
    }

    public function getSetRateUrl(): string
    {
        return $this->getUrl('mytek_marketplace/commission/setRate');
    }

    public function getStatementPdfUrl(int $id): string
    {
        return $this->getUrl('mytek_marketplace/commission/statementPdf', ['id' => $id]);
    }
}
