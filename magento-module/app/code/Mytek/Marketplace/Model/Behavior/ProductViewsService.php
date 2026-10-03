<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Behavior;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Mytek\Marketplace\Model\SellerRepository;
use Mytek\Marketplace\Setup\Patch\Data\AddSellerIdAttribute;

/**
 * Produits les plus vus par vendeur (ch. 4 § 4.2.3/§ 4.6, table user_product_behavior),
 * amélioration L. La table vit dans la base marketplace, les produits dans Magento : aucune
 * jointure SQL entre les deux (impossible, bases distinctes) ; les SKU les plus vus sont lus
 * dans la base marketplace, puis résolus en une seule requête sur le catalogue Magento, et
 * corrélés en PHP.
 */
class ProductViewsService
{
    private const TABLE = 'user_product_behavior';
    private const EVENT_PRODUCT_VIEW = 'product_view';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly SellerRepository $sellers
    ) {
    }

    /**
     * @return array<int, array{sku:string, name:string, views:int, seller_id:?int, shop_title:?string}>
     */
    public function getTopViewed(int $limit = 10): array
    {
        $conn = $this->resource->getConnectionByName(SellerRepository::CONNECTION);
        // Marge : certains SKU vus par le passé peuvent avoir été supprimés du catalogue depuis.
        $rows = $conn->fetchAll(
            $conn->select()
                ->from(self::TABLE, ['product_sku', 'views' => new Expression('COUNT(*)')])
                ->where('event_type = ?', self::EVENT_PRODUCT_VIEW)
                ->where('product_sku IS NOT NULL')
                ->group('product_sku')
                ->order('views DESC')
                ->limit($limit * 4)
        );
        if (!$rows) {
            return [];
        }

        $skus = array_column($rows, 'product_sku');
        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect(['name', AddSellerIdAttribute::ATTRIBUTE_CODE]);
        $collection->addFieldToFilter('sku', ['in' => $skus]);
        $bySku = [];
        foreach ($collection as $product) {
            $bySku[$product->getSku()] = $product;
        }

        $sellerIds = [];
        foreach ($bySku as $product) {
            $sellerId = (int)$product->getData(AddSellerIdAttribute::ATTRIBUTE_CODE);
            if ($sellerId > 0) {
                $sellerIds[$sellerId] = $sellerId;
            }
        }
        $sellersById = $this->sellers->getByIds(array_values($sellerIds));

        $out = [];
        foreach ($rows as $row) {
            $product = $bySku[$row['product_sku']] ?? null;
            if (!$product) {
                continue; // produit supprimé depuis la vue
            }
            $sellerId = (int)$product->getData(AddSellerIdAttribute::ATTRIBUTE_CODE);
            $seller = $sellersById[$sellerId] ?? null;
            $out[] = [
                'sku'        => $row['product_sku'],
                'name'       => $product->getName(),
                'views'      => (int)$row['views'],
                'seller_id'  => $sellerId ?: null,
                'shop_title' => $seller['shop_title'] ?? null,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }
}
