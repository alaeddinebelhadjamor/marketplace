<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ligne de vente synchronisée depuis Magento (table existante orders).
 *
 * Clé primaire composite (order_id, sku) : Eloquent ne gère pas les clés
 * composites, les lignes sont donc créées et lues, jamais mises à jour par modèle.
 * vendor_id désigne marketplace_seller.seller_id par convention, sans clé étrangère.
 */
class Order extends Model
{
    use HasFactory;

    protected $table = 'orders';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['order_id', 'sku', 'product_name', 'qty', 'price', 'vendor_id', 'processed_at'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'vendor_id' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Ajoute la colonne calculée effective_at : date de la commande Magento si
     * elle est connue, sinon date de synchronisation (processed_at).
     */
    public function scopeWithEffectiveDate(Builder $query): Builder
    {
        return $query
            ->leftJoin('order_magento_dates as omd', 'omd.order_id', '=', 'orders.order_id')
            ->select('orders.*', 'omd.ordered_at')
            ->selectRaw('COALESCE(omd.ordered_at, orders.processed_at) as effective_at');
    }

    /** Filtre sur la date effective, bornes incluses (dates AAAA-MM-JJ). */
    public function scopeEffectiveBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        $expr = 'COALESCE(omd.ordered_at, orders.processed_at)';
        if ($from) {
            $query->whereRaw("{$expr} >= ?", [$from.' 00:00:00']);
        }
        if ($to) {
            $query->whereRaw("{$expr} <= ?", [$to.' 23:59:59']);
        }

        return $query;
    }

    public function effectiveDate(): ?CarbonInterface
    {
        $value = $this->getAttribute('effective_at') ?? $this->processed_at;

        return $value ? Carbon::parse($value) : null;
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'vendor_id', 'seller_id');
    }

    /** Montant de la ligne : prix unitaire × quantité (même règle que la v1). */
    public function lineTotal(): float
    {
        return round((float) $this->price * (int) $this->qty, 2);
    }
}
