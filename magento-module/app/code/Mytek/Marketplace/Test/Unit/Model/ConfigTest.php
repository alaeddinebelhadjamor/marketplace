<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Mytek\Marketplace\Model\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values): Config
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn(string $path) => $values[$path] ?? null);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturnCallback(static fn(string $v) => 'clair:' . $v);
        return new Config($scope, $encryptor);
    }

    public function testBaseUrlIsTrimmedWithoutTrailingSlash(): void
    {
        $config = $this->config([Config::XML_API_BASE_URL => ' http://localhost:8010/ ']);
        $this->assertSame('http://localhost:8010', $config->getApiBaseUrl());
    }

    public function testAdminKeyIsDecrypted(): void
    {
        $config = $this->config([Config::XML_API_ADMIN_KEY => 'chiffre']);
        $this->assertSame('clair:chiffre', $config->getAdminApiKey());
    }

    public function testApiIsNotConfiguredWithoutKey(): void
    {
        $config = $this->config([Config::XML_API_BASE_URL => 'http://localhost:8010']);
        $this->assertSame('', $config->getAdminApiKey());
        $this->assertFalse($config->isApiConfigured());
    }

    public function testApiIsConfiguredWithUrlAndKey(): void
    {
        $config = $this->config([
            Config::XML_API_BASE_URL  => 'http://localhost:8010',
            Config::XML_API_ADMIN_KEY => 'chiffre',
        ]);
        $this->assertTrue($config->isApiConfigured());
    }

    public function testTimeoutIsAtLeastOneSecond(): void
    {
        $this->assertSame(1, $this->config([])->getApiTimeout());
        $this->assertSame(10, $this->config([Config::XML_API_TIMEOUT => '10'])->getApiTimeout());
    }
}
