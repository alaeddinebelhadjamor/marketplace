<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Secret TOTP et codes de secours d'un vendeur, chiffrés en base. */
class SellerTwoFactor extends Model
{
    protected $table = 'seller_two_factor';

    protected $primaryKey = 'seller_id';

    public $incrementing = false;

    protected $fillable = ['seller_id', 'secret', 'recovery_codes', 'confirmed_at'];

    protected $hidden = ['secret', 'recovery_codes'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id', 'seller_id');
    }
}
