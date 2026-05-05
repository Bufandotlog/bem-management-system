<?php
// config/app.php - Konfigurasi aplikasi
// VERSI: 3.2 - Minor: tambah fallback UPLOAD_PATH, perbaiki setlocale
//   CHANGED: Tambah pengecekan UPLOAD_PATH sudah defined atau belum
//   CHANGED: setlocale dengan @ untuk menghindari warning jika locale tidak tersedia
//   UNCHANGED: semua logika lainnya

// ============================================
// 1. LOAD PATH DETECTION TERLEBIH DAHULU
// ============================================
require_once __DIR__ . '/path-detection.php';

// ============================================
// 2. KONSTANTA APLIKASI
// ============================================
defined('SITE_NAME') || define('SITE_NAME', 'BEM Kabinet Astawidya');

// ============================================
// 3. SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    // Pakai @ agar tidak fatal di shared hosting yang restrict ini_set
    @ini_set('session.use_strict_mode', 1);
    @ini_set('session.gc_maxlifetime', 1800);

    session_start();

    // Idle timeout
    if (isset($_SESSION['_last_activity'])) {
        if (time() - $_SESSION['_last_activity'] > 1800) {
            session_unset();
            session_destroy();
            session_start();
        }
    }
    $_SESSION['_last_activity'] = time();
}

// ============================================
// 4. KONFIGURASI UPLOAD FILE
// ============================================
defined('MAX_FILE_SIZE')      || define('MAX_FILE_SIZE',      5 * 1024 * 1024);
defined('ALLOWED_EXTENSIONS') || define('ALLOWED_EXTENSIONS', ['jpg','jpeg','png','gif','webp','pdf']);
defined('ALLOWED_MIME_TYPES') || define('ALLOWED_MIME_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf',
]);

// ============================================
// 5. TIMEZONE
// ============================================
date_default_timezone_set('Asia/Jakarta');

// ============================================
// 6. ERROR REPORTING
// ============================================
// [FIX] logs/ dipindah ke DALAM htdocs agar bisa ditulis di InfinityFree
// Pastikan logs/ diblokir akses via .htaccess
$logsDir  = __DIR__ . '/../logs';
$logFile  = $logsDir . '/php-error.log';

if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    ini_set('log_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    ini_set('log_errors', 1);

    // [FIX] Buat folder logs/ dulu sebelum set error_log
    // Cegah 500 jika folder belum ada
    if (!is_dir($logsDir)) {
        @mkdir($logsDir, 0755, true);
    }
    // Hanya set error_log jika folder berhasil dibuat dan bisa ditulis
    if (is_dir($logsDir) && is_writable($logsDir)) {
        ini_set('error_log', $logFile);
    }
    // Jika tidak bisa tulis log, biarkan default — jangan fatal
}

// ============================================
// 7. FOLDER UPLOAD
// ============================================
// Pastikan UPLOAD_PATH sudah didefinisikan (dari path-detection.php)
if (!defined('UPLOAD_PATH')) {
    // Fallback jika path-detection.php gagal mendefinisikan
    define('UPLOAD_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR);
}

if (!is_dir(UPLOAD_PATH)) {
    @mkdir(UPLOAD_PATH, 0755, true);
}

if (is_dir(UPLOAD_PATH)) {
    foreach (['umum','kabinet','berita','kementerian','bph'] as $sub) {
        $subPath = UPLOAD_PATH . $sub . DIRECTORY_SEPARATOR;
        if (!is_dir($subPath)) {
            @mkdir($subPath, 0755, true);
        }
    }
}

// ============================================
// 8. DEBUG TOOL
// ============================================
$_debug_token = $_ENV['DEBUG_TOKEN'] ?? '';
$_debug_req   = $_GET['debug']        ?? '';
$_debug_ok    = APP_ENV === 'development'
                && !empty($_debug_token)
                && hash_equals($_debug_token, $_debug_req);

if ($_debug_ok) {
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    echo "<div style='background:#f0f0f0;padding:15px;margin:15px;border:2px solid #333;font-family:monospace;'>";
    echo "<h3 style='margin-top:0;color:#d00;'>DEBUG MODE</h3>";
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse;width:100%;'>";
    foreach ([
        'PHP_VERSION' => PHP_VERSION,
        'APP_ENV'     => APP_ENV,
        'BASE_URL'    => BASE_URL,
        'UPLOAD_PATH' => UPLOAD_PATH,
        'LOG_FILE'    => $logFile,
        'LOG_WRITABLE'=> (is_dir($logsDir) && is_writable($logsDir)) ? 'ya' : 'tidak',
    ] as $k => $v) {
        echo "<tr><td><strong>{$esc($k)}</strong></td><td>{$esc($v)}</td></tr>";
    }
    echo "</table></div>";
}
unset($_debug_token, $_debug_req, $_debug_ok);

// ============================================
// 9. INISIALISASI LAINNYA
// ============================================
ini_set('default_charset', 'UTF-8');
// Set locale dengan @ untuk menghindari warning jika locale tidak tersedia
@setlocale(LC_TIME, 'id_ID.utf8', 'Indonesian_indonesia.1252', 'id_ID');