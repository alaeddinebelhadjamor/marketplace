<?php

namespace App\Http\Resources;

use App\Models\Reclamation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Réclamation ou notification. Mêmes champs que la v1, plus last_message
 * (évite à l'interface une requête par réclamation pour l'aperçu).
 *
 * @mixin Reclamation
 */
class ReclamationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vendeur_id' => $this->vendeur_id,
            'type' => $this->type,
            'vendeur_viewed' => (int) $this->vendeur_viewed,
            'admin_viewed' => (int) $this->admin_viewed,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'last_message' => $this->when(isset($this->last_message), fn () => $this->last_message),
            // Clé conservée de la v1 (nom du modèle Sequelize).
            'MarketplaceSeller' => $this->whenLoaded('seller', fn () => $this->seller ? [
                'firstname' => $this->seller->firstname,
                'lastname' => $this->seller->lastname,
                'shop_title' => $this->seller->shop_title,
            ] : null),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
