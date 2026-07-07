<?php
// portal-astawidya.php - Pintu Gerbang Rahasia Login Admin
// Mengatur cookie akses sebelum mengarahkan ke login.php

// Gunakan secure cookie options
$cookieOptions = [
    'expires' => 0, // Cookie sesi (terhapus otomatis saat browser ditutup)
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
];

setcookie('admin_access', '1', $cookieOptions);

// Redirect ke login panel
header("Location: admin/login.php");
exit();
