<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Search;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Mytek\Marketplace\Model\Config;
use Mytek\Marketplace\Setup\Patch\Data\AddSellerIdAttribute;
use Psr\Log\LoggerInterface;

/**
 * Index OpenSearch de la marketplace (amélioration J ; ch. 2 tab. 2.12, ch. 4 § 4.1), distinct
 * de l'index natif de Magento : c'est celui que lit l'espace vendeur v2
 * (OpenSearchService::latestIndex(), motif `opensearch_index_*`). Ne contient que les produits
 * actifs portant un vendeur (décision X1 de l'audit).
 *
 * - rebuildFull() : reconstruction complète dans un nouvel index horodaté, puis suppression des
 *   anciens — reproduit le même principe de bascule sans interruption que l'indexeur natif de
 *   Magento (magento2_product_1_v3 → v4), déjà observé sur ce projet.
 * - indexProduct()/removeProduct() : mise à jour incrémentale d'un seul produit, appelée par les
 *   observateurs catalog_product_save_after / catalog_product_delete_after, pour refléter une
 *   activation ou une désactivation « aussitôt » (ch. 2 § 2.6.4) sans attendre une reconstruction
 *   complète.
 */
class MarketplaceIndexer
{
    public function __construct(
        private readonly OpenSearchIndexClient $client,
        private readonly Config $config,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return array{index:string, count:int} */
    public function rebuildFull(): array
    {
        $newIndex = $this->config->getOpenSearchIndexPrefix() . '_' . date('YmdHis');

        $documents = [];
        foreach ($this->eligibleProductsCollection() as $product) {
            $sellerId = (int)$product->getData(AddSellerIdAttribute::ATTRIBUTE_CODE);
            $documents[$product->getSku()] = ProductDocumentMapper::toDocument($product, $sellerId);
        }

        $this->client->bulkIndex($newIndex, $documents);
        $this->client->refresh($newIndex);

        foreach ($this->client->listIndices() as $oldIndex) {
            if ($oldIndex !== $newIndex) {
                $this->client->deleteIndex($oldIndex);
            }
        }

        return ['index' => $newIndex, 'count' => count($documents)];
    }

    /** Appelé après l'enregistrement d'un produit : indexe ou retire selon son éligibilité. */
    public function indexProduct(Product $product): void
    {
        $sellerId = (int)$product->getData(AddSellerIdAttribute::ATTRIBUTE_CODE);
        $isEligible = $sellerId > 0 && (int)$product->getStatus() === ProductStatus::STATUS_ENABLED;

        $currentIndex = $this->currentIndex();
        if ($currentIndex === null) {
            return; // pas encore de reconstruction complète : rien à mettre à jour incrémentalement
        }

        try {
            if ($isEligible) {
                $this->client->indexDocument($currentIndex, $product->getSku(), ProductDocumentMapper::toDocument($product, $sellerId));
            } else {
                $this->client->deleteDocument($currentIndex, $product->getSku());
            }
        } catch (\Throwable $e) {
            // Une indexation incrémentale en échec ne doit jamais faire échouer l'enregistrement
            // du produit dans l'admin : seulement journalisée, rattrapée au prochain rebuild.
            $this->logger->error('Mytek_Marketplace : indexation OpenSearch incrémentale en échec', [
                'sku' => $product->getSku(), 'error' => $e->getMessage(),
            ]);
        }
    }

    public function removeProduct(string $sku): void
    {
        $currentIndex = $this->currentIndex();
        if ($currentIndex === null) {
            return;
        }
        try {
            $this->client->deleteDocument($currentIndex, $sku);
        } catch (\Throwable $e) {
            $this->logger->error('Mytek_Marketplace : retrait OpenSearch en échec', ['sku' => $sku, 'error' => $e->getMessage()]);
        }
    }

    private function currentIndex(): ?string
    {
        $indices = $this->client->listIndices();
        return $indices ? end($indices) : null;
    }

    private function eligibleProductsCollection(): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect([
            'name', 'short_description', 'price', 'special_price', 'url_key', 'image',
            AddSellerIdAttribute::ATTRIBUTE_CODE,
        ]);
        $collection->addAttributeToFilter('status', ProductStatus::STATUS_ENABLED);
        $collection->addAttributeToFilter(AddSellerIdAttribute::ATTRIBUTE_CODE, ['gt' => 0]);
        return $collection;
    }
}
