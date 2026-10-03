<?php

namespace App\Http\Resources;

use App\Models\ReclamationAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReclamationAttachment */
class AttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message_id' => $this->message_id,
            'file_path' => $this->file_path,
            'file_type' => $this->file_type,
            'filename' => $this->filename(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
