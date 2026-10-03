<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Commission;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Mytek\Marketplace\Model\Commission\CommissionAdminProxy;

class SetRate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::commissions';

    public function __construct(Context $context, private readonly CommissionAdminProxy $proxy)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');

        $sellerId = (int)$this->getRequest()->getParam('seller_id');
        $rateParam = trim((string)$this->getRequest()->getParam('rate'));
        if (!$sellerId) {
            $this->messageManager->addErrorMessage(__('Please choose a seller.'));
            return $redirect;
        }

        // Champ vide = revenir au taux par défaut (rate = null côté API).
        $rate = $rateParam === '' ? null : (float)$rateParam / 100;
        if ($rate !== null && ($rate < 0 || $rate > 0.5)) {
            $this->messageManager->addErrorMessage(__('The rate must be between 0 and 50%.'));
            return $redirect;
        }

        try {
            $this->proxy->setRate($sellerId, $rate);
            $this->messageManager->addSuccessMessage(__('Commission rate updated.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect;
    }
}
