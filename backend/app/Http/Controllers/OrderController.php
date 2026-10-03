<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sales\SalesFilterRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Export\SellerSalesExport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/** Ventes du vendeur connecté. */
class OrderController extends Controller
{
    /** GET /api/orders : toutes les ventes du vendeur, les plus récentes d'abord. */
    public function index(SalesFilterRequest $request): AnonymousResourceCollection
    {
        return OrderResource::collection($this->query($request)->get());
    }

    /** GET /api/orders/export : export Excel filtré (SKU, période). */
    public function export(SalesFilterRequest $request, SellerSalesExport $export): Response
    {
        [$filename, $binary] = $export->build($this->query($request)->get(), $request->input('from'), $request->input('to'));

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function query(SalesFilterRequest $request)
    {
        $query = Order::query()->withEffectiveDate()
            ->where('orders.vendor_id', $request->user()->seller_id)
            ->effectiveBetween($request->input('from'), $request->input('to'))
            ->orderByDesc('effective_at')
            ->orderBy('orders.sku');

        if ($sku = trim((string) $request->input('sku'))) {
            $query->where('orders.sku', 'like', '%'.addcslashes($sku, '%_\\').'%');
        }

        return $query;
    }
}
