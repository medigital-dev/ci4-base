# Models

## BaseModel

Buat model aplikasi dengan mewarisi `MedigitalDev\Ci4Base\Models\BaseModel`. Konfigurasikan `$table`, `$primaryKey`, dan `$allowedFields` seperti model CI4 biasa.

```php
<?php

namespace App\Models;

use MedigitalDev\Ci4Base\Models\BaseModel;

class StudentModel extends BaseModel
{
    protected $table         = 'students';
    protected $primaryKey    = 'id';
    protected $allowedFields = ['name', 'email'];

    // Opsional: nama kolom unik tambahan yang nilainya dibuat sebagai UUID v4.
    protected $uniqueKey = 'uuid';

    protected array $relations = [
        ['schools s', 's.id = students.school_id', 'left'],
    ];
}
```

### Simpan dan cari data

`saveData()` menyimpan data, bisa insert atau memperbarui record yang ditemukan melalui `whereKey`. Nilai `whereKey` harus tersedia di `$data`. Default return adalah array data hasil simpan; parameter `return` dapat memilih satu nilai dari array tersebut. Jika validasi/database gagal, hasilnya array `success => false` berisi pesan error dan data.

```php
$students = new StudentModel();

$created = $students->saveData(['name' => 'Budi', 'email' => 'budi@example.com']);
$updatedId = $students->saveData(
    ['email' => 'budi@example.com', 'name' => 'Budi Santoso'],
    whereKey: 'email',
    return: 'id',
);

$student = $students->findBy('email', 'budi@example.com');
$studentsNamedBudi = $students->findAllBy('name', 'Budi');
$count = $students->countBy('name', 'Budi');
$students->deleteBy('email', 'budi@example.com');
```

`insertData($data, $whereKey, $returnId)` adalah wrapper kompatibilitas: mengembalikan `true` saat berhasil, atau ID saat `$returnId` bernilai `true`.

### Kondisi dan pencarian

`whereBy($pattern, $value)` menerima mini-DSL: `|` berarti OR dan `&` berarti AND. Nilai yang diberikan dipakai untuk semua kolom di pola.

```php
$students->whereBy('email|username', 'budi');
$students->whereBy('status&role', 'active');
$students->search(['name', 'email'], 'budi santoso');
```

`search()` membagi keyword per kata: kata-kata menjadi kondisi AND dalam setiap field, dan field digabung dengan OR.

### Simpan massal

```php
$result = $students->bulkSaveData(
    [
        ['email' => 'budi@example.com', 'name' => 'Budi'],
        ['email' => 'siti@example.com', 'name' => 'Siti'],
    ],
    matchField: 'email',
    batchSize: 500,
);
```

`matchField` menentukan kolom atau pola pencocokan untuk memisahkan insert dan update. Setiap baris harus memiliki nilai pencocokan. Jika `matchField` tidak diberikan, semua baris diperlakukan sebagai insert. Hasil berisi jumlah `inserted`, `updated`, dan `skipped`.

### UUID dan relasi

- `generateUuidV4()` membuat UUID v4 tanpa dependency eksternal.
- Jika `$uniqueKey` diatur, `saveData()` mengisi kolom tersebut dengan UUID v4 jika belum ada nilainya. Tambahkan unique constraint di database.
- `$relations` dapat berisi daftar `[tableAlias, joinCondition, joinType]` sebagai relasi default.
- `withRelations()` menambahkan relasi default; `relations($relations)` menambahkan relasi khusus untuk query tersebut; `withAllRelations($relations)` menggabungkan keduanya.

```php
$students->withRelations()->findAll();
$students->relations([
    ['schools s', 's.id = students.school_id', 'left'],
])->findAll();
```

Untuk rincian API CodeIgniter Model seperti konfigurasi validasi dan timestamps, lihat dokumentasi CodeIgniter 4. BaseModel mempertahankan perilaku dasar `CodeIgniter\Model` selain helper yang dijelaskan di atas.
