<?php
require_once "/var/www/html/bem/includes/functions.php";
$suuk = dbFetchOne("SELECT id, nama, role FROM users WHERE nama = 'suuk'");
if ($suuk) {
    echo "User found: {$suuk['nama']}, ID: {$suuk['id']}, Role: {$suuk['role']}\n";
    $ak = dbFetchOne("SELECT * FROM anggota_kementerian WHERE user_id = ?", [$suuk['id']], "i");
    if ($ak) echo "Found in anggota_kementerian.\n";
    else echo "NOT found in anggota_kementerian.\n";
} else {
    echo "User suuk not found.\n";
}
