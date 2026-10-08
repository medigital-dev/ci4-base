# Dokumentasi Pemakaian: BaseMigration

`MedigitalDev\Ci4Base\Migration\BaseMigration` menambahkan dua method yang tidak ada di Forge CI4: `createSchema()` dan `dropSchema()`.

**Driver yang didukung:** PostgreSQL (`Postgre`) dan SQL Server (`SQLSRV`). Di MySQL/MariaDB, schema sama dengan database, jadi pakai `$this->forge->createDatabase()` / `dropDatabase()` bawaan CI4. Memanggil method ini di driver lain akan melempar `RuntimeException` dengan pesan yang jelas.

## Pakai

```php
<?php

namespace App\Database\Migrations;

use MedigitalDev\Ci4Base\Migration\BaseMigration;

class CreateDapodikSchema extends BaseMigration
{
    public function up()
    {
        $this->createSchema('dapodik');

        $this->forge->addField([
            'id'   => ['type' => 'VARCHAR', 'constraint' => 36],
            'nama' => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable('dapodik.peserta_didik');
    }

    public function down()
    {
        $this->forge->dropTable('dapodik.peserta_didik', true);
        $this->dropSchema('dapodik');
    }
}
```

## createSchema()

```php
createSchema(string $schema, bool $ifNotExists = true): void
```

| Parameter      | Keterangan                                                             |
| -------------- | ---------------------------------------------------------------------- |
| `$schema`      | Nama schema. Hanya huruf, angka, underscore; tidak boleh diawali angka |
| `$ifNotExists` | `true` (default): tidak error kalau schema sudah ada                   |

## dropSchema()

```php
dropSchema(string $schema, bool $ifExists = true, bool $cascade = false): void
```

| Parameter   | Keterangan                                                                                                                 |
| ----------- | -------------------------------------------------------------------------------------------------------------------------- |
| `$schema`   | Nama schema                                                                                                                |
| `$ifExists` | `true` (default): tidak error kalau schema tidak ada                                                                       |
| `$cascade`  | `true`: ikut hapus semua objek di dalam schema (tabel, view, dst). **Hanya PostgreSQL.** Data ikut hilang, pakai hati-hati |

Tanpa `$cascade`, database akan menolak menghapus schema yang masih berisi objek. Itu perilaku yang aman, jadi hapus tabelnya dulu di `down()` seperti contoh di atas.

## Catatan

- Nama schema divalidasi ketat (regex) karena nama identifier tidak bisa di-bind sebagai parameter query. Nama dengan karakter khusus akan ditolak dengan `InvalidArgumentException`.
- Kedua method bersifat `protected`, dipanggil dari dalam class migration (`$this->createSchema(...)`).
- Pastikan schema dibuat **sebelum** `createTable('namaschema.tabel')`, dan dihapus **sesudah** tabelnya di `down()`.
