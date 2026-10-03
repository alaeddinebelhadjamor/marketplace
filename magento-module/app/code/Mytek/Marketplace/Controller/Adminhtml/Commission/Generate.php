<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Commission;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Mytek\Marketplace\Model\Commission\CommissionAdminProxy;

class Generate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::commissions';

    public function __construct(Context $context, private readonly CommissionAdminProxy $proxy)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');

        $period = (string)$this->getRequest()->getParam('period');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m');
        }

        try {
            $count = $this->proxy->generateStatements($period);
            $this->messageManager->addSuccessMessage(__('%1 statement(s) generated for %2.', $count, $period));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect;
    }
}
