# Testing

## Cara jalankan

```bash
composer install
composer test
```

atau langsung:

```bash
vendor/bin/phpunit
```

## Cakupan test di sini

Test di folder ini sengaja dibatasi ke logika **murni** (pure function/method) yang tidak butuh koneksi database atau service container CI4 penuh:

- `BaseModelUuidTest` — format, panjang, dan keunikan `BaseModel::generateUuid()`
- `FilesTest` — `isExtensionAllowed()` (pakai mock `UploadedFile`) dan `delete()` (pakai file temporary asli)

Method yang bergantung pada koneksi database (`saveData()`, `deleteBy()`, `countBy()`, `bulkSaveData()`) **tidak** di-test di level package ini. Alasannya: `BaseModel` extends `CodeIgniter\Model`, yang constructor-nya butuh service container CI4 penuh (`Config\Validation`, dll) — service itu normalnya disediakan oleh sebuah project CI4 lengkap (folder `app/`), bukan oleh package library yang berdiri sendiri. Memalsukan (mock) seluruh service container hanya untuk test package ini berisiko test jadi tidak mencerminkan perilaku CI4 yang sesungguhnya.

## Cara uji method yang butuh database

Uji method-method tersebut di **project CI4 nyata yang memakai package ini** (mis. `datamart-api`), bukan di repo `ci4-base`:

1. Buat test model kecil di project, extend `BaseModel`, tunjuk ke tabel sungguhan atau tabel sementara khusus test
2. Tulis test PHPUnit di project itu, extend `CodeIgniter\Test\CIUnitTestCase` (disediakan `codeigniter4/framework`) yang sudah menyiapkan service container dan koneksi DB test project tersebut
3. Jalankan lewat `php spark test` seperti test project CI4 pada umumnya

Ini juga sekaligus jadi cara paling realistis untuk memverifikasi package ter-install dan ter-autoload dengan benar dari Packagist/GitHub — bukan cuma logika internalnya yang benar, tapi juga integrasinya dengan project sungguhan.
