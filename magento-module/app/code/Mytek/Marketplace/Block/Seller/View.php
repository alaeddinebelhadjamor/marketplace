<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Block\Seller;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Mytek\Marketplace\Model\SellerRepository;

class View extends Template
{
    public const SORT_OPTIONS = [
        'relevance'  => 'Pertinence',
        'price_asc'  => 'Prix croissant',
        'price_desc' => 'Prix décroissant',
        'name'       => 'Nom (A-Z)',
    ];

    /** @var array|null|false */
    private $seller;

    private ?ProductCollection $products = null;
    private ?array $priceBounds = null;

    public function __construct(
        Context $context,
        private readonly SellerRepository $sellerRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getSeller(): ?array
    {
        if ($this->seller === null) {
            $id = (int)$this->getRequest()->getParam('id');
            $seller = $id ? $this->sellerRepository->getById($id) : null;
            $this->seller = ($seller && (int)$seller['status'] === SellerRepository::STATUS_VALIDATED)
                ? $seller
                : false;
        }
        return $this->seller ?: null;
    }

    /**
     * Collection de base (vendeur + statut/visibilité), sans filtre prix ni tri —
     * réutilisée à la fois pour la liste affichée et pour les bornes de prix du filtre.
     */
    private function getBaseCollection(): ProductCollection
    {
        $seller = $this->getSeller();
        $collection = $this->productCollectionFactory->create();
        $collection->setStoreId((int)$this->storeManager->getStore()->getId());
        $collection->addStoreFilter($this->storeManager->getStore());
        $collection->addAttributeToSelect(['name', 'price', 'special_price', 'image', 'short_description']);
        $collection->addAttributeToFilter('status', 1);
        $collection->addAttributeToFilter(
            'visibility',
            ['in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]]
        );
        $collection->addAttributeToFilter('seller_id', $seller ? (int)$seller['seller_id'] : 0);
        return $collection;
    }

    /**
     * Min/max de prix réels du catalogue de ce vendeur (avant filtre), pour les placeholders
     * du formulaire. Calculé en PHP sur la collection déjà chargée (catalogues vendeur = quelques
     * dizaines/centaines de produits, pas besoin d'une agrégation SQL séparée).
     */
    public function getPriceBounds(): array
    {
        if ($this->priceBounds === null) {
            $prices = [];
            foreach ($this->getBaseCollection() as $product) {
                $prices[] = (float)$product->getPrice();
            }
            $this->priceBounds = $prices
                ? ['min' => min($prices), 'max' => max($prices)]
                : ['min' => 0.0, 'max' => 0.0];
        }
        return $this->priceBounds;
    }

    public function getMinPriceParam(): ?string
    {
        $v = $this->getRequest()->getParam('min_price');
        return ($v !== null && $v !== '') ? (string)$v : null;
    }

    public function getMaxPriceParam(): ?string
    {
        $v = $this->getRequest()->getParam('max_price');
        return ($v !== null && $v !== '') ? (string)$v : null;
    }

    public function getCurrentSort(): string
    {
        $sort = (string)$this->getRequest()->getParam('sort', 'relevance');
        return array_key_exists($sort, self::SORT_OPTIONS) ? $sort : 'relevance';
    }

    public function getSortOptions(): array
    {
        return self::SORT_OPTIONS;
    }

    public function getProducts(): ProductCollection
    {
        if ($this->products === null) {
            $collection = $this->getBaseCollection();

            $min = $this->getMinPriceParam();
            $max = $this->getMaxPriceParam();
            if ($min !== null || $max !== null) {
                $filter = [];
                if ($min !== null && is_numeric($min)) {
                    $filter['from'] = (float)$min;
                }
                if ($max !== null && is_numeric($max)) {
                    $filter['to'] = (float)$max;
                }
                if ($filter) {
                    $collection->addAttributeToFilter('price', $filter);
                }
            }

            switch ($this->getCurrentSort()) {
                case 'price_asc':
                    $collection->setOrder('price', 'ASC');
                    break;
                case 'price_desc':
                    $collection->setOrder('price', 'DESC');
                    break;
                case 'name':
                    $collection->setOrder('name', 'ASC');
                    break;
                default:
                    $collection->setOrder('entity_id', 'DESC');
            }

            $this->products = $collection;
        }
        return $this->products;
    }

    public function getImageUrl(ProductInterface $product): string
    {
        $image = $product->getData('image');
        if (!$image || $image === 'no_selection') {
            return '';
        }
        $base = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        return rtrim($base, '/') . '/catalog/product' . $image;
    }

    public function formatPrice(float $price): string
    {
        return number_format($price, 3, ',', ' ') . ' DT';
    }

    public function getInitial(string $shopTitle): string
    {
        return mb_strtoupper(mb_substr(trim($shopTitle), 0, 1));
    }
}
