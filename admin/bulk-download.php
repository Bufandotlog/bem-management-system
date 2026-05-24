<?php
// admin/bulk-download.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth-check.php';

// Pastikan sudah login
if (!isLoggedIn()) {
    die("Akses ditolak.");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['ids'])) {
    die("Tidak ada data yang dipilih.");
}

if (!csrfVerify()) {
    die("Token keamanan tidak valid.");
}

set_time_limit(300); 
ini_set('memory_limit', '256M');

$selected_ids = $_POST['ids'];
if (!is_array($selected_ids)) $selected_ids = [$selected_ids];

$periode_id = getUserPeriode();
$zipName = "Arsip_Surat_Word_" . date('Ymd_His') . ".zip";
$tempDir = sys_get_temp_dir() . '/bulk_word_' . uniqid();
mkdir($tempDir, 0755, true);

// 1. Caching data global
$db_pengaturan = dbFetchAll("SELECT kunci, nilai FROM pengaturan");
$global_pengaturan = [];
foreach($db_pengaturan as $p) {
    if(trim($p['nilai']) !== '') $global_pengaturan[$p['kunci']] = $p['nilai'];
}
$global_ketua = getKetua($periode_id);

// 2. Kumpulkan ID
$final_ids = [];
foreach ($selected_ids as $id) {
    $id = (int)$id;
    $info = dbFetchOne("SELECT nomor_surat FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");
    if ($info) {
        $siblings = dbFetchAll("SELECT id FROM arsip_surat WHERE nomor_surat = ? AND periode_id = ?", [$info['nomor_surat'], $periode_id], "si");
        foreach ($siblings as $s) { $final_ids[] = (int)$s['id']; }
    }
}
$final_ids = array_unique($final_ids);

$addedFiles = 0;

foreach ($final_ids as $id) {
    $surat = dbFetchOne("SELECT * FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");
    if (!$surat) continue;

    $safeNomor = str_replace(['/', '\\'], '_', $surat['nomor_surat']);
    $safeTujuan = substr(preg_replace('/[^a-zA-Z0-9]/', '_', $surat['tujuan']), 0, 30);
    $fileName = $safeNomor . '_' . $safeTujuan;

    // 1. Jika ada file asli (Manual/Upload)
    if (!empty($surat['file_surat'])) {
        $filePath = UPLOAD_PATH . DIRECTORY_SEPARATOR . ltrim($surat['file_surat'], '/\\');
        if (file_exists($filePath)) {
            $ext = pathinfo($filePath, PATHINFO_EXTENSION);
            copy($filePath, $tempDir . '/' . $fileName . '.' . $ext);
            $addedFiles++;
        }
    } 
    // 2. Surat sistem (Konversi ke Word .doc)
    else {
        $_GET['id'] = $id;
        $_GET['bulk'] = 1;
        $BULK_PENGATURAN = $global_pengaturan;
        $BULK_KETUA = $global_ketua;

        ob_start();
        include __DIR__ . '/cetak-surat.php';
        $html = ob_get_clean();
        
        if ($html) {
            // Trik Word: Embed gambar ke Base64 agar tetap muncul offline
            $html = preg_replace_callback('/<img[^>]+src="([^">]+)"/i', function($matches) {
                $src = $matches[1];
                // Jika relative path, jadikan absolut
                if (!preg_match('~^https?://~i', $src)) {
                    $localPath = dirname(__DIR__) . '/' . ltrim(parse_url($src, PHP_URL_PATH), '/');
                    if (file_exists($localPath)) {
                        $type = pathinfo($localPath, PATHINFO_EXTENSION);
                        $data = file_get_contents($localPath);
                        $base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
                        return str_replace($src, $base64, $matches[0]);
                    }
                }
                return $matches[0];
            }, $html);

            // Gunakan $html langsung karena sudah berisi struktur HTML lengkap dari cetak-surat.php
            $wordContent = $html;

            file_put_contents($tempDir . '/' . $fileName . '.doc', $wordContent);
            $addedFiles++;
        }
    }
}

if ($addedFiles > 0) {
    $zipPath = sys_get_temp_dir() . '/' . $zipName;
    $cmd = "cd " . escapeshellarg($tempDir) . " && zip -r " . escapeshellarg($zipPath) . " .";
    exec($cmd);

    if (file_exists($zipPath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zipName . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
    } else {
        die("Gagal membuat ZIP.");
    }
} else {
    die("Tidak ada file.");
}

// Cleanup
$files = glob($tempDir . '/*');
foreach($files as $file) { if(is_file($file)) @unlink($file); }
@rmdir($tempDir);
exit();
