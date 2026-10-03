<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Seller;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Mytek\Marketplace\Model\SellerRepository;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::sellers_edit';

    private const REQUIRED = ['firstname', 'lastname', 'email', 'shop_title', 'contact_number'];

    public function __construct(Context $context, private readonly SellerRepository $sellers)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $request = $this->getRequest();
        $id = (int)$request->getParam('id');
        $redirect = $this->resultRedirectFactory->create();

        if (!$id || !$this->sellers->getById($id)) {
            $this->messageManager->addErrorMessage(__('Seller not found.'));
            return $redirect->setPath('*/*/index');
        }

        $data = (array)$request->getParam('seller', []);
        $data = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $data);

        foreach (self::REQUIRED as $field) {
            if (empty($data[$field])) {
                $this->messageManager->addErrorMessage(__('The field "%1" is required.', $field));
                return $redirect->setPath('*/*/edit', ['id' => $id]);
            }
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('Invalid email address.'));
            return $redirect->setPath('*/*/edit', ['id' => $id]);
        }

        try {
            $this->sellers->update($id, $data);
            $this->messageManager->addSuccessMessage(__('The seller information has been saved.'));
            return $redirect->setPath('*/*/index');
        } catch (\Throwable $e) {
            // Doublon d'email (contrainte UNIQUE) ou autre erreur SQL
            $this->messageManager->addErrorMessage(__('The seller could not be saved: %1', $e->getMessage()));
            return $redirect->setPath('*/*/edit', ['id' => $id]);
        }
    }
}
