<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Catalog;

use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Mytek\Marketplace\Setup\Patch\Data\AddSellerIdAttribute;

/**
 * Statistiques du catalogue Magento côté vendeurs (attribut seller_id), sans jointure avec la
 * base marketplace : uniquement des requêtes sur le catalogue Magento lui-même.
 */
class SellerProductStats
{
    public function __construct(private readonly ProductCollectionFactory $productCollectionFactory)
    {
    }

    /**
     * Produits de vendeurs en attente de modération (désactivés, portant un seller_id) —
     * tab. 2.12 et fig. 3.12.
     */
    public function countAwaitingModeration(): int
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_DISABLED);
        $collection->addAttributeToFilter(AddSellerIdAttribute::ATTRIBUTE_CODE, ['gt' => 0]);
        return (int)$collection->getSize();
    }
}
