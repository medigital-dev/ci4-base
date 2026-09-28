<?php

namespace MedigitalDev\Ci4Base\Models;

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
     * Aktif/nonaktifkan pembersihan cache otomatis saat saveData() / insertData() dipanggil
     */
    protected bool $cleanCacheOnSave = true;

    /** Prefix cache key untuk model ini. Null = fallback ke cache()->clean() (wipe semua cache) */
    protected ?string $cachePrefix = null;

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
     * Notes: Pastikan field tidak ambigu!
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

    /**
     * Bangun kondisi WHERE dari mini-DSL ('&' = AND grup, '|' = OR dalam grup),
     * dengan nilai diambil per-field dari $data (bukan satu value untuk semua field
     * seperti whereBy()).
     *
     * Semua field divalidasi dulu (harus ada di $data) SEBELUM query builder disentuh,
     * supaya kalau validasi gagal, builder tidak ditinggal dalam state groupStart()
     * yang belum di-groupEnd().
     *
     * @param string $pattern Mini-DSL pattern, mis. "email&username" atau "email|username"
     * @param array  $data    Data sumber nilai
     * @param string $context Nama variabel untuk pesan error (mis. "whereKey")
     *
     * @throws \InvalidArgumentException Jika pattern kosong/invalid atau field tidak ada di $data
     */
    private function buildMiniDslWhere(string $pattern, array $data, string $context = 'whereKey'): void
    {
        $andGroups = explode('&', $pattern);
        $parsedGroups = [];

        // Pass 1: parse & validasi semua field dulu, jangan sentuh query builder.
        foreach ($andGroups as $andGroup) {
            $orFields = array_values(array_filter(array_map('trim', explode('|', $andGroup)), fn($f) => $f !== ''));

            if (empty($orFields)) {
                throw new \InvalidArgumentException("Format \${$context} tidak valid: \"{$pattern}\".");
            }

            foreach ($orFields as $orField) {
                if (!array_key_exists($orField, $data)) {
                    throw new \InvalidArgumentException("Field \"{$orField}\" pada \${$context} tidak ditemukan di \$data.");
                }
            }

            $parsedGroups[] = $orFields;
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

    /**
     * Simpan data ke database dengan mekanisme upsert otomatis (insert jika belum ada, update jika sudah ada),
     * serta auto-generate unique key jika model punya $uniqueKey.
     *
     * SINTAKS $whereKey (mini-DSL untuk menentukan kriteria "record yang sama"):
     *   - '&' = AND antar grup   -> "email&username" berarti WHERE email=... AND username=...
     *   - '|' = OR dalam 1 grup  -> "email|username" berarti WHERE email=... OR username=...
     *   - Bisa dikombinasikan    -> "email&status|type" berarti WHERE email=... AND (status=... OR type=...)
     *   - Semua field yang disebut WAJIB ada di $data (dicek via array_key_exists), atau exception dilempar.
     *
     * @param array       $data     Data yang akan disimpan
     * @param string|null $whereKey Kriteria pencarian record existing (lihat sintaks di atas). Null = selalu insert baru
     *                               kecuali $data sudah mengandung primary key.
     * @param string|null $return   Nama key dari $data yang ingin dikembalikan saat sukses.
     *                               Null = kembalikan seluruh $data (termasuk primary key & unique key hasil generate).
     * @return array|string|int Saat gagal: array ['success'=>false,'message'=>...,'data'=>...].
     *                           Saat sukses: $data[$return] jika $return diisi, atau seluruh $data jika tidak.
     *
     * @throws \InvalidArgumentException Jika $whereKey/$return mereferensikan key yang tidak ada di $data
     */
    public function saveData(array $data, ?string $whereKey = null, ?string $return = null): array|string|int
    {
        $whereKey = ($whereKey === null || trim($whereKey) === '') ? null : $whereKey;
        $existingFound = false;

        if ($whereKey !== null) {
            $this->buildMiniDslWhere($whereKey, $data, 'whereKey');

            // Paksa return type array untuk hindari bug object-vs-array pada model yang pakai Entity
            $existing = $this->asArray()->first();

            if ($existing) {
                $data[$this->primaryKey] = $existing[$this->primaryKey];
                if ($this->uniqueKey !== null && isset($existing[$this->uniqueKey])) {
                    $data[$this->uniqueKey] = $existing[$this->uniqueKey];
                }
                $existingFound = true;
            }
        }

        if ($this->uniqueKey !== null && !$existingFound) {
            if (!isset($data[$this->uniqueKey]) || $data[$this->uniqueKey] === '') {
                $data[$this->uniqueKey] = $this->generateUniqueKey($this->uniqueKey);
            } else {
                $existing = $this->asArray()->findBy($this->uniqueKey, $data[$this->uniqueKey]);
                if ($existing) {
                    $data[$this->primaryKey] = $existing[$this->primaryKey];
                }
            }
        }

        $isInsert = !isset($data[$this->primaryKey]);

        if (!$this->save($data)) {
            return [
                'success' => false,
                'message' => $this->errors(),
                'data'    => $data,
            ];
        }

        if ($isInsert) {
            $data[$this->primaryKey] = $this->getInsertID();
        }

        $this->cleanRelatedCache();

        if ($return === null) {
            return $data;
        }

        if (!array_key_exists($return, $data)) {
            throw new \InvalidArgumentException("Key \"{$return}\" pada \$return tidak ditemukan di hasil data.");
        }

        return $data[$return];
    }

    /**
     * Alias eksplisit untuk insert/upsert (backward-compatible wrapper di atas saveData()).
     *
     * Secara fungsional sama persis dengan saveData() — disediakan untuk kode lama yang
     * sudah memanggil insertData(). Untuk kode baru, lebih baik langsung pakai saveData()
     * karena lebih fleksibel (bisa custom $return).
     *
     * @param array       $data     Data yang akan disimpan
     * @param string|null $whereKey Kriteria pencarian record existing (lihat dokumentasi saveData())
     * @param bool        $returnId Jika true, kembalikan hanya ID (uniqueKey jika ada, else primaryKey).
     *                               Jika false, kembalikan true saat sukses.
     *
     * @return array|string|int|bool Array ['success'=>false,...] saat gagal; bool/ID saat sukses.
     */
    public function insertData(array $data, ?string $whereKey = null, bool $returnId = false): array|string|int|bool
    {
        $result = $this->saveData($data, $whereKey);

        if (is_array($result) && ($result['success'] ?? true) === false) {
            return $result;
        }

        if (!$returnId) {
            return true;
        }

        return $this->uniqueKey !== null ? $result[$this->uniqueKey] : $result[$this->primaryKey];
    }

    /**
     * Simpan banyak baris sekaligus dengan mekanisme upsert (insert jika belum ada,
     * update jika sudah ada), TANPA query per-baris seperti saveData().
     *
     * Cara kerja:
     *  1. Jika $matchField diisi: kumpulkan semua nilai $matchField dari $rows, cek yang
     *     sudah ada di DB dalam beberapa query whereIn() (di-chunk 1000 per query, batas
     *     aman SQL Server). Jika $matchField NULL: langkah ini di-skip sepenuhnya, semua
     *     baris dianggap insert baru (dipakai kalau kamu sudah tahu pasti semua baris
     *     belum ada di DB, mis. sync data baru — jauh lebih cepat karena tanpa query cek).
     *  2. Pisahkan $rows jadi kelompok insert vs update berdasarkan hasil cek di atas.
     *  3. Kalau model punya $uniqueKey, generate UUID langsung di PHP untuk baris baru
     *     (tanpa query cek collision per baris — peluang collision UUIDv4 praktis nol;
     *     amankan lewat UNIQUE constraint di kolom itu pada level database).
     *  4. Jalankan insertBatch() / updateBatch() (CI4 built-in, otomatis di-chunk sesuai
     *     $batchSize) — jauh lebih sedikit query dibanding saveData() dipanggil per baris.
     *
     * Catatan:
     *  - $matchField, kalau diisi, harus SATU nama kolom (bukan mini-DSL '&'/'|' seperti
     *    whereKey di saveData()), karena dipakai sebagai kunci array untuk pencarian
     *    existing record secara massal.
     *  - $matchField = null berarti "tidak perlu matchField" → mode insert-only, tidak ada
     *    pengecekan existing record sama sekali, semua baris pasti masuk sebagai insert baru.
     *  - Kalau $matchField diisi, setiap baris di $rows WAJIB punya key tersebut.
     *  - Untuk baris yang match (update), primaryKey & uniqueKey existing otomatis
     *    ditempelkan ke baris tsb supaya updateBatch() tahu baris mana yang diupdate.
     *
     * @param array       $rows       Daftar baris data yang akan di-upsert
     * @param string|null $matchField Nama kolom untuk mencocokkan record yang sama (mis. 'murid_id').
     *                                 Isi null jika tidak perlu upsert check (mode insert-only).
     * @param int         $batchSize  Ukuran chunk untuk insertBatch()/updateBatch()
     *
     * @return array{inserted:int, updated:int, skipped:int}
     *
     * @throws \InvalidArgumentException Jika $matchField diisi tapi ada baris tanpa field tsb
     */
    public function bulkSaveData(array $rows, ?string $matchField = null, int $batchSize = 500): array
    {
        if (empty($rows) && count($rows) === 0) {
            return ['inserted' => 0, 'updated' => 0, 'skipped' => 0];
        }

        $existingMap = [];
        $matchFields = [];

        // 1. Parse matchField pattern (support & dan | operators)
        if ($matchField !== null) {
            $andGroups = explode('&', $matchField);
            foreach ($andGroups as $andGroup) {
                $orFields = array_values(array_filter(array_map('trim', explode('|', $andGroup)), fn($f) => $f !== ''));
                if (empty($orFields)) {
                    throw new \InvalidArgumentException("Format \$matchField tidak valid: \"{$matchField}\".");
                }
                $matchFields[] = $orFields;
            }
        }

        // 2. Cek existing record secara massal — hanya jika $matchField diisi.
        if ($matchField !== null && !empty($matchFields)) {
            // Validasi semua row memiliki required fields
            foreach ($rows as $i => $row) {
                // Validasi row adalah array
                if (!is_array($row)) {
                    throw new \InvalidArgumentException("Baris ke-{$i} harus berupa array, diperoleh: " . gettype($row));
                }

                foreach ($matchFields as $orFields) {
                    $hasAnyField = false;
                    foreach ($orFields as $field) {
                        if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                            $hasAnyField = true;
                            break;
                        }
                    }
                    if (!$hasAnyField) {
                        throw new \InvalidArgumentException("Baris ke-{$i} tidak memiliki \$matchField \"{$matchField}\" yang valid.");
                    }
                }
            }

            // Collect semua kolom yang diperlukan untuk select
            $selectCols = [$this->primaryKey];
            foreach ($matchFields as $orFields) {
                $selectCols = array_merge($selectCols, $orFields);
            }
            if ($this->uniqueKey !== null) {
                $selectCols[] = $this->uniqueKey;
            }
            $selectCols = array_unique($selectCols);

            // Query existing records per batch
            foreach (array_chunk($rows, 100) as $chunk) {
                $query = $this->select($selectCols);
                $isFirst = true;

                foreach ($chunk as $row) {
                    if (!$isFirst) {
                        $query->orGroupStart();
                    } else {
                        $query->groupStart();
                    }

                    // Build conditions untuk composite key
                    // Setiap AND group terhubung dengan implicit AND
                    $firstAndGroup = true;
                    foreach ($matchFields as $orFields) {
                        if (!$firstAndGroup) {
                            // Untuk AND group yang bukan yang pertama, wrap dalam groupStart
                            $query->groupStart();
                        }

                        // Fields dalam same OR group terhubung dengan OR
                        $firstOrField = true;
                        foreach ($orFields as $field) {
                            if ($firstOrField) {
                                if ($firstAndGroup) {
                                    // Field pertama dari AND group pertama
                                    $query->where($field, $row[$field] ?? null);
                                } else {
                                    // Field pertama dari AND group non-pertama
                                    $query->where($field, $row[$field] ?? null);
                                }
                                $firstOrField = false;
                            } else {
                                // OR field
                                $query->orWhere($field, $row[$field] ?? null);
                            }
                        }

                        if (!$firstAndGroup) {
                            $query->groupEnd();
                        }
                        $firstAndGroup = false;
                    }

                    $query->groupEnd();
                    $isFirst = false;
                }

                $existing = $query->asArray()->findAll();

                // Build composite key dari setiap hasil query
                foreach ($existing as $existRow) {
                    $keyParts = [];
                    foreach ($matchFields as $orFields) {
                        $found = false;
                        foreach ($orFields as $field) {
                            if (!empty($existRow[$field])) {
                                $keyParts[] = $existRow[$field];
                                $found = true;
                                break;
                            }
                        }
                        if (!$found) {
                            $keyParts[] = '';
                        }
                    }
                    $compositeKey = implode('||', $keyParts);
                    $existingMap[$compositeKey] = $existRow;
                }
            }
        }

        // 3. Pisahkan insert vs update.
        $toInsert = [];
        $toUpdate = [];

        foreach ($rows as $row) {
            $key = null;
            if ($matchField !== null && !empty($matchFields)) {
                $keyParts = [];
                foreach ($matchFields as $orFields) {
                    $found = false;
                    foreach ($orFields as $field) {
                        if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                            $keyParts[] = $row[$field];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $keyParts[] = '';
                    }
                }
                $key = implode('||', $keyParts);
            }

            if ($key !== null && isset($existingMap[$key])) {
                $row[$this->primaryKey] = $existingMap[$key][$this->primaryKey];
                if ($this->uniqueKey !== null && isset($existingMap[$key][$this->uniqueKey])) {
                    $row[$this->uniqueKey] = $existingMap[$key][$this->uniqueKey];
                }
                $toUpdate[] = $row;
            } else {
                if ($this->uniqueKey !== null && empty($row[$this->uniqueKey])) {
                    $row[$this->uniqueKey] = $this->generateUuidV4();
                }
                $toInsert[] = $row;
            }
        }

        // 4. Bulk insert & update
        if (!empty($toInsert)) {
            $this->insertBatch($toInsert, null, $batchSize);
        }

        if (!empty($toUpdate)) {
            $this->updateBatch($toUpdate, $this->primaryKey, $batchSize);
        }

        $this->cleanRelatedCache();

        return [
            'inserted' => count($toInsert),
            'updated'  => count($toUpdate),
            'skipped'  => 0,
        ];
    }

    /**
     * Generate ID unik (UUIDv4) untuk kolom tertentu, memastikan tidak ada duplikat di tabel ini.
     *
     * UUIDv4 pakai random_bytes (CSPRNG), jadi kemungkinan collision-nya praktis nol
     * (122-bit random) — jauh lebih aman dan tidak predictable dibanding uniqid() yang
     * berbasis timestamp. Loop pengecekan tetap dipertahankan sebagai jaring pengaman murah;
     * untuk keamanan penuh di beban concurrent tinggi, tambahkan juga UNIQUE constraint di
     * level database pada kolom ini.
     *
     * @param string $key Nama kolom yang harus unik
     * @return string UUIDv4 dalam format standar (36 karakter, mis. "3fa85f64-5717-4562-b3fc-2c963f66afa6")
     */
    private function generateUniqueKey(string $key): string
    {
        do {
            $id = $this->generateUuidV4();
        } while ($this->where($key, $id)->countAllResults() > 0);

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
     * Override $cachePrefix di model turunan untuk aktifkan pembersihan cache yang presisi,
     * atau override method ini kalau butuh strategi cache yang berbeda.
     */
    protected function cleanRelatedCache(): void
    {
        if (!$this->cleanCacheOnSave) {
            return;
        }

        if ($this->cachePrefix !== null) {
            cache()->deleteMatching($this->cachePrefix . '*');
        } else {
            cache()->clean();
        }
    }

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

    public function getInsertedUniqueId(): ?string
    {
        if (!is_null($this->uniqueKey)) {
            return $this->findBy($this->primaryKey, (string) $this->getInsertID(), $this->uniqueKey);
        }
        return null;
    }

    /**
     * Search satu atau beberapa kolom, dengan keyword yang dipecah per kata (AND),
     * lalu digabung antar-kolom pakai OR.
     *
     * Contoh:
     *   $this->searchLike(['sekolah', 'npsn', 'alamat'], 'smp 1 wonosari');
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
