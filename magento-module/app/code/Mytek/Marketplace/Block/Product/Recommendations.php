<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Product;

use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;
use Mytek\Marketplace\Model\Config;
use Mytek\Marketplace\Model\Recommendation\RecommendationProvider;

/**
 * Bloc « Vous aimerez aussi » de la fiche produit, alimenté par le moteur de
 * recommandation de l'API v2 (filtrage collaboratif ALS + similarité de contenu).
 * Pas de cache de bloc : RecommendationProvider met déjà la réponse en cache, avec une
 * durée plus courte après une erreur.
 */
class Recommendations extends Template
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $items = null;

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly Config $config,
        private readonly RecommendationProvider $provider,
        private readonly PriceCurrencyInterface $priceCurrency,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /** @return array<int, array<string, mixed>> */
    public function getItems(): array
    {
        if ($this->items === null) {
            $product = $this->registry->registry('current_product');
            $this->items = ($product && $this->config->isRecommendationsEnabled())
                ? $this->provider->getForSku((string)$product->getSku(), $this->config->getRecommendationsLimit())
                : [];
        }
        return $this->items;
    }

    public function getProductUrl(array $item): string
    {
        $suffix = (string)$this->_scopeConfig->getValue('catalog/seo/product_url_suffix', ScopeInterface::SCOPE_STORE);
        return $this->getBaseUrl() . rawurlencode((string)$item['url_key']) . $suffix;
    }

    public function getImageUrl(array $item): string
    {
        return $item['image'] ?: $this->getViewFileUrl('Magento_Catalog::images/product/placeholder/small_image.jpg');
    }

    public function formatPrice(float $amount): string
    {
        return $this->priceCurrency->format($amount, false);
    }

    /** Pourcentage de remise arrondi, 0 sans promotion. */
    public function getDiscountPercent(array $item): int
    {
        return ($item['special_price'] !== null && $item['price'] > 0)
            ? (int)round(100 * (1 - $item['special_price'] / $item['price']))
            : 0;
    }

    /** Raison affichée sous chaque produit : rend le moteur explicable pour le client. */
    public function getReasonLabel(array $item): string
    {
        return match ($item['source'] ?? '') {
            'hybrid'     => (string)__('Similar product'),
            'co_view'    => (string)__('Often viewed together'),
            'popularity' => (string)__('Trending'),
            default      => '',
        };
    }
}
