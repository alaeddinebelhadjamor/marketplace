<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Événement de navigation extrait du journal Apache (table existante user_product_behavior). */
class UserProductBehavior extends Model
{
    protected $table = 'user_product_behavior';

    const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'anonymous_id', 'session_id', 'product_sku', 'event_type',
        'category_id', 'search_query', 'source', 'device_type', 'created_at',
    ];
}
