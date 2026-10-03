<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Governorate;

use Mytek\Marketplace\Model\Governorate\GovernorateDiff;
use PHPUnit\Framework\TestCase;

class GovernorateDiffTest extends TestCase
{
    private const OPTIONS = ['Tunis' => 10, 'Ariana' => 11, 'Sfax' => 12];

    public function testProductWithoutSellerGovernorateGetsSet(): void
    {
        $diff = GovernorateDiff::compute(
            [3 => 'Tunis'],
            self::OPTIONS,
            [100 => ['seller_id' => 3, 'current_option_id' => null]]
        );

        $this->assertSame([10 => [100]], $diff['toSet']);
        $this->assertSame([], $diff['toClear']);
        $this->assertSame(1, $diff['checked']);
        $this->assertSame(1, $diff['updated']);
    }

    public function testAlreadyCorrectValueIsLeftAlone(): void
    {
        $diff = GovernorateDiff::compute(
            [3 => 'Tunis'],
            self::OPTIONS,
            [100 => ['seller_id' => 3, 'current_option_id' => 10]]
        );

        $this->assertSame([], $diff['toSet']);
        $this->assertSame([], $diff['toClear']);
        $this->assertSame(0, $diff['updated']);
    }

    public function testSellerChangedGovernorateUpdatesAllTheirProducts(): void
    {
        $diff = GovernorateDiff::compute(
            [3 => 'Sfax'], // le vendeur a changé de Tunis vers Sfax
            self::OPTIONS,
            [
                100 => ['seller_id' => 3, 'current_option_id' => 10], // Tunis -> Sfax
                101 => ['seller_id' => 3, 'current_option_id' => 10],
            ]
        );

        $this->assertSame([12 => [100, 101]], $diff['toSet']);
        $this->assertSame(2, $diff['updated']);
    }

    public function testSellerWithoutGovernorateClearsTheAttribute(): void
    {
        $diff = GovernorateDiff::compute(
            [], // aucun gouvernorat renseigné pour ce vendeur
            self::OPTIONS,
            [100 => ['seller_id' => 3, 'current_option_id' => 10]]
        );

        $this->assertSame([100], $diff['toClear']);
        $this->assertSame([], $diff['toSet']);
    }

    public function testUnknownGovernorateLabelClearsRatherThanGuessing(): void
    {
        $diff = GovernorateDiff::compute(
            [3 => 'Gouvernorat inconnu'],
            self::OPTIONS,
            [100 => ['seller_id' => 3, 'current_option_id' => null]]
        );

        $this->assertSame([], $diff['toSet']);
        $this->assertSame([], $diff['toClear']); // deja null, donc aucune mise a jour necessaire
        $this->assertSame(0, $diff['updated']);
    }

    public function testProductReassignedToAnotherSellerFollowsTheNewSeller(): void
    {
        $diff = GovernorateDiff::compute(
            [3 => 'Tunis', 5 => 'Sfax'],
            self::OPTIONS,
            [100 => ['seller_id' => 5, 'current_option_id' => 10]] // était au vendeur 3 (Tunis), rattaché au vendeur 5
        );

        $this->assertSame([12 => [100]], $diff['toSet']);
    }

    public function testGroupsMultipleProductsUnderTheSameOption(): void
    {
        $diff = GovernorateDiff::compute(
            [3 => 'Tunis', 5 => 'Tunis'],
            self::OPTIONS,
            [
                100 => ['seller_id' => 3, 'current_option_id' => null],
                101 => ['seller_id' => 5, 'current_option_id' => null],
            ]
        );

        $this->assertSame([10 => [100, 101]], $diff['toSet']);
    }
}
