<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Commission;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\LocalizedException;
use Mytek\Marketplace\Model\Commission\CommissionAdminProxy;

/** Relaie le PDF d'un relevé de paiement depuis l'API v2 (clé x-admin-key ajoutée côté serveur). */
class StatementPdf extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::commissions';

    public function __construct(Context $context, private readonly CommissionAdminProxy $proxy, private readonly RawFactory $rawFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $id = (int)$this->getRequest()->getParam('id');

        try {
            $response = $this->proxy->statementPdf($id);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'application/pdf', true);
        $result->setHeader('Content-Disposition', 'inline; filename="releve-' . $id . '.pdf"', true);
        $result->setHeader('X-Content-Type-Options', 'nosniff', true);
        $result->setContents($response->body);
        return $result;
    }
}
