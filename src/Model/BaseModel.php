<?php

namespace Said\Ci4Base\Model;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

/**
 * Base model generik untuk CodeIgniter 4.
 *
 * Fitur:
 * - Primary key UUID v4 (di-generate via random_bytes(), bukan auto-increment)
 * - Soft delete bawaan (kolom deleted_at)
 * - Mini-DSL query builder lewat applyFilters()
 * - saveData()/insertData() sebagai satu jalur insert-atau-update
 * - deleteBy(), countBy() untuk query kondisional cepat
 * - bulkSaveData() untuk sinkronisasi volume tinggi (batch insert/update)
 *
 * CATATAN: ini adalah template awal. Sesuaikan kembali dengan logika
 * spesifik project-mu (nama tabel, validasi, event) sebelum dipakai.
 */
class BaseModel extends Model
{
    protected $useSoftDeletes = true;
    protected $useTimestamps  = true;
    protected $returnType     = 'array';

    /**
     * Override supaya primary key SELALU dianggap sebagai UUID string,
     * bukan integer auto-increment. Sub-class cukup set $primaryKey seperti biasa.
     */
    protected $primaryKey = 'id';

    /**
     * Generate UUID v4 memakai random_bytes() (tanpa dependency ekstra).
     */
    public static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // versi 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // varian RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Hook callBeforeInsert: isi primary key dengan UUID kalau belum ada.
     * Daftarkan di $beforeInsert pada sub-class:
     *   protected $beforeInsert = ['generateUuidIfEmpty'];
     */
    protected function generateUuidIfEmpty(array $data): array
    {
        $pk = $this->primaryKey;

        if (empty($data['data'][$pk])) {
            $data['data'][$pk] = self::generateUuid();
        }

        return $data;
    }

    /**
     * Insert atau update satu baris data tergantung ada tidaknya primary key.
     * Ini adalah method utama yang dipakai di seluruh project.
     *
     * @param array       $data      Data yang mau disimpan
     * @param string|null $id        ID baris kalau update; null kalau insert baru
     * @return string  UUID baris yang disimpan
     */
    public function saveData(array $data, ?string $id = null): string
    {
        if ($id !== null) {
            $data[$this->primaryKey] = $id;
            $this->update($id, $data);
            return $id;
        }

        $newId = $data[$this->primaryKey] ?? self::generateUuid();
        $data[$this->primaryKey] = $newId;
        $this->insert($data);

        return $newId;
    }

    /**
     * Wrapper insert-only di atas saveData(), dipertahankan untuk kompatibilitas
     * kode lama yang memanggil insertData() secara eksplisit.
     */
    public function insertData(array $data): string
    {
        return $this->saveData($data, null);
    }

    /**
     * Hapus baris berdasarkan kondisi where sederhana (mini-DSL).
     * Contoh: $model->deleteBy(['sekolah_id' => $id, 'status' => 'draft']);
     */
    public function deleteBy(array $conditions): bool
    {
        return $this->where($conditions)->delete();
    }

    /**
     * Hitung baris berdasarkan kondisi where sederhana.
     * Contoh: $model->countBy(['sekolah_id' => $id]);
     */
    public function countBy(array $conditions): int
    {
        return $this->where($conditions)->countAllResults();
    }

    /**
     * Bulk insert/update untuk sinkronisasi volume tinggi.
     * Mengelompokkan baris baru (insert batch) dan baris existing (update per-batch)
     * supaya jumlah query jauh lebih sedikit dibanding loop saveData() satu-satu.
     *
     * @param array $rows        Daftar baris data
     * @param int   $batchSize   Ukuran batch per query (default 500)
     * @return array{inserted:int, updated:int}
     */
    public function bulkSaveData(array $rows, int $batchSize = 500): array
    {
        $toInsert = [];
        $toUpdate = [];

        foreach ($rows as $row) {
            if (empty($row[$this->primaryKey])) {
                $row[$this->primaryKey] = self::generateUuid();
                $toInsert[] = $row;
            } else {
                $toUpdate[] = $row;
            }
        }

        $inserted = 0;
        $updated  = 0;

        if (!empty($toInsert)) {
            foreach (array_chunk($toInsert, $batchSize) as $chunk) {
                $this->insertBatch($chunk);
                $inserted += count($chunk);
            }
        }

        if (!empty($toUpdate)) {
            foreach (array_chunk($toUpdate, $batchSize) as $chunk) {
                $this->updateBatch($chunk, $this->primaryKey);
                $updated += count($chunk);
            }
        }

        return ['inserted' => $inserted, 'updated' => $updated];
    }

    /**
     * Mini-DSL query builder: terima array kondisi fleksibel
     * (mendukung operator selain '=' lewat suffix key, mis. 'created_at >=').
     * Contoh:
     *   $model->applyFilters($model->builder(), [
     *       'sekolah_id' => $id,
     *       'created_at >=' => $tanggal,
     *   ]);
     */
    public function applyFilters(BaseBuilder $builder, array $filters): BaseBuilder
    {
        foreach ($filters as $key => $value) {
            if ($value === null) {
                $builder->where($key === strtolower($key) ? "$key IS NULL" : $key);
                continue;
            }
            $builder->where($key, $value);
        }

        return $builder;
    }
}
