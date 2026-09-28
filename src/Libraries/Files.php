<?php

namespace MedigitalDev\Ci4Base\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Helper penanganan file upload untuk CodeIgniter 4.
 *
 * CATATAN: ini adalah template awal. Sesuaikan kembali dengan konvensi
 * penamaan file dan validasi spesifik project-mu sebelum dipakai.
 */
class Files
{
    /**
     * Simpan file upload ke folder tujuan dengan nama unik (UUID + ekstensi asli),
     * supaya tidak ada file yang saling menimpa.
     *
     * @param UploadedFile $file
     * @param string       $destinationPath  Path absolut folder tujuan (mis. WRITEPATH . 'uploads/piagam')
     * @return string  Nama file yang disimpan (bukan path lengkap)
     */
    public function store(UploadedFile $file, string $destinationPath): string
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('File upload tidak valid: ' . $file->getErrorString());
        }

        $newName = $file->getRandomName();
        $file->move($destinationPath, $newName);

        return $newName;
    }

    /**
     * Hapus file dari disk kalau ada. Return true kalau berhasil dihapus
     * atau memang filenya sudah tidak ada.
     */
    public function delete(string $fullPath): bool
    {
        if (!is_file($fullPath)) {
            return true;
        }

        return unlink($fullPath);
    }

    /**
     * Validasi ekstensi file terhadap daftar yang diizinkan (case-insensitive).
     *
     * @param string[] $allowedExtensions  mis. ['pdf', 'jpg', 'png']
     */
    public function isExtensionAllowed(UploadedFile $file, array $allowedExtensions): bool
    {
        $ext = strtolower($file->getClientExtension());

        return in_array($ext, array_map('strtolower', $allowedExtensions), true);
    }

    /**
     * Bangun URL publik dari path relatif file yang tersimpan,
     * mis. untuk ditampilkan di response API.
     */
    public function publicUrl(string $relativePath): string
    {
        return rtrim(base_url(), '/') . '/' . ltrim($relativePath, '/');
    }
}
