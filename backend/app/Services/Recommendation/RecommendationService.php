<?php

namespace App\Services\Recommendation;

use App\Models\UserProductBehavior;
use App\Services\Magento\MagentoProductService;
use App\Services\Search\OpenSearchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Port du moteur de recommandation hybride de la v1
 * (marketplace-backend/services/recommendation.service.js) : mêmes signaux
 * (collaboratif ALS + sémantique contenu + co-visite de session +
 * popularité), même cascade de repli, même diversité epsilon-greedy. Seules
 * les couches d'accès changent (Eloquent au lieu de Sequelize, cache
 * Laravel partagé entre requêtes au lieu d'une variable de module Node
 * tenue en mémoire par un process persistant).
 */
class RecommendationService
{
    public function __construct(
        private readonly OpenSearchService $openSearch,
        private readonly MagentoProductService $magentoProducts,
    ) {}

    /**
     * Recommande jusqu'à topK SKUs liés à $sku. Cascade de signaux, du plus
     * spécifique au plus générique : hybride (ALS + contenu) → co-visite de
     * session → popularité globale (garantit toujours un résultat).
     */
    public function recommendForSku(string $sku, int $topK = 10): array
    {
        ['co_view' => $coView, 'popularity' => $popularity] = $this->coViewAndPopularity();

        $poolSize = $topK * (int) config('recommender.pool_factor');

        $pool = $this->hybridNeighbors($sku, $poolSize);
        $seen = array_flip([$sku, ...array_column($pool, 'sku')]);

        if (count($pool) < $poolSize) {
            $row = $coView[$sku] ?? [];
            arsort($row);
            foreach ($row as $candidateSku => $score) {
                if (isset($seen[$candidateSku])) {
                    continue;
                }
                $pool[] = ['sku' => $candidateSku, 'score' => $score, 'source' => 'co_view', 'detail' => null];
                $seen[$candidateSku] = true;
                if (count($pool) >= $poolSize) {
                    break;
                }
            }
        }

        if (count($pool) < $poolSize) {
            foreach ($popularity as $p) {
                if (isset($seen[$p['sku']])) {
                    continue;
                }
                $pool[] = ['sku' => $p['sku'], 'score' => $p['views'], 'source' => 'popularity', 'detail' => null];
                $seen[$p['sku']] = true;
                if (count($pool) >= $poolSize) {
                    break;
                }
            }
        }

        $primary = array_slice($pool, 0, $topK);

        return $this->applyDiversity($primary, $pool, (float) config('recommender.diversity_epsilon'));
    }

    /** Produits les plus consultés récemment (page d'accueil, cold start total). */
    public function getPopularProducts(int $topK = 10): array
    {
        ['popularity' => $popularity] = $this->coViewAndPopularity();

        return array_map(
            static fn ($p) => ['sku' => $p['sku'], 'score' => $p['views'], 'source' => 'popularity', 'detail' => null],
            array_slice($popularity, 0, $topK)
        );
    }

    /**
     * Enrichit une liste de SKUs (nom, prix, image, url). Chemin principal
     * OpenSearch (requête groupée), repli Magento REST produit par produit
     * si l'index est indisponible — même stratégie que SellerCatalogService.
     */
    public function enrichWithProductData(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        try {
            $result = $this->openSearch->search([
                'size' => count($skus),
                'query' => ['bool' => ['filter' => [
                    ['terms' => ['sku.keyword' => array_values($skus)]],
                    ['term' => ['status' => 1]],
                ]]],
                '_source' => ['sku', 'name', 'price', 'special_price', 'url_key', 'image'],
            ]);

            $bySku = [];
            foreach ($result['hits']['hits'] ?? [] as $hit) {
                $s = $hit['_source'] ?? [];
                if (isset($s['sku'])) {
                    $bySku[$s['sku']] = $s;
                }
            }

            if ($bySku !== []) {
                $magentoUrl = config('marketplace.magento.url');

                return collect($skus)
                    ->filter(fn ($sku) => isset($bySku[$sku]))
                    ->map(function ($sku) use ($bySku, $magentoUrl) {
                        $s = $bySku[$sku];
                        $price = (float) ($s['price'] ?? 0);
                        $special = isset($s['special_price']) && is_numeric($s['special_price']) ? (float) $s['special_price'] : null;

                        return [
                            'sku' => $s['sku'],
                            'name' => $s['name'] ?? '',
                            'price' => $price,
                            'special_price' => $special !== null && $special !== $price ? $special : null,
                            'url_key' => $s['url_key'] ?? null,
                            'image' => ! empty($s['image']) ? $magentoUrl.'/media/catalog/product'.$s['image'] : null,
                        ];
                    })->values()->all();
            }
        } catch (Throwable $e) {
            Log::info('OpenSearch indisponible pour l\'enrichissement recommandation, repli Magento', ['error' => $e->getMessage()]);
        }

        $results = [];
        foreach ($skus as $sku) {
            $p = $this->magentoProducts->find($sku);
            if ($p === null) {
                continue;
            }
            $results[] = $this->magentoProducts->format($p);
        }

        return $results;
    }

