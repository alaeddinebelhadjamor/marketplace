<?php

namespace App\Http\Resources;

use App\Models\ReclamationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReclamationMessage */
class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reclamation_id' => $this->reclamation_id,
            'sender' => $this->sender,
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
        ];
    }
}
