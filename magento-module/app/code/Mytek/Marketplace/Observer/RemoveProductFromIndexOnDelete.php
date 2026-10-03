<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Mytek\Marketplace\Model\Search\MarketplaceIndexer;

/** catalog_product_delete_after : retire le produit supprimé de l'index marketplace. */
class RemoveProductFromIndexOnDelete implements ObserverInterface
{
    public function __construct(private readonly MarketplaceIndexer $indexer)
    {
    }

    public function execute(Observer $observer): void
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();
        $this->indexer->removeProduct((string)$product->getSku());
    }
}
