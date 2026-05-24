<?php
// admin/auth-check.php - Middleware untuk cek akses per periode
// VERSI: 3.1 - REVIEW: tidak ada perubahan kode, hanya update versi
//   UNCHANGED: Semua fungsi tetap sama, sudah aman

/**
 * Cek apakah user punya akses ke periode tertentu.
 *
 * [FIX v3.0] Dua perubahan:
 *   1. Cast $periode_id dan $_SESSION['admin_periode_id'] ke (int)
 *      Mencegah PHP type juggling: "1" == true == "1abc" == 1
 *      Perbandingan == antar tipe berbeda bisa menghasilkan true yang tidak diharapkan.
 *   2. Membaca dari $_SESSION yang sudah divalidasi ke DB oleh isLoggedIn()
 *      di functions.php (interval 5 menit). Tidak perlu query DB lagi di sini
 *      karena validasi sudah dilakukan di lapisan sebelumnya.
 *
 * @param int $periode_id ID periode yang ingin diakses
 * @return bool
 */
function canAccessPeriode($periode_id) {
    $periode_id = (int) $periode_id; // [FIX] Pastikan integer

    // Superadmin bisa akses semua — cek dua kondisi dengan operator yang benar
    $canAll = !empty($_SESSION['admin_can_access_all']);
    $isSuperadmin = ($_SESSION['admin_role'] ?? '') === 'superadmin';

    if ($canAll || $isSuperadmin) {
        return true;
    }

    // [FIX] Cast ke (int) di kedua sisi — cegah type juggling pada ==
    return ((int) ($_SESSION['admin_periode_id'] ?? 0)) === $periode_id;
}

/**
 * Dapatkan periode yang sedang aktif untuk user ini.
 *
 * [FIX v3.0] selected_periode dari session sekarang divalidasi ke DB
 *   sebelum dipakai. Sebelumnya nilai apapun di session langsung
 *   dikembalikan — penyerang yang bisa memodifikasi session bisa
 *   mengakses periode yang tidak ada atau tidak diizinkan.
 *
 * @return int periode_id yang valid
 */
function getUserPeriode() {
    $canAll       = !empty($_SESSION['admin_can_access_all']);
    $isSuperadmin = ($_SESSION['admin_role'] ?? '') === 'superadmin';

    if ($canAll || $isSuperadmin) {
        if (isset($_SESSION['selected_periode'])) {
            $pid = (int) $_SESSION['selected_periode']; // [FIX] Cast ke int

            // [FIX] Validasi ke DB — pastikan periode ini benar-benar ada
            $exists = dbFetchOne(
                "SELECT id FROM periode_kepengurusan WHERE id = ?",
                [$pid],
                "i"
            );

            if ($exists) {
                return $pid;
            }

            // Periode tidak valid — hapus dari session dan fallback ke aktif
            unset($_SESSION['selected_periode']);
            error_log("getUserPeriode(): selected_periode [{$pid}] tidak ditemukan di DB, reset ke aktif");
        }

        // Tidak ada selected_periode atau tidak valid — ambil periode aktif
        return getActivePeriodeId();
    }

    // Admin biasa — kembalikan periode miliknya (sudah divalidasi saat login)
    return (int) ($_SESSION['admin_periode_id'] ?? 0);
}

/**
 * Ambil ID periode aktif dari database.
 * UNCHANGED dari sisi logika — tambah cast (int) pada return value.
 *
 * Menggunakan static variable agar tidak query DB berulang kali
 * dalam satu request yang memanggil getActivePeriodeId() berkali-kali.
 *
 * @return int
 */
function getActivePeriodeId() {
    static $active_id = null;

    if ($active_id === null) {
        $periode   = dbFetchOne("SELECT id FROM periode_kepengurusan WHERE is_active = 1 LIMIT 1");
        $active_id = (int) ($periode['id'] ?? 1); // [FIX] Cast ke int, LIMIT 1 eksplisit
    }

    return $active_id;
}

/**
 * Set periode aktif untuk superadmin.
 *
 * [FIX v3.0] periode_id sekarang:
 *   1. Di-cast ke (int) — tolak nilai non-integer
 *   2. Divalidasi ke DB — pastikan periode ini benar-benar ada
 *   Sebelumnya nilai apapun bisa di-set tanpa validasi.
 *
 * @param  int  $periode_id
 * @return bool true jika berhasil di-set, false jika tidak punya akses atau periode tidak valid
 */
function setSelectedPeriode($periode_id) {
    $canAll       = !empty($_SESSION['admin_can_access_all']);
    $isSuperadmin = ($_SESSION['admin_role'] ?? '') === 'superadmin';

    if (!$canAll && !$isSuperadmin) {
        return false; // Bukan superadmin — tolak
    }

    $periode_id = (int) $periode_id; // [FIX] Pastikan integer

    if ($periode_id <= 0) {
        return false; // ID tidak valid
    }

    // [FIX] Validasi ke DB sebelum simpan ke session
    $exists = dbFetchOne(
        "SELECT id FROM periode_kepengurusan WHERE id = ?",
        [$periode_id],
        "i"
    );

    if (!$exists) {
        error_log("setSelectedPeriode(): periode_id [{$periode_id}] tidak ditemukan di DB");
        return false;
    }

    $_SESSION['selected_periode'] = $periode_id;
    return true;
}

/**
 * Require akses ke periode tertentu — redirect jika tidak punya akses.
 *
 * [FIX v3.0] Dua perubahan:
 *   1. Gunakan fungsi redirect() dari functions.php bukan header() langsung.
 *      redirect() sudah punya proteksi Open Redirect dan selalu exit().
 *   2. Tambah exit() eksplisit setelah redirect() sebagai fallback defensif
 *      (konsisten dengan requireLogin() di functions.php).
 *
 * @param int    $periode_id   ID periode yang ingin diakses
 * @param string $redirect_to  Halaman tujuan jika akses ditolak
 */
function requirePeriodeAccess($periode_id, $redirect_to = 'dashboard.php') {
    if (!canAccessPeriode($periode_id)) {
        // [FIX] Gunakan redirect() bukan header() langsung
        // redirect() sudah handle flash message, open redirect protection, dan exit()
        redirect($redirect_to, 'Anda tidak memiliki akses ke periode ini!', 'error');
        exit(); // Defensif — jaga-jaga jika redirect() gagal karena headers_sent
    }
}