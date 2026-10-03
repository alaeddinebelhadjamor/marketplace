<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Notification;

use Magento\Framework\Exception\LocalizedException;
use Mytek\Marketplace\Model\Api\ApiException;
use Mytek\Marketplace\Model\Api\Client;
use Mytek\Marketplace\Model\SellerRepository;

/**
 * Envoi d'une notification à un vendeur (cas 2.17 du rapport), en passant par l'API v2
 * (POST /api/admin/notifications), qui crée la réclamation de type 0 comme le fait déjà la
 * synchronisation des commandes. Jusqu'ici seule l'API exposait cette fonction ; ceci lui
 * donne un écran dans l'admin, réservé à l'intégrateur et à l'administrateur (ACL
 * Mytek_Marketplace::notifications).
 */
class NotificationService
{
    public function __construct(
        private readonly Client $client,
        private readonly SellerRepository $sellers
    ) {
    }

    /**
     * @param array<int, array{path: string, name: string}> $files
     * @return int identifiant de la réclamation créée
     * @throws LocalizedException  vendeur introuvable
     * @throws ApiException        API injoignable ou en erreur
     */
    public function send(int $sellerId, string $message, array $files = []): int
    {
        if (!$this->sellers->getById($sellerId)) {
            throw new LocalizedException(__('Seller not found.'));
        }

        $response = $this->client->postMultipart(
            'api/admin/notifications',
            ['seller_id' => $sellerId, 'message' => $message],
            $files
        );

        if (!$response->isSuccessful()) {
            $detail = $response->errorMessage();
            throw new ApiException(
                $detail !== ''
                    ? __('The marketplace API refused the notification: %1', $detail)
                    : __('The marketplace API refused the notification (HTTP %1).', $response->status)
            );
        }

        return (int)($response->json()['notification_id'] ?? 0);
    }
}
