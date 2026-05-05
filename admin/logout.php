<?php
// admin/logout.php
// VERSI: 2.2 - CSRF PROTECTION VIA TOKEN URL
//   CHANGED: Validasi csrf_token dari GET sebelum logout
//   CHANGED: Jika token tidak ada/salah → redirect ke dashboard (bukan logout)
//   CHANGED: Kompatibel dengan fitur session token (user_sessions)
//   UNCHANGED: Delegasi ke logout() di functions.php

require_once __DIR__ . '/../includes/functions.php';

// CSRF via URL — token dibandingkan dengan yang ada di session
// Logout via link <a href> tidak bisa pakai POST form biasa
$tokenGet = $_GET['csrf_token'] ?? '';
$tokenSes = $_SESSION['csrf_token'] ?? '';

if (empty($tokenGet) || empty($tokenSes) || !hash_equals($tokenSes, $tokenGet)) {
    // Token tidak valid atau tidak ada — abaikan, kembali ke dashboard
    redirect('admin/dashboard.php', 'Request logout tidak valid.', 'error');
    exit();
}

// Token valid — jalankan logout
// logout() akan:
// 1. Hapus session dari tabel user_sessions
// 2. auditLog('LOGOUT')
// 3. Kosongkan $_SESSION
// 4. Hapus cookie
// 5. session_destroy()
// 6. redirect ke login
logout();