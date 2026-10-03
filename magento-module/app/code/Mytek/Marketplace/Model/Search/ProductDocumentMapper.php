<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Search;

use Magento\Catalog\Model\Product;

/**
 * Traduit un produit Magento vers le document attendu par la recherche de l'espace vendeur
 * (v2, `Services\Catalog\SellerCatalogService::fromOpenSearch`) : mêmes noms de champs,
 * mapping dynamique d'OpenSearch (aucun mapping explicite nécessaire — sku/name deviennent
 * automatiquement des champs text + sous-champ .keyword, seller_id/status/price des nombres).
 */
class ProductDocumentMapper
{
    /** @return array<string, mixed> */
    public static function toDocument(Product $product, int $sellerId): array
    {
        $special = $product->getData('special_price');
        return [
            'sku'               => (string)$product->getSku(),
            'name'              => (string)$product->getName(),
            'short_description' => (string)($product->getData('short_description') ?? ''),
            'url_key'           => $product->getData('url_key') !== null ? (string)$product->getData('url_key') : null,
            'price'             => (float)$product->getPrice(),
            'final_price'       => (float)$product->getFinalPrice(),
            'special_price'     => ($special !== null && $special !== '') ? (float)$special : null,
            'status'            => (int)$product->getStatus(),
            'image'             => $product->getData('image') !== null ? (string)$product->getData('image') : null,
            'seller_id'         => $sellerId,
        ];
    }
}
