<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Notification;

use Magento\Framework\Exception\LocalizedException;
use Mytek\Marketplace\Model\Api\ApiException;
use Mytek\Marketplace\Model\Api\Client;
use Mytek\Marketplace\Model\Api\Response;
use Mytek\Marketplace\Model\Notification\NotificationService;
use Mytek\Marketplace\Model\SellerRepository;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
    public function testRefusesUnknownSellerWithoutCallingTheApi(): void
    {
        $sellers = $this->createMock(SellerRepository::class);
        $sellers->method('getById')->with(99)->willReturn(null);
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('postMultipart');

        $this->expectException(LocalizedException::class);
        (new NotificationService($client, $sellers))->send(99, 'Bonjour');
    }

    public function testSendsMessageAndReturnsNotificationId(): void
    {
        $sellers = $this->createMock(SellerRepository::class);
        $sellers->method('getById')->with(3)->willReturn(['seller_id' => 3]);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('postMultipart')
            ->with('api/admin/notifications', ['seller_id' => 3, 'message' => 'Bonjour'], [])
            ->willReturn(new Response(200, '{"success":true,"notification_id":42}', 'application/json'));

        $id = (new NotificationService($client, $sellers))->send(3, 'Bonjour');
        $this->assertSame(42, $id);
    }

    public function testApiErrorBecomesApiExceptionWithDetail(): void
    {
        $sellers = $this->createMock(SellerRepository::class);
        $sellers->method('getById')->willReturn(['seller_id' => 3]);
        $client = $this->createMock(Client::class);
        $client->method('postMultipart')->willReturn(
            new Response(401, '{"message":"Clé d\'administration manquante ou invalide."}', 'application/json')
        );

        $this->expectException(ApiException::class);
        (new NotificationService($client, $sellers))->send(3, 'Bonjour');
    }
}
