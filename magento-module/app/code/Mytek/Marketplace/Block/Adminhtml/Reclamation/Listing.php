<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Adminhtml\Reclamation;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Mytek\Marketplace\Model\ReclamationRepository;

class Listing extends Template
{
    public function __construct(
        Context $context,
        private readonly ReclamationRepository $reclamations,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isUnseenOnly(): bool
    {
        return (bool)$this->getRequest()->getParam('unseen');
    }

    public function getReclamations(): array
    {
        return $this->reclamations->getList($this->isUnseenOnly());
    }

    public function getUnseenCount(): int
    {
        return $this->reclamations->countUnseen();
    }

    public function typeLabel(int $type): string
    {
        return ReclamationRepository::typeLabel($type);
    }

    public function typeClass(int $type): string
    {
        return $type === ReclamationRepository::TYPE_RESOLVED ? 'mk-badge mk-badge--ok' : 'mk-badge mk-badge--pending';
    }

    public function getViewUrl(int $id): string
    {
        return $this->getUrl('mytek_marketplace/reclamation/view', ['id' => $id]);
    }

    public function getFilterUrl(bool $unseen): string
    {
        return $this->getUrl('mytek_marketplace/reclamation/index', $unseen ? ['unseen' => 1] : []);
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
