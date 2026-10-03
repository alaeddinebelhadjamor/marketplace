<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model\Email;

use Magento\Framework\App\Area;
use Magento\Framework\DataObject;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Mytek\Marketplace\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Email au vendeur après validation ou refus (écart 2 de l'audit : l'écran d'inscription v1
 * promettait un email de confirmation que rien n'envoyait). Utilise les modèles d'email du
 * module et TransportBuilder, comme le reste de Magento ; en local, le SMTP système (sendmail)
 * est relié à un conteneur Mailpit pour voir les messages sans serveur de messagerie réel.
 *
 * Un échec d'envoi n'empêche jamais la validation ou le refus : il est seulement journalisé et
 * signalé à l'appelant, qui décide du message affiché à l'administrateur.
 */
class SellerNotifier
{
    private const TEMPLATE_VALIDATED = 'mytek_marketplace_seller_validated';
    private const TEMPLATE_REFUSED = 'mytek_marketplace_seller_refused';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function sendValidated(array $seller): bool
    {
        return $this->send(self::TEMPLATE_VALIDATED, $seller);
    }

    public function sendRefused(array $seller): bool
    {
        return $this->send(self::TEMPLATE_REFUSED, $seller);
    }

    private function send(string $templateId, array $seller): bool
    {
        $email = (string)($seller['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->logger->warning('Mytek_Marketplace : adresse email de vendeur invalide, notification non envoyée', [
                'seller_id' => $seller['seller_id'] ?? null,
            ]);
            return false;
        }

        try {
            $store = $this->storeManager->getStore();
            $this->transportBuilder
                ->setTemplateIdentifier($templateId)
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $store->getId()])
                ->setTemplateVars([
                    'seller'          => new DataObject($seller),
                    'vendor_space_url' => $this->config->getVendorSpaceUrl(),
                ])
                ->setFromByScope('general')
                ->addTo($email, trim(($seller['firstname'] ?? '') . ' ' . ($seller['lastname'] ?? '')));

            $this->transportBuilder->getTransport()->sendMessage();
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Mytek_Marketplace : échec de l\'envoi de l\'email au vendeur', [
                'seller_id' => $seller['seller_id'] ?? null,
                'template'  => $templateId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}
