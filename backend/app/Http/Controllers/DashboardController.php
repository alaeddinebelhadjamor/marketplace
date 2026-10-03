<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sales\SalesFilterRequest;
use App\Services\Statistics\SellerDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/** Tableau de bord avancé du vendeur. */
class DashboardController extends Controller
{
    /** GET /api/dashboard?from=AAAA-MM-JJ&to=AAAA-MM-JJ (30 derniers jours par défaut) */
    public function show(SalesFilterRequest $request, SellerDashboardService $dashboard): JsonResponse
    {
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : now();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $to->copy()->subDays(29);

        if ($from->diffInDays($to) > 366) {
            return response()->json(['message' => 'La période ne peut pas dépasser un an.'], 400);
        }

        return response()->json($dashboard->build($request->user()->seller_id, $from, $to));
    }
}
