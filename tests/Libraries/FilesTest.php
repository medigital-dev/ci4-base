<?php

namespace MedigitalDev\Ci4Base\Tests\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use MedigitalDev\Ci4Base\Libraries\Files;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase
{
    private Files $files;

    protected function setUp(): void
    {
        $this->files = new Files();
    }

    public function testIsExtensionAllowedReturnsTrueForAllowedExtension(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getClientExtension')->willReturn('pdf');

        $this->assertTrue($this->files->isExtensionAllowed($file, ['pdf', 'jpg', 'png']));
    }

    public function testIsExtensionAllowedIsCaseInsensitive(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getClientExtension')->willReturn('PDF');

        $this->assertTrue($this->files->isExtensionAllowed($file, ['pdf']));
    }

    public function testIsExtensionAllowedReturnsFalseForDisallowedExtension(): void
    {
        $file = $this->createMock(UploadedFile::class);
        $file->method('getClientExtension')->willReturn('exe');

        $this->assertFalse($this->files->isExtensionAllowed($file, ['pdf', 'jpg', 'png']));
    }

    public function testDeleteReturnsTrueWhenFileDoesNotExist(): void
    {
        $this->assertTrue($this->files->delete('/path/yang/tidak/ada.txt'));
    }

    public function testDeleteRemovesExistingFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ci4base_test_');
        $this->assertFileExists($tmpFile);

        $result = $this->files->delete($tmpFile);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($tmpFile);
    }
}
