<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Recommendation;

use Mytek\Marketplace\Model\Recommendation\RecommendationProvider;
use PHPUnit\Framework\TestCase;

class RecommendationProviderTest extends TestCase
{
    private function row(string $sku, array $extra = []): array
    {
        return $extra + [
            'sku' => $sku, 'name' => "Produit $sku", 'price' => 100, 'special_price' => null,
            'url_key' => strtolower($sku), 'image' => "http://img/$sku.jpg", 'score' => 1.0, 'source' => 'hybrid',
        ];
    }

    public function testKeepsEngineOrderAndLimit(): void
    {
        $payload = ['results' => [$this->row('B'), $this->row('C'), $this->row('D')]];

        $items = RecommendationProvider::normalize($payload, 'A', 2);

        $this->assertSame(['B', 'C'], array_column($items, 'sku'));
        $this->assertSame('hybrid', $items[0]['source']);
    }

    public function testExcludesCurrentProductDuplicatesAndIncompleteRows(): void
    {
        $payload = ['results' => [
            $this->row('a'),                      // produit courant (casse différente)
            $this->row('B'),
            $this->row('B'),                      // doublon
            $this->row('C', ['url_key' => '']),   // sans URL : lien impossible
            $this->row('D', ['name' => '  ']),    // sans nom
            'pas un tableau',
            $this->row('E'),
        ]];

        $items = RecommendationProvider::normalize($payload, 'A', 10);

        $this->assertSame(['B', 'E'], array_column($items, 'sku'));
    }

    public function testSpecialPriceKeptOnlyWhenItIsARealDiscount(): void
    {
        $payload = ['results' => [
            $this->row('B', ['price' => 1999, 'special_price' => 1599]),
            $this->row('C', ['price' => 500, 'special_price' => 500]),
            $this->row('D', ['price' => 500, 'special_price' => 0]),
        ]];

        $items = RecommendationProvider::normalize($payload, 'A', 10);

        $this->assertSame(1599.0, $items[0]['special_price']);
        $this->assertNull($items[1]['special_price']);
        $this->assertNull($items[2]['special_price']);
    }

    public function testEmptyImageBecomesNullAndMissingResultsGiveEmptyList(): void
    {
        $items = RecommendationProvider::normalize(['results' => [$this->row('B', ['image' => ''])]], 'A', 10);
        $this->assertNull($items[0]['image']);

        $this->assertSame([], RecommendationProvider::normalize([], 'A', 10));
        $this->assertSame([], RecommendationProvider::normalize(['results' => 'oops'], 'A', 10));
    }
}
