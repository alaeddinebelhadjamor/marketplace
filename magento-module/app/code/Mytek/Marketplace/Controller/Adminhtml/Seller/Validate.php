<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Seller;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Mytek\Marketplace\Model\SellerRepository;

class Validate extends Action implements HttpPostActionInterface
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
            $this->sellers->setStatus($id, SellerRepository::STATUS_VALIDATED);
            $this->messageManager->addSuccessMessage(
                __('Le vendeur « %1 » (%2) a été validé. Il peut maintenant se connecter à son espace.', $seller['shop_title'], $seller['email'])
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Erreur lors de la validation : %1', $e->getMessage()));
        }
        return $redirect;
    }
}
