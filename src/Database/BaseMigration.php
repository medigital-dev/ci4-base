<?php

namespace MedigitalDev\Ci4Base\Migration;

use CodeIgniter\Database\Migration;

/**
 * Base migration dengan dukungan SCHEMA, yang tidak disediakan oleh Forge CI4.
 *
 * Forge hanya punya createDatabase()/dropDatabase(). Untuk database yang
 * mengenal konsep schema (PostgreSQL, SQL Server), method di sini mengisi celah itu.
 *
 * Driver yang didukung: Postgre, SQLSRV.
 * Di MySQL/MariaDB, "schema" sama dengan "database", jadi gunakan
 * $this->forge->createDatabase() / dropDatabase() saja.
 */
abstract class BaseMigration extends Migration
{
    /**
     * Buat schema baru.
     *
     * @param string $schema       Nama schema (huruf, angka, underscore; tidak boleh diawali angka)
     * @param bool   $ifNotExists  true = tidak error kalau schema sudah ada
     */
    protected function createSchema(string $schema, bool $ifNotExists = true): void
    {
        $this->assertValidSchemaName($schema);

        switch ($this->db->DBDriver) {
            case 'Postgre':
                $name = '"' . $schema . '"'; // aman: nama sudah divalidasi regex
                $this->db->query('CREATE SCHEMA ' . ($ifNotExists ? 'IF NOT EXISTS ' : '') . $name);
                break;

            case 'SQLSRV':
                $name = '[' . $schema . ']';
                if ($ifNotExists) {
                    $this->db->query(
                        "IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = '{$schema}') EXEC('CREATE SCHEMA {$name}')"
                    );
                } else {
                    $this->db->query('CREATE SCHEMA ' . $name);
                }
                break;

            default:
                $this->throwUnsupportedDriver(__FUNCTION__);
        }
    }

    /**
     * Hapus schema.
     *
     * @param string $schema    Nama schema
     * @param bool   $ifExists  true = tidak error kalau schema tidak ada
     * @param bool   $cascade   true = ikut hapus semua objek di dalamnya (tabel, view, dst).
     *                          Hanya didukung PostgreSQL. Hati-hati: data ikut hilang.
     */
    protected function dropSchema(string $schema, bool $ifExists = true, bool $cascade = false): void
    {
        $this->assertValidSchemaName($schema);

        switch ($this->db->DBDriver) {
            case 'Postgre':
                $name = '"' . $schema . '"'; // aman: nama sudah divalidasi regex
                $this->db->query(
                    'DROP SCHEMA ' . ($ifExists ? 'IF EXISTS ' : '') . $name . ($cascade ? ' CASCADE' : '')
                );
                break;

            case 'SQLSRV':
                if ($cascade) {
                    throw new \RuntimeException('Opsi $cascade tidak didukung di SQL Server. Hapus objek di dalam schema terlebih dahulu.');
                }
                $this->db->query('DROP SCHEMA ' . ($ifExists ? 'IF EXISTS ' : '') . '[' . $schema . ']');
                break;

            default:
                $this->throwUnsupportedDriver(__FUNCTION__);
        }
    }

    /**
     * Nama schema dibatasi ke karakter aman, karena nama identifier
     * tidak bisa di-bind sebagai parameter query.
     */
    private function assertValidSchemaName(string $schema): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
            throw new \InvalidArgumentException(
                "Nama schema \"{$schema}\" tidak valid. Gunakan huruf, angka, dan underscore saja."
            );
        }
    }

    private function throwUnsupportedDriver(string $method): void
    {
        throw new \RuntimeException(
            sprintf('%s() tidak didukung untuk driver "%s". Driver yang didukung: Postgre, SQLSRV.', $method, $this->db->DBDriver)
        );
    }
}
