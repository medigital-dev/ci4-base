<?php

namespace MedigitalDev\Ci4Base\Models;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Model;
use Override;

abstract class BaseModel extends Model
{
    /**
     * ID Unique Key
     * @var ?string
     */
    protected $uniqueKey = null;

    /**
     * Aktif/nonaktifkan pembersihan cache otomatis saat upsert() / saveData() / insertData() /
     * bulkSaveData() / deleteBy() dipanggil.
     */
    protected bool $cleanCacheOnSave = true;

    /**
     * Prefix cache key untuk model ini.
     * Null = TIDAK ada pembersihan cache (aman untuk pemakai paket ini).
     * Isi di model turunan (mis. 'siswa_') untuk menghapus cache ber-prefix tsb saat data berubah,
     * atau override cleanRelatedCache() untuk strategi sendiri.
     */
    protected ?string $cachePrefix = null;

    /**
     * Jika true, record soft-deleted yang cocok dengan kunci pencarian akan di-restore
     * (deleted_at = null) lalu di-update. Jika false (default), record soft-deleted yang cocok
     * dianggap konflik: upsert() mengembalikan gagal, bulkSaveData() menolak barisnya.
     *
     * Catatan: untuk upsert(), $deletedField harus ada di $allowedFields agar restore tersimpan.
     */
    protected bool $restoreSoftDeleted = false;

    /**
     * Pelacak untuk getInsertedUniqueId(): uniqueKey dari penulisan terakhir lewat
     * save()/upsert() (null jika penulisan itu update), dan nilai insertID saat itu.
     */
    private ?string $trackedUniqueKey = null;
    private ?string $trackedInsertId  = null;

    /**
     * Pastikan $uniqueKey ikut di $allowedFields. Tanpa ini, proteksi field CI4 membuang kolom
     * tersebut diam-diam saat insert()/update() (dipakai save() dan upsert()), sehingga UUID yang
     * dibuat tidak pernah tersimpan. bulkSaveData() sudah menambahkannya sendiri.
     *
     * Hanya ditambahkan jika $allowedFields sudah berisi (agar pengecekan "kosong" di
     * bulkSaveData() tetap berfungsi). Jika model turunan punya initialize() sendiri,
     * panggil parent::initialize() di dalamnya.
     */
    protected function initialize(): void
    {
        parent::initialize();

        if (
            $this->uniqueKey !== null
            && $this->allowedFields !== []
            && !in_array($this->uniqueKey, $this->allowedFields, true)
        ) {
            $this->allowedFields[] = $this->uniqueKey;
        }
    }

    /**
     * Flexible where builder by string pattern.
     *
     * Format field:
     * - `|` untuk OR
     * - `&` untuk AND
     *
     * Contoh:
     *   ->whereBy('email|username', 'john') // email='john' OR username='john'
     *   ->whereBy('status&role', 'active')  // status='active' AND role='active'
     *
     * Notes: Pastikan field tidak ambigu! Hanya untuk string dari developer, JANGAN dari input pengguna akhir.
     *
     * @param string      $field Nama kolom (boleh kombinasi OR/AND)
     * @param string|null $value Nilai untuk pencarian
     *
     * @return static
     */
    public function whereBy(string $field, ?string $value = null)
    {
        $andGroups = explode('&', $field);

        foreach ($andGroups as $andGroup) {
            $orFields = array_values(array_filter(array_map('trim', explode('|', $andGroup)), fn($f) => $f !== ''));

            if (empty($orFields)) {
                throw new \InvalidArgumentException("Format \$field tidak valid: \"{$field}\".");
            }

            $this->groupStart();

            foreach ($orFields as $i => $orField) {
                if ($i === 0) {
                    $this->where($orField, $value);
                } else {
                    $this->orWhere($orField, $value);
                }
            }

            $this->groupEnd();
        }

        return $this;
    }

    // ------------------------------------------------------------------
    //  UPSERT
    // ------------------------------------------------------------------

