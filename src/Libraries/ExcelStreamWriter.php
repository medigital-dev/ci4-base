<?php

namespace App\Libraries;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * ExcelStreamWriter
 *
 * Penulis .xlsx streaming untuk dataset besar (ribuan-puluhan ribu baris),
 * dibangun di atas OpenSpout. Baris ditulis langsung ke disk satu per satu,
 * tanpa membangun object model di memori (beda dengan PhpSpreadsheet di
 * ExcelHandler yang melambat seiring jumlah baris). Kecepatan tulis konstan
 * dan memory footprint nyaris flat. Cocok dipasangkan dengan Model::chunk()
 * atau keyset pagination supaya query pun tidak menampung semua baris.
 *
 * Install dulu:
 *   composer require openspout/openspout
 *
 * Contoh:
 *   $xlsx = new \App\Libraries\ExcelStreamWriter();
 *   $xlsx->open(WRITEPATH . 'exports/pip.xlsx', ['NO', 'NAMA', 'NILAI']);
 *
 *   $model->chunk(500, function ($row) use ($xlsx) {
 *       $xlsx->writeRow([$row['no'], $row['nama'], $row['nilai']]);
 *   });
 *
 *   $path = $xlsx->close();
 *
 * Library ini hanya untuk TULIS BARU. Untuk baca file, isi template, dropdown,
 * atau styling per-sel, pakai ExcelHandler (PhpSpreadsheet).
 */
class ExcelStreamWriter
{
    protected ?Writer $writer = null;
    protected ?string $path   = null;

    /**
     * Buka file baru untuk ditulis secara streaming.
     *
     * @param string $path    Path tujuan .xlsx (folder dibuat otomatis kalau belum ada).
     * @param array  $headers Opsional: baris header, ditulis bold di baris pertama.
     *
     * @throws RuntimeException Jika openspout/openspout belum ter-install, atau stream sudah terbuka.
     */
    public function open(string $path, array $headers = []): static
    {
        if ($this->writer !== null) {
            throw new RuntimeException('Stream sudah dibuka. Panggil close() dulu sebelum open() lagi.');
        }

        if (! class_exists(Writer::class)) {
            throw new RuntimeException(
                'Package openspout/openspout belum ter-install. Jalankan: composer require openspout/openspout'
            );
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new Writer();
        $writer->openToFile($path);

        $this->writer = $writer;
        $this->path   = $path;

        if (! empty($headers)) {
            // OpenSpout v5: Row::fromValues($values, $rowStyle) TIDAK menerima Style lagi
            // (param ke-2 sekarang row height). Untuk baris ber-style pakai fromValuesWithStyle().
            $headerStyle = new Style(
                fontBold: true,
                fontColor: Color::WHITE,
                backgroundColor: '4472C4',
            );

            $writer->addRow(Row::fromValuesWithStyle(array_values($headers), $headerStyle));
        }

        return $this;
    }

    /**
     * Tulis satu baris. Array indexed atau asosiatif; urutan value yang dipakai (key diabaikan).
     */
    public function writeRow(array $row): static
    {
        $this->assertOpen();

        $this->writer->addRow(Row::fromValues(array_values($row)));

        return $this;
    }

    /**
     * Tulis banyak baris sekaligus (mis. satu batch dari Model::chunk()).
     *
     * @param array<int, array> $rows
     */
    public function writeRows(array $rows): static
    {
        foreach ($rows as $row) {
            $this->writeRow($row);
        }

        return $this;
    }

    /**
     * Tutup file dan selesaikan penulisan ke disk.
     * Wajib dipanggil setelah selesai, atau file .xlsx tidak lengkap.
     *
     * @return string Path file yang baru saja ditulis.
     */
    public function close(): string
    {
        $this->assertOpen();

        $this->writer->close();
        $path = $this->path;

        $this->writer = null;
        $this->path   = null;

        return $path;
    }

    /**
     * Apakah stream sedang terbuka (open() sudah dipanggil dan belum close()).
     */
    public function isOpen(): bool
    {
        return $this->writer !== null;
    }

    protected function assertOpen(): void
    {
        if ($this->writer === null) {
            throw new RuntimeException('Belum ada stream yang dibuka. Panggil open($path) dulu.');
        }
    }

    public function __destruct()
    {
        // Jaga-jaga kalau close() lupa dipanggil: tutup writer supaya file tidak korup.
        if ($this->writer !== null) {
            $this->writer->close();
        }
    }
}
