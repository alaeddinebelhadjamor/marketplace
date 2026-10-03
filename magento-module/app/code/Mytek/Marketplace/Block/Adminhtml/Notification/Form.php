<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Adminhtml\Notification;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Mytek\Marketplace\Model\SellerRepository;

class Form extends Template
{
    /** Limites de l'API v2 (config('marketplace.attachments')), à titre indicatif côté écran. */
    public const MAX_FILES = 10;
    public const ACCEPTED_EXTENSIONS = '.jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.doc,.docx,.xls,.xlsx,.csv,.wav,.mp3';

    public function __construct(
        Context $context,
        private readonly SellerRepository $sellers,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** Vendeurs validés : seuls eux peuvent se connecter et voir la notification. */
    public function getSellers(): array
    {
        return $this->sellers->getList(null, SellerRepository::STATUS_VALIDATED);
    }

    public function getSendUrl(): string
    {
        return $this->getUrl('mytek_marketplace/notification/send');
    }
}
