<?php
// admin/download_app.php - Proxy Unduhan Terproteksi APK Mobile BEM
// VERSI: 1.0 - Direct Web Distribution Khusus Pengurus BEM

require_once __DIR__ . '/../includes/functions.php';

// -----------------------------------------------------------------------------
// 1. Autentikasi Pengurus (Wajib Sesi Login Admin)
// -----------------------------------------------------------------------------
requireLogin();

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    http_response_code(403);
    accessDenied("Anda harus login sebagai pengurus terlebih dahulu untuk mengunduh aplikasi ini.");
}

// -----------------------------------------------------------------------------
// 2. Lokasi Storage Privat & Master Checksum SHA-256
// -----------------------------------------------------------------------------
$apkPath = __DIR__ . '/../storage/app_release/bem-mobile-v1.0.apk';

// HASH SHA-256 Resmi dari Rilis Flutter APK yang Di-build
$masterSha256 = 'd5aa38de289f7f9d3fe55fe91ef3a1af4046f3fc5079974d53817222d659454f';

if (!file_exists($apkPath)) {
    http_response_code(404);
    die("File installer aplikasi tidak ditemukan di server.");
}

// -----------------------------------------------------------------------------
// 3. Verifikasi Integritas File (Mencegah Tampering / Penimpaan Malware)
// -----------------------------------------------------------------------------
$currentHash = hash_file('sha256', $apkPath);

if (!hash_equals($masterSha256, $currentHash)) {
    $userId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    error_log(sprintf(
        "[SECURITY ALERT] Terdeteksi Modifikasi APK Mismatch! User ID: %s, IP: %s | Master: %s | Actual: %s",
        $userId, $ip, $masterSha256, $currentHash
    ));

    http_response_code(500);
    die("Peringatan Keamanan: Hash file APK di server tidak cocok dengan rilis resmi. Unduhan dibatalkan.");
}

// -----------------------------------------------------------------------------
// 4. Catat Log Audit Unduhan
// -----------------------------------------------------------------------------
$userId   = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$userName = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Pengurus';
$userIp   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (function_exists('auditLog')) {
    auditLog('DOWNLOAD', 'app_release', $userId, "Pengurus [{$userName}] mengunduh BEM Mobile APK v1.0 (IP: {$userIp})");
} else {
    error_log("[APK DOWNLOAD] User ID: {$userId} ({$userName}) | IP: {$userIp} | Date: " . date('Y-m-d H:i:s'));
}

// -----------------------------------------------------------------------------
// 5. Binary Stream Ke Browser Pengurus
// -----------------------------------------------------------------------------
if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="BEM-Astawidya-Official.apk"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($apkPath));

readfile($apkPath);
exit();
