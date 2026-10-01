# DTO

DTO pada folder ini menyimpan opsi konfigurasi secara terstruktur dan menggunakan named arguments agar pemanggilan lebih mudah dibaca.

## UploadOptions

Dipakai oleh `BaseUploadLibrary::doUpload()` untuk menetapkan batas upload.

```php
use MedigitalDev\Ci4Base\Dto\UploadOptions;

$options = new UploadOptions(
    allowedExtension: ['pdf', 'jpg'],
    maxSize: 5_000_000,
    customName: 'document-001',
    overwrite: false,
);
```

- `allowedExtension`: whitelist ekstensi tanpa titik, case-insensitive. Default kosong berarti semua ekstensi diperbolehkan.
- `maxSize`: ukuran maksimum dalam byte; `null` berarti tanpa batas.
- `customName`: nama file tanpa ekstensi; default nama acak.
- `overwrite`: izinkan menimpa nama file yang sudah ada; default `false`.

Lihat [panduan Libraries](../Libraries/README.md) untuk pemakaian lengkap.

## FileAttachOptions

Menyediakan opsi metadata untuk file yang dikaitkan dengan entitas, seperti tipe dan ID pemilik, collection, urutan, metadata tambahan, serta penghitungan hash. `replace: true` membutuhkan `fileableType` dan `fileableId`.

```php
use MedigitalDev\Ci4Base\Dto\FileAttachOptions;

$options = new FileAttachOptions(
    fileableType: 'users',
    fileableId: $userId,
    collection: 'avatar',
    replace: true,
);
```

Saat ini package belum menyediakan service/library yang menerima `FileAttachOptions`; DTO ini hanya mendefinisikan struktur opsinya.
