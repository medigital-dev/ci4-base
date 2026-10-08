# ci4-base

Library tambahan untuk project [CodeIgniter 4](https://codeigniter.com/).

## Attention

Libary ini saya buat khusus untuk saya agar mempermudah saya dalam membangun aplikasi menggunakan CodeIgniter 4, jika dirasa bermanfaat silahkan gunakan, dan jika ada yang perlu diperbaiki silahkan sampaikan. Mari kita kembangkan bersama.

## Instalasi

Pastikan project menggunakan PHP 8.1+ dan CodeIgniter 4.4+, lalu jalankan dari direktori project:

```bash
composer require medigital-dev/ci4-base
```

Composer akan memasang dependency library dan mendaftarkan namespace `MedigitalDev\Ci4Base\` secara otomatis.

## Dokumentasi penggunaan

Pilih panduan sesuai modul yang akan digunakan:

| Folder                                       | Isi dokumentasi                                                              |
| -------------------------------------------- | ---------------------------------------------------------------------------- |
| [Controllers](src/Controllers/README.md)     | `BaseApiController`, model otomatis, guard, validasi, dan response API       |
| [Database Migration](src/Database/Readme.md) | `BaseMigration`, migrasi database mendukung pembuatan dan penghapusan schema |
| [Dto](src/Dto/README.md)                     | Opsi upload dan opsi metadata lampiran file                                  |
| [Exceptions](src/Exceptions/README.md)       | `FileUploadException` dan penanganan kegagalan upload                        |
| [Helpers](src/Helpers/README.md)             | Upload sementara, pembersihan file, format tanggal/angka/nama Indonesia      |
| [Libraries](src/Libraries/README.md)         | Upload file dengan `BaseUploadLibrary`                                       |
| [Models](src/Models/README.md)               | `BaseModel`, pencarian, upsert, UUID, dan relasi                             |

Lihat [LICENSE](LICENSE) untuk informasi lisensi.
