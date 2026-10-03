<?php
declare(strict_types=1);

namespace Mytek\Marketplace\Test\Unit\Model\Notification;

use Mytek\Marketplace\Model\Notification\UploadedFilesReader;
use PHPUnit\Framework\TestCase;

class UploadedFilesReaderTest extends TestCase
{
    public function testEmptyOrMissingInputYieldsNoFiles(): void
    {
        $this->assertSame([], UploadedFilesReader::normalize(null));
        $this->assertSame([], UploadedFilesReader::normalize([]));
        $this->assertSame([], UploadedFilesReader::normalize(['tmp_name' => '']));
    }

    public function testNormalizesMultipleFilesAndSkipsEmptySlots(): void
    {
        $raw = [
            'name'     => ['a.pdf', '', 'b.jpg'],
            'tmp_name' => ['/tmp/php1', '', '/tmp/php2'],
            'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
        ];

        $files = UploadedFilesReader::normalize($raw, static fn() => true);

        $this->assertSame([
            ['path' => '/tmp/php1', 'name' => 'a.pdf'],
            ['path' => '/tmp/php2', 'name' => 'b.jpg'],
        ], $files);
    }

    public function testSkipsFilesWithUploadError(): void
    {
        $raw = [
            'name'     => ['too-big.pdf'],
            'tmp_name' => ['/tmp/php1'],
            'error'    => [UPLOAD_ERR_INI_SIZE],
        ];

        $this->assertSame([], UploadedFilesReader::normalize($raw, static fn() => true));
    }

    public function testSkipsPathsThatAreNotRealUploads(): void
    {
        // Simule is_uploaded_file() renvoyant faux (protection contre une injection de chemin)
        $raw = [
            'name'     => ['a.pdf'],
            'tmp_name' => ['/etc/passwd'],
            'error'    => [UPLOAD_ERR_OK],
        ];

        $this->assertSame([], UploadedFilesReader::normalize($raw, static fn() => false));
    }

    public function testSingleFileWithoutMultipleStillWorks(): void
    {
        $raw = ['name' => ['only.pdf'], 'tmp_name' => ['/tmp/php9'], 'error' => [0]];
        $this->assertSame([['path' => '/tmp/php9', 'name' => 'only.pdf']], UploadedFilesReader::normalize($raw, static fn() => true));
    }
}
