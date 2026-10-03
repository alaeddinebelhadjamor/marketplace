<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Seller;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Mytek\Marketplace\Model\SellerRepository;

/**
 * Petit bloc affiché sur la fiche produit : "Vendu par <boutique>", avec lien
 * vers la page boutique publique du vendeur (Mytek\Marketplace\Block\Seller\View).
 */
class Link extends Template
{
    /** @var array|null|false */
    private $seller;

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly SellerRepository $sellerRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSeller(): ?array
    {
        if ($this->seller === null) {
            $product = $this->registry->registry('current_product');
            $sellerId = $product ? (int)$product->getData('seller_id') : 0;
            $seller = $sellerId ? $this->sellerRepository->getById($sellerId) : null;
            $this->seller = ($seller && (int)$seller['status'] === SellerRepository::STATUS_VALIDATED)
                ? $seller
                : false;
        }
        return $this->seller ?: null;
    }

    public function getShopUrl(int $sellerId): string
    {
        return $this->getUrl('marketplace/seller/view', ['id' => $sellerId]);
    }
}
