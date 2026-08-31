<?php
// admin/google-auth.php
// Handler inisiasi penautan dan login Google OAuth 2.0

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/google-oauth.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$config = getGoogleAuthConfig();

if (!$config['configured']) {
    if ($action === 'login') {
        redirect('astawidya/bem.php', 'Fitur Login dengan Google belum dikonfigurasi di server.', 'error');
    } else {
        redirect('admin/system/pengaturan.php', 'Google OAuth belum dikonfigurasi di server. Silakan atur GOOGLE_CLIENT_ID dan GOOGLE_CLIENT_SECRET di .env', 'error');
    }
    exit();
}

// ----------------------------------------------------
// ACTION: UNLINK (Lepas Tautan Akun Google)
// ----------------------------------------------------
if ($action === 'unlink') {
    if (!isLoggedIn()) {
        redirect('astawidya/bem.php', 'Akses ditolak. Silakan login.', 'error');
        exit();
    }
    
    if (!csrfVerify()) {
        redirect('admin/system/pengaturan.php', 'Token CSRF tidak valid.', 'error');
        exit();
    }

    $adminId = (int)$_SESSION['admin_id'];
    dbQuery("UPDATE users SET google_id = NULL, google_email = NULL, google_linked_at = NULL WHERE id = ?", [$adminId], "i");
    
    auditLog('UNLINK_GOOGLE', 'users', $adminId, 'Pelepasan tautan akun Google');
    redirect('admin/system/pengaturan.php', 'Tautan akun Google berhasil dilepas.', 'success');
    exit();
}

// ----------------------------------------------------
// ACTION: LINK (Tautkan Akun Google untuk User Login)
// ----------------------------------------------------
if ($action === 'link') {
    if (!isLoggedIn()) {
        redirect('astawidya/bem.php', 'Akses ditolak. Silakan login terlebih dahulu untuk menautkan akun.', 'error');
        exit();
    }

    $token = csrfToken();
    $authUrl = buildGoogleAuthUrl('link', $token);
    header("Location: " . $authUrl);
    exit();
}

// ----------------------------------------------------
// ACTION: LOGIN (Login Pengurus via Akun Google)
// ----------------------------------------------------
if ($action === 'login') {
    if (isLoggedIn()) {
        redirect('admin/core/dashboard.php');
        exit();
    }

    // Validasi Turnstile — cegah bypass verifikasi keamanan via Google
    $turnstileSiteKey = $_ENV['TURNSTILE_SITE_KEY'] ?? getenv('TURNSTILE_SITE_KEY') ?: '';
    $turnstileSecret  = $_ENV['TURNSTILE_SECRET_KEY'] ?? getenv('TURNSTILE_SECRET_KEY') ?: '';
    $turnstileEnabledVal = $_ENV['TURNSTILE_ENABLED'] ?? getenv('TURNSTILE_ENABLED');
    $turnstileEnabled = ($turnstileEnabledVal !== null && $turnstileEnabledVal !== '')
        ? filter_var($turnstileEnabledVal, FILTER_VALIDATE_BOOLEAN)
        : ((defined('APP_ENV') ? APP_ENV : 'production') !== 'development');

    if ($turnstileEnabled && !empty($turnstileSiteKey) && !empty($turnstileSecret)) {
        $cfToken = trim($_GET['cf_token'] ?? $_POST['cf_token'] ?? '');
        if (empty($cfToken)) {
            redirect('astawidya/bem.php', 'Selesaikan verifikasi keamanan (Cloudflare Turnstile) terlebih dahulu sebelum login dengan Google.', 'error');
            exit();
        }
        $siteverifyResp = verifyTurnstileToken($cfToken, $turnstileSecret);
        if (!$siteverifyResp || !($siteverifyResp['success'] ?? false)) {
            recordFailedAttempt('google_turnstile_failed', null);
            redirect('astawidya/bem.php', 'Verifikasi Turnstile gagal. Silakan muat ulang halaman dan coba lagi.', 'error');
            exit();
        }
    }

    $token = csrfToken();
    $authUrl = buildGoogleAuthUrl('login', $token);
    header("Location: " . $authUrl);
    exit();
}

redirect('admin/system/pengaturan.php');
