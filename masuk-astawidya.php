<?php
// masuk-astawidya.php - Pintu Gerbang Rahasia BEM
// Mengatur cookie akses sebelum mengarahkan ke astawidya/bem.php

$cookieOptions = [
    'expires' => 0, // Sesi
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
];

setcookie('admin_access', '1', $cookieOptions);

header("Location: astawidya/bem.php");
exit();
