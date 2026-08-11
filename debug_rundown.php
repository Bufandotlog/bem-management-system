<?php
require_once __DIR__ . '/includes/functions.php';
$rundowns = dbFetchAll("SELECT id, nama_acara FROM arsip_rundown");
$kegiatans = dbFetchAll("SELECT id, nama_kegiatan FROM kegiatan");
echo "=== ARSIP RUNDOWN ===\n";
print_r($rundowns);
echo "\n=== KEGIATAN ===\n";
print_r($kegiatans);
?>
