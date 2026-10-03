<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Seller;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Mytek\Marketplace\Model\SellerRepository;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::sellers_delete';

    public function __construct(Context $context, private readonly SellerRepository $sellers)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');

        $seller = $id ? $this->sellers->getById($id) : null;
        if (!$seller) {
            $this->messageManager->addErrorMessage(__('Vendeur introuvable.'));
            return $redirect;
        }

        try {
            $this->sellers->delete($id);
            $this->messageManager->addSuccessMessage(__('Le vendeur « %1 » a été supprimé.', $seller['shop_title']));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(
                __('Suppression impossible (le vendeur a probablement des réclamations ou des commandes liées) : %1', $e->getMessage())
            );
        }
        return $redirect;
    }
}
