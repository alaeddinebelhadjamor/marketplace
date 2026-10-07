<?php

namespace App\Http\Controllers;

use App\Services\Recommendation\RecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Port du contrôleur de recommandation de la v1. Public (pas d'authentification),
 * destiné au storefront Magento (widget « produits similaires » sur une fiche
 * produit), au même titre que les autres routes publiques de catalogue.
 */
class RecommendationController extends Controller
{
    public function __construct(private readonly RecommendationService $recommendations) {}

    /** GET /api/recommendations?sku=&top_k= */
    public function forProduct(Request $request): JsonResponse
    {
        $sku = trim((string) $request->query('sku', ''));
        $topK = min(max((int) $request->query('top_k', 10), 1), 50);

        if ($sku === '') {
            return response()->json(['message' => 'Paramètre sku obligatoire'], 400);
        }

        $ranked = $this->recommendations->recommendForSku($sku, $topK);
        $products = $this->recommendations->enrichWithProductData(array_column($ranked, 'sku'));
        $bySku = collect($products)->keyBy('sku');

        $results = collect($ranked)
            ->filter(fn ($r) => $bySku->has($r['sku']))
            ->map(fn ($r) => [...$bySku->get($r['sku']), 'score' => $r['score'], 'source' => $r['source'], 'detail' => $r['detail'] ?? null])
            ->values();

        return response()->json(['sku' => $sku, 'count' => $results->count(), 'results' => $results]);
    }

    /** GET /api/recommendations/popular?top_k= */
    public function popular(Request $request): JsonResponse
    {
        $topK = min(max((int) $request->query('top_k', 10), 1), 50);

        $ranked = $this->recommendations->getPopularProducts($topK);
        $products = $this->recommendations->enrichWithProductData(array_column($ranked, 'sku'));
        $bySku = collect($products)->keyBy('sku');

        $results = collect($ranked)
            ->filter(fn ($r) => $bySku->has($r['sku']))
            ->map(fn ($r) => [...$bySku->get($r['sku']), 'score' => $r['score'], 'source' => $r['source']])
            ->values();

        return response()->json(['count' => $results->count(), 'results' => $results]);
    }
}
