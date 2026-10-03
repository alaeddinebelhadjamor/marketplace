<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Date de création d'une commande dans Magento (table annexe order_magento_dates). */
class OrderMagentoDate extends Model
{
    protected $table = 'order_magento_dates';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['order_id', 'ordered_at'];

    protected function casts(): array
    {
        return ['ordered_at' => 'datetime'];
    }
}
