<?php

namespace App\Services\Behavior;

use App\Services\Magento\MagentoClient;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Résout le slug d'une page .html en vue produit ou vue catégorie, par l'API
 * Magento (url_key).
 *
 * Améliorations v2 sur les limites citées au chapitre 4 : le cache survit aux
 * redémarrages (cache Laravel, 7 jours) et un échec n'est mémorisé qu'une heure,
 * puis retenté.
 */
class SlugResolver
{
    private const TTL = 604800;

    private const FAILURE_TTL = 3600;

    public function __construct(private readonly MagentoClient $magento) {}

    /** @return array{event_type: string, product_sku: ?string, category_id: ?int}|null */
    public function resolve(string $slug): ?array
    {
        $key = 'behavior:slug:'.sha1($slug);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'failed' ? null : $cached;
        }

        try {
            $product = $this->magento->get('products', $this->byUrlKey($slug));
            $items = $product->successful() ? ($product->json('items') ?? []) : null;
            if ($items === null) {
                throw new \RuntimeException('HTTP '.$product->status());
            }

            if ($items !== []) {
                $result = ['event_type' => 'product_view', 'product_sku' => $items[0]['sku'] ?? null, 'category_id' => null];
            } else {
                $categories = $this->magento->get('categories/list', $this->byUrlKey($slug));
                $categoryId = $categories->successful() ? ($categories->json('items.0.id')) : null;
                $result = ['event_type' => 'category_view', 'product_sku' => null, 'category_id' => $categoryId !== null ? (int) $categoryId : null];
            }

            Cache::put($key, $result, self::TTL);

            return $result;
        } catch (Throwable) {
            Cache::put($key, 'failed', self::FAILURE_TTL);

            return null;
        }
    }

    private function byUrlKey(string $slug): array
    {
        return [
            'searchCriteria[filter_groups][0][filters][0][field]' => 'url_key',
            'searchCriteria[filter_groups][0][filters][0][value]' => $slug,
            'searchCriteria[filter_groups][0][filters][0][condition_type]' => 'eq',
            'searchCriteria[pageSize]' => 1,
        ];
    }
}
