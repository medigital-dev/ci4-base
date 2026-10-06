<?php

use App\Libraries\ExcelHandler;
use App\Libraries\ExcelStreamWriter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ExcelStreamWriterTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/excel_stream_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '*') ?: [] as $entry) {
            is_dir($entry) ? rmdir($entry) : unlink($entry);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    public function testWriteAndReadBackWithHeader(): void
    {
        $writer = new ExcelStreamWriter();
        $writer->open($this->dir . 'out.xlsx', ['NO', 'NAMA'])
            ->writeRow([1, 'Budi'])
            ->writeRows([[2, 'Siti'], ['x' => 3, 'y' => 'Andi']]);
        $path = $writer->close();

        $rows = (new ExcelHandler())->load($path)->toArray();

        $this->assertSame($this->dir . 'out.xlsx', $path);
        $this->assertEquals([
            ['no' => 1, 'nama' => 'Budi'],
            ['no' => 2, 'nama' => 'Siti'],
            ['no' => 3, 'nama' => 'Andi'],
        ], $rows);
    }

    public function testWorksWithoutHeader(): void
    {
        $writer = new ExcelStreamWriter();
        $writer->open($this->dir . 'nohead.xlsx')->writeRow(['a', 'b']);
        $path = $writer->close();

        $this->assertSame([['a', 'b']], (new ExcelHandler())->load($path)->toArray(0, false));
    }

    public function testLongDigitStringsStayExact(): void
    {
        $nik = '3404010101010001';

        $writer = new ExcelStreamWriter();
        $writer->open($this->dir . 'nik.xlsx', ['NIK'])->writeRow([$nik]);
        $path = $writer->close();

        $this->assertSame($nik, (string) (new ExcelHandler())->load($path)->toArray()[0]['nik']);
    }

    public function testCreatesMissingFolder(): void
    {
        $writer = new ExcelStreamWriter();
        $writer->open($this->dir . 'sub/out.xlsx')->writeRow(['x']);
        $path = $writer->close();

        $this->assertFileExists($path);

        unlink($path);
    }

    public function testIsOpenFollowsLifecycle(): void
    {
        $writer = new ExcelStreamWriter();

        $this->assertFalse($writer->isOpen());
        $writer->open($this->dir . 'a.xlsx');
        $this->assertTrue($writer->isOpen());
        $writer->close();
        $this->assertFalse($writer->isOpen());
    }

    public function testOpenTwiceThrows(): void
    {
        $writer = new ExcelStreamWriter();
        $writer->open($this->dir . 'a.xlsx');

        try {
            $this->expectException(RuntimeException::class);
            $writer->open($this->dir . 'b.xlsx');
        } finally {
            $writer->close();
        }
    }

    public function testWriteBeforeOpenThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new ExcelStreamWriter())->writeRow(['x']);
    }

    public function testCloseBeforeOpenThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new ExcelStreamWriter())->close();
    }

    public function testDestructorClosesForgottenStream(): void
    {
        $path = $this->dir . 'lupa.xlsx';

        $writer = new ExcelStreamWriter();
        $writer->open($path, ['A'])->writeRow(['x']);
        unset($writer); // close() tidak dipanggil

        $this->assertSame([['a' => 'x']], (new ExcelHandler())->load($path)->toArray());
    }

    public function testManyRowsRoundTrip(): void
    {
        $writer = new ExcelStreamWriter();
        $writer->open($this->dir . 'besar.xlsx', ['NO', 'NAMA']);

        for ($i = 1; $i <= 5000; $i++) {
            $writer->writeRow([$i, "Siswa {$i}"]);
        }

        $path = $writer->close();

        $count = 0;
        foreach ((new ExcelHandler())->load($path)->chunk(1000) as $batch) {
            $count += count($batch);
        }

        $this->assertSame(5000, $count);
    }
}
