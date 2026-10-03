<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\Products\ListProductsRequest;
use App\Http\Requests\Products\SkuRequest;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdatePriceRequest;
use App\Models\ProduitConsulte;
use App\Models\Seller;
use App\Services\Catalog\SellerCatalogService;
use App\Services\Magento\MagentoProductService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Produits du vendeur (pont vers l'API Magento et OpenSearch). */
class ProductController extends Controller
{
    public function __construct(
        private readonly MagentoProductService $products,
        private readonly SellerCatalogService $catalog,
    ) {}

    /** POST /api/magento/product/add */
    public function store(StoreProductRequest $request): JsonResponse
    {
        $seller = $request->user();
        $created = $this->products->createForSeller($request->product(), $seller->seller_id);

        Audit::log('product_submitted', 'Soumission d\'un produit', $seller, null, ['sku' => $created['sku'] ?? null]);

        return response()->json(['message' => 'Produit ajouté avec succès', 'product' => $created], 201);
    }

    /** GET /api/magento/products : produits validés du vendeur. */
    public function index(ListProductsRequest $request): JsonResponse
    {
        return response()->json($this->catalog->validated(
            $request->user()->seller_id, $request->page(), $request->pageSize(), $request->search()
        ));
    }

    /** GET /api/magento/products/pending : produits en attente de validation. */
    public function pending(ListProductsRequest $request): JsonResponse
    {
        return response()->json($this->catalog->pending(
            $request->user()->seller_id, $request->page(), $request->pageSize(), $request->search()
        ));
    }

    /** PUT /api/magento/product/price */
    public function updatePrice(UpdatePriceRequest $request): JsonResponse
    {
        $seller = $request->user();
        $product = $this->ownedProduct($request->input('sku'));
        Gate::forUser($seller)->authorize('update-product', [$product]);

        $special = $request->filled('special_price') ? (float) $request->input('special_price') : null;
        $data = $this->products->updatePrice(
            $product['sku'],
            $request->filled('price') ? (float) $request->input('price') : null,
            $request->touchesPromotion(),
            $special,
            $request->input('special_from_date'),
            $request->input('special_to_date'),
        );

        Audit::log('product_price_updated', 'Modification du prix', $seller, null, [
            'sku' => $product['sku'],
            'old_price' => $product['price'] ?? null,
            'new_price' => $request->input('price'),
            'special_price' => $special,
        ]);

        return response()->json(['message' => 'Prix mis à jour avec succès', 'data' => $data]);
    }

    /** DELETE /api/magento/product/delete */
    public function destroy(SkuRequest $request): JsonResponse
    {
        $seller = $request->user();
        $product = $this->ownedProduct($request->sku());
        Gate::forUser($seller)->authorize('delete-product', [$product]);

        $this->products->delete($product['sku']);

        Audit::log('product_deleted', 'Suppression d\'un produit', $seller, null, ['sku' => $product['sku'], 'name' => $product['name'] ?? null]);

        return response()->json(['message' => 'Produit supprimé avec succès']);
    }

    /** GET /api/magento/products/url-key?sku= */
    public function urlKey(SkuRequest $request): JsonResponse
    {
        $product = $this->ownedProduct($request->sku());
        Gate::forUser($request->user())->authorize('view-product', [$product]);
        $urlKey = MagentoProductService::attribute($product, 'url_key');

        return response()->json([
            'sku' => $product['sku'],
            'url_key' => $urlKey,
            'url' => $urlKey ? config('marketplace.storefront_url').'/'.$urlKey.'.html' : null,
        ]);
    }

    /**
     * GET /api/magento/disabled-seller-products (clé admin)
     * Produits désactivés des vendeurs, hors ceux déjà consultés, enrichis du vendeur.
     */
    public function disabledSellerProducts(): JsonResponse
    {
        $seen = ProduitConsulte::query()->pluck('id_produit')->map(fn ($id) => (int) $id)->flip();

        $products = collect($this->products->allDisabledSellerProducts())
            ->filter(fn (array $p) => ($id = (int) ($p['id'] ?? $p['entity_id'] ?? 0)) > 0 && ! $seen->has($id))
            ->values();

        $sellerIds = $products->map(fn (array $p) => MagentoProductService::sellerIdOf($p))->filter()->unique();
        $sellers = Seller::query()->whereIn('seller_id', $sellerIds)->get(['seller_id', 'shop_title', 'firstname', 'lastname'])->keyBy('seller_id');

        $enriched = $products->map(function (array $p) use ($sellers) {
            $sellerId = MagentoProductService::sellerIdOf($p);
            $s = $sellerId ? $sellers->get($sellerId) : null;

            return array_merge($p, [
                'seller_id' => $sellerId,
                'shop_title' => $s?->shop_title,
                'firstname' => $s?->firstname,
                'lastname' => $s?->lastname,
            ]);
        })->all();

        return response()->json(['total' => count($enriched), 'products' => $enriched]);
    }

    /** POST /api/magento/produits-consultes (clé admin) */
    public function markConsulted(Request $request): JsonResponse
    {
        if (! $request->filled('id_produit')) {
            throw new ApiException('id_produit obligatoire', 400);
        }
        $request->validate(['id_produit' => ['integer', 'min:1']]);

        ProduitConsulte::query()->insertOrIgnore(['id_produit' => (int) $request->input('id_produit')]);

        return response()->json(['message' => 'Produit ajouté dans produits_consultes'], 201);
    }

    private function ownedProduct(string $sku): array
    {
        $product = $this->products->find($sku);
        if ($product === null) {
            throw new ApiException('Produit introuvable', 404);
        }

        return $product;
    }
}
