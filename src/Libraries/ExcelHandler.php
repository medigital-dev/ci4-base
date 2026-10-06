<?php

namespace MedigitalDev\Ci4Base\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * ExcelHandler
 *
 * Library CI4 untuk baca (read), tulis (write/export), dan edit template file
 * Excel, dibangun di atas PhpSpreadsheet.
 *
 * Install dulu:
 *   composer require phpoffice/phpspreadsheet
 *
 * Untuk export dataset besar (ribuan-puluhan ribu baris) pakai
 * \App\Libraries\ExcelStreamWriter (OpenSpout, streaming). Library ini
 * menyimpan seluruh workbook di memori sehingga tidak cocok untuk itu.
 *
 * ── BACA ──────────────────────────────────────────────────────────────
 *   $excel = new \App\Libraries\ExcelHandler();
 *   $excel->load(WRITEPATH . 'uploads/data.xlsx');   // atau ->read($uploadedFile)
 *   $rows  = $excel->toArray(); // header baris pertama -> key kolom
 *
 *   // File besar, baca per-batch:
 *   foreach ($excel->chunk(500) as $batch) {
 *       $model->insertBatch($batch);
 *   }
 *
 * ── TULIS ─────────────────────────────────────────────────────────────
 *   $excel = new \App\Libraries\ExcelHandler();
 *   $excel->setHeaders(['NIS', 'Nama', 'Kelas'])
 *         ->writeRows($students) // array of array / array asosiatif
 *         ->autoSizeColumns()
 *         ->styleHeaderRow();
 *
 *   $excel->save(WRITEPATH . 'exports/siswa.xlsx');   // simpan ke disk
 *   // atau langsung kirim ke browser dari controller:
 *   return $excel->download('siswa.xlsx');
 *
 * ── EDIT TEMPLATE ─────────────────────────────────────────────────────
 *   $excel->openForEdit(APPPATH . 'templates/rekap.xlsx')
 *         ->setCell('C4', 'Yogyakarta')
 *         ->save(WRITEPATH . 'exports/rekap-final.xlsx');
 */
class ExcelHandler
{
    /** Tipe file yang diterima read() — dideteksi dari ISI file, bukan hanya ekstensi. */
    protected const READABLE_TYPES = ['Xlsx', 'Xls'];

    /** Excel hanya menyimpan 15 digit angka dengan akurat. */
    protected const MAX_NUMERIC_DIGITS = 15;

    protected ?Spreadsheet $spreadsheet = null;
    protected ?string $filePath        = null;
    protected string $activeSheet      = 'Sheet1';

    public function __construct()
    {
        // Kosong: bisa dipakai untuk mode baca (load()) maupun mode tulis
        // (langsung panggil setHeaders()/writeRows(), spreadsheet baru dibuat lazy).
    }

    // ======================================================================
    // MODE BACA
    // ======================================================================

    /**
     * Buka file Excel yang sudah ada untuk dibaca.
     *
     * Mode ini HANYA untuk membaca (toArray()/chunk()): file tidak disimpan penuh
     * di memori, tiap pemanggilan baca akan membuka ulang file dari disk secara
     * ringan. Karena itu setCell()/writeRows()/save() TIDAK akan menulis ke file
     * ini — itu akan membuat spreadsheet baru yang kosong.
     *
     * Untuk mengedit file yang sudah ada (isi template, ubah sel, lalu simpan),
     * pakai openForEdit() di bawah.
     */
    public function load(string $filePath): static
    {
        if (! is_file($filePath)) {
            throw new RuntimeException("File tidak ditemukan: {$filePath}");
        }

        $this->filePath = $filePath;

        return $this;
    }

    /**
     * Buka file dari form upload (CI4 UploadedFile) untuk dibaca.
     *
     * Dicek dua lapis: ekstensi (.xls/.xlsx) dan ISI file (harus benar-benar
     * Xlsx/Xls, bukan CSV/HTML/teks yang cuma diganti namanya). File dibaca
     * langsung dari lokasi sementara upload PHP, jadi TIDAK dipindah dan tidak
     * ada sisa file yang perlu dibersihkan — tapi juga hanya hidup selama request.
     * Kalau hasil upload mau diproses nanti (queue/cron), simpan dulu dengan
     * $file->move() lalu pakai load().
     */
    public function read(UploadedFile $file): static
    {
        if (! $file->isValid() || $file->hasMoved()) {
            throw new RuntimeException('Upload Error: ' . $file->getErrorString());
        }

        if (! in_array(strtolower($file->getClientExtension()), ['xls', 'xlsx'], true)) {
            throw new RuntimeException('Upload Error: hanya file .xls atau .xlsx yang diterima.');
        }

        $path = $file->getTempName();

        if (! is_file($path)) {
            throw new RuntimeException("File tidak ditemukan: {$path}");
        }

        try {
            $type = IOFactory::identify($path);
        } catch (\Throwable $e) {
            log_message('error', 'Read Excel Error: ' . $e->getMessage());

            throw new RuntimeException('Upload Error: isi file bukan Excel yang valid.');
        }

        if (! in_array($type, self::READABLE_TYPES, true)) {
            throw new RuntimeException('Upload Error: isi file bukan Excel (terdeteksi: ' . $type . ').');
        }

        $this->filePath = $path;

        return $this;
    }

