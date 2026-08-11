<?php
// admin/google-callback.php
// Callback endpoint setelah otorisasi Google OAuth 2.0

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/google-oauth.php';

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

// Jika user membatalkan otorisasi
if (!empty($error)) {
    if (isLoggedIn()) {
        redirect('admin/system/pengaturan.php', 'Proses otorisasi Google dibatalkan.', 'error');
    } else {
        redirect('astawidya/bem.php', 'Proses otorisasi Google dibatalkan.', 'error');
    }
    exit();
}

// Validasi state token untuk anti-CSRF
$expectedState = $_SESSION['google_auth_state'] ?? '';
unset($_SESSION['google_auth_state']);

if (empty($state) || empty($expectedState) || !hash_equals($expectedState, $state)) {
    if (isLoggedIn()) {
        redirect('admin/system/pengaturan.php', 'Sesi otorisasi Google tidak valid (State mismatch).', 'error');
    } else {
        redirect('astawidya/bem.php', 'Sesi otorisasi Google tidak valid (State mismatch).', 'error');
    }
    exit();
}

// Tentukan action ('link' atau 'login')
$action = $_SESSION['google_auth_action'] ?? (str_starts_with($state, 'link_') ? 'link' : 'login');
unset($_SESSION['google_auth_action']);

// Tukar authorization code dengan access token
$tokenData = exchangeGoogleCodeForToken($code);
if (!$tokenData || empty($tokenData['access_token'])) {
    $targetUrl = ($action === 'link') ? 'admin/pengaturan.php' : 'astawidya/bem.php';
    redirect($targetUrl, 'Gagal mendapatkan akses token dari Google.', 'error');
    exit();
}

// Ambil info pengguna Google
$googleUser = getGoogleUserInfo($tokenData['access_token']);
if (!$googleUser || empty($googleUser['sub'])) {
    $targetUrl = ($action === 'link') ? 'admin/pengaturan.php' : 'astawidya/bem.php';
    redirect($targetUrl, 'Gagal mengambil data profil Google.', 'error');
    exit();
}

$googleId    = $googleUser['sub'];
$googleEmail = $googleUser['email'] ?? '';

// ============================================
// MODUL: LINKING GOOGLE ACCOUNT
// ============================================
if ($action === 'link') {
    if (!isLoggedIn()) {
        redirect('astawidya/bem.php', 'Sesi login telah habis. Silakan login kembali.', 'error');
        exit();
    }

    $adminId = (int)$_SESSION['admin_id'];

    // Cek apakah google_id ini sudah dipakai oleh akun lain
    $existing = dbFetchOne("SELECT id, username FROM users WHERE google_id = ? AND id != ? LIMIT 1", [$googleId, $adminId], "si");
    if ($existing) {
        redirect('admin/system/pengaturan.php', 'Akun Google ini (' . htmlspecialchars($googleEmail) . ') sudah ditautkan ke akun BEM pengurus lain.', 'error');
        exit();
    }

    // Update data penautan ke tabel users
    dbQuery(
        "UPDATE users SET google_id = ?, google_email = ?, google_linked_at = NOW() WHERE id = ?",
        [$googleId, $googleEmail, $adminId],
        "ssi"
    );

    auditLog('LINK_GOOGLE', 'users', $adminId, 'Penautan akun Google: ' . $googleEmail);
    redirect('admin/system/pengaturan.php', 'Akun Google (' . htmlspecialchars($googleEmail) . ') berhasil ditautkan!', 'success');
    exit();
}

// ============================================
// MODUL: LOGIN GOOGLE ACCOUNT
// ============================================
if ($action === 'login') {
    $user = dbFetchOne(
        "SELECT id, nama, username, password, role, periode_id, can_access_all, is_active, totp_secret, totp_enabled
         FROM users WHERE google_id = ? LIMIT 1",
        [$googleId], "s"
    );

    if (!$user) {
        redirect('astawidya/bem.php', 'Akun Google ini (' . htmlspecialchars($googleEmail) . ') belum ditautkan ke akun BEM manapun. Silakan login dengan username & password terlebih dahulu lalu tautkan di menu Pengaturan.', 'error');
        exit();
    }

    if (!$user['is_active']) {
        recordFailedAttempt('login_failed', $user['username']);
        redirect('astawidya/bem.php', 'Akun BEM Anda tidak aktif. Silakan hubungi Administrator.', 'error');
        exit();
    }

    // Jika user mengaktifkan 2FA
    if ($user['totp_enabled'] && !empty($user['totp_secret'])) {
        session_regenerate_id(true);
        $_SESSION['2fa_pending']    = true;
        $_SESSION['2fa_user_id']    = $user['id'];
        $_SESSION['2fa_attempts']   = 0;
        $_SESSION['_last_activity'] = time();
        redirect('admin/auth/2fa-verify.php');
        exit();
    }

    // Login Berhasil langsung
    session_regenerate_id(true);
    $_SESSION['admin_logged_in']      = true;
    $_SESSION['admin_id']             = $user['id'];
    $_SESSION['admin_name']           = $user['nama'];
    $_SESSION['admin_username']       = $user['username'];
    $_SESSION['admin_role']           = $user['role'];
    $_SESSION['admin_periode_id']     = $user['periode_id'];
    $_SESSION['admin_can_access_all'] = $user['can_access_all'];
    $_SESSION['2fa_verified']         = false;
    $_SESSION['_last_activity']       = time();
    $_SESSION['_auth_last_check']     = time();

    recordUserSession($user['id']);

    $ip = getClientIp();
    dbQuery("UPDATE users SET last_login = NOW(), last_ip = ? WHERE id = ?", [$ip, $user['id']], "si");

    auditLog('LOGIN_GOOGLE', 'users', $user['id'], 'Login berhasil menggunakan Google OAuth (' . $googleEmail . ')');
    redirect('admin/core/dashboard.php', "Selamat datang kembali, {$user['nama']}!", 'success');
    exit();
}

redirect('admin/system/pengaturan.php');
