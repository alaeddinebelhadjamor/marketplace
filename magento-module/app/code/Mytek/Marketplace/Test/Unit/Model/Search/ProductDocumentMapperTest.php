<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Search;

use Magento\Catalog\Model\Product;
use Mytek\Marketplace\Model\Search\ProductDocumentMapper;
use PHPUnit\Framework\TestCase;

class ProductDocumentMapperTest extends TestCase
{
    private function product(array $data): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn($data['sku']);
        $product->method('getName')->willReturn($data['name']);
        $product->method('getPrice')->willReturn($data['price']);
        $product->method('getFinalPrice')->willReturn($data['final_price'] ?? $data['price']);
        $product->method('getStatus')->willReturn($data['status'] ?? 1);
        $product->method('getData')->willReturnCallback(static fn(string $key) => $data[$key] ?? null);
        return $product;
    }

    public function testMapsAllFields(): void
    {
        $product = $this->product([
            'sku' => 'IPH-11', 'name' => 'iPhone 11', 'price' => 999.0, 'final_price' => 899.0,
            'status' => 1, 'short_description' => 'Un bon téléphone', 'url_key' => 'iphone-11',
            'image' => '/i/p/iphone.jpg', 'special_price' => 899.0,
        ]);

        $doc = ProductDocumentMapper::toDocument($product, 3);

        $this->assertSame([
            'sku' => 'IPH-11',
            'name' => 'iPhone 11',
            'short_description' => 'Un bon téléphone',
            'url_key' => 'iphone-11',
            'price' => 999.0,
            'final_price' => 899.0,
            'special_price' => 899.0,
            'status' => 1,
            'image' => '/i/p/iphone.jpg',
            'seller_id' => 3,
        ], $doc);
    }

    public function testMissingOptionalFieldsBecomeNullOrEmpty(): void
    {
        $product = $this->product(['sku' => 'X', 'name' => 'X', 'price' => 10.0]);

        $doc = ProductDocumentMapper::toDocument($product, 5);

        $this->assertSame('', $doc['short_description']);
        $this->assertNull($doc['url_key']);
        $this->assertNull($doc['special_price']);
        $this->assertNull($doc['image']);
        $this->assertSame(5, $doc['seller_id']);
    }

    public function testEmptyStringSpecialPriceIsTreatedAsAbsent(): void
    {
        $product = $this->product(['sku' => 'X', 'name' => 'X', 'price' => 10.0, 'special_price' => '']);
        $this->assertNull(ProductDocumentMapper::toDocument($product, 1)['special_price']);
    }
}
