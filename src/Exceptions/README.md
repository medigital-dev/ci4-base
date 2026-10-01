# Exceptions

## FileUploadException

`MedigitalDev\Ci4Base\Exceptions\FileUploadException` adalah turunan `RuntimeException` untuk kegagalan upload yang perlu membedakan pesan aman bagi pengguna dari detail teknis.

```php
use MedigitalDev\Ci4Base\Exceptions\FileUploadException;

try {
    $path = md_tempUpload($file, ['csv', 'xlsx']);
} catch (FileUploadException $exception) {
    $messageForUser = $exception->getUserMessage();
    log_message('error', $exception->getMessage());
}
```

- `getUserMessage()` mengembalikan pesan ringkas yang aman ditampilkan ke pengguna.
- `getMessage()` mewarisi pesan teknis dari `RuntimeException` dan cocok untuk log, bukan ditampilkan mentah ke pengguna.

Helper `md_tempUpload()` melempar exception ini ketika upload gagal. Sebaliknya, `BaseUploadLibrary::doUpload()` mengembalikan array dengan `status: false`; method tersebut tidak melempar `FileUploadException` untuk error upload normal.

Lihat [panduan Helpers](../Helpers/README.md) dan [panduan Libraries](../Libraries/README.md).
