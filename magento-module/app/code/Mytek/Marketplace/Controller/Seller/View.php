<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Seller;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;
use Mytek\Marketplace\Model\SellerRepository;

/**
 * Page boutique publique d'un vendeur marketplace (front-office Magento).
 * URL : /marketplace/seller/view/id/{seller_id}
 */
class View extends Action implements HttpGetActionInterface
{
    public function __construct(
        Context $context,
        private readonly PageFactory $resultPageFactory,
        private readonly SellerRepository $sellerRepository
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $seller = $id ? $this->sellerRepository->getById($id) : null;

        if (!$seller || (int)$seller['status'] !== SellerRepository::STATUS_VALIDATED) {
            return $this->_forward('noroute');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set($seller['shop_title'] . ' - Boutique Mytek Marketplace');
        return $resultPage;
    }
}
