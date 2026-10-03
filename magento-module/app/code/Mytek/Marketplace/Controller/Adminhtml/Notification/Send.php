<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Notification;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Mytek\Marketplace\Model\Notification\NotificationService;
use Mytek\Marketplace\Model\Notification\UploadedFilesReader;

class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::notifications';

    public function __construct(Context $context, private readonly NotificationService $notifications)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');

        $sellerId = (int)$this->getRequest()->getParam('seller_id');
        $message = trim((string)$this->getRequest()->getParam('message'));

        if (!$sellerId) {
            $this->messageManager->addErrorMessage(__('Please choose a seller.'));
            return $redirect;
        }
        if ($message === '') {
            $this->messageManager->addErrorMessage(__('The message cannot be empty.'));
            return $redirect;
        }

        $files = UploadedFilesReader::normalize($this->getRequest()->getFiles('attachments'));

        try {
            $this->notifications->send($sellerId, $message, $files);
            $this->messageManager->addSuccessMessage(__('Notification sent to the seller.'));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $redirect;
    }
}
