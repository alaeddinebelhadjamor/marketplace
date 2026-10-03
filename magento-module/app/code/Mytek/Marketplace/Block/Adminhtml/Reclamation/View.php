<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Adminhtml\Reclamation;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Mytek\Marketplace\Model\ReclamationRepository;

class View extends Template
{
    /**
     * Les pièces jointes sont stockées et servies par le backend Node.js
     * (route publique GET /api/attachments/view/:filename).
     */
    private const NODE_ATTACHMENTS_URL = 'http://localhost:3000/api/attachments/view/';

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
        return (int)($this->getReclamation()['type'] ?? 0) === ReclamationRepository::TYPE_RESOLVED;
    }

    public function typeLabel(): string
    {
        return ReclamationRepository::typeLabel((int)($this->getReclamation()['type'] ?? 0));
    }

    public function isFromAdmin(array $message): bool
    {
        return (int)$message['sender'] === ReclamationRepository::SENDER_ADMIN;
    }

    public function getAttachmentUrl(array $attachment): string
    {
        return self::NODE_ATTACHMENTS_URL . rawurlencode(basename((string)$attachment['file_path']));
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
