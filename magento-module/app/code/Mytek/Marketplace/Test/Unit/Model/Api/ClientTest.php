<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Api;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Mytek\Marketplace\Model\Api\ApiException;
use Mytek\Marketplace\Model\Api\Client;
use Mytek\Marketplace\Model\Api\Response;
use Mytek\Marketplace\Model\Config;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClientTest extends TestCase
{
    private Config $config;
    private ClientFactory $factory;
    private GuzzleClient $guzzle;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->guzzle = $this->createMock(GuzzleClient::class);
        $this->factory = $this->createMock(ClientFactory::class);
        $this->factory->method('create')->willReturn($this->guzzle);
    }

    private function client(): Client
    {
        return new Client($this->config, $this->factory, $this->createMock(LoggerInterface::class));
    }

    private function configured(): void
    {
        $this->config->method('isApiConfigured')->willReturn(true);
        $this->config->method('getApiBaseUrl')->willReturn('http://localhost:8010');
        $this->config->method('getAdminApiKey')->willReturn('cle-de-test');
        $this->config->method('getApiTimeout')->willReturn(5);
    }

    public function testRefusesToCallWhenNotConfigured(): void
    {
        $this->config->method('isApiConfigured')->willReturn(false);
        $this->guzzle->expects($this->never())->method('request');

        $this->expectException(ApiException::class);
        $this->client()->get('api/attachments/view/a.pdf');
    }

    public function testSendsAdminKeyHeaderAndReturnsResponse(): void
    {
        $this->configured();
        $this->guzzle->expects($this->once())
            ->method('request')
            ->with('GET', 'api/attachments/view/a.pdf', $this->callback(
                static fn(array $options) => $options['headers']['x-admin-key'] === 'cle-de-test'
            ))
            ->willReturn(new PsrResponse(200, ['Content-Type' => 'application/pdf'], '%PDF'));

        $response = $this->client()->get('/api/attachments/view/a.pdf');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('%PDF', $response->body);
        $this->assertSame('application/pdf', $response->contentType);
    }

    public function testNetworkFailureBecomesApiException(): void
    {
        $this->configured();
        $this->guzzle->method('request')->willThrowException(
            new ConnectException('Connection refused', new Request('GET', 'x'))
        );

        $this->expectException(ApiException::class);
        $this->client()->get('api/stats/all');
    }

    public function testMultipartSendsFieldsAndFiles(): void
    {
        $this->configured();
        $file = tempnam(sys_get_temp_dir(), 'mk');
        file_put_contents($file, 'contenu');

        $this->guzzle->expects($this->once())
            ->method('request')
            ->with('POST', 'api/admin/notifications', $this->callback(static function (array $options): bool {
                $names = array_column($options['multipart'], 'name');
                return $names === ['seller_id', 'message', 'attachments[]']
                    && $options['multipart'][2]['filename'] === 'facture.pdf';
            }))
            ->willReturn(new PsrResponse(200, [], '{"success":true,"notification_id":12}'));

        $response = $this->client()->postMultipart(
            'api/admin/notifications',
            ['seller_id' => 3, 'message' => 'Bonjour'],
            [['path' => $file, 'name' => 'facture.pdf']]
        );
        unlink($file);

        $this->assertSame(12, $response->json()['notification_id']);
    }

    public function testErrorMessagePrefersValidationErrors(): void
    {
        $response = new Response(422, '{"message":"Données invalides","errors":{"seller_id":["Vendeur introuvable"]}}', 'application/json');
        $this->assertSame('Vendeur introuvable', $response->errorMessage());

        $response = new Response(401, '{"message":"Clé invalide"}', 'application/json');
        $this->assertSame('Clé invalide', $response->errorMessage());
    }
}
