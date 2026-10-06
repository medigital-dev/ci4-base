<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Plugins extends BaseConfig
{
    /**
     * Peta nama plugin -> lokasi file CSS & JS-nya.
     *
     * Path bersifat relatif (sama seperti argumen yang dipakai script_tag()/link_tag()),
     * jadi otomatis digabung dengan baseURL saat dicetak.
     *
     * Format:
     * 'nama-plugin' => [
     *     'css' => ['path/ke/file1.css', 'path/ke/file2.css'],
     *     'js'  => ['path/ke/file1.js', 'path/ke/file2.js'],
     * ]
     *
     * Urutan array css/js DIPERTAHANKAN saat dicetak — penting untuk plugin
     * yang punya urutan load (mis. datatables inti sebelum adapter bootstrap5).
     *
     * Sesuaikan path di bawah dengan struktur folder public/plugins Anda.
     */
    public array $plugins = [
        // Tambahkan plugin lain di sini sesuai kebutuhan...
    ];
}
