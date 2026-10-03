<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Reclamation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Mytek\Marketplace\Model\ReclamationRepository;

class Resolve extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::reclamations';

    public function __construct(Context $context, private readonly ReclamationRepository $reclamations)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/view', ['id' => $id]);

        if (!$id || !$this->reclamations->getById($id)) {
            $this->messageManager->addErrorMessage(__('Réclamation introuvable.'));
            return $redirect->setPath('*/*/index');
        }

        try {
            $this->reclamations->resolve($id);
            $this->messageManager->addSuccessMessage(__('La réclamation #%1 a été marquée comme résolue.', $id));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Opération impossible : %1', $e->getMessage()));
        }
        return $redirect;
    }
}