    /**
     * Buka file Excel yang sudah ada untuk DIEDIT (bukan sekadar dibaca).
     * Seluruh isi file dimuat ke memori, sehingga setCell(), setCells(),
     * writeRows(), styleHeaderRow(), dst akan menulis langsung ke atas data
     * yang sudah ada, dan save()/download() menyimpan hasilnya.
     *
     * Cocok untuk kasus "isi template laporan yang sudah ada layout-nya"
     * (kop surat, format baku instansi, dsb).
     *
     * Contoh:
     *   $excel = new ExcelHandler();
     *   $excel->openForEdit(APPPATH . 'templates/template_rekap.xlsx')
     *         ->setCell('C4', 'Yogyakarta')
     *         ->setCell('C5', date('d-m-Y'));
     *
     *   $excel->save(WRITEPATH . 'exports/rekap-final.xlsx');
     */
    public function openForEdit(string $filePath): static
    {
        if (! is_file($filePath)) {
            throw new RuntimeException("File tidak ditemukan: {$filePath}");
        }

        $this->filePath    = $filePath;
        $reader            = IOFactory::createReaderForFile($filePath);
        $this->spreadsheet = $reader->load($filePath);
        $this->activeSheet = $this->spreadsheet->getActiveSheet()->getTitle();

        return $this;
    }

    /**
     * Daftar nama sheet dalam file, tanpa load seluruh data.
     */
    public function getSheetNames(): array
    {
        $this->assertLoaded();

        return IOFactory::createReaderForFile($this->filePath)
            ->listWorksheetNames($this->filePath);
    }

