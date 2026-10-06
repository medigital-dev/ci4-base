<?php

if (! function_exists('_build_asset_url')) {
    /**
     * Helper internal: bangun URL final untuk src/href.
     * Dipakai bersama oleh script_tag() dan link_tag() agar logikanya
     * tidak diduplikasi di dua tempat berbeda (sumber bug #5).
     */
    function _build_asset_url(string $value, bool $indexPage): string
    {
        if ($value === '') {
            return '';
        }

        // URL absolut (http://, https://, //cdn...) dibiarkan apa adanya
        if (preg_match('#^([a-z]+:)?//#i', $value) === 1) {
            return $value;
        }

        return $indexPage ? site_url($value) : slash_item('baseURL') . $value;
    }
}

if (! function_exists('_normalize_asset_url')) {
    /**
     * Normalisasi URL untuk keperluan pengecekan duplikat saja.
     * (Tidak dipakai untuk output, jadi aman di-lowercase.)
     */
    function _normalize_asset_url(string $finalUrl): string
    {
        if ($finalUrl === '') {
            return '';
        }

        return strtolower(preg_replace('#^\.\/#', '', rtrim($finalUrl, '/')));
    }
}

if (! function_exists('script_tag')) {
    /**
     * Script
     *
     * Generates one or more script tags, prevents duplicates, and normalizes URLs.
     *
     * @param string|array $src       Single script source, or array of sources/attributes
     * @param bool         $indexPage Should `Config\App::$indexPage` be added to the JS path
     */
    function script_tag($src = '', bool $indexPage = false): string
    {
        static $loadedScripts = []; // menyimpan daftar src yang sudah pernah dipanggil

        // Jika array berisi banyak script (list numerik)
        if (is_array($src) && array_is_list($src)) {
            $output = '';
            foreach ($src as $s) {
                $output .= script_tag($s, $indexPage);
            }
            return $output;
        }

        // Script tunggal
        $cspNonce = csp_script_nonce();
        $cspNonce = $cspNonce !== '' ? ' ' . $cspNonce : $cspNonce;
        $script   = '<script' . $cspNonce . ' ';

        // Jika bukan array atribut, ubah ke array ['src' => 'path.js']
        if (! is_array($src)) {
            $src = ['src' => $src];
        }

        // Ambil nilai src
        $srcValue = $src['src'] ?? '';

        // Tentukan URL final (dipakai untuk output DAN untuk cek duplikat)
        $finalUrl      = _build_asset_url($srcValue, $indexPage);
        $normalizedUrl = _normalize_asset_url($finalUrl);

        // Cegah duplikasi
        if ($normalizedUrl !== '' && in_array($normalizedUrl, $loadedScripts, true)) {
            return ''; // sudah pernah dimuat
        }

        // Tandai sudah dimuat
        if ($normalizedUrl !== '') {
            $loadedScripts[] = $normalizedUrl;
        }

        // Cetak src lebih dulu (jika ada), dengan escaping
        if ($finalUrl !== '') {
            $script .= 'src="' . esc($finalUrl, 'attr') . '" ';
        }

        // Bangun atribut lainnya
        foreach ($src as $k => $v) {
            // 'src' sudah ditangani di atas, 'indexPage' adalah parameter
            // kontrol, bukan atribut HTML -> jangan sampai ikut tercetak.
            if ($k === 'src' || $k === 'indexPage') {
                continue;
            }

            if ($v === false) {
                // atribut boolean yang di-nonaktifkan -> jangan dicetak
                continue;
            }

            if ($v === null || $v === true) {
                // atribut boolean HTML: defer, async, dst.
                $script .= $k . ' ';
                continue;
            }

            $script .= $k . '="' . esc((string) $v, 'attr') . '" ';
        }

        return rtrim($script) . '></script>' . PHP_EOL;
    }
}

if (! function_exists('link_tag')) {
    /**
     * Link
     *
     * Generates one or more <link> tags, prevents duplicates, and normalizes URLs.
     *
     * @param string|array $href Stylesheet href or array of multiple hrefs/attributes
     */
    function link_tag(
        $href = '',
        string $rel = 'stylesheet',
        string $type = 'text/css',
        string $title = '',
        string $media = '',
        bool $indexPage = false,
        string $hreflang = ''
    ): string {
        static $loadedLinks = []; // untuk mencegah duplikat

        // Jika array list (beberapa file)
        if (is_array($href) && array_is_list($href)) {
            $output = '';
            foreach ($href as $h) {
                $output .= link_tag($h, $rel, $type, $title, $media, $indexPage, $hreflang);
            }
            return $output;
        }

        // Ekstrak atribut jika array tunggal
        if (is_array($href)) {
            $rel       = $href['rel'] ?? $rel;
            $type      = $href['type'] ?? $type;
            $title     = $href['title'] ?? $title;
            $media     = $href['media'] ?? $media;
            $hreflang  = $href['hreflang'] ?? '';
            $indexPage = $href['indexPage'] ?? $indexPage;
            $href      = $href['href'] ?? '';
        }

        // Tentukan URL final (dipakai untuk output DAN untuk cek duplikat)
        $finalUrl      = _build_asset_url($href, $indexPage);
        $normalizedUrl = _normalize_asset_url($finalUrl);

        // Cegah duplikat
        if ($normalizedUrl !== '' && in_array($normalizedUrl, $loadedLinks, true)) {
            return ''; // sudah pernah dimuat
        }

        // Tandai sudah dimuat
        if ($normalizedUrl !== '') {
            $loadedLinks[] = $normalizedUrl;
        }

        // Buat atribut <link>
        $attributes = [
            'href' => $finalUrl,
            'rel'  => $rel,
        ];

        if ($hreflang !== '') {
            $attributes['hreflang'] = $hreflang;
        }

        if ($type !== '' && $rel !== 'canonical' && $hreflang === '' && ! ($rel === 'alternate' && $media !== '')) {
            $attributes['type'] = $type;
        }

        if ($media !== '') {
            $attributes['media'] = $media;
        }

        if ($title !== '') {
            $attributes['title'] = $title;
        }

        return '<link' . stringify_attributes($attributes) . _solidus() . '>' . PHP_EOL;
    }
}

