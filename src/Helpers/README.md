# Helpers

Helper di folder ini berisi fungsi global. File helper tidak dimuat otomatis oleh mapping PSR-4 Composer package; include file yang diperlukan satu kali pada bootstrap aplikasi.

```php
require_once VENDORPATH . 'medigital-dev/ci4-base/src/Helpers/md_files_helper.php';
require_once VENDORPATH . 'medigital-dev/ci4-base/src/Helpers/md_indonesia_helper.php';
```

Sesuaikan path vendor jika project memakai lokasi instalasi Composer yang berbeda.

## File helper

### `md_tempUpload()`

Menyimpan upload sementara ke `WRITEPATH/temporaries` dan mengembalikan full path. Cocok untuk file transit seperti spreadsheet yang akan diproses lalu dibuang. Selalu berikan whitelist ekstensi untuk upload yang dapat dikirim pengguna.

```php
$path = md_tempUpload(
    $this->request->getFile('spreadsheet'),
    allowedExtension: ['xlsx', 'xls', 'csv'],
    maxSize: 10_000_000,
);
```

Fungsi ini melempar `FileUploadException` jika upload gagal. Pembersihan file lama dilakukan secara probabilistik; untuk pembersihan terjamin, jadwalkan command/cron sendiri.

### `md_cleanFiles()`

Menghapus file lama di level teratas suatu direktori, tanpa rekursi. Signature: `md_cleanFiles(string $path, int $maxAge = 86400, array $skip = []): int`. Parameter `skip` berisi pola nama `fnmatch`; return value adalah jumlah file yang berhasil dihapus.

## Helper Indonesia

- `md_toEyd(?string $nama): ?string`: merapikan kapitalisasi nama, tanda hubung, singkatan bertitik, dan angka Romawi. Input kosong menghasilkan `null`.
- `md_toTerbilang(int|float $angka, bool $isRupiah = true): string`: mengubah angka menjadi kata bahasa Indonesia, mendukung nilai negatif dan skala sampai kuadriliun.
- `md_tanggal($tanggalWaktu = 'now', string $format = 'd-m-Y', $timezone = null): ?string`: format tanggal/waktu dengan nama hari dan bulan Indonesia; format mengikuti `date()` PHP.
- `md_angka($angka, int $decimal = 0, string $pemisahDesimal = ',', string $pemisahRibuan = '.'): string`: format angka gaya Indonesia.
- `md_rupiah($angka, string $prefix = 'Rp', int $decimal = 0): string`: format angka sebagai nominal Rupiah.

```php
$nama = md_toEyd('siti nur-aini');       // Siti Nur-Aini
$terbilang = md_toTerbilang(1500);       // Seribu Lima Ratus Rupiah
$tanggal = md_tanggal('2025-01-31', 'd F Y');
$angka = md_angka(12345.67, 2);          // 12.345,67
$rupiah = md_rupiah(12500);              // Rp 12.500
```
