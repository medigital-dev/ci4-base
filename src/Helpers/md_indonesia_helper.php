<?php

if (!function_exists('md_toEyd')) {
    /**
     * Mengonversi nama menjadi format Title Case sesuai kaidah EYD.
     *
     * - Menghapus karakter selain huruf, spasi, tanda hubung (-), apostrof ('), dan titik (.)
     * - Merapikan spasi berlebih (termasuk leading/trailing)
     * - Kapital di awal tiap kata, setelah tanda hubung, dan setelah titik
     *   (mis. "siti nur-aini" -> "Siti Nur-Aini", "s.kom" -> "S.Kom")
     * - Huruf setelah apostrof tetap huruf kecil
     *   (mis. "su'udi" -> "Su'udi", bukan "Su'Udi")
     * - Kata yang berupa angka romawi valid (I, V, X, L, C, D, M) ditulis kapital semua
     *   (mis. "yusuf ii" -> "Yusuf II", bukan "Yusuf Ii")
     *
     * Catatan: deteksi angka romawi berbasis pola huruf, sehingga kata umum yang
     * kebetulan tersusun dari huruf romawi valid (mis. "mix", "lid", "dim")
     * juga akan dikapitalkan semua. Ini jarang terjadi pada nama, tapi perlu
     * disadari jika dipakai untuk data non-nama.
     *
     * @param string|null $nama Nama yang akan diformat
     * @return string|null Nama hasil format, atau null jika input null/kosong
     */
    function md_toEyd(?string $nama): ?string
    {
        if ($nama === null) {
            return null;
        }

        $nama = trim($nama);
        if ($nama === '') {
            return null;
        }

        // Hanya izinkan huruf, spasi, tanda hubung, apostrof, dan titik
        $nama = preg_replace("/[^a-zA-Z\s'\.\-]/", '', $nama);
        $nama = preg_replace('/\s+/', ' ', trim($nama));

        if ($nama === '' || $nama === null) {
            return null;
        }

        $nama = mb_strtolower($nama);
        $kataArray = explode(' ', $nama);

        // Pola angka romawi valid: I, II, III, IV, ..., MMMCMXCIX, dst.
        $romawiPattern = '/^M{0,4}(CM|CD|D?C{0,3})(XC|XL|L?X{0,3})(IX|IV|V?I{0,3})$/i';

        foreach ($kataArray as &$kata) {
            // Cek dulu apakah keseluruhan kata adalah angka romawi valid
            $tanpaTitik = str_replace('.', '', $kata);
            if ($tanpaTitik !== '' && preg_match($romawiPattern, $tanpaTitik)) {
                $kata = strtoupper($kata);
                continue;
            }

            // Kapital di awal kata, setelah tanda hubung, dan setelah titik
            $kata = preg_replace_callback(
                "/(^|-|\.)([a-z])/",
                fn($m) => $m[1] . strtoupper($m[2]),
                $kata
            );

            // Huruf setelah apostrof selalu kecil
            $kata = preg_replace_callback(
                "/(')([a-zA-Z])/",
                fn($m) => $m[1] . strtolower($m[2]),
                $kata
            );
        }
        unset($kata);

        return implode(' ', $kataArray);
    }
}

if (!function_exists('md_toTerbilang')) {
    /**
     * Mengonversi angka menjadi kalimat bilangan dalam bahasa Indonesia (terbilang).
     * Mendukung bilangan negatif, nol, hingga skala kuadriliun (< 10^18).
     *
     * @param int|float $angka    Bilangan bulat (boleh negatif). Nilai float akan
     *                            dibulatkan ke bilangan bulat terdekat.
     * @param bool      $isRupiah Jika true: hasil Title Case + akhiran "Rupiah".
     *                            Jika false: hasil huruf kecil tanpa akhiran.
     * @return string
     */
    function md_toTerbilang(int|float $angka, bool $isRupiah = true): string
    {
        if (is_float($angka) && (is_nan($angka) || is_infinite($angka))) {
            throw new \InvalidArgumentException('Nilai angka tidak valid (NaN/Infinite).');
        }

        $angka   = (int) round($angka);
        $negatif = $angka < 0;
        $mutlak  = abs($angka);

        $hasil = $mutlak === 0 ? 'nol' : _terbilang_konversi($mutlak);

        // Rapikan spasi ganda yang mungkin muncul akibat sisa bagi = 0
        $hasil = preg_replace('/\s+/', ' ', trim($hasil));

        if ($negatif) {
            $hasil = 'minus ' . $hasil;
        }

        return $isRupiah ? (ucwords($hasil) . ' Rupiah') : $hasil;
    }
}

