<?php

namespace MedigitalDev\Ci4Base\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;
use MedigitalDev\Ci4Base\Dto\UploadOptions;

class Files
{
    /**
     * Library unggah berkas — generik & reusable untuk kebutuhan upload di seluruh aplikasi.
     *
     * @param UploadedFile  $file     Berkas yang diupload (dari $this->request->getFile())
     * @param string        $toFolder Subfolder tujuan relatif terhadap WRITEPATH (default: 'uploads')
     * @param UploadOptions $options  Opsi tambahan — lihat UploadOptions untuk daftar lengkap & default-nya
     * @return array{status: bool, message: string, error: string|null, data: array}
     */
    public function doUpload(UploadedFile $file, string $toFolder = 'uploads', UploadOptions $options = new UploadOptions()): array
    {
        if (!$file->isValid()) {
            return $this->uploadError('Upload Error.', '[File Not Valid] ' . $file->getErrorString() . ' (kode: ' . $file->getError() . ')');
        }

        if ($file->hasMoved()) {
            return $this->uploadError('Upload Error.', '[hasMoved] Berkas sudah pernah dipindahkan sebelumnya.');
        }

        $extension = strtolower($file->guessExtension() ?: $file->getExtension());
        $mimeType  = $file->getMimeType();
        $fileSize  = $file->getSize();
        $clientName = $this->sanitizeClientName($file->getClientName());
        if (!empty($options->allowedExtension)) {
            $allowedNormalized = array_map(
                static fn(string $ext): string => strtolower(ltrim(trim($ext), '.')),
                $options->allowedExtension
            );

            if (!in_array($extension, $allowedNormalized, true)) {
                return $this->uploadError('Upload Error.', 'Format file tidak sesuai. [' . implode(', ', $allowedNormalized) . ']');
            }
        }

        if ($options->maxSize !== null && $fileSize > $options->maxSize) {
            return $this->uploadError(
                'Upload Error.',
                sprintf('Ukuran file (%.1f KB) melebihi batas maksimal (%.1f KB).', $fileSize / 1024, $options->maxSize / 1024)
            );
        }

        $toFolder = trim(str_replace(['\\', '..'], ['/', ''], $toFolder), '/');
        $folder   = rtrim(WRITEPATH, '/\\') . ($toFolder !== '' ? DIRECTORY_SEPARATOR . $toFolder : '');

        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
            return $this->uploadError('Upload Error.', 'Gagal membuat folder penyimpanan.');
        }

        // customName berasal dari opsi yang bisa saja diturunkan dari input luar (mis. nama entitas) —
        // dibersihkan dulu supaya tidak bisa dipakai untuk path traversal / nama file aneh.
        $safeCustomName = $options->customName !== null
            ? trim(preg_replace('/[^A-Za-z0-9_\-]+/', '_', $options->customName), '_')
            : null;

        $newName = ($safeCustomName !== null && $safeCustomName !== '')
            ? $safeCustomName . '.' . $extension
            : $file->getRandomName();

        if (!$options->overwrite && is_file($folder . DIRECTORY_SEPARATOR . $newName)) {
            return $this->uploadError('Upload Error.', "Berkas \"{$newName}\" sudah ada.");
        }

        try {
            $file->move($folder, $newName, $options->overwrite);
        } catch (\Throwable $e) {
            return $this->uploadError('Upload Error.', 'Gagal memindahkan file: ' . $e->getMessage());
        }

        // Pakai variabel yang sudah dibaca DI ATAS, bukan panggil ulang method $file->... di sini
        $data = [
            'clientname' => $clientName,
            'filename'   => $newName,
            'path'       => $toFolder,
            'fullpath'   => $folder . DIRECTORY_SEPARATOR . $newName,
            'type'       => $mimeType,
            'extension'  => $extension,
            'size'       => $fileSize,
        ];

        return ['status' => true, 'message' => 'Upload Berhasil.', 'error' => null, 'data' => $data];
    }

    /**
     * Helper internal untuk membentuk response error yang konsisten.
     */
    private function uploadError(string $message, string $error): array
    {
        return ['status' => false, 'message' => $message, 'error' => $error, 'data' => []];
    }

    /**
     * Normalisasi nama client file agar aman untuk disimpan ke database dan filesystem.
     * Spasi diubah menjadi underscore/dash, karakter tidak valid dibersihkan.
     */
    private function sanitizeClientName(?string $name, string $replacement = '_'): ?string
    {
        if ($name === null || trim($name) === '') {
            return $name;
        }

        $normalized = preg_replace('/\s+/', $replacement, trim($name));
        $normalized = preg_replace('/[^A-Za-z0-9._-]+/', $replacement, $normalized);

        return $normalized;
    }
}
