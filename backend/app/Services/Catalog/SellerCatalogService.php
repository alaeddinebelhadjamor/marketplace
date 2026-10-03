<?php

namespace App\Services\Catalog;

use App\Services\Magento\MagentoProductService;
use App\Services\Search\OpenSearchService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Catalogue d'un vendeur vu depuis l'espace vendeur.
 *
 * Produits validés : OpenSearch d'abord (filtre seller_id + recherche
 * partielle), repli automatique sur l'API Magento si OpenSearch ou son index
 * est indisponible, sans erreur visible pour le vendeur (tab. 2.6).
 */
class SellerCatalogService
{
    public function __construct(
        private readonly OpenSearchService $search,
        private readonly MagentoProductService $products,
    ) {}

    public function validated(int $sellerId, int $page, int $pageSize, string $search = ''): array
    {
        try {
            return $this->fromOpenSearch($sellerId, $page, $pageSize, $search) + ['source' => 'opensearch'];
        } catch (Throwable $e) {
            Log::info('OpenSearch indisponible, repli sur l\'API Magento', ['error' => $e->getMessage()]);
        }

        return $this->products->listForSeller(MagentoProductService::STATUS_ENABLED, $sellerId, $page, $pageSize, $search)
            + ['source' => 'magento'];
    }

    public function pending(int $sellerId, int $page, int $pageSize, string $search = ''): array
    {
        return $this->products->listForSeller(MagentoProductService::STATUS_DISABLED, $sellerId, $page, $pageSize, $search)
            + ['source' => 'magento'];
    }

    private function fromOpenSearch(int $sellerId, int $page, int $pageSize, string $search): array
    {
        $query = ['bool' => ['filter' => [['term' => ['seller_id' => $sellerId]]]]];

        if ($search !== '') {
            $term = '*'.mb_strtolower($this->escapeWildcard($search)).'*';
            $query['bool']['must'] = [[
                'bool' => [
                    'should' => [
                        ['wildcard' => ['sku.keyword' => ['value' => $term, 'case_insensitive' => true]]],
                        ['wildcard' => ['name.keyword' => ['value' => $term, 'case_insensitive' => true]]],
                    ],
                    'minimum_should_match' => 1,
                ],
            ]];
        }

        $result = $this->search->search([
            'from' => ($page - 1) * $pageSize,
            'size' => $pageSize,
            'query' => $query,
            '_source' => ['sku', 'name', 'short_description', 'price', 'final_price', 'special_price', 'status', 'image', 'url_key'],
        ]);

        $magentoUrl = config('marketplace.magento.url');
        $products = collect($result['hits']['hits'])->map(function (array $hit) use ($magentoUrl) {
            $s = $hit['_source'] ?? [];
            $price = (float) ($s['price'] ?? 0);
            $special = isset($s['special_price']) && is_numeric($s['special_price']) ? (float) $s['special_price'] : null;

            return [
                'sku' => $s['sku'] ?? '',
                'name' => $s['name'] ?? '',
                'short_description' => $s['short_description'] ?? '',
                'url_key' => $s['url_key'] ?? null,
                'price' => $price,
                'special_price' => $special !== null && $special !== $price ? $special : null,
                'status' => (int) ($s['status'] ?? 1),
                'image' => ! empty($s['image']) ? $magentoUrl.'/media/catalog/product'.$s['image'] : null,
            ];
        })->values()->all();

        $total = (int) ($result['hits']['total']['value'] ?? count($products));

        return [
            'total' => $total,
            'totalPages' => (int) ceil($total / max(1, $pageSize)),
            'page' => $page,
            'pageSize' => $pageSize,
            'products' => $products,
        ];
    }

    /** Neutralise les jokers saisis par l'utilisateur dans une requête wildcard. */
    private function escapeWildcard(string $value): string
    {
        return addcslashes($value, '*?\\');
    }
}
