<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Seller;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Pending extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::sellers_validate';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Mytek_Marketplace::sellers_pending');
        $page->getConfig()->getTitle()->prepend(__('Seller approval'));
        return $page;
    }
}
