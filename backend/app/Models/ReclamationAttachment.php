<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pièce jointe d'un message (table existante reclamation_attachments).
 * file_path garde le format relatif de la v1 (uploads/reclamations/<nom>) ;
 * seul le nom de fichier final sert à retrouver le fichier.
 */
class ReclamationAttachment extends Model
{
    protected $table = 'reclamation_attachments';

    const UPDATED_AT = null;

    protected $fillable = ['message_id', 'file_path', 'file_type'];

    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'message_id' => 'integer',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ReclamationMessage::class, 'message_id');
    }

    /** Nom du fichier, quel que soit le séparateur enregistré (/ ou \ sous Windows). */
    public function filename(): string
    {
        $parts = preg_split('#[\\\\/]#', (string) $this->file_path);

        return (string) end($parts);
    }
}
