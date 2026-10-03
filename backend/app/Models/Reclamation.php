<?php

namespace App\Models;

use App\Enums\ReclamationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réclamation ou notification (table existante reclamations).
 * type : 0 notification, 1 réclamation ouverte, 2 réclamation résolue.
 */
class Reclamation extends Model
{
    use HasFactory;

    protected $table = 'reclamations';

    protected $fillable = ['vendeur_id', 'type', 'vendeur_viewed', 'admin_viewed'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'vendeur_id' => 'integer',
            'type' => 'integer',
            'vendeur_viewed' => 'integer',
            'admin_viewed' => 'integer',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class, 'vendeur_id', 'seller_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ReclamationMessage::class, 'reclamation_id')->orderBy('created_at')->orderBy('id');
    }

    public function isResolved(): bool
    {
        return $this->type === ReclamationType::Resolved->value;
    }

    public function isOpen(): bool
    {
        return $this->type === ReclamationType::Open->value;
    }

    public function scopeOwnedBy(Builder $query, int $sellerId): Builder
    {
        return $query->where('vendeur_id', $sellerId);
    }
}