if (!function_exists('_terbilang_konversi')) {
    /**
     * Helper rekursif internal untuk terbilang(). Mengonversi bilangan bulat
     * POSITIF (> 0) menjadi rangkaian kata angka bahasa Indonesia.
     * Jangan panggil langsung dari luar — gunakan terbilang().
     *
     * @param int $angka Bilangan bulat positif
     * @return string
     */
    function _terbilang_konversi(int $angka): string
    {
        static $huruf = [
            '',
            'satu',
            'dua',
            'tiga',
            'empat',
            'lima',
            'enam',
            'tujuh',
            'delapan',
            'sembilan',
            'sepuluh',
            'sebelas',
        ];

        return match (true) {
            $angka < 12 => ' ' . $huruf[$angka],
            $angka < 20 => _terbilang_konversi($angka - 10) . ' belas',
            $angka < 100 => _terbilang_konversi(intdiv($angka, 10)) . ' puluh' . _terbilang_konversi($angka % 10),
            $angka < 200 => ' seratus' . _terbilang_konversi($angka - 100),
            $angka < 1000 => _terbilang_konversi(intdiv($angka, 100)) . ' ratus' . _terbilang_konversi($angka % 100),
            $angka < 2000 => ' seribu' . _terbilang_konversi($angka - 1000),
            $angka < 1_000_000 => _terbilang_konversi(intdiv($angka, 1000)) . ' ribu' . _terbilang_konversi($angka % 1000),
            $angka < 1_000_000_000 => _terbilang_konversi(intdiv($angka, 1_000_000)) . ' juta' . _terbilang_konversi($angka % 1_000_000),
            $angka < 1_000_000_000_000 => _terbilang_konversi(intdiv($angka, 1_000_000_000)) . ' miliar' . _terbilang_konversi($angka % 1_000_000_000),
            $angka < 1_000_000_000_000_000 => _terbilang_konversi(intdiv($angka, 1_000_000_000_000)) . ' triliun' . _terbilang_konversi($angka % 1_000_000_000_000),
            $angka < 1_000_000_000_000_000_000 => _terbilang_konversi(intdiv($angka, 1_000_000_000_000_000)) . ' kuadriliun' . _terbilang_konversi($angka % 1_000_000_000_000_000),
            default => ' [angka di luar batas dukungan]',
        };
    }
}

