<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Search;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientFactory;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Mytek\Marketplace\Model\Config;
use Mytek\Marketplace\Model\Search\OpenSearchIndexClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OpenSearchIndexClientTest extends TestCase
{
    private Config $config;
    private GuzzleClient $guzzle;
    private ClientFactory $factory;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getOpenSearchUrl')->willReturn('http://localhost:9201');
        $this->config->method('getOpenSearchIndexPrefix')->willReturn('opensearch_index');
        $this->guzzle = $this->createMock(GuzzleClient::class);
        $this->factory = $this->createMock(ClientFactory::class);
        $this->factory->method('create')->willReturn($this->guzzle);
    }

    private function client(): OpenSearchIndexClient
    {
        return new OpenSearchIndexClient($this->config, $this->factory, $this->createMock(LoggerInterface::class));
    }

    public function testListIndicesParsesCatOutput(): void
    {
        $this->guzzle->method('request')->willReturn(new PsrResponse(200, [], "opensearch_index_20261001\nopensearch_index_20261002\n"));
        $this->assertSame(['opensearch_index_20261001', 'opensearch_index_20261002'], $this->client()->listIndices());
    }

    public function testListIndicesEmptyBodyReturnsEmptyArray(): void
    {
        $this->guzzle->method('request')->willReturn(new PsrResponse(200, [], ''));
        $this->assertSame([], $this->client()->listIndices());
    }

    public function testBulkIndexSendsNdjsonWithIndexAndIdPerDocument(): void
    {
        $this->guzzle->expects($this->once())->method('request')->with(
            'POST',
            '_bulk',
            $this->callback(function (array $options): bool {
                $lines = explode("\n", trim($options['body']));
                return $options['headers']['Content-Type'] === 'application/x-ndjson'
                    && count($lines) === 4
                    && json_decode($lines[0], true)['index']['_index'] === 'opensearch_index_1'
                    && json_decode($lines[0], true)['index']['_id'] === 'SKU-1'
                    && json_decode($lines[1], true)['sku'] === 'SKU-1';
            })
        )->willReturn(new PsrResponse(200));

        $this->client()->bulkIndex('opensearch_index_1', [
            'SKU-1' => ['sku' => 'SKU-1'],
            'SKU-2' => ['sku' => 'SKU-2'],
        ]);
    }

    public function testBulkIndexWithNoDocumentsDoesNothing(): void
    {
        $this->guzzle->expects($this->never())->method('request');
        $this->client()->bulkIndex('opensearch_index_1', []);
    }

    public function testDeleteDocumentIgnores404(): void
    {
        $this->guzzle->method('request')->willReturn(new PsrResponse(404));
        // Ne doit pas lancer d'exception.
        $this->client()->deleteDocument('opensearch_index_1', 'SKU-1');
        $this->addToAssertionCount(1);
    }

    public function testFailedRequestThrows(): void
    {
        $this->guzzle->method('request')->willReturn(new PsrResponse(500, [], 'boom'));
        $this->expectException(\RuntimeException::class);
        $this->client()->indexDocument('opensearch_index_1', 'SKU-1', ['sku' => 'SKU-1']);
    }
}