if (! function_exists('plugins')) {
    /**
     * Muat CSS & JS untuk satu atau beberapa plugin sekaligus,
     * berdasarkan peta yang didefinisikan di Config\Plugins.
     *
     * Secara default, output CSS otomatis "disuntikkan" ke section
     * `pluginsCss` dan output JS ke section `pluginsJs` — TIDAK peduli
     * di mana plugins() ini dipanggil secara fisik di dalam view
     * (boleh di tengah section 'content' sekalipun). Layout tetap
     * merender lewat renderSection('pluginsCss') / renderSection('pluginsJs')
     * seperti biasa.
     *
     * Contoh pemakaian paling umum (cukup satu baris, taruh di mana saja):
     *   <?= plugins('datatable') ?>
     *   <?= plugins(['datatable', 'select2']) ?>
     *
     * Kalau nama section Anda berbeda dari default:
     *   <?= plugins('datatable', false, true, 'headCss', 'footJs') ?>
     *
     * Kalau TIDAK ingin auto-inject ke section (mis. dipakai di luar
     * konteks layout/section, atau di partial view untuk AJAX):
     *   <?= plugins('datatable', false, false) ?>   // hasilnya dikembalikan sebagai string biasa
     *
     * @param string|array $namaPlugins Nama plugin tunggal, atau array nama plugin.
     *  - 'bootstrap5'
     *  - 'bootstrap5bundle'
     *  - 'bootstrapIcon'
     *  - 'datatable'
     *  - 'fontawesome7'
     *  - 'fontawesome7brands'
     *  - 'fontawesome7regular'
     *  - 'fontawesome7solid'
     *  - 'jquery'
     *  - 'popper'
     *  - 'select2'
     *  - 'apexcharts'
     * @param bool         $indexPage   Diteruskan ke script_tag()/link_tag().
     * @param bool         $autoSection Jika true (default), CSS/JS otomatis disuntik
     *                                  ke section di bawah ini dan fungsi return ''.
     *                                  Jika false, fungsi return string HTML gabungan
     *                                  (css lalu js) untuk ditaruh manual.
     * @param string       $cssSection  Nama section tujuan untuk CSS.
     * @param string       $jsSection   Nama section tujuan untuk JS.
     */
    function plugins(
        $namaPlugins,
        bool $indexPage = false,
        bool $autoSection = true,
        string $cssSection = 'pluginsCss',
        string $jsSection = 'pluginsJs'
    ): string {
        static $config = null;
        $config ??= config('Plugins');

        // Normalisasi input jadi array nama plugin
        $namaPlugins = is_array($namaPlugins) ? $namaPlugins : [$namaPlugins];

        $cssOutput = '';
        $jsOutput  = '';

        foreach ($namaPlugins as $nama) {
            $nama = strtolower(trim((string) $nama));

            if ($nama === '') {
                continue;
            }

            if (! isset($config->plugins[$nama])) {
                log_message('warning', 'plugins(): plugin "{nama}" tidak terdaftar di Config\Plugins.', ['nama' => $nama]);
                continue;
            }

            $plugin = $config->plugins[$nama];

            if (! empty($plugin['css'])) {
                $cssOutput .= link_tag($plugin['css'], 'stylesheet', 'text/css', '', '', $indexPage);
            }

            if (! empty($plugin['js'])) {
                $jsOutput .= script_tag($plugin['js'], $indexPage);
            }
        }

        // Mode lama: kembalikan string, biar user taruh manual di mana pun.
        if (! $autoSection) {
            return $cssOutput . $jsOutput;
        }

        // Mode baru (default): suntik langsung ke section CI4 yang sedang aktif,
        // terlepas dari posisi fisik pemanggilan plugins() ini di dalam view.
        $renderer = _plugins_get_active_renderer();

        if ($renderer === null) {
            // Fallback aman: kalau untuk suatu alasan renderer tidak tersedia
            // (mis. dipanggil di luar konteks render view), kembalikan string
            // biasa saja daripada silently kehilangan CSS/JS.
            return $cssOutput . $jsOutput;
        }

        if ($cssOutput !== '') {
            $renderer->section($cssSection);
            echo $cssOutput;
            $renderer->endSection();
        }

        if ($jsOutput !== '') {
            $renderer->section($jsSection);
            echo $jsOutput;
            $renderer->endSection();
        }

        return '';
    }
}

if (! function_exists('_plugins_get_active_renderer')) {
    /**
     * Ambil instance renderer (View) yang sedang dipakai untuk merender
     * halaman saat ini. Ini objek yang SAMA dengan `$this` di dalam file view,
     * sehingga section() / endSection() yang dipanggil dari sini akan
     * tergabung dengan section yang sama seperti kalau ditulis manual.
     */
    function _plugins_get_active_renderer()
    {
        if (! function_exists('service')) {
            return null;
        }

        try {
            $renderer = service('renderer');
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_object($renderer) || ! method_exists($renderer, 'section') || ! method_exists($renderer, 'endSection')) {
            return null;
        }

        return $renderer;
    }
}
