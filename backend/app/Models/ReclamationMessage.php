<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Message d'une réclamation (table existante reclamation_messages). sender : 0 admin, 1 vendeur. */
class ReclamationMessage extends Model
{
    use HasFactory;

    protected $table = 'reclamation_messages';

    const UPDATED_AT = null;

    protected $fillable = ['reclamation_id', 'sender', 'message'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'reclamation_id' => 'integer',
            'sender' => 'integer',
        ];
    }

    public function reclamation(): BelongsTo
    {
        return $this->belongsTo(Reclamation::class, 'reclamation_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ReclamationAttachment::class, 'message_id');
    }
}
