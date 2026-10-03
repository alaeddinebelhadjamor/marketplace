<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Mytek\Marketplace\Model\Config;

/**
 * Lien « Espace Vendeur » de l'en-tête (ch. 3 § 3.3) : l'adresse de l'espace vendeur
 * se règle dans Stores > Configuration > Mytek > Marketplace.
 */
class VendorSpaceLink extends Template
{
    public function __construct(Context $context, private readonly Config $config, array $data = [])
    {
        parent::__construct($context, $data);
    }

    public function getVendorSpaceUrl(): string
    {
        return $this->config->getVendorSpaceUrl();
    }
}
