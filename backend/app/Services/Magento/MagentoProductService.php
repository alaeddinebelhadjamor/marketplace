<?php

namespace App\Services\Magento;

use App\Exceptions\ApiException;
use Illuminate\Support\Arr;

/**
 * Opérations sur les produits Magento d'un vendeur.
 * Le rattachement d'un produit à son vendeur repose sur l'attribut seller_id.
 */
class MagentoProductService
{
    public const STATUS_ENABLED = 1;

    public const STATUS_DISABLED = 2;

    public function __construct(private readonly MagentoClient $client) {}

    /** Produit par SKU, ou null s'il n'existe pas. */
    public function find(string $sku): ?array
    {
        $response = $this->client->get('products/'.rawurlencode($sku));

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new ApiException('Erreur Magento lors de la lecture du produit.', 502, ['error' => $response->json('message')]);
        }

        return $response->json();
    }

    /**
     * Crée un produit pour un vendeur. Le produit est toujours désactivé
     * (en attente de validation) et rattaché au vendeur, quoi que contienne
     * la requête.
     *
     * @throws ApiException 409 si le SKU ou le nom (clé d'URL) existe déjà
     */
    public function createForSeller(array $product, int $sellerId): array
    {
        $product = $this->withSellerId($product, $sellerId);
        $product['status'] = self::STATUS_DISABLED;

        if ($this->find((string) $product['sku']) !== null) {
            throw new ApiException('Un produit avec le même SKU existe déjà', 409);
        }

        $response = $this->client->post('products', ['product' => $product]);

        if (! $response->successful()) {
            $message = (string) $response->json('message', '');
            if (str_contains($message, 'URL key for specified store already exists')) {
                throw new ApiException('Un produit avec le même nom existe déjà', 409);
            }
            throw new ApiException('Erreur Magento', 502, ['error' => $message]);
        }

        return $response->json();
    }

    /**
     * Met à jour le prix et, si $touchPromotion, la promotion.
     * Une promotion à null est envoyée vide : Magento retire alors le prix
     * spécial (la v1 ne savait pas retirer une promotion).
     */
    public function updatePrice(string $sku, ?float $price, bool $touchPromotion, ?float $specialPrice, ?string $from, ?string $to): array
    {
        $payload = ['custom_attributes' => []];
        if ($price !== null) {
            $payload['price'] = $price;
        }
        if ($touchPromotion) {
            $promo = $specialPrice !== null;
            $payload['custom_attributes'][] = ['attribute_code' => 'special_price', 'value' => $promo ? (string) $specialPrice : ''];
            $payload['custom_attributes'][] = ['attribute_code' => 'special_from_date', 'value' => $promo ? (string) $from : ''];
            $payload['custom_attributes'][] = ['attribute_code' => 'special_to_date', 'value' => $promo ? (string) $to : ''];
        }

        $response = $this->client->put('products/'.rawurlencode($sku), ['product' => $payload]);

        if (! $response->successful()) {
            throw new ApiException('Erreur updatePrice', 502, ['error' => $response->json('message')]);
        }

        return $response->json();
    }

    public function delete(string $sku): void
    {
        $response = $this->client->delete('products/'.rawurlencode($sku));

        if (! $response->successful()) {
            throw new ApiException('Erreur suppression produit', 502, ['error' => $response->json('message')]);
        }
    }

    /**
     * Liste paginée des produits d'un vendeur filtrés par statut, via
     * searchCriteria (même requête que la v1).
     */
    public function listForSeller(int $status, int $sellerId, int $page, int $pageSize, string $search = ''): array
    {
        $params = [
            'searchCriteria[filter_groups][0][filters][0][field]' => 'status',
            'searchCriteria[filter_groups][0][filters][0][value]' => $status,
            'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'eq',
            'searchCriteria[filter_groups][1][filters][0][field]' => 'seller_id',
            'searchCriteria[filter_groups][1][filters][0][value]' => $sellerId,
            'searchCriteria[filter_groups][1][filters][0][condition_type]' => 'eq',
            'searchCriteria[pageSize]' => $pageSize,
            'searchCriteria[currentPage]' => $page,
        ];

        if ($search !== '') {
            $like = '%'.$search.'%';
            $params += [
                'searchCriteria[filter_groups][2][filters][0][field]' => 'sku',
                'searchCriteria[filter_groups][2][filters][0][value]' => $like,
                'searchCriteria[filter_groups][2][filters][0][condition_type]' => 'like',
                'searchCriteria[filter_groups][2][filters][1][field]' => 'name',
                'searchCriteria[filter_groups][2][filters][1][value]' => $like,
                'searchCriteria[filter_groups][2][filters][1][condition_type]' => 'like',
            ];
        }

        $response = $this->client->get('products', $params);
        if (! $response->successful()) {
            throw new ApiException('Erreur récupération produits', 502, ['error' => $response->json('message')]);
        }

        $total = (int) $response->json('total_count', 0);
        $products = collect($response->json('items', []))
            ->map(fn (array $p) => $this->format($p, $status))
            ->values()
            ->all();

        return [
            'total' => $total,
            'totalPages' => (int) ceil($total / max(1, $pageSize)),
            'page' => $page,
            'pageSize' => $pageSize,
            'products' => $products,
        ];
    }

    /**
     * Tous les produits désactivés ayant un seller_id (toutes pages).
     *
     * @return list<array>
     */
    public function allDisabledSellerProducts(): array
    {
        $all = [];
        $page = 1;
        $pageSize = 100;

        do {
            $response = $this->client->get('products', [
                'searchCriteria[filter_groups][0][filters][0][field]' => 'seller_id',
                'searchCriteria[filter_groups][0][filters][0][value]' => '*',
                'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'notnull',
                'searchCriteria[filter_groups][1][filters][0][field]' => 'status',
                'searchCriteria[filter_groups][1][filters][0][value]' => self::STATUS_DISABLED,
                'searchCriteria[filter_groups][1][filters][0][condition_type]' => 'eq',
                'searchCriteria[pageSize]' => $pageSize,
                'searchCriteria[currentPage]' => $page,
            ]);
            if (! $response->successful()) {
                throw new ApiException('Erreur récupération produits', 502, ['error' => $response->json('message')]);
            }
            $items = $response->json('items', []);
            $totalPages = (int) ceil(((int) $response->json('total_count', 0)) / $pageSize);
            $all = array_merge($all, $items);
            $page++;
        } while ($items !== [] && $page <= $totalPages);

        return $all;
    }

    public static function attribute(array $product, string $code): mixed
    {
        foreach ($product['custom_attributes'] ?? [] as $attr) {
            if (($attr['attribute_code'] ?? null) === $code) {
                return $attr['value'] ?? null;
            }
        }

        return null;
    }

    public static function sellerIdOf(array $product): ?int
    {
        $value = self::attribute($product, 'seller_id');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /** Format compact renvoyé à l'interface (identique à la v1). */
    public function format(array $p, ?int $status = null): array
    {
        $price = (float) ($p['price'] ?? 0);
        $special = self::attribute($p, 'special_price');
        $special = is_numeric($special) ? (float) $special : null;
        $image = Arr::get($p, 'media_gallery_entries.0.file');

        return [
            'sku' => $p['sku'] ?? '',
            'name' => $p['name'] ?? '',
            'short_description' => (string) (self::attribute($p, 'short_description') ?? ''),
            'url_key' => self::attribute($p, 'url_key'),
            'price' => $price,
            'special_price' => $special !== null && $special !== $price ? $special : null,
            'special_from_date' => self::attribute($p, 'special_from_date'),
            'special_to_date' => self::attribute($p, 'special_to_date'),
            'status' => $status ?? (int) ($p['status'] ?? self::STATUS_ENABLED),
            'image' => $image ? config('marketplace.magento.url').'/media/catalog/product'.$image : null,
        ];
    }

    private function withSellerId(array $product, int $sellerId): array
    {
        $attributes = array_values(array_filter(
            $product['custom_attributes'] ?? [],
            fn ($a) => ($a['attribute_code'] ?? null) !== 'seller_id'
        ));
        $attributes[] = ['attribute_code' => 'seller_id', 'value' => $sellerId];
        $product['custom_attributes'] = $attributes;

        return $product;
    }
}
