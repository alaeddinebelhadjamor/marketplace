<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Import de produits en masse et son résultat ligne par ligne. */
class ProductImport extends Model
{
    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = [
        'seller_id', 'original_name', 'stored_path', 'status', 'total_rows',
        'success_rows', 'failed_rows', 'errors', 'failure_reason', 'finished_at',
    ];

    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return [
            'seller_id' => 'integer',
            'total_rows' => 'integer',
            'success_rows' => 'integer',
            'failed_rows' => 'integer',
            'errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'seller_id', 'seller_id');
    }
}
