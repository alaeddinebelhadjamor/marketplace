<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Mytek\Marketplace\Model\Search\MarketplaceIndexer;

/** catalog_product_save_after : reflète aussitôt une activation/désactivation (ch. 2 § 2.6.4). */
class ReindexProductOnSave implements ObserverInterface
{
    public function __construct(private readonly MarketplaceIndexer $indexer)
    {
    }

    public function execute(Observer $observer): void
    {
        /** @var Product $product */
        $product = $observer->getEvent()->getProduct();
        $this->indexer->indexProduct($product);
    }
}
