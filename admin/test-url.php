<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config.php';

$lpj = dbFetchOne("SELECT * FROM lpj_dokumen WHERE id=2");
$proker = json_decode($lpj['proker_terlaksana'], true);

echo "<pre>";
foreach ($proker as $idx => $pk) {
    echo "Proker " . ($idx+1) . ": " . ($pk['Nama Program Kerja'] ?? '') . "\n";
    $docs = $pk['dokumentasi'] ?? [];
    foreach ($docs as $d) {
        $path = $d['file_path'];
        echo "  - Original DB Path: " . $path . "\n";
        echo "  - Relative Path:    " . get_relative_upload_path($path) . "\n";
        echo "  - uploadUrl():      " . uploadUrl($path) . "\n\n";
    }
}
echo "</pre>";
