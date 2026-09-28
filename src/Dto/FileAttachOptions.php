<?php

namespace MedigitalDev\Ci4Base\Dto;

/**
 * DTO opsi untuk UploadService::saveUpload() — mengatur bagaimana file
 * "dilekatkan" ke entitas lain di database (tabel files, polymorphic relation).
 *
 * Contoh: new FileAttachOptions(
 *     fileableType: 'users',
 *     fileableId: $userId,
 *     collection: 'avatar',
 *     replace: true, // ganti avatar lama, bukan menumpuk
 * )
 */
final class FileAttachOptions
{
    /**
     * @param string|null $fileableType Nama tabel/model pemilik, mis. 'users', 'documents'. Null = file berdiri sendiri (tidak nempel entitas apa pun)
     * @param string|null $fileableId   ID baris pemilik (UUID string, sesuai skema fileable_id CHAR(36))
     * @param string      $collection   Grup/tag file, mis. 'avatar', 'ktp', 'gallery' (default: 'default')
     * @param bool        $replace      true = upsert (file baru menggantikan file lama di kombinasi type+id+collection yang sama, cocok untuk avatar).
     *                                  false = selalu insert baru (cocok untuk multi-file seperti galeri/lampiran). Default: false
     * @param int|null    $uploadedBy   ID user (users.id, Shield) yang mengunggah. Null = otomatis ambil dari auth()->id() jika tersedia
     * @param int         $sortOrder    Urutan tampil, dipakai untuk kasus multi-file (default: 0)
     * @param array       $metadata     Data tambahan bebas, mis. ['width' => 1920, 'height' => 1080]
     * @param bool        $computeHash  Hitung SHA-256 file untuk deteksi duplikat. Nonaktifkan untuk file sangat besar demi performa (default: true)
     */
    public function __construct(
        public readonly ?string $fileableType = null,
        public readonly ?string $fileableId = null,
        public readonly string $collection = 'default',
        public readonly bool $replace = false,
        public readonly ?int $uploadedBy = null,
        public readonly int $sortOrder = 0,
        public readonly array $metadata = [],
        public readonly bool $computeHash = true,
    ) {
        if ($replace && ($fileableType === null || $fileableId === null)) {
            throw new \InvalidArgumentException(
                'FileAttachOptions: $replace=true membutuhkan $fileableType dan $fileableId agar tahu file mana yang akan digantikan.'
            );
        }
    }
}
