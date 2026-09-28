<?php

namespace MedigitalDev\Ci4Base\Dto;

/**
 * DTO opsi untuk UploadService::doUpload().
 * Gunakan named arguments agar jelas opsi mana yang diisi tanpa perlu hafal urutan.
 *
 * Contoh: new UploadOptions(allowedExtension: ['jpg', 'png'], maxSize: 2_000_000)
 */
final class UploadOptions
{
    /**
     * @param string[]    $allowedExtension Whitelist ekstensi tanpa titik, case-insensitive
     *                                       (default: [] => semua ekstensi diperbolehkan)
     * @param int|null    $maxSize          Batas ukuran file dalam byte (default: null => tanpa batas)
     * @param string|null $customName       Nama file custom tanpa ekstensi (default: null => nama random otomatis)
     * @param bool        $overwrite        Timpa file jika nama sudah ada (default: false)
     */
    public function __construct(
        public readonly array $allowedExtension = [],
        public readonly ?int $maxSize = null,
        public readonly ?string $customName = null,
        public readonly bool $overwrite = false,
    ) {}
}
