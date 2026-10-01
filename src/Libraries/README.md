# Libraries

## BaseUploadLibrary

`MedigitalDev\Ci4Base\Libraries\BaseUploadLibrary` memvalidasi dan memindahkan `UploadedFile` ke direktori di bawah `WRITEPATH`. Parameter `toFolder` adalah path relatif dan dibuat otomatis jika belum ada.

```php
use MedigitalDev\Ci4Base\Dto\UploadOptions;
use MedigitalDev\Ci4Base\Libraries\BaseUploadLibrary;

$file = $this->request->getFile('document');
$result = (new BaseUploadLibrary())->doUpload(
    $file,
    'uploads/documents',
    new UploadOptions(
        allowedExtension: ['pdf', 'jpg', 'png'],
        maxSize: 5_000_000,
        customName: 'document-001',
    ),
);

if (!$result['status']) {
    return $this->response->setStatusCode(400)->setJSON([
        'message' => $result['message'],
        'error'   => $result['error'],
    ]);
}

$fileInfo = $result['data'];
```

Bentuk hasil selalu `['status' => bool, 'message' => string, 'error' => ?string, 'data' => array]`. Saat berhasil, `data` berisi `clientname`, `filename`, `path`, `fullpath`, `type`, `extension`, dan `size`. Saat gagal, `data` berupa array kosong.

`UploadOptions` mendukung ekstensi yang diizinkan, batas ukuran dalam byte, nama file kustom, dan pilihan overwrite. Jika ekstensi tidak dibatasi, semua ekstensi diperbolehkan; tentukan whitelist yang sesuai kebutuhan aplikasi.

Untuk file sementara, helper `md_tempUpload()` membungkus library ini dan melempar `FileUploadException` saat gagal. Lihat [panduan Helpers](../Helpers/README.md).
