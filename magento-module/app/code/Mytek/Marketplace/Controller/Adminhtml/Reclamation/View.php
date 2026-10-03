<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Reclamation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Mytek\Marketplace\Model\ReclamationRepository;

class View extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::reclamations';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ReclamationRepository $reclamations
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $reclamation = $id ? $this->reclamations->getById($id) : null;
        if (!$reclamation) {
            $this->messageManager->addErrorMessage(__('Claim not found.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        // L'ouverture de la conversation vaut lecture côté admin
        $this->reclamations->markSeenByAdmin($id);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Mytek_Marketplace::reclamations');
        $page->getConfig()->getTitle()->prepend(__('Claim #%1 — %2', $id, $reclamation['shop_title'] ?? ''));
        return $page;
    }
}
