<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Mytek\Marketplace\Model\OrderStats;
use Mytek\Marketplace\Model\ReclamationRepository;
use Mytek\Marketplace\Model\SellerRepository;

class Dashboard extends Template
{
    public function __construct(
        Context $context,
        private readonly OrderStats $stats,
        private readonly SellerRepository $sellers,
        private readonly ReclamationRepository $reclamations,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getKpis(): array
    {
        return $this->stats->getKpis();
    }

    public function getSellerCounts(): array
    {
        return $this->sellers->countByStatus();
    }

    public function getUnseenReclamations(): int
    {
        return $this->reclamations->countUnseen();
    }

    public function getSort(): string
    {
        return $this->getRequest()->getParam('sort') === 'orders' ? 'orders' : 'revenue';
    }

    public function getTopSellers(): array
    {
        return $this->stats->getTopSellers($this->getSort(), 10);
    }

    public function getExportUrl(): string
    {
        return $this->getUrl('mytek_marketplace/dashboard/export');
    }

    public function getSortUrl(string $sort): string
    {
        return $this->getUrl('mytek_marketplace/dashboard/index', ['sort' => $sort]);
    }

    public function getPendingUrl(): string
    {
        return $this->getUrl('mytek_marketplace/seller/pending');
    }

    public function getReclamationsUrl(): string
    {
        return $this->getUrl('mytek_marketplace/reclamation/index', ['unseen' => 1]);
    }

    public function money(float $amount): string
    {
        return number_format($amount, 2, ',', ' ') . ' DT';
    }
}
