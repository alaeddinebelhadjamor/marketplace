<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Adminhtml\Seller;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Mytek\Marketplace\Model\SellerRepository;

class Form extends Template
{
    private ?array $seller = null;

    public function __construct(
        Context $context,
        private readonly SellerRepository $sellers,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSeller(): array
    {
        if ($this->seller === null) {
            $id = (int)$this->getRequest()->getParam('id');
            $this->seller = $this->sellers->getById($id) ?? [];
        }
        return $this->seller;
    }

    public function value(string $field): string
    {
        return (string)($this->getSeller()[$field] ?? '');
    }

    public function statusLabel(): string
    {
        return SellerRepository::statusLabel((int)($this->getSeller()['status'] ?? 0));
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('mytek_marketplace/seller/save', ['id' => (int)($this->getSeller()['seller_id'] ?? 0)]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('mytek_marketplace/seller/index');
    }

    /** Gouvernorats de Tunisie pour la liste déroulante */
    public function getGovernorates(): array
    {
        return [
            'Ariana', 'Béja', 'Ben Arous', 'Bizerte', 'Gabès', 'Gafsa', 'Jendouba', 'Kairouan',
            'Kasserine', 'Kébili', 'Le Kef', 'Mahdia', 'La Manouba', 'Médenine', 'Monastir', 'Nabeul',
            'Sfax', 'Sidi Bouzid', 'Siliana', 'Sousse', 'Tataouine', 'Tozeur', 'Tunis', 'Zaghouan',
        ];
    }
}
