<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Reclamation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Mytek\Marketplace\Model\Reclamation\AttachmentProxy;

/**
 * Affiche ou télécharge une pièce jointe de réclamation, servie par l'API v2.
 * URL : mytek_marketplace/reclamation/attachment/id/{reclamation}/file/{nom}[/download/1]
 */
class Attachment extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::reclamations';

    public function __construct(
        Context $context,
        private readonly AttachmentProxy $proxy,
        private readonly RawFactory $rawFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $file = (string)$this->getRequest()->getParam('file');
        $download = (bool)$this->getRequest()->getParam('download');

        try {
            $response = $this->proxy->fetch($id, $file, $download);
        } catch (LocalizedException $e) {
            // Fichier introuvable (NotFoundException) ou API en erreur : retour à la conversation.
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/view', ['id' => $id]);
        }

        [$type, $disposition] = AttachmentProxy::safeContentType($response->contentType, $download);

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', $type, true);
        $result->setHeader('Content-Disposition', $disposition . '; filename="' . $file . '"', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $result->setHeader('Cache-Control', 'private, no-store', true);
        $result->setContents($response->body);
        return $result;
    }
}
