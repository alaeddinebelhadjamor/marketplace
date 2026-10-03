<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model;

use Mytek\Marketplace\Model\ReclamationRepository;
use PHPUnit\Framework\TestCase;

class ReclamationRepositoryTest extends TestCase
{
    public function testFileNameHandlesUnixAndWindowsPaths(): void
    {
        $this->assertSame('1712-abc.pdf', ReclamationRepository::fileName('uploads/reclamations/1712-abc.pdf'));
        $this->assertSame('1712-abc.pdf', ReclamationRepository::fileName('uploads\\reclamations\\1712-abc.pdf'));
        $this->assertSame('photo.png', ReclamationRepository::fileName('photo.png'));
    }

    public function testIsResolved(): void
    {
        $this->assertTrue(ReclamationRepository::isResolved(['type' => '2']));
        $this->assertFalse(ReclamationRepository::isResolved(['type' => 1]));
        $this->assertFalse(ReclamationRepository::isResolved(['type' => 0]));
        $this->assertFalse(ReclamationRepository::isResolved([]));
    }

    public function testTypeLabel(): void
    {
        $this->assertSame('Notification', ReclamationRepository::typeLabel(ReclamationRepository::TYPE_NOTIFICATION));
        $this->assertSame('Open', ReclamationRepository::typeLabel(ReclamationRepository::TYPE_OPEN));
        $this->assertSame('Resolved', ReclamationRepository::typeLabel(ReclamationRepository::TYPE_RESOLVED));
    }
}
