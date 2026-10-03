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

        $reclamation = $id ? $this->reclamations->getById($id) : null;
        if (!$reclamation) {
            $this->messageManager->addErrorMessage(__('Claim not found.'));
            return $redirect->setPath('*/*/index');
        }
        // Tableau 2.16 : une réclamation résolue n'accepte plus de réponse. Contrôle serveur,
        // le formulaire étant seulement masqué dans la page.
        if (ReclamationRepository::isResolved($reclamation)) {
            $this->messageManager->addErrorMessage(__('This claim is resolved: no new reply can be added.'));
            return $redirect;
        }
        if ($message === '') {
            $this->messageManager->addErrorMessage(__('The message cannot be empty.'));
            return $redirect;
        }

        try {
            $this->reclamations->reply($id, $message);
            $this->messageManager->addSuccessMessage(__('Reply sent to the seller.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('The reply could not be sent: %1', $e->getMessage()));
        }
        return $redirect;
    }
}
