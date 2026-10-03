<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model;

use Mytek\Marketplace\Model\SellerRepository;
use PHPUnit\Framework\TestCase;

class SellerRepositoryTest extends TestCase
{
    /**
     * @dataProvider statusProvider
     */
    public function testStatusLabel(int $status, string $expected): void
    {
        $this->assertSame($expected, SellerRepository::statusLabel($status));
    }

    public function statusProvider(): array
    {
        return [
            'en attente' => [SellerRepository::STATUS_PENDING, 'En attente'],
            'validé'     => [SellerRepository::STATUS_VALIDATED, 'Validé'],
            'refusé'     => [SellerRepository::STATUS_REFUSED, 'Refusé'],
            'inconnu'    => [9, 'En attente'],
        ];
    }
}
