<?php

use CodeIgniter\HTTP\Files\UploadedFile;
use MedigitalDev\Ci4Base\Dto\UploadOptions;
use MedigitalDev\Ci4Base\Exceptions\FileUploadException;
use MedigitalDev\Ci4Base\Libraries\BaseUploadLibrary;

if (!defined('TEMP_UPLOAD_SUBFOLDER')) {
    define('TEMP_UPLOAD_SUBFOLDER', 'temporaries');
}

if (!function_exists('md_tempUpload')) {
    /**
     * Helper untuk upload file sementara (tidak dicatat ke database).
     * Cocok untuk file transit seperti import Excel/CSV yang cuma dibaca lalu dibuang.
     * File akan disimpan pada folder WRITEPATH/temporaries, dan folder akan dibersihkan
     * secara berkala dari file lama (probabilistik, bukan di setiap panggilan).
     *
     * @param UploadedFile $file             File upload
     * @param array        $allowedExtension Whitelist ekstensi, mis. ['xlsx', 'xls', 'csv'].
     *                                       Default [] = semua ekstensi diperbolehkan
     *                                       (TIDAK disarankan untuk file transit publik — sebaiknya selalu diisi)
     * @param int|null     $maxSize          Batas ukuran file dalam byte, default null = tanpa batas
     * @return string Fullpath lokasi file di disk.
     * @throws FileUploadException Jika upload gagal.
     */
    function md_tempUpload(UploadedFile $file, array $allowedExtension = [], ?int $maxSize = null): string
    {
        $tempFullPath = rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . TEMP_UPLOAD_SUBFOLDER;

        // Cleanup dijalankan probabilistik (2% peluang per panggilan), bukan setiap kali —
        // supaya tidak membebani I/O di setiap request upload. Untuk kepastian pembersihan,
        // sebaiknya tetap tambahkan CI4 Command + cron job terpisah sebagai jaring pengaman.
        if (random_int(1, 100) <= 2) {
            md_cleanFiles($tempFullPath);
        }

        $options = new UploadOptions(
            allowedExtension: $allowedExtension,
            maxSize: $maxSize,
        );

        $result = (new BaseUploadLibrary())->doUpload($file, TEMP_UPLOAD_SUBFOLDER, $options);

        if (!$result['status']) {
            log_message('error', 'tempUpload gagal: ' . $result['error']);

            throw new FileUploadException($result['message'], (string) $result['error']);
        }

        return $result['data']['fullpath']
            ?? throw new FileUploadException('Upload Error.', 'tempUpload: fullpath tidak ditemukan pada hasil upload.');
    }
}

if (!function_exists('md_cleanFiles')) {
    /**
     * Hapus file lama dari folder (non-rekursif, hanya level teratas).
     *
     * @param string $path   Path folder
     * @param int    $maxAge Umur maksimum file (detik), default 86400 = 1 hari
     * @param array  $skip   Daftar pola nama file (fnmatch) yang tidak boleh dihapus
     * @return int           Jumlah file yang berhasil dihapus
     */
    function md_cleanFiles(string $path, int $maxAge = 86400, array $skip = []): int
    {
        $path = rtrim($path, '/\\');

        if (!is_dir($path)) {
            return 0;
        }

        $files = glob($path . '/*');
        if ($files === false) {
            log_message('error', "cleanFiles: gagal membaca folder \"{$path}\".");
            return 0;
        }

        $deleted = 0;
        $now     = time();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $base     = basename($file);
            $skipThis = false;
            foreach ($skip as $pattern) {
                if (fnmatch($pattern, $base)) {
                    $skipThis = true;
                    break;
                }
            }
            if ($skipThis) {
                continue;
            }

            $mtime = filemtime($file);
            if ($mtime === false) {
                // Gagal baca mtime (race condition/permission) — lewati, jangan asumsikan "tua"
                continue;
            }

            if (($now - $mtime) > $maxAge) {
                if (unlink($file)) {
                    $deleted++;
                } else {
                    log_message('warning', "cleanFiles: gagal hapus \"{$file}\", cek permission.");
                }
            }
        }

        return $deleted;
    }
}
