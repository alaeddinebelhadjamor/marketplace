<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Adminhtml\Reclamation;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Mytek\Marketplace\Model\ReclamationRepository;

class View extends Template
{
    private ?array $reclamation = null;

    public function __construct(
        Context $context,
        private readonly ReclamationRepository $reclamations,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getReclamationId(): int
    {
        return (int)$this->getRequest()->getParam('id');
    }

    public function getReclamation(): array
    {
        if ($this->reclamation === null) {
            $this->reclamation = $this->reclamations->getById($this->getReclamationId()) ?? [];
        }
        return $this->reclamation;
    }

    public function getMessages(): array
    {
        return $this->reclamations->getMessages($this->getReclamationId());
    }

    public function isResolved(): bool
    {
        return ReclamationRepository::isResolved($this->getReclamation());
    }

    public function typeLabel(): string
    {
        return ReclamationRepository::typeLabel((int)($this->getReclamation()['type'] ?? 0));
    }

    public function isFromAdmin(array $message): bool
    {
        return (int)$message['sender'] === ReclamationRepository::SENDER_ADMIN;
    }

    public function getAttachmentName(array $attachment): string
    {
        return ReclamationRepository::fileName((string)$attachment['file_path']);
    }

    /**
     * Les pièces jointes sont servies par l'API v2, qui exige la clé admin : le lien pointe
     * vers un contrôleur admin qui relaie le fichier (Controller\Adminhtml\Reclamation\Attachment).
     */
    public function getAttachmentUrl(array $attachment, bool $download = false): string
    {
        $params = ['id' => $this->getReclamationId(), 'file' => $this->getAttachmentName($attachment)];
        if ($download) {
            $params['download'] = 1;
        }
        return $this->getUrl('mytek_marketplace/reclamation/attachment', $params);
    }

    public function getReplyUrl(): string
    {
        return $this->getUrl('mytek_marketplace/reclamation/reply', ['id' => $this->getReclamationId()]);
    }

    public function getResolveUrl(): string
    {
        return $this->getUrl('mytek_marketplace/reclamation/resolve', ['id' => $this->getReclamationId()]);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('mytek_marketplace/reclamation/index');
    }

    public function formatDateTime(?string $dt): string
    {
        if (!$dt) {
            return '—';
        }
        $ts = strtotime($dt);
        return $ts ? date('d/m/Y H:i', $ts) : $dt;
    }
}
