<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Relevé mensuel de paiement d'un vendeur (CA brut, commission, net à verser). */
class PayoutStatement extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    protected $table = 'payout_statements';

    protected $fillable = [
        'seller_id', 'period', 'orders_count', 'items_qty', 'gross',
        'commission_rate', 'commission', 'net', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'seller_id' => 'integer',
            'orders_count' => 'integer',
            'items_qty' => 'integer',
            'gross' => 'float',
            'commission_rate' => 'float',
            'commission' => 'float',
            'net' => 'float',
            'paid_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id', 'seller_id');
    }
}
