<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Taux de commission propre à un vendeur (remplace le taux par défaut). */
class CommissionRate extends Model
{
    protected $table = 'commission_rates';

    protected $primaryKey = 'seller_id';

    public $incrementing = false;

    protected $fillable = ['seller_id', 'rate'];

    protected function casts(): array
    {
        return ['seller_id' => 'integer', 'rate' => 'float'];
    }
}
