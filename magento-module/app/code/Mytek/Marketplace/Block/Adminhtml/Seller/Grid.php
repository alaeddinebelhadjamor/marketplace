<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Adminhtml\Seller;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Mytek\Marketplace\Model\SellerRepository;

class Grid extends Template
{
    public function __construct(
        Context $context,
        private readonly SellerRepository $sellers,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isPendingOnly(): bool
    {
        return (bool)$this->getData('pending_only');
    }

    public function getSearch(): string
    {
        return (string)$this->getRequest()->getParam('q', '');
    }

    public function getStatusFilter(): ?int
    {
        if ($this->isPendingOnly()) {
            return SellerRepository::STATUS_PENDING;
        }
        $s = $this->getRequest()->getParam('status');
        return ($s === null || $s === '') ? null : (int)$s;
    }

    public function getSellers(): array
    {
        return $this->sellers->getList(
            $this->isPendingOnly() ? null : $this->getSearch(),
            $this->getStatusFilter()
        );
    }

    /** @return array<int,int> status => count */
    public function getCounts(): array
    {
        return $this->sellers->countByStatus();
    }

    public function statusLabel(int $status): string
    {
        return SellerRepository::statusLabel($status);
    }

    public function statusClass(int $status): string
    {
        return match ($status) {
            SellerRepository::STATUS_VALIDATED => 'mk-badge mk-badge--ok',
            SellerRepository::STATUS_REFUSED   => 'mk-badge mk-badge--ko',
            default                            => 'mk-badge mk-badge--pending',
        };
    }

    public function canValidate(): bool
    {
        return $this->_authorization->isAllowed('Mytek_Marketplace::sellers_validate');
    }

    public function canEdit(): bool
    {
        return $this->_authorization->isAllowed('Mytek_Marketplace::sellers_edit');
    }

    public function canDelete(): bool
    {
        return $this->_authorization->isAllowed('Mytek_Marketplace::sellers_delete');
    }

    public function getActionUrl(string $action, array $params = []): string
    {
        return $this->getUrl('mytek_marketplace/seller/' . $action, $params);
    }

    public function formatDateTime(?string $dt): string
    {
        if (!$dt) {
            return '—';
        }
        $ts = strtotime($dt);
        return $ts ? date('d/m/Y H:i', $ts) : $dt;
    }
}
