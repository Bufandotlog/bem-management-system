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

    $token = csrfToken();
    $authUrl = buildGoogleAuthUrl('login', $token);
    header("Location: " . $authUrl);
    exit();
}

redirect('admin/system/pengaturan.php');
