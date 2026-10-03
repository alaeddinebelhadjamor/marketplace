<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Ligne de vente. Mêmes champs que la v1, plus ordered_at (date Magento si
 * connue) et effective_at (date utilisée pour les filtres et graphiques).
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this->order_id,
            'sku' => $this->sku,
            'product_name' => $this->product_name,
            'qty' => (int) $this->qty,
            'price' => number_format((float) $this->price, 2, '.', ''),
            'vendor_id' => (int) $this->vendor_id,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'ordered_at' => $this->ordered_at ? Carbon::parse($this->ordered_at)->toIso8601String() : null,
            'effective_at' => $this->effectiveDate()?->toIso8601String(),
        ];
    }
}
