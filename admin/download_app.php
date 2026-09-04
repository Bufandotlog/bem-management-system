<?php
// admin/download_app.php - Proxy Unduhan Terproteksi APK Mobile BEM
// VERSI: 2.1 - Multi-variant (universal + 4 ABI) + preview release (Kodular webview)
//
// Sumber kebenaran: /var/www/html/bem/storage/app_release/releases.json
// (di-generate oleh .github/workflows/release-mobile.yml, dilindungi .htaccess)
//
// Cara pakai (rilis stabil, default):
//   /admin/download_app.php            -> default: universal
//   /admin/download_app.php?arch=arm64-v8a   -> APK arm64 (HP modern)
//   /admin/download_app.php?arch=armeabi-v7a -> APK armv7 (HP lama)
//   /admin/download_app.php?arch=x86         -> APK x86 (emulator)
//   /admin/download_app.php?arch=x86_64      -> APK x86_64 (emulator 64-bit)
//
// Cara pakai (rilis preview, internal-only):
//   /admin/download_app.php?release=preview -> APK Kodular webview darurat
//   Catatan: APK preview dibuat via Kodular (WebView -> bembudiutomo.my.id).
//   Bukan pengganti rilis Flutter resmi. Hanya untuk rilis cepat.
//
// Validasi: SHA-256 APK dicek on-the-fly terhadap metadata di releases.json.
// Bila file APK atau metadata tidak ada / SHA mismatch -> 4xx/5xx + audit.

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
// 2. Lokasi Storage Privat & Metadata Sumber Kebenaran
// -----------------------------------------------------------------------------
$storageDir  = __DIR__ . '/../storage/app_release';
$releasesJson = $storageDir . '/releases.json';

// -----------------------------------------------------------------------------
// 2b. Pilih rilis: stable (rilis resmi Flutter, default) atau preview (Kodular
//     webview darurat, internal-only). '?release=preview' override ke preview;
//     default tetap 'stable' agar pengurus tidak salah unduh.
// -----------------------------------------------------------------------------
$releaseType = isset($_GET['release']) ? strtolower((string) $_GET['release']) : 'stable';
if (!in_array($releaseType, ['stable', 'preview'], true)) {
    http_response_code(400);
    die("Tipe rilis tidak dikenal. Pilihan: stable, preview");
}

// Whitelist ABI (lihat releases.json). Diabaikan untuk preview (selalu 1 file universal).
$allowedArchs = ['universal', 'arm64-v8a', 'armeabi-v7a', 'x86', 'x86_64'];

$requestedArch = isset($_GET['arch']) ? (string) $_GET['arch'] : 'universal';
if ($releaseType === 'stable' && !in_array($requestedArch, $allowedArchs, true)) {
    http_response_code(400);
    die("Arsitektur tidak dikenal. Pilihan valid: " . implode(', ', $allowedArchs));
}

if (!is_readable($releasesJson)) {
    http_response_code(500);
    error_log("[APK DOWNLOAD] releases.json tidak terbaca di {$releasesJson}");
    die("Metadata rilis tidak tersedia di server. Hubungi admin.");
}

$releases = json_decode((string) file_get_contents($releasesJson), true);
if (!is_array($releases) || !isset($releases['apks']) || !is_array($releases['apks'])) {
    http_response_code(500);
    error_log("[APK DOWNLOAD] releases.json tidak valid/corrupt: " . json_last_error_msg());
    die("Metadata rilis corrupt. Hubungi admin.");
}

// Cari entry untuk arch yang diminta.
$entry = null;
if ($releaseType === 'preview') {
    if (!isset($releases['preview']['apks']) || !is_array($releases['preview']['apks']) || empty($releases['preview']['apks'])) {
        http_response_code(404);
        die("Rilis preview belum tersedia. Hubungi admin.");
    }
    $entry = $releases['preview']['apks'][0];
    $requestedArch = (string) ($entry['arch'] ?? 'universal');
} else {
    foreach ($releases['apks'] as $row) {
        if (isset($row['arch']) && $row['arch'] === $requestedArch) {
            $entry = $row;
            break;
        }
    }
}
if ($entry === null) {
    http_response_code(404);
    die("Varian {$requestedArch} tidak tersedia dalam rilis {$releaseType}. Pilih: " . implode(', ', $allowedArchs));
}