    /**
     * Simpan data dengan mekanisme upsert (insert jika belum ada, update jika sudah ada),
     * serta auto-generate unique key jika model punya $uniqueKey.
     *
     * SINTAKS $whereKey (mini-DSL untuk menentukan kriteria "record yang sama"):
     *   - '&' = AND antar grup   -> "email&username" berarti WHERE email=... AND username=...
     *   - '|' = OR dalam 1 grup  -> "email|username" berarti WHERE email=... OR username=...
     *   - Bisa dikombinasikan    -> "email&status|type" berarti WHERE email=... AND (status=... OR type=...)
     *   - Semua field yang disebut WAJIB ada di $data (array_key_exists). Dalam grup OR, field yang
     *     nilainya kosong (null / string kosong) dilewati; jika SEMUA field di satu grup kosong,
     *     exception dilempar (mencegah "IS NULL" mengenai record yang salah).
     *   - HANYA untuk string dari developer, jangan dari input pengguna akhir.
     *
     * Urutan pencarian record existing:
     *   1. $whereKey (jika diisi) -- cocok >1 record = RuntimeException
     *   2. primary key di $data (jika $whereKey null)
     *   3. $uniqueKey di $data (jika belum ketemu)
     * Record soft-deleted ikut dicari; lihat $restoreSoftDeleted.
     *
     * Saat update, $uniqueKey milik record existing selalu dipertahankan.
     *
     * KONTRAK RETURN
     *   $return = null:
     *     sukses -> ['success' => true,  'status' => 'inserted'|'updated', 'data' => $data]
     *     gagal  -> ['success' => false, 'status' => 'failed', 'message' => array|string, 'data' => $data]
     *   $return = 'nama_key':
     *     sukses -> nilai $data[$nama_key], atau null jika kosong
     *     gagal  -> null (detail error: $this->errors())
     *   $return harus berupa key yang dikenal (ada di $data, primary key, uniqueKey, atau allowedFields);
     *   selain itu InvalidArgumentException (dicek SEBELUM menulis ke DB).
     *
     * @param array       $data     Data yang akan disimpan
     * @param string|null $whereKey Kriteria pencarian record existing
     * @param string|null $return   Key dari hasil yang ingin dikembalikan langsung
     *
     * @return mixed
     *
     * @throws \InvalidArgumentException Jika $whereKey/$return tidak valid
     * @throws \RuntimeException         Jika $whereKey cocok dengan lebih dari satu record
     */
    public function upsert(array $data, ?string $whereKey = null, ?string $return = null): mixed
    {
        $whereKey = ($whereKey === null || trim($whereKey) === '') ? null : $whereKey;

        $this->assertReturnKey($return, $data);

        try {
            $result = $this->performUpsert($data, $whereKey);
        } catch (DatabaseException) {
            // Kemungkinan race condition (insert bentrok UNIQUE constraint karena proses lain
            // baru saja menyimpan record yang sama): cari ulang sekali, lalu insert/update.
            // Jika penyebabnya lain, percobaan kedua akan melempar exception yang sama.
            $this->builder()->resetQuery();
            $result = $this->performUpsert($data, $whereKey);
        }

        if ($result['success']) {
            if ($this->uniqueKey !== null) {
                $this->trackInsertedUniqueKey(
                    $result['status'] === 'inserted' ? ($result['data'][$this->uniqueKey] ?? null) : null
                );
            }

            $this->cleanRelatedCache();
        }

        if ($return !== null) {
            return $result['success'] ? ($result['data'][$return] ?? null) : null;
        }

        return $result;
    }

    /**
     * Inti proses upsert (tanpa cache, tanpa format $return).
     *
     * @return array{success:bool, status:string, data:array, message?:array|string}
     */
    private function performUpsert(array $data, ?string $whereKey): array
    {
        $existing = null;

        if ($whereKey !== null) {
            $this->buildMiniDslWhere($whereKey, $data, 'whereKey');
            $matches = $this->asArray()->withDeleted()->findAll(2);

            if (count($matches) > 1) {
                throw new \RuntimeException("whereKey \"{$whereKey}\" cocok dengan lebih dari satu record.");
            }

            $existing = $matches[0] ?? null;
        } elseif (!$this->isBlankMatchValue($data[$this->primaryKey] ?? null)) {
            $existing = $this->asArray()->withDeleted()->find($data[$this->primaryKey]);
        }

        if ($existing === null && $this->uniqueKey !== null && !$this->isBlankMatchValue($data[$this->uniqueKey] ?? null)) {
            $existing = $this->asArray()->withDeleted()->findBy($this->uniqueKey, (string) $data[$this->uniqueKey]);
        }

        if ($existing !== null) {
            $existingPk = $existing[$this->primaryKey];

            if (
                !$this->isBlankMatchValue($data[$this->primaryKey] ?? null)
                && (string) $data[$this->primaryKey] !== (string) $existingPk
            ) {
                return $this->upsertFailure('Primary key pada $data berbeda dengan record yang cocok.', $data);
            }

            if ($this->useSoftDeletes && !empty($existing[$this->deletedField])) {
                if (!$this->restoreSoftDeleted) {
                    return $this->upsertFailure('Record yang cocok berstatus soft-deleted dan $restoreSoftDeleted = false.', $data);
                }
                $data[$this->deletedField] = null;
            }

            $data[$this->primaryKey] = $existingPk;

            // Pertahankan uniqueKey lama, jangan di-regenerate / diganti
            if ($this->uniqueKey !== null && !$this->isBlankMatchValue($existing[$this->uniqueKey] ?? null)) {
                $data[$this->uniqueKey] = $existing[$this->uniqueKey];
            }

            if ($this->update($existingPk, $data) === false) {
                return $this->upsertFailure($this->lastErrors(), $data);
            }

            return ['success' => true, 'status' => 'updated', 'data' => $data];
        }

        if ($this->uniqueKey !== null && $this->isBlankMatchValue($data[$this->uniqueKey] ?? null)) {
            $data[$this->uniqueKey] = $this->generateUniqueKey($this->uniqueKey);
        }

        $id = $this->insert($data, true);

        if ($id === false) {
            return $this->upsertFailure($this->lastErrors(), $data);
        }

        if ($this->isBlankMatchValue($data[$this->primaryKey] ?? null)) {
            $data[$this->primaryKey] = $id;
        }

        return ['success' => true, 'status' => 'inserted', 'data' => $data];
    }

