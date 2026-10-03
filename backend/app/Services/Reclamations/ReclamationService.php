<?php

namespace App\Services\Reclamations;

use App\Enums\MessageSender;
use App\Enums\ReclamationType;
use App\Events\SellerInboxUpdated;
use App\Exceptions\ApiException;
use App\Models\Reclamation;
use App\Models\ReclamationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Règles métier des réclamations et notifications.
 * type : 0 notification, 1 ouverte, 2 résolue. sender : 0 admin, 1 vendeur.
 */
class ReclamationService
{
    public function __construct(private readonly AttachmentStorage $attachments) {}

    /** Ouverture d'une réclamation par un vendeur. */
    public function open(int $sellerId, string $message, array $files = []): Reclamation
    {
        return DB::transaction(function () use ($sellerId, $message, $files) {
            $reclamation = Reclamation::create([
                'vendeur_id' => $sellerId,
                'type' => ReclamationType::Open->value,
                'vendeur_viewed' => 1,
                'admin_viewed' => 0,
            ]);
            $this->addMessage($reclamation, MessageSender::Seller, $message, $files);

            return $reclamation;
        });
    }

    /** Réponse du vendeur. @throws ApiException 409 si résolue */
    public function sellerReply(Reclamation $reclamation, string $message, array $files = []): ReclamationMessage
    {
        $this->ensureNotResolved($reclamation);

        return DB::transaction(function () use ($reclamation, $message, $files) {
            $msg = $this->addMessage($reclamation, MessageSender::Seller, $message, $files);
            $reclamation->forceFill(['admin_viewed' => 0])->save();

            return $msg;
        });
    }

    /** Réponse de l'administration. @throws ApiException 409 si résolue */
    public function adminReply(Reclamation $reclamation, string $message, array $files = []): ReclamationMessage
    {
        $this->ensureNotResolved($reclamation);

        $msg = DB::transaction(function () use ($reclamation, $message, $files) {
            $msg = $this->addMessage($reclamation, MessageSender::Admin, $message, $files);
            $reclamation->forceFill(['vendeur_viewed' => 0])->save();

            return $msg;
        });

        $this->broadcast($reclamation, 'reply', $message);

        return $msg;
    }

    /** Clôture par le vendeur : seules les réclamations ouvertes. */
    public function resolveBySeller(Reclamation $reclamation): void
    {
        if (! $reclamation->isOpen()) {
            throw new ApiException('Seules les réclamations ouvertes peuvent être résolues.', 400, ['error' => 'Seules les réclamations ouvertes peuvent être résolues.']);
        }
        $reclamation->forceFill(['type' => ReclamationType::Resolved->value, 'vendeur_viewed' => 1])->save();
    }

    /** Clôture par l'administration. */
    public function resolveByAdmin(Reclamation $reclamation): void
    {
        $reclamation->forceFill(['type' => ReclamationType::Resolved->value])->save();
        $this->broadcast($reclamation, 'resolved', 'Votre réclamation a été marquée comme résolue.');
    }

    /** Notification (réclamation de type 0) envoyée à un vendeur. */
    public function notify(int $sellerId, string $message, array $files = []): Reclamation
    {
        $reclamation = DB::transaction(function () use ($sellerId, $message, $files) {
            $reclamation = Reclamation::create([
                'vendeur_id' => $sellerId,
                'type' => ReclamationType::Notification->value,
                'vendeur_viewed' => 0,
                'admin_viewed' => 1,
            ]);
            $this->addMessage($reclamation, MessageSender::Admin, $message, $files);

            return $reclamation;
        });

        $this->broadcast($reclamation, 'notification', $message);

        return $reclamation;
    }

    private function addMessage(Reclamation $reclamation, MessageSender $sender, string $text, array $files): ReclamationMessage
    {
        $message = $reclamation->messages()->create([
            'sender' => $sender->value,
            'message' => $text,
        ]);
        if ($files !== []) {
            $this->attachments->storeAll($message, $files);
        }

        return $message;
    }

    private function ensureNotResolved(Reclamation $reclamation): void
    {
        if ($reclamation->isResolved()) {
            throw new ApiException('Reclamation already resolved', 409, ['error' => 'Reclamation already resolved']);
        }
    }

    /** Diffusion temps réel : un échec (Reverb arrêté) ne bloque jamais l'action. */
    private function broadcast(Reclamation $reclamation, string $kind, string $preview): void
    {
        try {
            event(new SellerInboxUpdated($reclamation->vendeur_id, $reclamation->id, $reclamation->type, $kind, $preview));
        } catch (Throwable $e) {
            Log::warning('Diffusion temps réel impossible', ['error' => $e->getMessage()]);
        }
    }
}
