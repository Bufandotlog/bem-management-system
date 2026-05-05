<?php
// admin/config.php - Konfigurasi untuk halaman admin
// VERSI: 3.1 - Kompatibel dengan replay protection TOTP dan user_sessions
//   UNCHANGED: Semua logika keamanan tetap sama
//   CHANGED: Versi saja untuk dokumentasi

// ============================================
// 1. LOAD DEPENDENSI
// ============================================
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth-check.php';

// ============================================
// 2. CEK LOGIN (tidak ada duplikasi)
// ============================================
// requireLogin() dipanggil di header.php, bukan di sini.

// ============================================
// 3. AMBIL DATA USER DARI SESSION
// ============================================
$admin_id       = (int) ($_SESSION['admin_id']       ?? 0);
$admin_name     = $_SESSION['admin_name']     ?? 'Admin';
$admin_username = $_SESSION['admin_username'] ?? '';
$admin_role     = $_SESSION['admin_role']     ?? 'admin';

// ============================================
// 4. VALIDASI USER KE DATABASE (FRESH)
// ============================================
$user_periode_id    = null;
$user_can_access_all = false;

if ($admin_id > 0) {
    $user_data = dbFetchOne(
        "SELECT periode_id, can_access_all, is_active
         FROM users
         WHERE id = ? AND is_active = 1",
        [$admin_id],
        "i"
    );

    if (!$user_data) {
        error_log("admin/config.php: User ID [{$admin_id}] tidak aktif atau tidak ditemukan — session dihancurkan");
        session_unset();
        session_destroy();
        redirect('admin/login.php', 'Sesi tidak valid, silakan login kembali.', 'error');
        exit();
    }

    $user_periode_id     = $user_data['periode_id']    ?? null;
    $user_can_access_all = (bool) ($user_data['can_access_all'] ?? false);
}

// ============================================
// 5. FUNGSI PERIODE (menggunakan auth-check.php)
// ============================================
/**
 * Ambil data periode lengkap berdasarkan periode_id.
 * SELECT * diganti kolom spesifik untuk keamanan dan performa.
 *
 * @param  int|null $periode_id null = ambil periode user saat ini
 * @return array|null
 */
function getPeriodeData($periode_id = null) {
    if ($periode_id === null) {
        $periode_id = getUserPeriode(); // dari auth-check.php
    }

    $periode_id = (int) $periode_id;

    if ($periode_id <= 0) {
        return null;
    }

    return dbFetchOne(
        "SELECT id, nama, tahun_mulai, tahun_selesai, is_active
         FROM periode_kepengurusan
         WHERE id = ?",
        [$periode_id],
        "i"
    );
}

// ============================================
// 6. SET PERIODE AKTIF UNTUK REQUEST INI
// ============================================
$active_periode = getUserPeriode();   // dari auth-check.php
$periode_data   = getPeriodeData($active_periode);

// ============================================
// 7. CONSTANTS
// ============================================
defined('ADMIN_URL')  || define('ADMIN_URL',  BASE_URL . 'admin/');
defined('ADMIN_PATH') || define('ADMIN_PATH', __DIR__ . '/');
?>