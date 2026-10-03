<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Reclamation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Mytek\Marketplace\Model\ReclamationRepository;

class Reply extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::reclamations';

    public function __construct(Context $context, private readonly ReclamationRepository $reclamations)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $message = trim((string)$this->getRequest()->getParam('message'));
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/view', ['id' => $id]);

        if (!$id || !$this->reclamations->getById($id)) {
            $this->messageManager->addErrorMessage(__('Réclamation introuvable.'));
            return $redirect->setPath('*/*/index');
        }
        if ($message === '') {
            $this->messageManager->addErrorMessage(__('Le message ne peut pas être vide.'));
            return $redirect;
        }

        try {
            $this->reclamations->reply($id, $message);
            $this->messageManager->addSuccessMessage(__('Réponse envoyée au vendeur.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Envoi impossible : %1', $e->getMessage()));
        }
        return $redirect;
    }
}
