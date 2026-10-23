<?php

/**
 * Masking Helper
 *
 * Letakkan di: app/Helpers/masking_helper.php
 * Load      : helper('md_masking');
 *             atau di BaseController: protected $helpers = ['md_masking'];
 */

if (! function_exists('md_mask_string')) {
    /**
     * Masking string generik (multibyte-safe).
     *
     * @param string|null $value        Teks asli
     * @param int         $visibleStart Jumlah karakter terlihat di awal
     * @param int         $visibleEnd   Jumlah karakter terlihat di akhir
     * @param string      $maskChar     Karakter penyamar
     *
     * Jika teks terlalu pendek (<= visibleStart + visibleEnd), seluruhnya
     * disamarkan supaya tidak bocor.
     */
    function md_mask_string(?string $value, int $visibleStart = 3, int $visibleEnd = 0, string $maskChar = '*'): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $visibleStart = max(0, $visibleStart);
        $visibleEnd   = max(0, $visibleEnd);
        $length       = mb_strlen($value);

        if ($length <= $visibleStart + $visibleEnd) {
            return str_repeat($maskChar, $length);
        }

        $start  = mb_substr($value, 0, $visibleStart);
        $end    = $visibleEnd > 0 ? mb_substr($value, -$visibleEnd) : '';
        $middle = str_repeat($maskChar, $length - $visibleStart - $visibleEnd);

        return $start . $middle . $end;
    }
}

if (! function_exists('md_mask_nik')) {
    /**
     * NIK / No. KK: 3 digit awal terlihat.
     * 3404010101010001 => 340*************
     */
    function md_mask_nik(?string $nik, int $visibleStart = 3, int $visibleEnd = 0): string
    {
        return md_mask_string($nik, $visibleStart, $visibleEnd);
    }
}

if (! function_exists('md_mask_name')) {
    /**
     * Nama: huruf pertama tiap kata terlihat.
     * Muhammad Said => M******** S***
     */
    function md_mask_name(?string $name, int $visiblePerWord = 1): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }

        $words = preg_split('/\s+/u', $name);

        return implode(' ', array_map(
            static fn(string $word): string => md_mask_string($word, $visiblePerWord, 0),
            $words
        ));
    }
}

if (! function_exists('md_mask_email')) {
    /**
     * Email: bagian lokal disamarkan, domain tetap.
     * said.dev@gmail.com => sa*****@gmail.com
     */
    function md_mask_email(?string $email, int $visibleStart = 2): string
    {
        $email = trim((string) $email);
        if ($email === '') {
            return '';
        }

        if (strpos($email, '@') === false) {
            return md_mask_string($email, $visibleStart, 0);
        }

        [$local, $domain] = explode('@', $email, 2);

        return md_mask_string($local, $visibleStart, 0) . '@' . $domain;
    }
}

if (! function_exists('md_mask_phone')) {
    /**
     * No. HP: 4 digit awal & 3 digit akhir terlihat.
     * 081234567890 => 0812*****890
     */
    function md_mask_phone(?string $phone, int $visibleStart = 4, int $visibleEnd = 3): string
    {
        return md_mask_string($phone, $visibleStart, $visibleEnd);
    }
}

if (! function_exists('md_mask_fields')) {
    /**
     * Masking field tertentu pada array data (mis. hasil query / response API).
     *
     * $rules: ['nama_field' => 'nik'|'name'|'email'|'phone'|callable|[start, end]]
     *
     * Contoh:
     *   md_mask_fields($rows, [
     *       'nik'          => 'nik',
     *       'nama'         => 'name',
     *       'nomor_hp'     => 'phone',
     *       'nomor_kk'     => [3, 2],
     *       'nama_ibu'     => fn ($v) => md_mask_name($v),
     *   ]);
     *
     * Bisa menerima satu record (assoc array) atau daftar record.
     * Objek (mis. entity/stdClass) dikonversi ke array lebih dulu.
     */
    function md_mask_fields(array $data, array $rules): array
    {
        $isList = isset($data[0]) && (is_array($data[0]) || is_object($data[0]));

        if ($isList) {
            return array_map(
                static fn($row): array => md_mask_fields((array) $row, $rules),
                $data
            );
        }

        foreach ($rules as $field => $rule) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            $value = (string) $data[$field];

            if (is_callable($rule)) {
                $data[$field] = $rule($value);
            } elseif (is_array($rule)) {
                $data[$field] = md_mask_string($value, (int) ($rule[0] ?? 3), (int) ($rule[1] ?? 0));
            } else {
                $data[$field] = match ($rule) {
                    'nik'   => md_mask_nik($value),
                    'name'  => md_mask_name($value),
                    'email' => md_mask_email($value),
                    'phone' => md_mask_phone($value),
                    default => md_mask_string($value),
                };
            }
        }

        return $data;
    }
}
