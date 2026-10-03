<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Email;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Mail\TransportInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mytek\Marketplace\Model\Config;
use Mytek\Marketplace\Model\Email\SellerNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SellerNotifierTest extends TestCase
{
    private function seller(array $overrides = []): array
    {
        return array_merge([
            'seller_id' => 7,
            'firstname' => 'Ala',
            'lastname'  => 'Eddine',
            'shop_title' => 'Ala Tech Store',
            'email'     => 'ala@example.test',
        ], $overrides);
    }

    private function notifier(TransportBuilder $builder): SellerNotifier
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createMock(Config::class);
        $config->method('getVendorSpaceUrl')->willReturn('http://localhost:5180');

        return new SellerNotifier($builder, $storeManager, $config, $this->createMock(LoggerInterface::class));
    }

    /** Un TransportBuilder qui accepte l'enchaînement fluide et renvoie un transport mocké. */
    private function fluentBuilder(): TransportBuilder
    {
        $builder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('sendMessage');
        $builder->method('getTransport')->willReturn($transport);
        return $builder;
    }

    public function testSendValidatedUsesTheRightTemplate(): void
    {
        $builder = $this->fluentBuilder();
        $builder->expects($this->once())->method('setTemplateIdentifier')->with('mytek_marketplace_seller_validated')->willReturnSelf();

        $this->assertTrue($this->notifier($builder)->sendValidated($this->seller()));
    }

    public function testSendRefusedUsesTheRightTemplate(): void
    {
        $builder = $this->fluentBuilder();
        $builder->expects($this->once())->method('setTemplateIdentifier')->with('mytek_marketplace_seller_refused')->willReturnSelf();

        $this->assertTrue($this->notifier($builder)->sendRefused($this->seller()));
    }

    public function testInvalidEmailIsRefusedWithoutCallingTransportBuilder(): void
    {
        $builder = $this->createMock(TransportBuilder::class);
        $builder->expects($this->never())->method('setTemplateIdentifier');

        $this->assertFalse($this->notifier($builder)->sendValidated($this->seller(['email' => 'pas-un-email'])));
    }

    public function testMissingEmailIsRefused(): void
    {
        $builder = $this->createMock(TransportBuilder::class);
        $builder->expects($this->never())->method('setTemplateIdentifier');

        $this->assertFalse($this->notifier($builder)->sendValidated($this->seller(['email' => ''])));
    }

    public function testTransportFailureIsCaughtAndReturnsFalse(): void
    {
        $builder = $this->createMock(TransportBuilder::class);
        foreach (['setTemplateIdentifier', 'setTemplateOptions', 'setTemplateVars', 'setFromByScope', 'addTo'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('getTransport')->willThrowException(new \RuntimeException('SMTP indisponible'));

        $this->assertFalse($this->notifier($builder)->sendValidated($this->seller()));
    }
}
