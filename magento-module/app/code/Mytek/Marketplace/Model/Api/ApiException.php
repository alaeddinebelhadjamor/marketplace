<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Api;

use Magento\Framework\Exception\LocalizedException;

/** Échec d'un appel à l'API v2 de la marketplace (configuration, réseau ou réponse en erreur). */
class ApiException extends LocalizedException
{
}
