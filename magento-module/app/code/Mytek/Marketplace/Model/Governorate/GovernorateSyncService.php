<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Governorate;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Action as ProductAction;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\Store;
use Mytek\Marketplace\Model\SellerRepository;
use Mytek\Marketplace\Setup\Patch\Data\AddSellerGovernorateAttribute;

/**
 * Tient à jour l'attribut produit seller_governorate : rempli pour les produits existants,
 * mis à jour quand un vendeur change de gouvernorat ou qu'un produit change de vendeur
 * (amélioration B). Deux requêtes séparées (base marketplace, catalogue Magento), corrélées en
 * PHP : aucune jointure SQL entre les deux bases.
 */
class GovernorateSyncService
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly ProductAction $productAction,
        private readonly EavConfig $eavConfig
    ) {
    }

    /** @return array{checked:int, updated:int} */
    public function sync(): array
    {
        $sellerGovernorates = $this->sellerGovernorates();
        $optionIdByLabel = $this->optionIdByLabel();
        $products = $this->currentProductStates();

        $diff = GovernorateDiff::compute($sellerGovernorates, $optionIdByLabel, $products);

        foreach ($diff['toSet'] as $optionId => $productIds) {
            $this->productAction->updateAttributes($productIds, [
                AddSellerGovernorateAttribute::ATTRIBUTE_CODE => $optionId,
            ], Store::DEFAULT_STORE_ID);
        }
        if ($diff['toClear']) {
            $this->productAction->updateAttributes($diff['toClear'], [
                AddSellerGovernorateAttribute::ATTRIBUTE_CODE => null,
            ], Store::DEFAULT_STORE_ID);
        }

        return ['checked' => $diff['checked'], 'updated' => $diff['updated']];
    }

    /** @return array<int, string> seller_id => gouvernorat (vendeurs ayant un gouvernorat renseigné) */
    private function sellerGovernorates(): array
    {
        $conn = $this->resource->getConnectionByName(SellerRepository::CONNECTION);
        return $conn->fetchPairs(
            $conn->select()->from('marketplace_seller', ['seller_id', 'governorate'])
                ->where('governorate IS NOT NULL')
                ->where('governorate != ?', '')
        );
    }

    /** @return array<string, int> libellé du gouvernorat => option_id de l'attribut */
    private function optionIdByLabel(): array
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, AddSellerGovernorateAttribute::ATTRIBUTE_CODE);
        $byLabel = [];
        foreach ($attribute->getOptions() as $option) {
            $value = $option->getValue();
            if ($value === '' || $value === null) {
                continue; // option vide ("— Sélectionner —") ajoutée automatiquement par Magento
            }
            $byLabel[(string)$option->getLabel()] = (int)$value;
        }
        return $byLabel;
    }

    /**
     * @return array<int, array{seller_id:int, current_option_id:?int}> product_id => état actuel,
     *         pour les produits portant un seller_id (seuls concernés par ce suivi)
     */
    private function currentProductStates(): array
    {
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['seller_id', AddSellerGovernorateAttribute::ATTRIBUTE_CODE]);
        $collection->addAttributeToFilter('seller_id', ['gt' => 0]);

        $states = [];
        foreach ($collection as $product) {
            $current = $product->getData(AddSellerGovernorateAttribute::ATTRIBUTE_CODE);
            $states[(int)$product->getId()] = [
                'seller_id'         => (int)$product->getData('seller_id'),
                'current_option_id' => $current !== null && $current !== '' ? (int)$current : null,
            ];
        }
        return $states;
    }
}
