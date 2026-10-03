<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Seller;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Mytek\Marketplace\Model\SellerRepository;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::sellers_edit';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly SellerRepository $sellers
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $seller = $id ? $this->sellers->getById($id) : null;
        if (!$seller) {
            $this->messageManager->addErrorMessage(__('Vendeur introuvable.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Mytek_Marketplace::sellers');
        $page->getConfig()->getTitle()->prepend(
            __('Modifier le vendeur : %1 %2', $seller['firstname'], $seller['lastname'])
        );
        return $page;
    }
}