$apkFileName  = (string) ($entry['file']  ?? '');
$masterSha256 = strtolower((string) ($entry['sha256'] ?? ''));
$appVersion   = (string) (
    $releaseType === 'preview'
        ? ($releases['preview']['version'] ?? '0.0.0-preview')
        : ($releases['latest'] ?? 'unknown')
);

if ($apkFileName === '' || $masterSha256 === '' || !preg_match('/^[a-f0-9]{64}$/', $masterSha256)) {
    http_response_code(500);
    error_log("[APK DOWNLOAD] Entry releases.json tidak valid untuk arch={$requestedArch} release={$releaseType}");
    die("Metadata rilis untuk varian ini tidak valid. Hubungi admin.");
}

$apkPath = $storageDir . '/' . $apkFileName;
if (!is_file($apkPath)) {
    http_response_code(404);
    error_log("[APK DOWNLOAD] File APK hilang di server: {$apkPath} (arch={$requestedArch}, user=" . ($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0) . ")");
    die("File installer untuk varian {$requestedArch} tidak ditemukan di server.");
}

// -----------------------------------------------------------------------------
// 3. Verifikasi Integritas File (Mencegah Tampering / Penimpaan Malware)
// -----------------------------------------------------------------------------
$currentHash = hash_file('sha256', $apkPath);

if (!hash_equals($masterSha256, $currentHash)) {
    $userId = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0;
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    error_log(sprintf(
        "[SECURITY ALERT] APK Mismatch! arch=%s user=%s ip=%s master=%s actual=%s",
        $requestedArch, $userId, $ip, $masterSha256, $currentHash
    ));

    http_response_code(500);
    die("Peringatan Keamanan: Hash file APK {$requestedArch} di server tidak cocok dengan rilis resmi. Unduhan dibatalkan.");
}

// -----------------------------------------------------------------------------
// 4. Catat Log Audit Unduhan
// -----------------------------------------------------------------------------
$userId   = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
$userName = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Pengurus';
$userIp   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

if (function_exists('auditLog')) {
    auditLog(
        'DOWNLOAD',
        'app_release',
        $userId,
        "Pengurus [{$userName}] mengunduh BEM Mobile APK v{$appVersion} ({$releaseType}/{$requestedArch}) IP: {$userIp}"
    );
} else {
    error_log("[APK DOWNLOAD] User ID: {$userId} ({$userName}) | release: {$releaseType} | arch: {$requestedArch} | v: {$appVersion} | IP: {$userIp} | Date: " . date('Y-m-d H:i:s'));
}

// -----------------------------------------------------------------------------
// 5. Binary Stream Ke Browser Pengurus
// -----------------------------------------------------------------------------
if (ob_get_level()) {
    ob_end_clean();
}

// Filename: stable=universal -> BEM-Astawidya-Official.apk (backward-compat v1.0),
// stable=arch -> BEM-Astawidya-v<version>-<arch>.apk.
// Preview -> BEM-Astawidya-v<version>-preview.apk (selalu 1 file).
if ($releaseType === 'preview') {
    $downloadName = sprintf('BEM-Astawidya-v%s-preview.apk', $appVersion);
} elseif ($requestedArch === 'universal') {
    $downloadName = 'BEM-Astawidya-Official.apk';
} else {
    $downloadName = sprintf('BEM-Astawidya-v%s-%s.apk', $appVersion, $requestedArch);
}

header('Content-Description: File Transfer');
header('Content-Type: application/vnd.android.package-archive');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');
header('Content-Length: ' . filesize($apkPath));
header('X-App-Version: ' . $appVersion);
header('X-App-Arch: ' . $requestedArch);
header('X-App-Release: ' . $releaseType);

readfile($apkPath);
exit();
