<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Controller\Adminhtml\Reclamation;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mytek_Marketplace::reclamations';

    public function __construct(Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Mytek_Marketplace::reclamations');
        $page->getConfig()->getTitle()->prepend(__('Réclamations des vendeurs'));
        return $page;
    }
}