    /**
     * Baca seluruh sheet menjadi array.
     *
     * Header dinormalisasi jadi key (huruf kecil, spasi -> underscore, simbol dibuang).
     * Header kosong dinamai kolom_N, header kembar diberi sufiks _2, _3, dst —
     * jadi tidak ada kolom yang tertimpa diam-diam.
     *
     * @param int|string $sheet     Index (0-based) atau nama sheet.
     * @param bool|int   $hasHeader Baris mana yang dipakai sebagai key kolom (0-based).
     *                              - true  (default) -> baris ke-0 (baris pertama file).
     *                              - false            -> tanpa header, hasil berupa array indexed.
     *                              - int (0, 1, 2, …) -> baris ke-N dipakai sebagai header;
     *                                semua baris sebelum & termasuk baris header dilewati.
     * @param bool       $trim      Trim spasi di awal/akhir nilai string.
     */
    public function toArray($sheet = 0, bool|int $hasHeader = true, bool $trim = true): array
    {
        $this->assertLoaded();

        $reader = IOFactory::createReaderForFile($this->filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($this->filePath);

        try {
            $worksheet = is_int($sheet) ? $spreadsheet->getSheet($sheet) : $spreadsheet->getSheetByName($sheet);
            if ($worksheet === null) {
                throw new RuntimeException("Sheet tidak ditemukan: {$sheet}");
            }

            $rows = $worksheet->toArray(null, true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        $headerRowIndex = $this->resolveHeaderRowIndex($hasHeader);
        $header         = null;

        if ($headerRowIndex !== null) {
            if (! array_key_exists($headerRowIndex, $rows)) {
                throw new RuntimeException("Baris header ke-{$headerRowIndex} tidak ditemukan di sheet.");
            }

            $header = $this->buildHeader($rows[$headerRowIndex]);

            // Buang baris header beserta semua baris sebelumnya (mis. judul/baris kosong di atas header).
            $rows = array_slice($rows, $headerRowIndex + 1);
        }

        return $this->processRows($rows, $header, $trim);
    }

    /**
     * Baca sheet secara bertahap (generator) dalam potongan N baris.
     * Memori tetap kecil walau file besar: ukuran sheet dibaca tanpa memuat sel,
     * dan tiap potongan hanya memuat baris miliknya.
     *
     * Catatan performa: format .xlsx tidak bisa di-seek, jadi setiap potongan
     * membaca ulang XML sheet dari awal. Total waktu naik seiring jumlah potongan;
     * untuk file puluhan ribu baris pakai $chunkSize lebih besar (mis. 1000-2000).
     *
     * @param int        $chunkSize Jumlah baris per potongan.
     * @param int|string $sheet     Index (0-based) atau nama sheet.
     * @param bool|int   $hasHeader Sama seperti toArray().
     * @param bool       $trim      Trim spasi di awal/akhir nilai string.
     *
     * @return \Generator<int, array<int, array<string|int, mixed>>>
     */
    public function chunk(int $chunkSize = 500, $sheet = 0, bool|int $hasHeader = true, bool $trim = true): \Generator
    {
        $this->assertLoaded();

        if ($chunkSize < 1) {
            throw new RuntimeException('chunkSize minimal 1.');
        }

        // Ukuran sheet dari listWorksheetInfo(): memindai XML tanpa membangun sel di memori.
        $infoReader = IOFactory::createReaderForFile($this->filePath);
        $infoReader->setReadDataOnly(true);

        $info = null;
        foreach ($infoReader->listWorksheetInfo($this->filePath) as $index => $candidate) {
            if (is_int($sheet) ? $index === $sheet : $candidate['worksheetName'] === $sheet) {
                $info = $candidate;
                break;
            }
        }

        if ($info === null) {
            throw new RuntimeException('Sheet tidak ditemukan: ' . $sheet);
        }

        $sheetName  = $info['worksheetName'];
        $lastColumn = $info['lastColumnLetter'];
        $totalRows  = (int) $info['totalRows'];

        $headerRowIndex = $this->resolveHeaderRowIndex($hasHeader);              // 0-based, null = tanpa header
        $headerSheetRow = $headerRowIndex !== null ? $headerRowIndex + 1 : null; // baris fisik (1-based)

        $header = null;
        if ($headerSheetRow !== null) {
            if ($headerSheetRow > $totalRows) {
                throw new RuntimeException("Baris header ke-{$headerRowIndex} tidak ditemukan di sheet.");
            }

            $header = $this->buildHeader($this->readRange($sheetName, $headerSheetRow, $headerSheetRow, $lastColumn)[0]);
        }

        $startFrom = $headerSheetRow !== null ? $headerSheetRow + 1 : 1;

        for ($start = $startFrom; $start <= $totalRows; $start += $chunkSize) {
            $end   = min($start + $chunkSize - 1, $totalRows);
            $batch = $this->processRows($this->readRange($sheetName, $start, $end, $lastColumn), $header, $trim);

            if (! empty($batch)) {
                yield $batch;
            }
        }
    }

    /**
     * Baca satu rentang baris dari satu sheet dengan read filter (hanya sel di rentang itu yang dimuat).
     */
    protected function readRange(string $sheetName, int $startRow, int $endRow, string $lastColumn): array
    {
        $filter = new class($startRow, $endRow) implements IReadFilter {
            public function __construct(private int $startRow, private int $endRow) {}

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->startRow && $row <= $this->endRow;
            }
        };

        $reader = IOFactory::createReaderForFile($this->filePath);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter($filter);

        $spreadsheet = $reader->load($this->filePath);

        try {
            return $spreadsheet->getSheetByName($sheetName)
                ->rangeToArray("A{$startRow}:{$lastColumn}{$endRow}", null, true, true, false);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    // ======================================================================
    // MODE TULIS
    // ======================================================================

    /**
     * Set/ganti sheet aktif untuk operasi tulis. Membuat sheet baru jika belum ada.
     */
    public function sheet(string $name): static
    {
        $this->initWriter();

        if (! $this->spreadsheet->sheetNameExists($name)) {
            $this->spreadsheet->createSheet()->setTitle($name);
        }

        $this->activeSheet = $name;
        $this->spreadsheet->setActiveSheetIndexByName($name);

        return $this;
    }

    /**
     * Tulis baris header pada baris pertama sheet aktif.
     */
    public function setHeaders(array $headers, ?string $sheetName = null): static
    {
        $this->initWriter();

        if ($sheetName !== null) {
            $this->sheet($sheetName);
        }

        $ws  = $this->currentWorksheet();
        $col = 1;

        foreach ($headers as $header) {
            $ws->setCellValue([$col, 1], $header);
            $col++;
        }

        return $this;
    }

    /**
     * Tulis banyak baris sekaligus, dimulai setelah baris terakhir yang terisi.
     *
     * Tiap baris berupa array indexed atau asosiatif. Yang dipakai adalah URUTAN
     * value-nya; key diabaikan (tidak dicocokkan dengan header).
     *
     * String yang seluruhnya angka dan lebih dari 15 digit (NIK, nomor rekening)
     * otomatis ditulis eksplisit sebagai TEKS, supaya hasilnya tidak bergantung
     * pada versi PhpSpreadsheet. Sel angka berisi lebih dari 15 digit bermasalah
     * di Excel: tampil 3,40401E+15 dan digit setelah ke-15 hilang begitu sel
     * diedit/disimpan ulang.
     */
    public function writeRows(array $rows, ?string $sheetName = null): static
    {
        $this->initWriter();

        if ($sheetName !== null) {
            $this->sheet($sheetName);
        }

        $ws       = $this->currentWorksheet();
        $rowIndex = $this->nextEmptyRow($ws);

        foreach ($rows as $row) {
            $col = 1;

            foreach (array_values($row) as $value) {
                if (is_string($value) && strlen($value) > self::MAX_NUMERIC_DIGITS && ctype_digit($value)) {
                    $ws->setCellValueExplicit([$col, $rowIndex], $value, DataType::TYPE_STRING);
                } else {
                    $ws->setCellValue([$col, $rowIndex], $value);
                }

                $col++;
            }

            $rowIndex++;
        }

        return $this;
    }

    /**
     * Tulis satu baris saja. Jangan dipanggil berulang untuk dataset besar
     * (ribuan baris ke atas): PhpSpreadsheet menyimpan seluruh workbook di
     * memori — pakai ExcelStreamWriter untuk itu.
     */
    public function writeRow(array $row, ?string $sheetName = null): static
    {
        return $this->writeRows([$row], $sheetName);
    }

    /**
     * Tulis nilai ke satu sel tertentu.
     *
     * Terima dua bentuk koordinat:
     *   - String gaya Excel: 'B3', 'AA10', dst.
     *   - Array [kolom, baris] 1-based: [2, 3] artinya sama dengan 'B3'.
     *
     * Contoh:
     *   $excel->setCell('B3', 'Budi');
     *   $excel->setCell([2, 3], 'Budi');             // sama saja dengan di atas
     *   $excel->setCell('D1', 'Rekap', 'Ringkasan'); // ke sheet lain
     *
     * Nilai string yang diawali '=' dibaca Excel sebagai rumus.
     */
    public function setCell(string|array $coordinate, mixed $value, ?string $sheetName = null): static
    {
        $this->initWriter();

        if ($sheetName !== null) {
            $this->sheet($sheetName);
        }

        $this->currentWorksheet()->setCellValue($coordinate, $value);

        return $this;
    }

    /**
     * Tulis banyak sel sekaligus dalam satu panggilan, dengan koordinat string sebagai key.
     *
     * Contoh:
     *   $excel->setCells([
     *       'B3' => 'Budi',
     *       'C3' => 'Guru',
     *       'D3' => 2024,
     *   ]);
     */
    public function setCells(array $cells, ?string $sheetName = null): static
    {
        $this->initWriter();

        if ($sheetName !== null) {
            $this->sheet($sheetName);
        }

        $ws = $this->currentWorksheet();

        foreach ($cells as $coordinate => $value) {
            $ws->setCellValue($coordinate, $value);
        }

        return $this;
    }

    /**
     * Ambil nilai dari satu sel tertentu (berguna untuk isi template: baca dulu, cek, lalu tulis ulang).
     */
    public function getCell(string|array $coordinate, ?string $sheetName = null): mixed
    {
        $this->initWriter();

        if ($sheetName !== null) {
            $this->sheet($sheetName);
        }

        return $this->currentWorksheet()->getCell($coordinate)->getValue();
    }

    /**
     * Pasang dropdown (daftar pilihan) pada satu sel, beberapa sel, atau range sekaligus.
     *
     * PENTING: Excel membatasi list inline (`"opsi1,opsi2,..."`) maksimal 255 karakter
     * total. Untuk daftar pendek yang tidak berubah (mis. ['Ya','Tidak']) itu aman.
     * Tapi untuk daftar dari data referensi database yang jumlah/panjangnya bisa berubah,
     * inline list bisa diam-diam gagal atau merusak file begitu datanya bertambah.
     * Method ini OTOMATIS menulis opsi ke sheet bantuan tersembunyi dan membuat dropdown
     * merujuk ke range sheet itu — aman untuk daftar berapa pun panjangnya, dan tidak
     * bermasalah walau ada opsi yang mengandung koma atau tanda kutip.
     *
     * @param string|array $coordinate Satu koordinat ('B2'), banyak koordinat (['B2','B3']),
     *                                  atau range ('B2:B100').
     * @param array        $options    Daftar pilihan, contoh: array_column($rows, 'name').
     * @param string|null  $sourceKey  Nama unik untuk sheet bantuan (dipakai sebagai judul sheet
     *                                  tersembunyi). Wajib diisi beda-beda kalau kamu pasang lebih
     *                                  dari satu dropdown dengan sumber data berbeda dalam satu
     *                                  file, misal 'ref_kategori' dan 'ref_status'. Kalau
     *                                  dikosongkan, dibuat otomatis dari isi $options (kurang stabil
     *                                  untuk dipanggil ulang, sebaiknya selalu diisi eksplisit).
     *
     * Contoh:
     *   $excel->setDropdown('D4:D200', array_column($kategori, 'name'), sourceKey: 'ref_kategori');
     *   $excel->setDropdown('E4:E200', array_column($status, 'name'), sourceKey: 'ref_status');
     */
    public function setDropdown(
        string|array $coordinate,
        array $options,
        ?string $sheetName = null,
        bool $allowBlank = true,
        ?string $errorMessage = null,
        ?string $sourceKey = null
    ): static {
        $options = array_values(array_filter($options, fn($v) => $v !== null && $v !== ''));

        if (empty($options)) {
            throw new RuntimeException('Daftar opsi dropdown kosong — pastikan data referensi tersedia sebelum memanggil setDropdown().');
        }

        $inlineLength = strlen(implode(',', $options)) + 2; // +2 untuk tanda kutip pembuka/penutup

        // Daftar pendek & aman (<= 255 karakter, tanpa koma/kutip di dalam opsi) -> inline list, lebih ringan.
        $unsafe = array_reduce(
            $options,
            fn($carry, $v) => $carry || str_contains((string) $v, ',') || str_contains((string) $v, '"'),
            false
        );

        if ($inlineLength <= 255 && ! $unsafe) {
            return $this->setDataValidation($coordinate, [
                'type'        => 'list',
                'options'     => $options,
                'allowBlank'  => $allowBlank,
                'errorTitle'  => 'Pilihan tidak valid',
                'error'       => $errorMessage ?? ('Nilai harus salah satu dari: ' . implode(', ', $options)),
                'promptTitle' => 'Pilih salah satu',
                'prompt'      => implode(', ', $options),
            ], $sheetName);
        }

        // Daftar panjang / mengandung koma atau kutip -> tulis ke sheet bantuan tersembunyi, dropdown rujuk ke range.
        $sourceKey   = $sourceKey ?? ('_ref_' . substr(md5(implode('|', $options)), 0, 8));
        $sourceRange = $this->writeHiddenOptionList($sourceKey, $options);

        return $this->setDataValidation($coordinate, [
            'type'        => 'list',
            'source'      => $sourceRange,
            'allowBlank'  => $allowBlank,
            'errorTitle'  => 'Pilihan tidak valid',
            'error'       => $errorMessage ?? 'Nilai harus dipilih dari daftar yang tersedia.',
            'promptTitle' => 'Pilih salah satu',
            'prompt'      => 'Pilih dari daftar yang tersedia',
        ], $sheetName);
    }

    /**
     * Tulis daftar opsi ke sheet bantuan tersembunyi dan kembalikan referensi range-nya
     * (dipakai sebagai formula dropdown, misal: 'ref_kategori'!$A$1:$A$25).
     * Sheet lama dengan key yang sama akan ditimpa (opsi terbaru dari database selalu dipakai).
     */
    protected function writeHiddenOptionList(string $key, array $options): string
    {
        $this->initWriter();

        $currentActive = $this->activeSheet;
        $title         = substr(preg_replace('/[^A-Za-z0-9_]/', '', $key), 0, 31) ?: 'ref_list';

        if ($this->spreadsheet->sheetNameExists($title)) {
            $helper = $this->spreadsheet->getSheetByName($title);
            $helper->removeColumn('A', 1); // bersihkan isi lama sebelum ditulis ulang
        } else {
            $helper = $this->spreadsheet->createSheet();
            $helper->setTitle($title);
        }

        foreach (array_values($options) as $i => $value) {
            $helper->setCellValueExplicit([1, $i + 1], (string) $value, DataType::TYPE_STRING);
        }

        // HIDDEN (bukan VERY_HIDDEN): tetap tersembunyi dari tampilan normal, tapi tidak
        // memblokir Excel membaca sumber data validasinya.
        $helper->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // Kembalikan ke sheet yang tadi aktif supaya operasi berikutnya (setCell, dst) tidak nyasar.
        $this->spreadsheet->setActiveSheetIndexByName($currentActive);

        return "'" . $title . "'!\$A\$1:\$A\$" . count($options);
    }

    /**
     * Pasang aturan validasi data pada satu sel, beberapa sel, atau range ('B2:B100').
     * Satu range = satu aturan di file (bukan satu aturan per sel), jadi ringan
     * walau range-nya ribuan baris.
     *
     * Opsi yang didukung ($config):
     *   - type        : 'list' | 'whole' | 'decimal' | 'date' | 'textLength' | 'custom'
     *   - options     : array pilihan (khusus type 'list')
     *   - source      : referensi range untuk list, mis. "'ref'!$A$1:$A$25" (alternatif options)
     *   - operator    : 'between', 'notBetween', 'equal', 'notEqual', 'greaterThan', 'lessThan',
     *                   'greaterThanOrEqual', 'lessThanOrEqual'. Kalau dikosongkan: min+max ->
     *                   between, hanya min -> greaterThanOrEqual, hanya max -> lessThanOrEqual.
     *   - min, max    : batas nilai. Untuk operator satu-nilai (greaterThan, equal, dst) cukup isi salah satu.
     *                   Type 'date' menerima string tanggal ('2026-01-31') atau DateTimeInterface.
     *   - formula     : formula custom (khusus type 'custom'), misal '=B2>0'
     *   - allowBlank  : bool, default true
     *   - errorTitle, error       : judul & pesan saat input tidak valid (error style: STOP)
     *   - promptTitle, prompt     : judul & pesan bantuan saat sel dipilih
     *
     * Contoh — batasi angka 0-100:
     *   $excel->setDataValidation('D2:D100', [
     *       'type'  => 'whole',
     *       'min'   => 0,
     *       'max'   => 100,
     *       'error' => 'Nilai harus antara 0 dan 100',
     *   ]);
     *
     * Contoh — batasi tanggal minimal hari ini:
     *   $excel->setDataValidation('E2', [
     *       'type' => 'date',
     *       'min'  => date('Y-m-d'),
     *   ]);
     */
    public function setDataValidation(string|array $coordinate, array $config, ?string $sheetName = null): static
    {
        $this->initWriter();

        if ($sheetName !== null) {
            $this->sheet($sheetName);
        }

        $ws = $this->currentWorksheet();

        $typeMap = [
            'list'       => DataValidation::TYPE_LIST,
            'whole'      => DataValidation::TYPE_WHOLE,
            'decimal'    => DataValidation::TYPE_DECIMAL,
            'date'       => DataValidation::TYPE_DATE,
            'textLength' => DataValidation::TYPE_TEXTLENGTH,
            'custom'     => DataValidation::TYPE_CUSTOM,
        ];

        $operatorMap = [
            'between'            => DataValidation::OPERATOR_BETWEEN,
            'notBetween'         => DataValidation::OPERATOR_NOTBETWEEN,
            'equal'              => DataValidation::OPERATOR_EQUAL,
            'notEqual'           => DataValidation::OPERATOR_NOTEQUAL,
            'greaterThan'        => DataValidation::OPERATOR_GREATERTHAN,
            'lessThan'           => DataValidation::OPERATOR_LESSTHAN,
            'greaterThanOrEqual' => DataValidation::OPERATOR_GREATERTHANOREQUAL,
            'lessThanOrEqual'    => DataValidation::OPERATOR_LESSTHANOREQUAL,
        ];

        $type = $typeMap[$config['type'] ?? ''] ?? throw new RuntimeException(
            'Tipe validasi tidak dikenal: ' . ($config['type'] ?? '(kosong)') . '. Gunakan: ' . implode(', ', array_keys($typeMap))
        );

        $validation = new DataValidation();
        $validation->setType($type);
        $validation->setAllowBlank($config['allowBlank'] ?? true);
        $validation->setShowInputMessage(isset($config['prompt']));
        $validation->setShowErrorMessage(true);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);

        if ($type === DataValidation::TYPE_LIST) {
            $validation->setShowDropDown(true);

            if (isset($config['source'])) {
                // Referensi ke range sheet lain, misal: 'ref'!$A$1:$A$25
                // Tidak terkena batas 255 karakter seperti list inline.
                $validation->setFormula1($config['source']);
            } else {
                $validation->setFormula1('"' . implode(',', $config['options'] ?? []) . '"');
            }
        } elseif ($type === DataValidation::TYPE_CUSTOM) {
            $validation->setFormula1($config['formula'] ?? '');
        } else {
            $min = $config['min'] ?? null;
            $max = $config['max'] ?? null;

            $operatorKey = $config['operator']
                ?? ($min !== null && $max !== null ? 'between' : ($min !== null ? 'greaterThanOrEqual' : 'lessThanOrEqual'));

            $validation->setOperator($operatorMap[$operatorKey] ?? throw new RuntimeException(
                "Operator validasi tidak dikenal: {$operatorKey}. Gunakan: " . implode(', ', array_keys($operatorMap))
            ));

            if (in_array($operatorKey, ['between', 'notBetween'], true)) {
                if ($min === null || $max === null) {
                    throw new RuntimeException("Operator '{$operatorKey}' butuh min dan max.");
                }

                $validation->setFormula1($this->validationValue($type, $min));
                $validation->setFormula2($this->validationValue($type, $max));
            } else {
                $single = $min ?? $max;

                if ($single === null) {
                    throw new RuntimeException("Operator '{$operatorKey}' butuh nilai min atau max.");
                }

                $validation->setFormula1($this->validationValue($type, $single));
            }
        }

        if (isset($config['errorTitle']) || isset($config['error'])) {
            $validation->setErrorTitle($config['errorTitle'] ?? 'Nilai tidak valid');
            $validation->setError($config['error'] ?? 'Nilai yang dimasukkan tidak diperbolehkan.');
        }

        if (isset($config['promptTitle']) || isset($config['prompt'])) {
            $validation->setPromptTitle($config['promptTitle'] ?? 'Info');
            $validation->setPrompt($config['prompt'] ?? '');
        }

        // Satu objek per koordinat/range (setDataValidation mengisi sqref, jadi tidak boleh dibagi).
        foreach ((array) $coordinate as $coord) {
            $ws->setDataValidation($coord, clone $validation);
        }

        return $this;
    }

    /**
     * Ubah nilai batas validasi jadi formula yang dimengerti Excel.
     * Untuk type 'date', string tanggal/DateTime dikonversi ke nomor seri Excel
     * (string 'Y-m-d' mentah akan dianggap formula yang tidak valid oleh Excel).
     */
    protected function validationValue(string $type, mixed $value): string
    {
        if ($type === DataValidation::TYPE_DATE) {
            if ($value instanceof DateTimeInterface) {
                return (string) Date::PHPToExcel($value);
            }

            if (is_string($value) && ! is_numeric($value) && ! str_starts_with($value, '=')) {
                $serial = Date::stringToExcel($value);

                if ($serial === false) {
                    throw new RuntimeException("Format tanggal tidak dikenali: {$value}. Pakai 'Y-m-d'.");
                }

                return (string) $serial;
            }
        }

        return (string) $value;
    }

    /**
     * Bold + beri warna latar pada baris header (baris 1) sheet aktif, lalu bekukan baris itu.
     */
    public function styleHeaderRow(?string $sheetName = null): static
    {
        $this->initWriter();

        if ($sheetName !== null) {
            $this->sheet($sheetName);
        }

        $ws         = $this->currentWorksheet();
        $lastColumn = $ws->getHighestDataColumn(1);

        $ws->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
        ]);

        $ws->freezePane('A2');

        return $this;
    }

    /**
     * Sesuaikan lebar kolom otomatis mengikuti isi (sheet aktif), termasuk kolom setelah Z (AA, AB, …).
     */
    public function autoSizeColumns(?string $sheetName = null): static
    {
        $this->initWriter();

        if ($sheetName !== null) {
            $this->sheet($sheetName);
        }

        $ws   = $this->currentWorksheet();
        $last = Coordinate::columnIndexFromString($ws->getHighestDataColumn());

        for ($i = 1; $i <= $last; $i++) {
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        return $this;
    }

    /**
     * Simpan spreadsheet ke file di disk.
     */
    public function save(string $path, string $format = 'Xlsx'): string
    {
        $this->initWriter();

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = IOFactory::createWriter($this->spreadsheet, $format);
        $writer->save($path);

        return $path;
    }

    /**
     * Kirim file sebagai download ke browser (dipanggil dari controller: `return $excel->download(...)`).
     * Mengembalikan response CI4 lengkap dengan header-nya.
     *
     * File sementara dibuat di writable/cache dan SELALU dihapus, termasuk kalau penulisan gagal.
     * Seluruh isi file dimuat ke memori untuk jadi body response; untuk export sangat besar
     * pakai ExcelStreamWriter lalu kirim file hasilnya dengan $response->download().
     *
     * @param string $format 'Xlsx' (default), 'Xls', 'Csv', atau 'Ods'
     */
    public function download(string $filename = 'export.xlsx', string $format = 'Xlsx')
    {
        $this->initWriter();

        $mimes = [
            'Xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Xls'  => 'application/vnd.ms-excel',
            'Ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
            'Csv'  => 'text/csv',
        ];

        $dir = WRITEPATH . 'cache/';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpPath = tempnam($dir, 'excel_');

        try {
            IOFactory::createWriter($this->spreadsheet, $format)->save($tmpPath);
            $content = file_get_contents($tmpPath);
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }

        // Nama file aman untuk header: buang CR/LF, kutip, dan pemisah path.
        $safeName = preg_replace('/[\r\n"\\\\\/]+/', '_', $filename);

        return service('response')
            ->setHeader('Content-Type', $mimes[$format] ?? 'application/octet-stream')
            ->setHeader(
                'Content-Disposition',
                'attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName)
            )
            ->setHeader('Cache-Control', 'max-age=0')
            ->setBody($content);
    }

    // ======================================================================
    // HELPER INTERNAL
    // ======================================================================

    protected function initWriter(): void
    {
        if ($this->spreadsheet === null) {
            $this->spreadsheet = new Spreadsheet();
            $this->spreadsheet->getActiveSheet()->setTitle($this->activeSheet);
        }
    }

    protected function currentWorksheet(): Worksheet
    {
        return $this->spreadsheet->getSheetByName($this->activeSheet);
    }

    protected function assertLoaded(): void
    {
        if ($this->filePath === null) {
            throw new RuntimeException('Belum ada file yang di-load. Panggil load($path) atau read($file) dulu untuk mode baca.');
        }
    }

    /**
     * Baris pertama yang masih kosong pada sheet (1 kalau sheet masih kosong).
     * Memakai getHighestDataRow(): sel yang hanya ber-style (umum di template) tidak dihitung.
     */
    protected function nextEmptyRow(Worksheet $ws): int
    {
        $highest = $ws->getHighestDataRow();

        $isEmpty = $highest === 1
            && $ws->getHighestDataColumn() === 'A'
            && (! $ws->cellExists('A1') || $ws->getCell('A1')->getValue() === null);

        return $isEmpty ? 1 : $highest + 1;
    }

    /**
     * Ubah satu baris header mentah jadi daftar key yang unik dan tidak kosong.
     */
    protected function buildHeader(array $row): array
    {
        $header = [];
        $seen   = [];

        foreach (array_values($row) as $i => $value) {
            $base = $this->normalizeHeader((string) $value);
            $key  = $base !== '' ? $base : 'kolom_' . ($i + 1);
            $n    = 1;

            while (isset($seen[$key])) {
                $n++;
                $key = ($base !== '' ? $base : 'kolom_' . ($i + 1)) . '_' . $n;
            }

            $seen[$key] = true;
            $header[]   = $key;
        }

        return $header;
    }

    /**
     * Buang baris kosong, trim (opsional), dan gabungkan dengan header (kalau ada).
     */
    protected function processRows(array $rows, ?array $header, bool $trim): array
    {
        $result = [];

        foreach ($rows as $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            if ($trim) {
                $row = array_map(fn($v) => is_string($v) ? trim($v) : $v, $row);
            }

            $result[] = $header !== null ? $this->combineRow($header, $row) : $row;
        }

        return $result;
    }

    protected function normalizeHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/\s+/', '_', $header);

        return preg_replace('/[^a-z0-9_]/', '', $header) ?: $header;
    }

    protected function combineRow(array $header, array $row): array
    {
        $count = count($header);
        $row   = array_pad(array_slice($row, 0, $count), $count, null);

        return array_combine($header, $row);
    }

    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && $value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Ubah nilai parameter $hasHeader menjadi index baris header (0-based), atau null jika tanpa header.
     *
     * - false        -> null (tanpa header)
     * - true         -> 0    (baris pertama / default)
     * - int (0,1,2…) -> dipakai apa adanya sebagai index baris header (0-based)
     */
    protected function resolveHeaderRowIndex(bool|int $hasHeader): ?int
    {
        if ($hasHeader === false) {
            return null;
        }

        if ($hasHeader === true) {
            return 0;
        }

        return max(0, $hasHeader);
    }

    /**
     * Lepas spreadsheet dari memori. Dipanggil otomatis saat object dihancurkan;
     * panggil manual kalau object masih hidup lama setelah selesai dipakai.
     */
    public function dispose(): void
    {
        if ($this->spreadsheet !== null) {
            $this->spreadsheet->disconnectWorksheets();
            $this->spreadsheet = null;
        }
    }

    public function __destruct()
    {
        $this->dispose();
    }
}