    /**
     * Override save() bawaan CI4:
     *  - Jika model punya $uniqueKey dan nilainya kosong, isi otomatis dengan UUID baru
     *    (atau pertahankan nilai milik record existing saat update).
     *  - Setelah sukses, bersihkan cache terkait (lihat cleanRelatedCache()), sama seperti upsert().
     */
    #[Override]
    public function save($row): bool
    {
        $insertIdBefore = $this->insertID;

        if ($this->uniqueKey !== null && (is_array($row) || is_object($row))) {
            if (is_array($row)) {
                $data = $row;
            } elseif (is_callable([$row, 'toRawArray'])) {
                $data = $row->toRawArray();
            } else {
                $data = get_object_vars($row);

                if (!array_key_exists($this->uniqueKey, $data)) {
                    $data[$this->uniqueKey] = $row->{$this->uniqueKey} ?? null;
                }
                if (!array_key_exists($this->primaryKey, $data)) {
                    $data[$this->primaryKey] = $row->{$this->primaryKey} ?? null;
                }
            }

            if ($this->isBlankMatchValue($data[$this->uniqueKey] ?? null)) {
                $uniqueKey = null;
                $primaryKey = $data[$this->primaryKey] ?? null;

                if (!$this->isBlankMatchValue($primaryKey)) {
                    $existing = $this->asArray()->withDeleted()->find($primaryKey);
                    $uniqueKey = $existing[$this->uniqueKey] ?? null;
                }

                if ($this->isBlankMatchValue($uniqueKey)) {
                    $uniqueKey = $this->generateUniqueKey($this->uniqueKey);
                }

                if (is_array($row)) {
                    $row[$this->uniqueKey] = $uniqueKey;
                } else {
                    $row->{$this->uniqueKey} = $uniqueKey;
                }
            }
        }

        $result = parent::save($row);

        if ($result) {
            if ($this->uniqueKey !== null && (is_array($row) || is_object($row))) {
                // insertID hanya berubah kalau CI4 benar-benar melakukan insert pada panggilan ini
                $inserted = $this->insertID !== $insertIdBefore;
                $key      = is_array($row) ? ($row[$this->uniqueKey] ?? null) : ($row->{$this->uniqueKey} ?? null);

                $this->trackInsertedUniqueKey($inserted ? $key : null);
            }

            $this->cleanRelatedCache();
        }

        return $result;
    }

    private function trackInsertedUniqueKey(mixed $key): void
    {
        $this->trackedUniqueKey = $this->isBlankMatchValue($key) ? null : (string) $key;
        $this->trackedInsertId  = (string) $this->insertID;
    }

    private function upsertFailure(array|string $message, array $data): array
    {
        return ['success' => false, 'status' => 'failed', 'message' => $message, 'data' => $data];
    }

    /** Error validasi model; jika kosong, pakai pesan error database terakhir. */
    private function lastErrors(): array
    {
        $errors = $this->errors();

        if ($errors === []) {
            $dbError = $this->db->error();
            $errors  = ['database' => $dbError['message'] ?? 'Gagal menyimpan data.'];
        }

        return $errors;
    }

    private function assertReturnKey(?string $return, array $data): void
    {
        if (
            $return === null
            || array_key_exists($return, $data)
            || $return === $this->primaryKey
            || $return === $this->uniqueKey
            || in_array($return, $this->allowedFields, true)
        ) {
            return;
        }

        throw new \InvalidArgumentException("Key \"{$return}\" pada \$return tidak dikenal.");
    }

