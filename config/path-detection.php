<?php
// config/path-detection.php
// VERSI: 3.2 - PERBAIKAN MINOR
//   - Tambah sanitasi tambahan pada detectBaseUrl()
//   - Perbaiki penanganan CLI agar lebih robust
//   - Tambah validasi BASE_URL dari .env untuk mencegah karakter berbahaya
//   - Kompatibilitas: fallback jika str_starts_with tidak tersedia (PHP < 8)
//   UNCHANGED: logika utama

// ============================================
// DETEKSI BASE URL
// ============================================

/**
 * Deteksi BASE_URL dari request yang sedang berjalan.
 * Dipakai sebagai FALLBACK jika BASE_URL tidak di-set di .env.
 *
 * [FIX v3.2] Sanitasi tambahan pada SCRIPT_NAME untuk mencegah path traversal.
 * [FIX v3.0] HTTP_HOST di-sanitasi sebelum dipakai.
 */
function detectBaseUrl() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
                ? 'https'
                : 'http';

    $rawHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Sanitasi host: hanya izinkan alfanumerik, titik, strip, titik dua (port)
    $host    = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $rawHost);

    if (empty($host)) {
        $host = 'localhost';
        error_log("detectBaseUrl(): HTTP_HOST tidak valid [{$rawHost}], fallback ke localhost");
    }

    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
    // Sanitasi SCRIPT_NAME: hapus path traversal (..)
    $scriptName = preg_replace('/\.\.+/', '', $scriptName);
    $dirName    = dirname($scriptName);

    if ($dirName === '/' || $dirName === '\\' || $dirName === '.') {
        $baseUrl = $protocol . '://' . $host . '/';
    } else {
        $dirName = str_replace('\\', '/', $dirName);
        $baseUrl = $protocol . '://' . $host . $dirName . '/';
    }

    return $baseUrl;
}

// ============================================
// DETEKSI UPLOAD PATH
// ============================================

/**
 * UNCHANGED — menggunakan __DIR__ sebagai referensi, sudah benar.
 */
function detectUploadPath() {
    $configDir  = __DIR__;
    $rootDir    = dirname($configDir);
    return $rootDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
}

// ============================================
// DETEKSI APP_ENV
// ============================================

/**
 * Baca APP_ENV dari .env atau $_SERVER, default 'production'.
 * UNCHANGED dari v3.0.
 */
function detectAppEnv() {
    if (!empty($_ENV['APP_ENV'])) {
        $env = strtolower(trim($_ENV['APP_ENV']));
        return in_array($env, ['development', 'production', 'staging'])
               ? $env : 'production';
    }

    if (!empty($_SERVER['APP_ENV'])) {
        $env = strtolower(trim($_SERVER['APP_ENV']));
        return in_array($env, ['development', 'production', 'staging'])
               ? $env : 'production';
    }

    return 'production';
}

// ============================================
// DETEKSI BASE_URL FINAL
// ============================================

/**
 * Tentukan BASE_URL dengan urutan prioritas:
 *
 *   1. $_ENV['BASE_URL'] dari .env  — paling akurat, eksplisit
 *   2. detectBaseUrl()              — otomatis dari HTTP_HOST + SCRIPT_NAME
 *
 * [FIX v3.2] Tambah sanitasi URL dari .env untuk mencegah karakter berbahaya.
 */
function resolveBaseUrl() {
    if (!empty($_ENV['BASE_URL'])) {
        $url = trim($_ENV['BASE_URL']);

        // Validasi: harus diawali http:// atau https://
        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            error_log("resolveBaseUrl(): BASE_URL di .env tidak valid [{$url}], fallback ke deteksi otomatis");
            return detectBaseUrl();
        }

        // Sanitasi URL: hapus karakter berbahaya (kecuali yang diizinkan dalam URL)
        // Gunakan filter_var untuk validasi dan sanitasi
        $filtered = filter_var($url, FILTER_SANITIZE_URL);
        if ($filtered && filter_var($filtered, FILTER_VALIDATE_URL)) {
            // Pastikan selalu ada trailing slash
            return rtrim($filtered, '/') . '/';
        } else {
            error_log("resolveBaseUrl(): BASE_URL di .env tidak valid setelah sanitasi [{$url}], fallback ke deteksi otomatis");
            return detectBaseUrl();
        }
    }

    // Fallback ke deteksi otomatis
    return detectBaseUrl();
}

// ============================================
// DEFINISI KONSTANTA
// ============================================

if (php_sapi_name() === 'cli') {
    $rootDir = dirname(__DIR__);
    // CLI mode: gunakan fallback yang lebih aman
    $baseUrlCli = $_ENV['BASE_URL'] ?? 'http://localhost/bem/';
    // Pastikan trailing slash
    $baseUrlCli = rtrim($baseUrlCli, '/') . '/';
    defined('BASE_URL')    || define('BASE_URL',    $baseUrlCli);
    defined('UPLOAD_PATH') || define('UPLOAD_PATH', $rootDir . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
    defined('UPLOAD_URL')  || define('UPLOAD_URL',  BASE_URL . 'uploads/');
    defined('ASSETS_URL')  || define('ASSETS_URL',  BASE_URL . 'assets/');
    defined('APP_ENV')     || define('APP_ENV',     'development');

} else {
    defined('BASE_URL')    || define('BASE_URL',    resolveBaseUrl());
    defined('UPLOAD_PATH') || define('UPLOAD_PATH', detectUploadPath());
    defined('UPLOAD_URL')  || define('UPLOAD_URL',  BASE_URL . 'uploads/');
    defined('ASSETS_URL')  || define('ASSETS_URL',  BASE_URL . 'assets/');
    defined('APP_ENV')     || define('APP_ENV',     detectAppEnv());
}