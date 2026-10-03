<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Reclamation;

use Magento\Framework\Exception\NotFoundException;
use Mytek\Marketplace\Model\Api\ApiException;
use Mytek\Marketplace\Model\Api\Client;
use Mytek\Marketplace\Model\Api\Response;
use Mytek\Marketplace\Model\ReclamationRepository;

/**
 * Relais des pièces jointes : l'admin Magento ne connaît pas la clé de l'API, il passe par ce
 * service qui vérifie que le fichier appartient bien à la réclamation, puis le demande à la v2.
 */
class AttachmentProxy
{
    /** Types affichables dans le navigateur ; les autres sont téléchargés. */
    private const INLINE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'text/plain'];

    private const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/';

    public function __construct(
        private readonly ReclamationRepository $reclamations,
        private readonly Client $client
    ) {
    }

    public static function isValidFilename(string $filename): bool
    {
        return (bool)preg_match(self::FILENAME_PATTERN, $filename) && !str_contains($filename, '..');
    }

    /**
     * @throws NotFoundException fichier invalide ou étranger à la réclamation
     * @throws ApiException      API injoignable ou en erreur
     */
    public function fetch(int $reclamationId, string $filename, bool $download): Response
    {
        if (!self::isValidFilename($filename) || !$this->reclamations->hasAttachment($reclamationId, $filename)) {
            throw new NotFoundException(__('Attachment not found.'));
        }

        $route = $download ? 'api/attachments/download/' : 'api/attachments/view/';
        $response = $this->client->get($route . rawurlencode($filename));

        if ($response->status === 404) {
            throw new NotFoundException(__('Attachment not found.'));
        }
        if (!$response->isSuccessful()) {
            throw new ApiException(__('The marketplace API refused the request (HTTP %1).', $response->status));
        }
        return $response;
    }

    /** Type MIME sûr à renvoyer au navigateur, et mode d'affichage. */
    public static function safeContentType(string $upstream, bool $download): array
    {
        $type = strtolower(trim(explode(';', $upstream)[0]));
        if (!$download && in_array($type, self::INLINE_TYPES, true)) {
            return [$type, 'inline'];
        }
        return [$type !== '' ? $type : 'application/octet-stream', 'attachment'];
    }
}
