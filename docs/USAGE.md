# Dokumentasi Pemakaian — BaseModel

Dokumentasi lengkap tiap method di `Said\Ci4Base\Model\BaseModel`.

## Daftar isi
- [Setup](#setup)
- [Migrasi tabel](#migrasi-tabel)
- [generateUuid()](#generateuuid)
- [generateUuidIfEmpty()](#generateuuidifempty)
- [saveData()](#savedata)
- [insertData()](#insertdata)
- [deleteBy()](#deleteby)
- [countBy()](#countby)
- [bulkSaveData()](#bulksavedata)
- [applyFilters()](#applyfilters)
- [Soft delete bawaan CI4](#soft-delete-bawaan-ci4)
- [Validasi & timestamps](#validasi--timestamps)

---

## Setup

Extend `BaseModel` seperti extend `CodeIgniter\Model` biasa:

```php
<?php

namespace App\Models;

use Said\Ci4Base\Model\BaseModel;

class StudentModel extends BaseModel
{
    protected $table         = 'peserta_didik';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['nama', 'nisn', 'sekolah_id', 'tanggal_lahir'];

    // WAJIB didaftarkan supaya UUID otomatis terisi saat insert
    protected $beforeInsert  = ['generateUuidIfEmpty'];
}
```

> Kalau sub-class-mu sudah punya `$beforeInsert` sendiri untuk keperluan lain, tambahkan `'generateUuidIfEmpty'` ke array yang sama — jangan di-override jadi array baru yang menghilangkan hook lain.

## Migrasi tabel

Karena primary key berupa **UUID string** (bukan auto-increment integer), kolom `id` di migration harus bertipe string, bukan `INT AUTO_INCREMENT`:

```php
$this->forge->addField([
    'id'         => ['type' => 'VARCHAR', 'constraint' => 36],
    'nama'       => ['type' => 'VARCHAR', 'constraint' => 255],
    'created_at' => ['type' => 'DATETIME', 'null' => true],
    'updated_at' => ['type' => 'DATETIME', 'null' => true],
    'deleted_at' => ['type' => 'DATETIME', 'null' => true], // wajib untuk soft delete
]);
$this->forge->addPrimaryKey('id');
$this->forge->createTable('peserta_didik');
```

---

## generateUuid()

Static method, generate string UUID v4 baru. Berguna kalau kamu perlu ID sebelum data disimpan (mis. dipakai di beberapa tabel relasi sekaligus).

```php
$id = StudentModel::generateUuid();
// '3fa85f64-5717-4562-b3fc-2c963f66afa6'
```

## generateUuidIfEmpty()

Hook internal yang dipanggil otomatis oleh CI4 saat insert, **asalkan didaftarkan** di `$beforeInsert` sub-class-mu (lihat [Setup](#setup)). Kamu tidak perlu memanggilnya manual.

---

## saveData()

Method utama untuk insert **atau** update, tergantung ada tidaknya `$id`.

```php
public function saveData(array $data, ?string $id = null): string
```

**Insert baru** (parameter `$id` dikosongkan / `null`):
```php
$id = $model->saveData([
    'nama' => 'Budi',
    'nisn' => '0012345678',
]);
// $id = UUID baru, sudah otomatis di-generate
```

**Update baris existing** (isi `$id`):
```php
$model->saveData(['nama' => 'Budi Santoso'], $id);
```

Return value selalu UUID baris tersebut (baik baru maupun existing) — berguna kalau langsung mau dipakai untuk insert ke tabel relasi:

```php
$studentId = $studentModel->saveData($studentData);
$addressModel->saveData(['peserta_didik_id' => $studentId, 'alamat' => $alamat]);
```

## insertData()

Alias insert-only di atas `saveData()`, dipertahankan untuk kompatibilitas kode lama:

```php
$id = $model->insertData(['nama' => 'Budi']);
// sama persis dengan: $model->saveData(['nama' => 'Budi'], null);
```

---

## deleteBy()

Hapus baris (soft delete, karena `$useSoftDeletes = true`) berdasarkan array kondisi where.

```php
public function deleteBy(array $conditions): bool
```

```php
$model->deleteBy(['sekolah_id' => $sekolahId, 'status' => 'draft']);
// setara dengan: $model->where('sekolah_id', $sekolahId)->where('status', 'draft')->delete();
```

## countBy()

Hitung jumlah baris berdasarkan array kondisi where, tanpa perlu menulis `where()->countAllResults()` manual tiap kali.

```php
public function countBy(array $conditions): int
```

```php
$total = $model->countBy(['sekolah_id' => $sekolahId]);
```

---

## bulkSaveData()

Untuk sinkronisasi volume tinggi (mis. ribuan baris dari API Dapodik). Otomatis memisahkan baris **baru** (insert batch) dan baris **existing** (update batch), sehingga jumlah query jauh lebih sedikit dibanding loop `saveData()` satu per satu.

```php
public function bulkSaveData(array $rows, int $batchSize = 500): array
```

```php
$rows = [
    ['nama' => 'Budi', 'nisn' => '001'],                              // tanpa id → insert
    ['id' => $existingId, 'nama' => 'Siti Update', 'nisn' => '002'],   // dengan id → update
    // ... ribuan baris lain dari sync Dapodik
];

$result = $model->bulkSaveData($rows, batchSize: 500);

// $result = ['inserted' => 1200, 'updated' => 340]
```

**Kapan pakai `bulkSaveData()` vs `saveData()`:**
| Situasi | Method |
|---|---|
| Simpan 1 baris dari form/API | `saveData()` |
| Sync ratusan/ribuan baris sekaligus (cron, import Dapodik) | `bulkSaveData()` |

Sesuaikan `batchSize` kalau tabel punya banyak kolom atau ada limit `max_allowed_packet` di database — default `500` aman untuk kebanyakan kasus.

---

## applyFilters()

Mini-DSL untuk membangun query dengan array kondisi fleksibel, termasuk operator selain `=`.

```php
public function applyFilters(BaseBuilder $builder, array $filters): BaseBuilder
```

```php
$builder = $model->applyFilters($model->builder(), [
    'sekolah_id'     => $sekolahId,
    'created_at >='  => $tanggalMulai,
    'created_at <='  => $tanggalSelesai,
]);

$rows = $builder->get()->getResultArray();
```

Berguna untuk endpoint listing/filter dinamis (mis. dari query string API) tanpa harus menulis banyak `if` manual untuk tiap kemungkinan filter.

---

## Soft delete bawaan CI4

Karena `$useSoftDeletes = true` sudah di-set di `BaseModel`, semua fitur soft delete standar CI4 otomatis tersedia di sub-class-mu:

```php
$model->find($id);                 // hanya baris yang belum dihapus
$model->withDeleted()->find($id);  // termasuk yang sudah soft-deleted
$model->onlyDeleted()->findAll();  // hanya yang sudah soft-deleted
$model->purgeDeleted();            // hard delete permanen baris yang sudah soft-deleted
```

`deleteBy()` yang disediakan `BaseModel` otomatis soft delete (mengisi `deleted_at`), **bukan** menghapus baris secara permanen.

## Validasi & timestamps

`$useTimestamps = true` sudah aktif di `BaseModel`, jadi `created_at`/`updated_at` terisi otomatis — tidak perlu ditambahkan manual di `saveData()`.

Untuk validasi, tambahkan seperti model CI4 biasa di sub-class:

```php
protected $validationRules = [
    'nisn' => 'required|min_length[10]',
];
```

`saveData()`/`insertData()` akan tetap menjalankan validasi ini karena keduanya memanggil `insert()`/`update()` bawaan CI4 di baliknya — kalau validasi gagal, method akan return `false` dari operasi insert/update seperti biasa (cek `$model->errors()` untuk detail pesan error).
