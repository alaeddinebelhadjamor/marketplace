<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Nouveauté dans la boîte du vendeur : notification système (nouvelles ventes,
 * message de l'administration) ou réponse du support à une réclamation.
 *
 * Diffusé en temps réel par Reverb sur le canal privé du vendeur. Remplace
 * l'interrogation toutes les 30 secondes de la v1.
 */
class SellerInboxUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $sellerId,
        public readonly int $reclamationId,
        public readonly int $type,
        public readonly string $kind,   // notification | reply | resolved
        public readonly string $preview,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('seller.'.$this->sellerId)];
    }

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'reclamation_id' => $this->reclamationId,
            'type' => $this->type,
            'kind' => $this->kind,
            'preview' => mb_substr($this->preview, 0, 160),
            'at' => now()->toIso8601String(),
        ];
    }
}
