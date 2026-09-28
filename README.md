# ci4-base

Base model & util reusable untuk project [CodeIgniter 4](https://codeigniter.com/): UUID primary key, soft delete, mini-DSL query builder, dan `bulkSaveData()` untuk sync volume tinggi.

Repo ini di-host di GitHub supaya bisa dipakai dari komputer mana pun (kantor, rumah, server) — Composer akan mengambilnya lewat internet, bukan dari folder lokal.

## 1. Push repo ini ke GitHub (sekali di awal)

```bash
cd ci4-base
git init
git add .
git commit -m "Initial commit"
```

Buat repo baru di GitHub (boleh **private** kalau tidak ingin dibagikan ke publik — Composer tetap bisa akses selama kamu login/auth), lalu:

```bash
git remote add origin https://github.com/medigital-dev/ci4-base.git
git branch -M main
git push -u origin main
```

## 2. Pakai di project CI4 (dari PC mana pun)

Di `composer.json` project CI4-mu, tambahkan:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/medigital-dev/ci4-base.git" }
],
"require": {
    "medigital-dev/ci4-base": "dev-main"
}
```

Lalu:

```bash
composer install
```

Composer akan clone repo dari GitHub — jadi berhasil di PC kantor, PC rumah, atau server mana pun, selama ada akses internet ke GitHub. Tidak ada ketergantungan ke folder lokal seperti path repository.

### Kalau repo di-set private

Composer akan minta autentikasi sekali per PC (disimpan di `auth.json` global, tidak perlu diulang tiap project):

```bash
composer config --global github-oauth.github.com <personal-access-token>
```

Buat token di GitHub: **Settings → Developer settings → Personal access tokens** (scope minimal: `repo` untuk private repo).

## 3. Update ke versi terbaru

Kalau pakai `dev-main` (selalu ikuti commit terbaru di branch main):

```bash
composer update medigital-dev/ci4-base
```

**Rekomendasi setelah stabil:** begitu kodenya sudah teruji dan tidak sering berubah drastis, mulai buat tag versi supaya project production tidak ikut ter-update otomatis tiap kamu commit:

```bash
git tag v1.0.0
git push --tags
```

Lalu di project yang mau "dikunci" ke versi stabil, ganti constraint:

```json
"require": {
    "medigital-dev/ci4-base": "^1.0"
}
```

Project yang masih fase development bisa tetap pakai `dev-main` supaya langsung dapat perubahan terbaru; project production pakai versi tag supaya tidak ada perubahan mendadak yang bikin error.

## Pakai di kode

```php
<?php

namespace App\Models;

use Said\Ci4Base\Model\BaseModel;

class StudentModel extends BaseModel
{
    protected $table         = 'peserta_didik';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['nama', 'nisn', 'sekolah_id'];
    protected $beforeInsert  = ['generateUuidIfEmpty'];
}
```

```php
$model = new StudentModel();

$id = $model->saveData(['nama' => 'Budi', 'nisn' => '001']);   // insert
$model->saveData(['nama' => 'Budi Santoso'], $id);              // update
$model->deleteBy(['sekolah_id' => $sekolahId, 'status' => 'draft']);
$total = $model->countBy(['sekolah_id' => $sekolahId]);
$result = $model->bulkSaveData($rowsFromDapodik, batchSize: 500);
```

📖 **Dokumentasi lengkap tiap method** (setup migration, `applyFilters()`, soft delete, validasi, dst) ada di [docs/USAGE.md](./docs/USAGE.md).

## Menambah util baru

Tambahkan file di sub-folder `src/` sesuai jenisnya, lalu sesuaikan namespace mengikuti nama foldernya (PSR-4 mengharuskan namespace mencerminkan struktur folder):

```
src/
  Model/
    BaseModel.php        → namespace Said\Ci4Base\Model;
  Libraries/
    Files.php             → namespace Said\Ci4Base\Libraries;
```

Contoh pakai `Files`:

```php
use Said\Ci4Base\Libraries\Files;

$files = new Files();
$filename = $files->store($this->request->getFile('piagam'), WRITEPATH . 'uploads/piagam');
```

Setelah nambah file baru, tidak perlu ubah `composer.json` apa pun — cukup commit + push, lalu `composer update medigital-dev/ci4-base` di project yang mau pakai.

## Lisensi

MIT — lihat [LICENSE](./LICENSE).
