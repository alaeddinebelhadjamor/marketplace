<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Seller;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Mytek\Marketplace\Model\Email\SellerNotifier;
use Mytek\Marketplace\Model\SellerRepository;

class Validate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::sellers_validate';

    public function __construct(
        Context $context,
        private readonly SellerRepository $sellers,
        private readonly SellerNotifier $notifier
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create()->setRefererOrBaseUrl();

        $seller = $id ? $this->sellers->getById($id) : null;
        if (!$seller) {
            $this->messageManager->addErrorMessage(__('Seller not found.'));
            return $redirect;
        }

        try {
            $this->sellers->setStatus($id, SellerRepository::STATUS_VALIDATED);
            $this->messageManager->addSuccessMessage(
                __('Seller "%1" (%2) has been approved and can now sign in.', $seller['shop_title'], $seller['email'])
            );
            if (!$this->notifier->sendValidated($seller)) {
                $this->messageManager->addWarningMessage(
                    __('The confirmation email could not be sent to the seller (the account was approved anyway).')
                );
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('The seller could not be approved: %1', $e->getMessage()));
        }
        return $redirect;
    }
}
