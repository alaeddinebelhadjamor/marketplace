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
            $this->messageManager->addErrorMessage(__('Claim not found.'));
            return $redirect->setPath('*/*/index');
        }

        try {
            $this->reclamations->resolve($id);
            $this->messageManager->addSuccessMessage(__('Claim #%1 has been marked as resolved.', $id));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('The operation failed: %1', $e->getMessage()));
        }
        return $redirect;
    }
}
