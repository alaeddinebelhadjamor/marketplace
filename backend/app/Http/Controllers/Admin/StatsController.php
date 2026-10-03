<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Http\Resources\SellerResource;
use App\Models\Order;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Données globales réservées à l'administration (clé x-admin-key). */
class StatsController extends Controller
{
    /** GET /api/stats/sellers */
    public function sellers(Request $request): JsonResponse
    {
        $sellers = Seller::query()->orderBy('seller_id')->get();

        return response()->json([
            'success' => true,
            'count' => $sellers->count(),
            'data' => SellerResource::collection($sellers)->resolve($request),
        ]);
    }

    /** GET /api/stats/orders */
    public function orders(Request $request): JsonResponse
    {
        $orders = Order::query()->withEffectiveDate()->orderByDesc('effective_at')->get();

        return response()->json([
            'success' => true,
            'count' => $orders->count(),
            'data' => OrderResource::collection($orders)->resolve($request),
        ]);
    }

    /** GET /api/stats/all */
    public function all(Request $request): JsonResponse
    {
        $sellers = Seller::query()->orderBy('seller_id')->get();
        $orders = Order::query()->withEffectiveDate()->orderByDesc('effective_at')->get();

        return response()->json([
            'success' => true,
            'sellersCount' => $sellers->count(),
            'ordersCount' => $orders->count(),
            'sellers' => SellerResource::collection($sellers)->resolve($request),
            'orders' => OrderResource::collection($orders)->resolve($request),
        ]);
    }
}
