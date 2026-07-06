<?php
// admin/dev-login.php

// Load .env first so path-detection.php detects development correctly
(function () {
    $candidates = [
        dirname(__DIR__, 1) . '/.env',
        dirname(__DIR__, 2) . '/.env',
    ];
    foreach ($candidates as $envFile) {
        if (!file_exists($envFile)) continue;
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            $val = trim($val, "\"'");
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $val;
            }
        }
        break;
    }
})();

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Hanya izinkan di environment development
if (defined('APP_ENV') && APP_ENV !== 'development') {
    die("Akses ditolak. Halaman ini hanya untuk development.");
}

$user = dbFetchOne("SELECT * FROM users WHERE username = 'bufan'");
if ($user) {
    $_SESSION['admin_id'] = (int)$user['id'];
    $_SESSION['admin_name'] = $user['username'];
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_role'] = $user['role'];
    $_SESSION['admin_periode_id'] = (int)$user['periode_id'];
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    header("Location: buat-panitia.php");
    exit();
} else {
    die("User bufan tidak ditemukan di database.");
}