    // ───────────────────────────────────────────────────────────────────

    /** Classement hybride : fusionne collaboratif + contenu pour $sku. */
    private function hybridNeighbors(string $sku, int $topK): array
    {
        $mfSims = $this->similarityMap($this->loadVectorModel('item_factors.json', 'item_factors'), $sku);
        $contentSims = $this->similarityMap($this->loadVectorModel('content_embeddings.json', 'embeddings'), $sku);

        if ($mfSims === null && $contentSims === null) {
            return [];
        }

        $candidates = array_unique([...array_keys($mfSims ?? []), ...array_keys($contentSims ?? [])]);
        $candidates = array_values(array_filter($candidates, static fn ($c) => $c !== $sku));

        $wCollaborative = (float) config('recommender.w_collaborative');
        $wContent = (float) config('recommender.w_content');

        $ranked = array_map(static function ($candSku) use ($mfSims, $contentSims, $wCollaborative, $wContent) {
            $mfScore = $mfSims[$candSku] ?? 0.0;
            $contentScore = $contentSims[$candSku] ?? 0.0;

            return [
                'sku' => $candSku,
                'score' => $wCollaborative * $mfScore + $wContent * $contentScore,
                'source' => 'hybrid',
                'detail' => [
                    'matrix_factorization' => isset($mfSims[$candSku]) ? $mfScore : null,
                    'content' => isset($contentSims[$candSku]) ? $contentScore : null,
                ],
            ];
        }, $candidates);

        usort($ranked, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($ranked, 0, $topK);
    }

    /** Similarité cosinus de $sku à tous les autres SKUs connus du modèle donné (ou null si absent). */
    private function similarityMap(?array $model, string $sku): ?array
    {
        if ($model === null || ! isset($model['sku_to_index'][$sku])) {
            return null;
        }

        $idx = $model['sku_to_index'][$sku];
        $vec = $model['vectors'][$idx];
        $norm = $model['norms'][$idx];

        $map = [];
        foreach ($model['vectors'] as $i => $other) {
            if ($i === $idx) {
                continue;
            }
            $map[$model['skus'][$i]] = $this->cosine($vec, $other, $norm, $model['norms'][$i]);
        }

        return $map;
    }

    private function cosine(array $a, array $b, float $normA, float $normB): float
    {
        $dot = 0.0;
        foreach ($a as $i => $value) {
            $dot += $value * $b[$i];
        }

        return $dot / ($normA * $normB);
    }

    /**
     * Charge un modèle vectoriel (collaboratif ou contenu) depuis le disque.
     * La clé de cache inclut la date de modification du fichier : un
     * ré-entraînement invalide automatiquement le cache à la requête
     * suivante, sans mécanisme de rechargement à chaud explicite (inutile
     * ici, contrairement à la v1 où le modèle restait en mémoire d'un
     * process Node persistant).
     */
    private function loadVectorModel(string $fileName, string $vectorsKey): ?array
    {
        $path = base_path(config('recommender.models_path').'/'.$fileName);
        if (! is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);
        $ttl = (int) config('recommender.model_cache_ttl_seconds');

        return Cache::remember("recommender:model:{$fileName}:{$mtime}", now()->addSeconds($ttl), function () use ($path, $vectorsKey) {
            $parsed = json_decode((string) file_get_contents($path), true);
            if (! is_array($parsed) || ! isset($parsed[$vectorsKey], $parsed['skus'])) {
                return null;
            }

            $vectors = $parsed[$vectorsKey];
            $skuToIndex = array_flip($parsed['skus']);
            $norms = array_map(
                static fn ($v) => sqrt(array_sum(array_map(static fn ($x) => $x * $x, $v))) ?: 1e-8,
                $vectors
            );

            return ['skus' => $parsed['skus'], 'vectors' => $vectors, 'sku_to_index' => $skuToIndex, 'norms' => $norms];
        });
    }

    /**
     * Reconstruit, si le cache a expiré, la matrice de co-visite SKU↔SKU et
     * le classement de popularité à partir de user_product_behavior : même
     * fenêtre glissante et même pondération par temps de présence estimé
     * (dwell time, écart avec l'évènement suivant de la même session) que
     * la v1.
     */
    private function coViewAndPopularity(): array
    {
        $ttl = (int) config('recommender.behavior_cache_ttl_seconds');

        return Cache::remember('recommender:co_view_popularity', now()->addSeconds($ttl), function () {
            $since = now()->subDays((int) config('recommender.window_days'));

            $events = UserProductBehavior::query()
                ->where('event_type', 'product_view')
                ->whereNotNull('product_sku')
                ->where('created_at', '>=', $since)
                ->orderBy('session_id')
                ->orderBy('created_at')
                ->limit((int) config('recommender.max_events'))
                ->get(['session_id', 'product_sku', 'created_at']);

            $bySession = [];
            foreach ($events as $event) {
                if (! $event->session_id) {
                    continue;
                }
                $bySession[$event->session_id][] = $event;
            }

            $coView = [];
            $bump = static function (string $a, string $b, float $weight) use (&$coView): void {
                $coView[$a][$b] = ($coView[$a][$b] ?? 0.0) + $weight;
            };

            $popularityWeights = [];

            foreach ($bySession as $sessionEvents) {
                $weightBySku = [];
                $count = count($sessionEvents);
                for ($i = 0; $i < $count; $i++) {
                    $sku = $sessionEvents[$i]->product_sku;
                    $next = $sessionEvents[$i + 1] ?? null;
                    $dwellSeconds = $next
                        ? $sessionEvents[$i]->created_at->diffInSeconds($next->created_at)
                        : (int) config('recommender.default_dwell_s');
                    $weightBySku[$sku] = ($weightBySku[$sku] ?? 0.0) + $this->dwellWeight($dwellSeconds);
                }

                $distinct = array_keys($weightBySku);
                $n = count($distinct);
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        $pairWeight = ($weightBySku[$distinct[$i]] + $weightBySku[$distinct[$j]]) / 2;
                        $bump($distinct[$i], $distinct[$j], $pairWeight);
                        $bump($distinct[$j], $distinct[$i], $pairWeight);
                    }
                }

                foreach ($weightBySku as $sku => $weight) {
                    $popularityWeights[$sku] = ($popularityWeights[$sku] ?? 0.0) + $weight;
                }
            }

            arsort($popularityWeights);
            $popularity = [];
            foreach ($popularityWeights as $sku => $views) {
                $popularity[] = ['sku' => $sku, 'views' => $views];
            }

            return ['co_view' => $coView, 'popularity' => $popularity];
        });
    }

    /** Poids d'engagement normalisé (1.0 = temps « neutre ») à partir d'un temps estimé en secondes. */
    private function dwellWeight(int|float $seconds): float
    {
        $clamped = min(max($seconds, (int) config('recommender.min_dwell_s')), (int) config('recommender.max_dwell_s'));

        return $clamped / (int) config('recommender.default_dwell_s');
    }

    /**
     * Mélange aléatoirement (epsilon-greedy) une partie du classement final
     * avec d'autres candidats du vivier, pour limiter l'effet « les produits
     * déjà populaires le restent ». Implémenté indépendamment, inspiré du
     * principe d'exploration epsilon-greedy classique en bandits/recommandation.
     */
    private function applyDiversity(array $primary, array $pool, float $epsilon): array
    {
        $used = array_flip(array_column($primary, 'sku'));
        $extras = array_values(array_filter($pool, static fn ($c) => ! isset($used[$c['sku']])));

        if ($extras === []) {
            return $primary;
        }

        $result = array_values($primary);
        for ($i = 0; $i < count($result) && $extras !== []; $i++) {
            if (mt_rand() / mt_getrandmax() >= $epsilon) {
                continue;
            }
            $pickIdx = array_rand($extras);
            $replacement = $extras[$pickIdx];
            array_splice($extras, $pickIdx, 1);
            unset($used[$result[$i]['sku']]);
            $result[$i] = $replacement;
            $used[$replacement['sku']] = true;
        }

        return $result;
    }
}
