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
        // L'amorce des tests rend les phrases __() sans traduction : on vérifie la clé.
        $this->assertSame($expected, SellerRepository::statusLabel($status));
    }

    public function statusProvider(): array
    {
        return [
            'en attente' => [SellerRepository::STATUS_PENDING, 'Pending'],
            'validé'     => [SellerRepository::STATUS_VALIDATED, 'Approved'],
            'refusé'     => [SellerRepository::STATUS_REFUSED, 'Refused'],
            'inconnu'    => [9, 'Pending'],
        ];
    }

    public function testEscapeLikeNeutralisesWildcards(): void
    {
        $this->assertSame('100\% coton\_bio', SellerRepository::escapeLike('100% coton_bio'));
        $this->assertSame('a\\\\b', SellerRepository::escapeLike('a\\b'));
        $this->assertSame('Ala Tech', SellerRepository::escapeLike('Ala Tech'));
    }
}
