<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Seller;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Mytek\Marketplace\Model\SellerRepository;

class Refuse extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::sellers_validate';

    public function __construct(Context $context, private readonly SellerRepository $sellers)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create()->setRefererOrBaseUrl();

        $seller = $id ? $this->sellers->getById($id) : null;
        if (!$seller) {
            $this->messageManager->addErrorMessage(__('Vendeur introuvable.'));
            return $redirect;
        }

        try {
            $this->sellers->setStatus($id, SellerRepository::STATUS_REFUSED);
            $this->messageManager->addSuccessMessage(
                __('L\'inscription du vendeur « %1 » (%2) a été refusée.', $seller['shop_title'], $seller['email'])
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Erreur lors du refus : %1', $e->getMessage()));
        }
        return $redirect;
    }
}
