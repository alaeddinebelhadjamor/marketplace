<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Reclamation;

use Magento\Framework\Exception\NotFoundException;
use Mytek\Marketplace\Model\Api\ApiException;
use Mytek\Marketplace\Model\Api\Client;
use Mytek\Marketplace\Model\Api\Response;
use Mytek\Marketplace\Model\Reclamation\AttachmentProxy;
use Mytek\Marketplace\Model\ReclamationRepository;
use PHPUnit\Framework\TestCase;

class AttachmentProxyTest extends TestCase
{
    /**
     * @dataProvider filenameProvider
     */
    public function testFilenameValidation(string $name, bool $valid): void
    {
        $this->assertSame($valid, AttachmentProxy::isValidFilename($name));
    }

    public function filenameProvider(): array
    {
        return [
            'nom généré par l\'API' => ['1712345678-ab12cd.pdf', true],
            'image'                 => ['photo_1.jpeg', true],
            'remontée de dossier'   => ['../../.env', false],
            'chemin'                => ['uploads/a.pdf', false],
            'antislash'             => ['uploads\\a.pdf', false],
            'double point'          => ['a..pdf', false],
            'fichier caché'         => ['.env', false],
            'vide'                  => ['', false],
            'encodé'                => ['..%2Fetc', false],
        ];
    }

    public function testSafeContentType(): void
    {
        $this->assertSame(['application/pdf', 'inline'], AttachmentProxy::safeContentType('application/pdf', false));
        $this->assertSame(['image/png', 'inline'], AttachmentProxy::safeContentType('image/png; charset=binary', false));
        $this->assertSame(['application/pdf', 'attachment'], AttachmentProxy::safeContentType('application/pdf', true));
        // Un HTML ou un SVG ne doit jamais s'afficher dans le domaine de l'admin
        $this->assertSame(['text/html', 'attachment'], AttachmentProxy::safeContentType('text/html', false));
        $this->assertSame(['image/svg+xml', 'attachment'], AttachmentProxy::safeContentType('image/svg+xml', false));
        $this->assertSame(['application/octet-stream', 'attachment'], AttachmentProxy::safeContentType('', false));
    }

    public function testInvalidNameNeverReachesTheApi(): void
    {
        $repo = $this->createMock(ReclamationRepository::class);
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('get');

        $this->expectException(NotFoundException::class);
        (new AttachmentProxy($repo, $client))->fetch(1, '../secret', false);
    }

    public function testAttachmentOfAnotherClaimIsRefused(): void
    {
        $repo = $this->createMock(ReclamationRepository::class);
        $repo->method('hasAttachment')->with(1, 'a.pdf')->willReturn(false);
        $client = $this->createMock(Client::class);
        $client->expects($this->never())->method('get');

        $this->expectException(NotFoundException::class);
        (new AttachmentProxy($repo, $client))->fetch(1, 'a.pdf', false);
    }

    public function testFetchUsesViewOrDownloadRoute(): void
    {
        $repo = $this->createMock(ReclamationRepository::class);
        $repo->method('hasAttachment')->willReturn(true);
        $client = $this->createMock(Client::class);
        $client->expects($this->exactly(2))->method('get')
            ->withConsecutive(['api/attachments/view/a.pdf'], ['api/attachments/download/a.pdf'])
            ->willReturn(new Response(200, '%PDF', 'application/pdf'));

        $proxy = new AttachmentProxy($repo, $client);
        $this->assertSame('%PDF', $proxy->fetch(1, 'a.pdf', false)->body);
        $this->assertSame('%PDF', $proxy->fetch(1, 'a.pdf', true)->body);
    }

    public function testMissingFileOnApiIsNotFound(): void
    {
        $repo = $this->createMock(ReclamationRepository::class);
        $repo->method('hasAttachment')->willReturn(true);
        $client = $this->createMock(Client::class);
        $client->method('get')->willReturn(new Response(404, '{"message":"File not found"}', 'application/json'));

        $this->expectException(NotFoundException::class);
        (new AttachmentProxy($repo, $client))->fetch(1, 'a.pdf', false);
    }

    public function testApiErrorIsReported(): void
    {
        $repo = $this->createMock(ReclamationRepository::class);
        $repo->method('hasAttachment')->willReturn(true);
        $client = $this->createMock(Client::class);
        $client->method('get')->willReturn(new Response(401, '{"message":"Clé invalide"}', 'application/json'));

        $this->expectException(ApiException::class);
        (new AttachmentProxy($repo, $client))->fetch(1, 'a.pdf', false);
    }
}
