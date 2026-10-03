<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Lecture de la configuration du module (Stores > Configuration > Mytek > Marketplace).
 */
class Config
{
    public const XML_API_BASE_URL = 'mytek_marketplace/api/base_url';
    public const XML_API_ADMIN_KEY = 'mytek_marketplace/api/admin_key';
    public const XML_API_TIMEOUT = 'mytek_marketplace/api/timeout';
    public const XML_VENDOR_SPACE_URL = 'mytek_marketplace/links/vendor_space_url';
    public const XML_OPENSEARCH_URL = 'mytek_marketplace/search/opensearch_url';
    public const XML_OPENSEARCH_INDEX_PREFIX = 'mytek_marketplace/search/index_prefix';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function getApiBaseUrl(): string
    {
        return rtrim(trim((string)$this->scopeConfig->getValue(self::XML_API_BASE_URL)), '/');
    }

    /** Clé x-admin-key en clair : à n'utiliser que côté serveur, jamais dans une page. */
    public function getAdminApiKey(): string
    {
        $stored = (string)$this->scopeConfig->getValue(self::XML_API_ADMIN_KEY);
        return $stored === '' ? '' : trim($this->encryptor->decrypt($stored));
    }

    public function isApiConfigured(): bool
    {
        return $this->getApiBaseUrl() !== '' && $this->getAdminApiKey() !== '';
    }

    public function getApiTimeout(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_API_TIMEOUT));
    }

    public function getVendorSpaceUrl(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_VENDOR_SPACE_URL));
    }

    public function getOpenSearchUrl(): string
    {
        return rtrim(trim((string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_URL)), '/');
    }

    public function getOpenSearchIndexPrefix(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_OPENSEARCH_INDEX_PREFIX));
    }
}
