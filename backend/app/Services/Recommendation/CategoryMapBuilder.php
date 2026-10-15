<?php

namespace App\Services\Recommendation;

use App\Services\Magento\MagentoClient;
use RuntimeException;

/**
 * Construit le signal « catégorie » du moteur hybride : pour chaque produit
 * du catalogue Magento, l'ensemble de ses catégories et de leurs ancêtres
 * (racines techniques exclues). RecommendationService en déduit une affinité
 * de Jaccard entre deux produits : 1 dans la même feuille, une valeur
 * intermédiaire dans des catégories sœurs, 0 sans ancêtre commun.
 *
 * Ce signal ne dépend pas du volume de commandes : il garde des
 * recommandations cohérentes tant que le filtrage collaboratif manque de
 * données (démarrage à froid).
 */
class CategoryMapBuilder
{
    private const PAGE_SIZE = 200;

    /** Catégories techniques (« Root Catalog », « Default Category ») : partagées par tous. */
    private const MAX_TECHNICAL_LEVEL = 1;

    public function __construct(private readonly MagentoClient $magento) {}

    /** @return array{n_items: int, n_categories: int} */
    public function build(): array
    {
        $ancestors = $this->ancestorsByCategory();

        $map = [];
        $page = 1;
        do {
            $response = $this->magento->get('products', [
                'searchCriteria[pageSize]' => self::PAGE_SIZE,
                'searchCriteria[currentPage]' => $page,
                'fields' => 'items[sku,extension_attributes[category_links]],total_count',
            ]);
            if (! $response->successful()) {
                throw new RuntimeException('Lecture des produits Magento impossible (HTTP '.$response->status().').');
            }

            $items = $response->json('items') ?? [];
            foreach ($items as $item) {
                $set = [];
                foreach ($item['extension_attributes']['category_links'] ?? [] as $link) {
                    foreach ($ancestors[(int) $link['category_id']] ?? [] as $id) {
                        $set[$id] = true;
                    }
                }
                if ($set !== []) {
                    $map[$item['sku']] = array_keys($set);
                }
            }
            $total = (int) $response->json('total_count', 0);
        } while ($items !== [] && $page++ * self::PAGE_SIZE < $total);

        $path = base_path(config('recommender.models_path').'/item_categories.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode([
            'built_at' => now()->toIso8601String(),
            'n_items' => count($map),
            'categories' => $map,
        ]));

        return ['n_items' => count($map), 'n_categories' => count($ancestors)];
    }

    /**
     * Chemin de chaque catégorie (elle-même et ses ancêtres), depuis l'arbre Magento.
     *
     * @return array<int, int[]>
     */
    private function ancestorsByCategory(): array
    {
        $response = $this->magento->get('categories');
        if (! $response->successful()) {
            throw new RuntimeException('Lecture de l\'arbre des catégories Magento impossible (HTTP '.$response->status().').');
        }

        $paths = [];
        $walk = function (array $node, array $path) use (&$walk, &$paths): void {
            if ((int) ($node['level'] ?? 0) > self::MAX_TECHNICAL_LEVEL) {
                $path[] = (int) $node['id'];
                $paths[(int) $node['id']] = $path;
            }
            foreach ($node['children_data'] ?? [] as $child) {
                $walk($child, $path);
            }
        };
        $walk($response->json(), []);

        return $paths;
    }
}