    /**
     * Format lama saveData(): sukses -> $data (atau $data[$return]); gagal -> ['success'=>false,...].
     *
     * @deprecated Gunakan upsert(). Dihapus di versi mayor berikutnya.
     *
     * @return array|string|int|null
     */
    public function saveData(array $data, ?string $whereKey = null, ?string $return = null): mixed
    {
        $this->assertReturnKey($return, $data);

        $result = $this->upsert($data, $whereKey);

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => $result['message'],
                'data'    => $result['data'],
            ];
        }

        return $return === null ? $result['data'] : ($result['data'][$return] ?? null);
    }

    /**
     * Alias untuk insert/upsert (wrapper di atas upsert()).
     *
     * @param array       $data     Data yang akan disimpan
     * @param string|null $whereKey Kriteria pencarian record existing (lihat dokumentasi upsert())
     * @param bool        $returnId Jika true, kembalikan hanya ID (uniqueKey jika ada, else primaryKey).
     *                               Jika false, kembalikan true saat sukses.
     *
     * @return array|string|int|bool Array ['success'=>false,...] saat gagal; bool/ID saat sukses.
     */
    public function insertData(array $data, ?string $whereKey = null, bool $returnId = false): array|string|int|bool
    {
        $result = $this->upsert($data, $whereKey);

        if (!$result['success']) {
            return $result;
        }

        if (!$returnId) {
            return true;
        }

        return $this->uniqueKey !== null
            ? $result['data'][$this->uniqueKey]
            : $result['data'][$this->primaryKey];
    }

    // ------------------------------------------------------------------
    //  BULK UPSERT
    // ------------------------------------------------------------------

    /**
     * Simpan banyak baris sekaligus dengan upsert, tanpa query per-baris.
     *
     * Cara kerja:
     *  1. Jika $matchField diisi: ambil record existing yang mungkin cocok lewat whereIn()
     *     (chunk 1000) pada grup AND pertama, bangun indeks per kolom, lalu cocokkan tiap baris
     *     di PHP (irisan semua grup AND; dalam grup OR, cukup salah satu field cocok).
     *     Jika NULL: semua baris di-insert tanpa pengecekan.
     *  2. Baris duplikat di dalam $rows digabung (baris terakhir menang), dihitung 'skipped'.
     *  3. Hanya kolom di $allowedFields (+ primary key, uniqueKey, deletedField) yang ditulis;
     *     kolom lain dibuang, karena insertBatch()/updateBatch() tidak menyaring sendiri.
     *  4. Baris dikelompokkan per set kolom lalu insertBatch()/updateBatch() dalam SATU transaksi.
     *
     * Aturan pencocokan:
     *  - Nilai dibandingkan setelah trim + lowercase (override normalizeMatchValue() bila collation
     *    DB Anda case-sensitive). Nilai kosong tidak pernah dipakai mencocokkan.
     *  - Pola "a|b&c" berarti (a OR b) AND c.
     *  - Baris DITOLAK jika: tidak punya nilai untuk suatu grup, cocok >1 record, atau cocok record
     *    soft-deleted padahal $restoreSoftDeleted = false.
     *
     * @param array       $rows       Daftar baris data
     * @param string|null $matchField Pola pencocokan (mini-DSL, lihat upsert()). Null = insert-only.
     * @param int         $batchSize  Ukuran chunk insertBatch()/updateBatch()
     * @param bool        $strict     true: baris ditolak => RuntimeException (transaksi tidak dimulai).
     *                                false: baris ditolak dilewati, indeksnya dicatat di 'rejected'.
     *
     * @return array{inserted:int, updated:int, skipped:int, rejected:int[]}
     *         skipped = duplikat yang digabung + baris yang ditolak (mode non-strict)
     *
     * @throws \InvalidArgumentException Pola/baris tidak valid
     * @throws \LogicException           $allowedFields kosong
     * @throws \RuntimeException         Baris ditolak (strict) atau batch gagal (transaksi di-rollback)
     */
    public function bulkSaveData(array $rows, ?string $matchField = null, int $batchSize = 500, bool $strict = true): array
    {
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'rejected' => []];

        if ($rows === []) {
            return $result;
        }

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException("Baris ke-{$i} harus berupa array, diperoleh: " . gettype($row));
            }
        }

        $allowedMap = $this->allowedColumnMap();
        $groups     = ($matchField !== null && trim($matchField) !== '')
            ? $this->parseMatchPattern($matchField, 'matchField')
            : [];

        $reject = function (int|string $i, string $reason) use (&$result, $strict): void {
            if ($strict) {
                throw new \RuntimeException("Baris ke-{$i}: {$reason}");
            }
            $result['rejected'][] = $i;
            $result['skipped']++;
        };

        $toInsert = []; // key identitas => row (atau list biasa jika tanpa $matchField)
        $toUpdate = []; // primary key   => row
        $restore  = []; // primary key   => true, untuk record soft-deleted yang di-restore

        if ($groups === []) {
            $toInsert = array_values($rows);
        } else {
            [$index, $byPk] = $this->loadExistingIndex($rows, $groups);

            foreach ($rows as $i => $row) {
                $matched = null; // set primary key kandidat (irisan semua grup AND)
                $ident   = [];   // identitas baris untuk dedup antar-baris baru
                $missing = false;

                foreach ($groups as $groupFields) {
                    $ids      = [];
                    $hasValue = false;

                    foreach ($groupFields as $field) {
                        if (!array_key_exists($field, $row) || $this->isBlankMatchValue($row[$field])) {
                            continue;
                        }

                        $norm = $this->normalizeMatchValue($row[$field]);

                        if (!$hasValue) {
                            $ident[] = $field . '=' . $norm; // field terisi pertama jadi identitas grup
                        }
                        $hasValue = true;

                        foreach ($index[$field][$norm] ?? [] as $pk) {
                            $ids[$pk] = true;
                        }
                    }

                    if (!$hasValue) {
                        $missing = true;
                        break;
                    }

                    $matched = $matched === null ? $ids : array_intersect_key($matched, $ids);
                }

                if ($missing) {
                    $reject($i, "tidak memiliki nilai untuk \$matchField \"{$matchField}\".");
                    continue;
                }

                if (count($matched) > 1) {
                    $reject($i, 'cocok dengan ' . count($matched) . " record untuk \$matchField \"{$matchField}\".");
                    continue;
                }

                if (count($matched) === 1) {
                    $pk       = array_key_first($matched);
                    $existing = $byPk[$pk];
                    $deleted  = $this->useSoftDeletes && !empty($existing[$this->deletedField]);

                    if ($deleted && !$this->restoreSoftDeleted) {
                        $reject($i, 'cocok dengan record soft-deleted dan $restoreSoftDeleted = false.');
                        continue;
                    }

                    if ($deleted) {
                        $restore[$pk] = true;
                    }

                    if (isset($toUpdate[$pk])) {
                        $result['skipped']++; // duplikat di $rows: gabung, baris terakhir menang
                    }
                    $toUpdate[$pk] = array_merge($toUpdate[$pk] ?? [], $row);
                } else {
                    $key = implode('||', $ident);

                    if (isset($toInsert[$key])) {
                        $result['skipped']++;
                    }
                    $toInsert[$key] = array_merge($toInsert[$key] ?? [], $row);
                }
            }

            // Lengkapi baris update dengan PK & uniqueKey existing, restore jika perlu
            foreach ($toUpdate as $pk => &$row) {
                $existing = $byPk[$pk];
                $row[$this->primaryKey] = $existing[$this->primaryKey];

                if ($this->uniqueKey !== null && !$this->isBlankMatchValue($existing[$this->uniqueKey] ?? null)) {
                    $row[$this->uniqueKey] = $existing[$this->uniqueKey];
                }
                if (isset($restore[$pk])) {
                    $row[$this->deletedField] = null;
                }
            }
            unset($row);
        }

        // uniqueKey untuk baris baru
        if ($this->uniqueKey !== null) {
            foreach ($toInsert as &$row) {
                if ($this->isBlankMatchValue($row[$this->uniqueKey] ?? null)) {
                    $row[$this->uniqueKey] = $this->generateUuidV4();
                }
            }
            unset($row);
        }

        // Saring kolom (mencegah mass assignment) dan buang baris yang tidak punya apa-apa untuk ditulis
        foreach ($toInsert as $k => $row) {
            $toInsert[$k] = array_intersect_key($row, $allowedMap);
            if ($toInsert[$k] === []) {
                unset($toInsert[$k]);
            }
        }
        foreach ($toUpdate as $k => $row) {
            $toUpdate[$k] = array_intersect_key($row, $allowedMap);
            if (count($toUpdate[$k]) <= 1) { // hanya primary key: tidak ada yang diupdate
                unset($toUpdate[$k]);
            }
        }

        // Eksekusi dalam satu transaksi
        $this->db->transBegin();

        try {
            foreach ($this->groupRowsBySignature($toInsert) as $chunk) {
                if ($this->insertBatch($chunk, null, $batchSize) === false) {
                    throw new \RuntimeException('insertBatch gagal: ' . json_encode($this->lastErrors()));
                }
            }

            foreach ($this->groupRowsBySignature($toUpdate) as $chunk) {
                if ($this->updateBatch($chunk, $this->primaryKey, $batchSize) === false) {
                    throw new \RuntimeException('updateBatch gagal: ' . json_encode($this->lastErrors()));
                }
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        $result['inserted'] = count($toInsert);
        $result['updated']  = count($toUpdate);

        $this->cleanRelatedCache();

        return $result;
    }

    /**
     * Peta kolom yang boleh ditulis oleh bulkSaveData().
     *
     * @return array<string,int>
     */
    private function allowedColumnMap(): array
    {
        if ($this->allowedFields === []) {
            throw new \LogicException(static::class . ': $allowedFields kosong, bulkSaveData() tidak dapat menyaring kolom.');
        }

        $cols = array_merge($this->allowedFields, [$this->primaryKey]);

        if ($this->uniqueKey !== null) {
            $cols[] = $this->uniqueKey;
        }
        if ($this->useSoftDeletes) {
            $cols[] = $this->deletedField;
        }

        return array_flip($cols);
    }

    /**
     * Ambil record existing yang mungkin cocok, lalu bangun indeks per kolom.
     *
     * Kandidat diambil lewat grup AND pertama saja (superset dari semua kecocokan, karena
     * kecocokan harus memenuhi semua grup); grup lain disaring di PHP lewat irisan indeks.
     *
     * @param array      $rows   Seluruh baris input
     * @param string[][] $groups Hasil parseMatchPattern()
     *
     * @return array{0: array<string, array<string, array>>, 1: array<string|int, array>}
     *         [0] indeks: field => nilai ternormalisasi => list primary key
     *         [1] record existing per primary key
     */
    private function loadExistingIndex(array $rows, array $groups): array
    {
        $allFields = array_values(array_unique(array_merge(...$groups)));

        $selectCols = array_merge([$this->primaryKey], $allFields);
        if ($this->uniqueKey !== null) {
            $selectCols[] = $this->uniqueKey;
        }
        if ($this->useSoftDeletes) {
            $selectCols[] = $this->deletedField;
        }
        $selectCols = array_values(array_unique($selectCols));

        $byPk = [];

        foreach ($groups[0] as $field) {
            $values = [];
            foreach ($rows as $row) {
                if (array_key_exists($field, $row) && !$this->isBlankMatchValue($row[$field])) {
                    $values[trim((string) $row[$field])] = true;
                }
            }
            $values = array_map('strval', array_keys($values));

            // 1000 per query: aman untuk batas parameter SQL Server (2100)
            foreach (array_chunk($values, 1000) as $chunk) {
                $found = $this->select($selectCols)
                    ->withDeleted()
                    ->whereIn($field, $chunk)
                    ->asArray()
                    ->findAll();

                foreach ($found as $existRow) {
                    $byPk[$existRow[$this->primaryKey]] = $existRow;
                }
            }
        }

        $index = [];
        foreach ($byPk as $pk => $existRow) {
            foreach ($allFields as $field) {
                if (!isset($existRow[$field]) || $this->isBlankMatchValue($existRow[$field])) {
                    continue;
                }
                $index[$field][$this->normalizeMatchValue($existRow[$field])][] = $pk;
            }
        }

        return [$index, $byPk];
    }

    /**
     * Kelompokkan baris menurut set kolomnya (insertBatch/updateBatch mengasumsikan semua baris
     * punya kolom sama). Kolom yang tidak ada di suatu baris dibiarkan tidak disentuh,
     * bukan diisi NULL.
     *
     * @return array[] list kelompok; tiap kelompok = list baris
     */
    private function groupRowsBySignature(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            ksort($row);
            $groups[implode('|', array_keys($row))][] = $row;
        }

        return array_values($groups);
    }

    // ------------------------------------------------------------------
    //  MINI-DSL HELPERS
    // ------------------------------------------------------------------

    /**
     * Parse pola mini-DSL jadi list grup AND; tiap grup berisi daftar field OR.
     * "nisn|nik&sekolah_id" => [['nisn','nik'], ['sekolah_id']]
     *
     * @return string[][]
     */
    private function parseMatchPattern(string $pattern, string $context = 'matchField'): array
    {
        $groups = [];

        foreach (explode('&', $pattern) as $andGroup) {
            $orFields = array_values(array_filter(array_map('trim', explode('|', $andGroup)), fn($f) => $f !== ''));

            if ($orFields === []) {
                throw new \InvalidArgumentException("Format \${$context} tidak valid: \"{$pattern}\".");
            }

            $groups[] = $orFields;
        }

        return $groups;
    }

    protected function isBlankMatchValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Normalisasi nilai sebelum dibandingkan di PHP (bulkSaveData). Default: trim + lowercase,
     * cocok untuk collation case-insensitive umum (MySQL, SQL Server). Override di model turunan
     * jika collation kolom case-sensitive.
     */
    protected function normalizeMatchValue(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * Bangun kondisi WHERE dari mini-DSL ('&' = AND grup, '|' = OR dalam grup),
     * dengan nilai diambil per-field dari $data (bukan satu value untuk semua field
     * seperti whereBy()).
     *
     * Semua field divalidasi dulu SEBELUM query builder disentuh, supaya kalau validasi gagal,
     * builder tidak ditinggal dalam state groupStart() yang belum di-groupEnd().
     * Field bernilai kosong dalam grup OR dilewati; grup yang seluruh field-nya kosong = exception.
     *
     * @param string $pattern Mini-DSL pattern, mis. "email&username" atau "email|username"
     * @param array  $data    Data sumber nilai
     * @param string $context Nama variabel untuk pesan error (mis. "whereKey")
     *
     * @throws \InvalidArgumentException Jika pattern invalid, field tidak ada di $data, atau grup kosong
     */
    private function buildMiniDslWhere(string $pattern, array $data, string $context = 'whereKey'): void
    {
        $parsedGroups = [];

        // Pass 1: parse & validasi semua field dulu, jangan sentuh query builder.
        foreach ($this->parseMatchPattern($pattern, $context) as $orFields) {
            $usable = [];

            foreach ($orFields as $orField) {
                if (!array_key_exists($orField, $data)) {
                    throw new \InvalidArgumentException("Field \"{$orField}\" pada \${$context} tidak ditemukan di \$data.");
                }
                if (!$this->isBlankMatchValue($data[$orField])) {
                    $usable[] = $orField;
                }
            }

            if ($usable === []) {
                throw new \InvalidArgumentException(
                    'Semua field pada grup "' . implode('|', $orFields) . "\" di \${$context} bernilai kosong di \$data."
                );
            }

            $parsedGroups[] = $usable;
        }

        // Pass 2: baru bangun query, sekarang aman (tidak akan exception di tengah jalan).
        foreach ($parsedGroups as $orFields) {
            $this->groupStart();

            foreach ($orFields as $i => $orField) {
                if ($i === 0) {
                    $this->where($orField, $data[$orField]);
                } else {
                    $this->orWhere($orField, $data[$orField]);
                }
            }

            $this->groupEnd();
        }
    }

    // ------------------------------------------------------------------
    //  UNIQUE KEY & CACHE
    // ------------------------------------------------------------------

    /**
     * Generate ID unik (UUIDv4) untuk kolom tertentu, memastikan tidak ada duplikat di tabel ini
     * (termasuk record soft-deleted).
     *
     * Loop pengecekan hanya jaring pengaman murah; untuk beban concurrent tinggi, tambahkan
     * UNIQUE constraint di level database pada kolom ini (upsert() akan mencoba ulang sekali
     * jika insert bentrok).
     *
     * @param string $key Nama kolom yang harus unik
     * @return string UUIDv4 format standar (36 karakter)
     */
    private function generateUniqueKey(string $key): string
    {
        do {
            $id = $this->generateUuidV4();
        } while ($this->withDeleted()->where($key, $id)->countAllResults() > 0);

        return $id;
    }

    /**
     * Generate UUID versi 4 (random) tanpa dependency eksternal.
     *
     * @return string
     */
    public function generateUuidV4(): string
    {
        $bytes = random_bytes(16);

        // Set versi (4) dan varian (RFC 4122) sesuai spesifikasi UUIDv4
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Bersihkan cache terkait model ini saja (bukan seluruh cache aplikasi).
     *
     * Hanya bekerja jika $cachePrefix diisi di model turunan; jika null, tidak melakukan apa-apa.
     * Handler cache yang tidak mendukung deleteMatching() (mis. Memcached) dilewati tanpa error;
     * override method ini jika butuh strategi cache yang berbeda.
     */
    protected function cleanRelatedCache(): void
    {
        if (!$this->cleanCacheOnSave || $this->cachePrefix === null) {
            return;
        }

        try {
            cache()->deleteMatching($this->cachePrefix . '*');
        } catch (\BadMethodCallException) {
            // handler tidak mendukung deleteMatching()
        }
    }

    // ------------------------------------------------------------------
    //  FINDERS
    // ------------------------------------------------------------------

    /**
     * Find record by field and optionally return specific field.
     *
     * @param string      $whereBy     Field(s) to match. Gunakan:
     *                                 - `|` untuk OR
     *                                 - `&` untuk AND
     * @param string|null $value       Nilai yang dicari
     * @param string      $returnField Field yang ingin dikembalikan, default '*'
     *
     * @return array|string|null
     */
    public function findBy(string $whereBy, ?string $value = null, string $returnField = '*'): array|string|null
    {
        // Gunakan helper whereBy untuk membangun kondisi pencarian
        $this->whereBy($whereBy, $value);

        // Ambil hasil pertama
        $result = $this->first();

        if (!$result) {
            return null; // Tidak ditemukan
        }

        // Jika ingin seluruh hasil
        if ($returnField === '*') {
            return $result;
        }

        // Jika hanya ingin 1 kolom tertentu
        return $result[$returnField] ?? null;
    }

    /**
     * Find all records by field and optionally return only specific field.
     *
     * @param string      $whereBy     Field(s) untuk kondisi pencarian.
     * @param string|null $value       Nilai yang dicari.
     * @param string      $returnField Field yang dikembalikan. Gunakan '*' untuk semua kolom.
     *
     * @return array|null
     */
    public function findAllBy(string $whereBy, ?string $value = null, string $returnField = '*'): ?array
    {
        $this->whereBy($whereBy, $value);

        $results = $this->findAll();

        if (!$results) {
            return null;
        }

        if ($returnField === '*') {
            return $results;
        }

        // Ambil hanya kolom tertentu dari semua hasil
        return array_column($results, $returnField);
    }

    /**
     * Hitung jumlah record berdasarkan kondisi mini-DSL whereBy().
     *
     * @param string      $whereBy Field(s) untuk kondisi pencarian ('|' OR, '&' AND)
     * @param string|null $value   Nilai yang dicari
     *
     * @return int
     */
    public function countBy(string $whereBy, ?string $value = null): int
    {
        $this->whereBy($whereBy, $value);

        return $this->countAllResults();
    }

    /**
     * Hapus record berdasarkan kondisi mini-DSL whereBy(), lalu bersihkan cache terkait.
     *
     * @param string      $whereBy Field(s) untuk kondisi pencarian ('|' OR, '&' AND)
     * @param string|null $value   Nilai yang dicari
     *
     * @return bool True jika delete berhasil dijalankan (termasuk jika 0 baris terhapus)
     */
    public function deleteBy(string $whereBy, ?string $value = null): bool
    {
        $this->whereBy($whereBy, $value);

        $result = (bool) $this->delete();

        if ($result) {
            $this->cleanRelatedCache();
        }

        return $result;
    }

    // ------------------------------------------------------------------
    //  RELATIONS
    // ------------------------------------------------------------------

    /**
     * Relasi default yang bisa di-override di model turunan.
     * Format:
     *  [
     *      ['table alias', 'ON condition', 'joinType'],
     *      ...
     *  ]
     *
     * joinType = left (default)
     */
    protected array $relations = [];

    /**
     * Menyimpan alias-alias tabel yang sudah di-JOIN
     * agar tidak terjadi JOIN duplikat.
     */
    protected array $joinedAliases = [];

    /**
     * Reset state setelah query dijalankan
     */
    protected function resetJoinState(): void
    {
        $this->joinedAliases = [];
    }

    /**
     * Auto-extract alias dari string table:
     * "table alias"
     * "table AS alias"
     * "table as alias"
     */
    protected function extractAlias(string $table): string
    {
        $table = trim($table);

        // Format "table AS alias"
        if (stripos($table, ' as ') !== false) {
            [, $alias] = preg_split('/\s+as\s+/i', $table);
            return strtolower(trim($alias));
        }

        // Format "table alias"
        $parts = preg_split('/\s+/', $table);
        if (count($parts) === 2) {
            return strtolower(trim($parts[1]));
        }

        // Tidak ada alias → gunakan nama tabel
        return strtolower($table);
    }

    /**
     * JOIN aman tanpa duplikasi
     */
    protected function safeJoin(string $table, string $on = '', string $type = 'left'): void
    {
        $alias = $this->extractAlias($table);

        // Jika sudah pernah join → skip
        if (isset($this->joinedAliases[$alias])) {
            return;
        }

        $this->joinedAliases[$alias] = true;

        $this->join($table, $on, $type);
    }

    /**
     * Normalisasi relasi baru
     */
    protected function normalizeRelations(array|string $relations): array
    {
        // Jika string → buat array relasi lengkap
        if (is_string($relations)) {
            return [
                [$relations, '', 'left']
            ];
        }

        $normalized = [];

        foreach ($relations as $rel) {
            if (is_string($rel)) {
                $normalized[] = [$rel, '', 'left'];
            } else {
                $normalized[] = array_pad($rel, 3, 'left');
            }
        }

        return $normalized;
    }

    /**
     * Aktifkan relasi default dari model
     */
    public function withRelations(): static
    {
        foreach ($this->relations as $rel) {
            [$table, $on, $type] = array_pad($rel, 3, 'left');
            $this->safeJoin($table, $on, $type);
        }

        return $this;
    }

    /**
     * Tambahkan relasi baru TANPA relasi default
     */
    public function relations(array|string $relations): static
    {
        $relations = $this->normalizeRelations($relations);

        foreach ($relations as $rel) {
            [$table, $on, $type] = $rel;
            $this->safeJoin($table, $on, $type);
        }

        return $this;
    }

    /**
     * Gabungkan relasi default + relasi tambahan
     */
    public function withAllRelations(array|string $relations): static
    {
        // Default relations
        $this->withRelations();

        // Additional relations
        $this->relations($relations);

        return $this;
    }

    /**
     * Override findAll() agar reset state setelah query
     */
    public function findAll(?int $limit = null, int $offset = 0)
    {
        $result = parent::findAll($limit, $offset);
        $this->resetJoinState();
        return $result;
    }

    /**
     * Override first() agar reset state setelah query
     */
    public function first()
    {
        $result = parent::first();
        $this->resetJoinState();
        return $result;
    }

    /**
     * Override countAllResults() agar reset state setelah query.
     *
     * Tanpa override ini, ->withRelations()->countAllResults() memang menghasilkan
     * JOIN yang benar di query COUNT-nya sendiri, tapi $joinedAliases tidak ikut
     * direset padahal query builder CI4 di-reset otomatis (saat $reset = true,
     * default-nya). Akibatnya query berikutnya yang panggil withRelations()/relations()
     * lagi (mis. countAllResults() untuk total lalu findAll() untuk pagination) akan
     * salah kira alias sudah pernah di-JOIN dan skip join()-nya — relasinya hilang.
     */
    #[Override]
    public function countAllResults(bool $reset = true, bool $test = false)
    {
        if ($reset) {
            $this->resetJoinState();
        }
        return parent::countAllResults($reset, $test);
    }

    /**
     * uniqueKey dari record yang BARU di-insert oleh save()/upsert()/saveData()/insertData() terakhir.
     * Mengembalikan null jika penulisan terakhir adalah update (bukan insert) atau model tanpa $uniqueKey.
     *
     * CI4 hanya mengisi insertID saat insert dan tidak menggantinya saat update, jadi mencari
     * lewat getInsertID() saja bisa mengembalikan UUID record lain. Karena itu hasil penulisan
     * terakhir dilacak sendiri; pencarian lewat insertID hanya dipakai kalau ada insert lain
     * (mis. insert() langsung) sejak penulisan yang dilacak.
     */
    public function getInsertedUniqueId(): ?string
    {
        if ($this->uniqueKey === null) {
            return null;
        }

        if ($this->trackedInsertId !== null && (string) $this->insertID === $this->trackedInsertId) {
            return $this->trackedUniqueKey;
        }

        return $this->findBy($this->primaryKey, (string) $this->getInsertID(), $this->uniqueKey);
    }

    /**
     * Search satu atau beberapa kolom, dengan keyword yang dipecah per kata (AND),
     * lalu digabung antar-kolom pakai OR.
     *
     * Contoh:
     *   $this->search(['sekolah', 'npsn', 'alamat'], 'smp 1 wonosari');
     *
     * Hasil SQL kira-kira:
     *   WHERE (
     *     (sekolah LIKE %smp% AND sekolah LIKE %1% AND sekolah LIKE %wonosari%)
     *     OR (npsn LIKE %smp% AND npsn LIKE %1% AND npsn LIKE %wonosari%)
     *     OR (alamat LIKE %smp% AND alamat LIKE %1% AND alamat LIKE %wonosari%)
     *   )
     */
    public function search(array|string $fields, string $keyword): static
    {
        $fields   = is_array($fields) ? $fields : [$fields];
        $keywords = preg_split('/\s+/', trim($keyword), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($keywords)) {
            return $this;
        }

        $this->groupStart();

        foreach ($fields as $i => $field) {
            $method = $i === 0 ? 'groupStart' : 'orGroupStart';
            $this->{$method}();

            foreach ($keywords as $word) {
                $this->like($field, $word);
            }

            $this->groupEnd();
        }

        $this->groupEnd();

        return $this;
    }
}
