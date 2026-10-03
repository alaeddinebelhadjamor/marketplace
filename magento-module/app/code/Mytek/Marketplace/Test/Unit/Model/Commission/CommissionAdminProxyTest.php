<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Commission;

use Magento\Framework\Exception\NotFoundException;
use Mytek\Marketplace\Model\Api\ApiException;
use Mytek\Marketplace\Model\Api\Client;
use Mytek\Marketplace\Model\Api\Response;
use Mytek\Marketplace\Model\Commission\CommissionAdminProxy;
use PHPUnit\Framework\TestCase;

class CommissionAdminProxyTest extends TestCase
{
    public function testGenerateStatementsReturnsCount(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('post')
            ->with('api/admin/statements/generate', ['period' => '2026-10'])
            ->willReturn(new Response(200, '{"period":"2026-10","statements":5}', 'application/json'));

        $this->assertSame(5, (new CommissionAdminProxy($client))->generateStatements('2026-10'));
    }

    public function testGenerateStatementsFailureBecomesApiException(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('post')->willReturn(new Response(422, '{"message":"period invalide"}', 'application/json'));

        $this->expectException(ApiException::class);
        (new CommissionAdminProxy($client))->generateStatements('bad');
    }

    public function testSetRateSendsRateAsJson(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('put')
            ->with('api/admin/sellers/3/commission-rate', ['rate' => 0.08])
            ->willReturn(new Response(200, '{"seller_id":3,"rate":0.08}', 'application/json'));

        (new CommissionAdminProxy($client))->setRate(3, 0.08);
    }

    public function testSetRateNullResetsToDefault(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())
            ->method('put')
            ->with('api/admin/sellers/3/commission-rate', ['rate' => null])
            ->willReturn(new Response(200, '{}', 'application/json'));

        (new CommissionAdminProxy($client))->setRate(3, null);
    }

    public function testStatementPdfNotFoundBecomesNotFoundException(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('get')->willReturn(new Response(404, '{"message":"Relevé introuvable"}', 'application/json'));

        $this->expectException(NotFoundException::class);
        (new CommissionAdminProxy($client))->statementPdf(99);
    }

    public function testStatementPdfReturnsBinaryBody(): void
    {
        $client = $this->createMock(Client::class);
        $client->method('get')->willReturn(new Response(200, '%PDF-fake', 'application/pdf'));

        $response = (new CommissionAdminProxy($client))->statementPdf(1);
        $this->assertSame('%PDF-fake', $response->body);
    }
}
