<?php

use App\Libraries\ExcelHandler;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * @internal
 */
final class ExcelHandlerTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/excel_handler_' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    private function makeFile(array $headers, array $rows, string $name = 'data.xlsx'): string
    {
        $excel = new ExcelHandler();
        $excel->setHeaders($headers)->writeRows($rows);

        return $excel->save($this->dir . $name);
    }

    // ------------------------------------------------------------------ baca/tulis

    public function testWriteThenReadRoundTrip(): void
    {
        $path = $this->makeFile(['NIS', 'Nama Siswa', 'Kelas'], [
            ['001', 'Budi', 'X-A'],
            ['002', 'Siti', 'X-B'],
        ]);

        $rows = (new ExcelHandler())->load($path)->toArray();

        $this->assertSame([
            ['nis' => '001', 'nama_siswa' => 'Budi', 'kelas' => 'X-A'],
            ['nis' => '002', 'nama_siswa' => 'Siti', 'kelas' => 'X-B'],
        ], $rows);
    }

    public function testAssociativeRowsAreWrittenByPosition(): void
    {
        $path = $this->makeFile(['A', 'B'], [['x' => 1, 'y' => 2]]);

        $this->assertEquals([['a' => 1, 'b' => 2]], (new ExcelHandler())->load($path)->toArray());
    }

    public function testLongDigitStringsAreStoredAsText(): void
    {
        $nik  = '3404010101010001';
        $path = $this->makeFile(['NIK', 'Kode'], [[$nik, '12345']]);

        // Dibuka tanpa readDataOnly supaya tipe sel terbaca.
        $ws = IOFactory::load($path)->getActiveSheet();

        $this->assertSame($nik, (string) $ws->getCell('A2')->getValue());
        $this->assertSame('s', $ws->getCell('A2')->getDataType());   // NIK 16 digit: teks
        $this->assertSame('n', $ws->getCell('B2')->getDataType());   // angka pendek: tetap angka
    }

    public function testDuplicateAndEmptyHeadersAreMadeUnique(): void
    {
        $path = $this->makeFile(['Nama', 'Nama', '', 'Nama'], [['a', 'b', 'c', 'd']]);

        $rows = (new ExcelHandler())->load($path)->toArray();

        $this->assertSame(['nama' => 'a', 'nama_2' => 'b', 'kolom_3' => 'c', 'nama_3' => 'd'], $rows[0]);
    }

    public function testHeaderOnLaterRowSkipsTitleRows(): void
    {
        $excel = new ExcelHandler();
        $excel->writeRows([
            ['REKAP DATA'],
            [],
            ['NIS', 'Nama'],
            ['1', 'Budi'],
            ['2', 'Siti'],
        ]);
        $path = $excel->save($this->dir . 'title.xlsx');

        $viaArray = (new ExcelHandler())->load($path)->toArray(0, 2);
        $viaChunk = iterator_to_array((new ExcelHandler())->load($path)->chunk(10, 0, 2), false);

        $expected = [['nis' => 1, 'nama' => 'Budi'], ['nis' => 2, 'nama' => 'Siti']];

        $this->assertEquals($expected, $viaArray);
        $this->assertEquals($expected, $viaChunk[0]);
    }

    public function testToArrayWithoutHeaderReturnsIndexedRows(): void
    {
        $path = $this->makeFile(['A', 'B'], [['x', 'y']]);

        $rows = (new ExcelHandler())->load($path)->toArray(0, false);

        $this->assertSame([['A', 'B'], ['x', 'y']], $rows);
    }

    public function testToArrayUnknownSheetThrows(): void
    {
        $path = $this->makeFile(['A'], [['x']]);

        $this->expectException(RuntimeException::class);
        (new ExcelHandler())->load($path)->toArray('TidakAda');
    }

    public function testLoadMissingFileThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new ExcelHandler())->load($this->dir . 'tidak-ada.xlsx');
    }

    // ------------------------------------------------------------------ chunk

    public function testChunkSplitsIntoBatchesAndKeepsAllRows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = [$i, "Siswa {$i}"];
        }

        $path = $this->makeFile(['No', 'Nama'], $rows);

        $sizes = [];
        $all   = [];

        foreach ((new ExcelHandler())->load($path)->chunk(10) as $batch) {
            $sizes[] = count($batch);
            $all     = array_merge($all, $batch);
        }

        $this->assertSame([10, 10, 5], $sizes);
        $this->assertSame(range(1, 25), array_map('intval', array_column($all, 'no')));
        $this->assertSame('Siswa 25', $all[24]['nama']);
    }

    public function testChunkEqualsToArray(): void
    {
        $rows = [];
        for ($i = 1; $i <= 40; $i++) {
            $rows[] = [$i, "N{$i}", $i * 2];
        }

        $path = $this->makeFile(['A', 'B', 'C'], $rows);

        $chunked = [];
        foreach ((new ExcelHandler())->load($path)->chunk(7) as $batch) {
            $chunked = array_merge($chunked, $batch);
        }

        $this->assertEquals((new ExcelHandler())->load($path)->toArray(), $chunked);
    }

    public function testChunkSelectsSheetByName(): void
    {
        $excel = new ExcelHandler();
        $excel->setHeaders(['A'])->writeRows([['pertama']]);
        $excel->setHeaders(['B'], 'Kedua')->writeRows([['kedua']], 'Kedua');
        $path = $excel->save($this->dir . 'multi.xlsx');

        $batches = iterator_to_array((new ExcelHandler())->load($path)->chunk(10, 'Kedua'), false);

        $this->assertSame([['b' => 'kedua']], $batches[0]);
    }

    public function testChunkUnknownSheetThrows(): void
    {
        $path = $this->makeFile(['A'], [['x']]);

        $this->expectException(RuntimeException::class);
        iterator_to_array((new ExcelHandler())->load($path)->chunk(10, 'TidakAda'));
    }

    // ------------------------------------------------------------------ template / tulis lanjutan

    public function testOpenForEditKeepsTemplateAndAppendsAfterDataRows(): void
    {
        $template = $this->makeFile(['NIS', 'Nama'], [['1', 'Budi']], 'template.xlsx');

        $excel = new ExcelHandler();
        $excel->openForEdit($template)->setCell('D1', 'Catatan')->writeRows([['2', 'Siti']]);
        $out = $excel->save($this->dir . 'hasil.xlsx');

        $rows = (new ExcelHandler())->load($out)->toArray(0, false);

        $this->assertSame('Catatan', $rows[0][3]);
        $this->assertSame('Siti', $rows[2][1]);
    }

    public function testAutoSizeColumnsCoversColumnsBeyondZ(): void
    {
        $excel = new class extends ExcelHandler {
            public function worksheet(): Worksheet
            {
                return $this->currentWorksheet();
            }
        };

        $excel->writeRows([range(1, 30)])->autoSizeColumns();

        $ws = $excel->worksheet();
        $this->assertTrue($ws->getColumnDimension('Z')->getAutoSize());
        $this->assertTrue($ws->getColumnDimension('AA')->getAutoSize());
        $this->assertTrue($ws->getColumnDimension('AD')->getAutoSize());
    }

    // ------------------------------------------------------------------ dropdown & validasi

    public function testShortDropdownUsesInlineList(): void
    {
        $excel = new ExcelHandler();
        $excel->setHeaders(['Status'])->setDropdown('A2:A10', ['Ya', 'Tidak']);
        $path = $excel->save($this->dir . 'dd.xlsx');

        $loaded = IOFactory::load($path);

        $this->assertSame(1, $loaded->getSheetCount());
        $this->assertSame('"Ya,Tidak"', $loaded->getActiveSheet()->getDataValidation('A5')->getFormula1());
    }

    public function testLongOrCommaDropdownUsesHiddenSheet(): void
    {
        $options = [];
        for ($i = 1; $i <= 60; $i++) {
            $options[] = "Jabatan nomor {$i}, bagian khusus";
        }

        $excel = new ExcelHandler();
        $excel->setHeaders(['Jabatan'])->setDropdown('A2:A50', $options, sourceKey: 'ref_jabatan');
        $path = $excel->save($this->dir . 'dd-long.xlsx');

        $loaded = IOFactory::load($path);
        $helper = $loaded->getSheetByName('ref_jabatan');

        $this->assertNotNull($helper);
        $this->assertSame(Worksheet::SHEETSTATE_HIDDEN, $helper->getSheetState());
        $this->assertSame($options[59], $helper->getCell('A60')->getValue());
        $this->assertSame("'ref_jabatan'!\$A\$1:\$A\$60", $loaded->getSheetByName('Sheet1')->getDataValidation('A10')->getFormula1());
    }

    public function testOptionWithQuoteFallsBackToHiddenSheet(): void
    {
        $excel = new ExcelHandler();
        $excel->setHeaders(['Ket'])->setDropdown('A2', ['Ya', 'Kelas "A"'], sourceKey: 'ref_ket');
        $path = $excel->save($this->dir . 'dd-quote.xlsx');

        $this->assertNotNull(IOFactory::load($path)->getSheetByName('ref_ket'));
    }

    public function testRangeValidationIsSingleRuleInFile(): void
    {
        $excel = new ExcelHandler();
        $excel->setHeaders(['Nilai'])->setDataValidation('A2:A500', ['type' => 'whole', 'min' => 0, 'max' => 100]);
        $path = $excel->save($this->dir . 'range.xlsx');

        $zip = new ZipArchive();
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertSame(1, substr_count($xml, '<dataValidation '));
        $this->assertStringContainsString('sqref="A2:A500"', $xml);
    }

    public function testSingleBoundOperatorDefaults(): void
    {
        $excel = new ExcelHandler();
        $excel->setDataValidation('A1', ['type' => 'whole', 'min' => 5]);
        $excel->setDataValidation('B1', ['type' => 'whole', 'max' => 9]);
        $path = $excel->save($this->dir . 'ops.xlsx');

        $ws = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('greaterThanOrEqual', $ws->getDataValidation('A1')->getOperator());
        $this->assertSame('5', $ws->getDataValidation('A1')->getFormula1());
        $this->assertSame('lessThanOrEqual', $ws->getDataValidation('B1')->getOperator());
        $this->assertSame('9', $ws->getDataValidation('B1')->getFormula1());
    }

    public function testDateValidationConvertsToExcelSerial(): void
    {
        $excel = new ExcelHandler();
        $excel->setDataValidation('A1', ['type' => 'date', 'min' => '2026-01-01']);
        $path = $excel->save($this->dir . 'date.xlsx');

        $formula = IOFactory::load($path)->getActiveSheet()->getDataValidation('A1')->getFormula1();

        $this->assertTrue(is_numeric($formula));
        $this->assertGreaterThan(40000, (float) $formula);
    }

    public function testInvalidValidationConfigThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new ExcelHandler())->setDataValidation('A1', ['type' => 'ngawur']);
    }

    public function testBetweenWithoutMaxThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new ExcelHandler())->setDataValidation('A1', ['type' => 'whole', 'operator' => 'between', 'min' => 1]);
    }

    public function testEmptyDropdownOptionsThrow(): void
    {
        $this->expectException(RuntimeException::class);
        (new ExcelHandler())->setDropdown('A1', [null, '']);
    }

    // ------------------------------------------------------------------ download

    public function testDownloadSetsHeadersAndLeavesNoTempFile(): void
    {
        $excel = new ExcelHandler();
        $excel->setHeaders(['A'])->writeRows([['x']]);

        $before   = glob(WRITEPATH . 'cache/excel_*') ?: [];
        $response = $excel->download("lap\"oran\r\n.xlsx");
        $after    = glob(WRITEPATH . 'cache/excel_*') ?: [];

        $this->assertSame($before, $after);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->getHeaderLine('Content-Type')
        );
        $this->assertStringContainsString('filename="lap_oran_.xlsx"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('PK', substr($response->getBody(), 0, 2)); // xlsx = zip
    }

    // ------------------------------------------------------------------ read() dari upload

    public function testReadAcceptsRealExcelUpload(): void
    {
        $path = $this->makeFile(['A'], [['x']]);

        $file = new UploadedFile($path, 'data.xlsx', 'application/vnd.ms-excel', filesize($path), UPLOAD_ERR_OK);

        $this->assertSame([['a' => 'x']], (new ExcelHandler())->read($file)->toArray());
    }

    public function testReadRejectsWrongExtension(): void
    {
        $path = $this->makeFile(['A'], [['x']]);
        $file = new UploadedFile($path, 'data.csv', 'text/csv', filesize($path), UPLOAD_ERR_OK);

        $this->expectException(RuntimeException::class);
        (new ExcelHandler())->read($file);
    }

    public function testReadRejectsTextFileRenamedToXlsx(): void
    {
        $path = $this->dir . 'palsu.xlsx';
        file_put_contents($path, "nama,kelas\nBudi,X\n");
        $file = new UploadedFile($path, 'palsu.xlsx', 'application/vnd.ms-excel', filesize($path), UPLOAD_ERR_OK);

        $this->expectException(RuntimeException::class);
        (new ExcelHandler())->read($file);
    }
}