if (!function_exists('md_tanggal')) {
    /**
     * Mengonversi tanggal/waktu ke format berbahasa Indonesia (nama hari & bulan).
     *
     * @param string|int|\DateTimeInterface|null $tanggalWaktu Tanggal input: string yang bisa
     *        diparse strtotime() (mis. "2025-01-31", "next monday"), Unix timestamp (int),
     *        objek DateTime/DateTimeImmutable/Time, atau null/'now' untuk waktu saat ini.
     * @param string $format Format keluaran ala date() PHP (default: 'd-m-Y')
     * @param string|\DateTimeZone|null $timezone Timezone opsional. Null = pakai timezone default PHP.
     * @return string Tanggal/waktu terformat dengan nama hari & bulan berbahasa Indonesia
     *
     * @throws \InvalidArgumentException Jika $tanggalWaktu berupa string yang tidak bisa diparse
     */
    function md_tanggal(
        string|int|\DateTimeInterface|null $tanggalWaktu = 'now',
        string $format = 'd-m-Y',
        string|\DateTimeZone|null $timezone = null
    ): ?string {
        if (is_null($tanggalWaktu)) return null;
        static $namaHariIndonesia = [
            'Sunday' => 'Minggu',
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
            'Sun' => 'Min',
            'Mon' => 'Sen',
            'Tue' => 'Sel',
            'Wed' => 'Rab',
            'Thu' => 'Kam',
            'Fri' => 'Jum',
            'Sat' => 'Sab',
        ];

        static $namaBulanIndonesia = [
            'January' => 'Januari',
            'February' => 'Februari',
            'March' => 'Maret',
            'April' => 'April',
            'May' => 'Mei',
            'June' => 'Juni',
            'July' => 'Juli',
            'August' => 'Agustus',
            'September' => 'September',
            'October' => 'Oktober',
            'November' => 'November',
            'December' => 'Desember',
            'Jan' => 'Jan',
            'Feb' => 'Feb',
            'Mar' => 'Mar',
            'Apr' => 'Apr',
            'Jun' => 'Jun',
            'Jul' => 'Jul',
            'Aug' => 'Agu',
            'Sep' => 'Sep',
            'Oct' => 'Okt',
            'Nov' => 'Nov',
            'Dec' => 'Des',
        ];

        $tz = match (true) {
            $timezone instanceof \DateTimeZone => $timezone,
            is_string($timezone) => new \DateTimeZone($timezone),
            default => null, // null -> DateTimeImmutable pakai timezone default PHP
        };

        // Normalisasi berbagai tipe input jadi DateTimeImmutable
        if ($tanggalWaktu instanceof \DateTimeInterface) {
            $date = \DateTimeImmutable::createFromInterface($tanggalWaktu);
            if ($tz !== null) {
                $date = $date->setTimezone($tz);
            }
        } elseif (is_int($tanggalWaktu)) {
            $date = (new \DateTimeImmutable('now', $tz))->setTimestamp($tanggalWaktu);
        } else {
            $tanggalWaktu = ($tanggalWaktu === null || $tanggalWaktu === '') ? 'now' : $tanggalWaktu;
            try {
                $date = new \DateTimeImmutable($tanggalWaktu, $tz);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException("Tanggal/waktu tidak valid: \"{$tanggalWaktu}\"", 0, $e);
            }
        }

        $hasil = $date->format($format);

        return strtr($hasil, $namaHariIndonesia + $namaBulanIndonesia);
    }
}

if (!function_exists('md_angka')) {
    /**
     * Memformat angka dengan pemisah ribuan & desimal gaya Indonesia.
     * Fungsi generik murni untuk format angka — tidak menambahkan simbol mata uang apa pun.
     *
     * @param float|int|string|null $angka           Angka yang akan diformat. String numerik juga diterima.
     * @param int                   $decimal         Jumlah angka di belakang koma, minimal 0 (default: 0)
     * @param string                $pemisahDesimal  Pemisah desimal (default: ',')
     * @param string                $pemisahRibuan   Pemisah ribuan (default: '.')
     * @return string Angka yang telah diformat
     *
     * @throws \InvalidArgumentException Jika $angka bukan angka valid, atau $decimal negatif
     */
    function md_angka(
        float|int|string|null $angka,
        int $decimal = 0,
        string $pemisahDesimal = ',',
        string $pemisahRibuan = '.'
    ): string {
        if ($decimal < 0) {
            throw new \InvalidArgumentException('Parameter $decimal tidak boleh negatif.');
        }

        if ($angka === null || $angka === '') {
            $angka = 0;
        }

        if (!is_numeric($angka)) {
            throw new \InvalidArgumentException("Nilai \$angka tidak valid: \"{$angka}\"");
        }

        return number_format((float) $angka, $decimal, $pemisahDesimal, $pemisahRibuan);
    }
}

if (!function_exists('md_rupiah')) {
    /**
     * Memformat angka menjadi format mata uang Rupiah.
     * Wrapper di atas angka() — menambahkan prefix mata uang & penempatan tanda minus.
     *
     * @param float|int|string|null $angka   Angka yang akan diformat
     * @param string                $prefix  Prefix mata uang (default: 'Rp')
     * @param int                   $decimal Jumlah angka di belakang koma (default: 0)
     * @return string Angka yang telah diformat ke mata uang Rupiah
     */
    function md_rupiah(
        float|int|string|null $angka,
        string $prefix = 'Rp',
        int $decimal = 0
    ): string {
        $nilai = (float) ($angka === null || $angka === '' ? 0 : $angka);
        $negatif = $nilai < 0;

        $formatted = md_angka(abs($nilai), $decimal);

        $prefix = trim($prefix);
        $hasil = $prefix !== '' ? $prefix . ' ' . $formatted : $formatted;

        return $negatif ? '-' . $hasil : $hasil;
    }
}
