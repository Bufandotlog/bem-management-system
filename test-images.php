<?php
// test-images.php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h1>Diagnostic Tool: S3 Images LPJ ID 2</h1>";

$lpj = dbFetchOne("SELECT * FROM lpj_dokumen WHERE id=2");
if (!$lpj) {
    die("LPJ ID 2 tidak ditemukan di database.");
}

$proker = json_decode($lpj['proker_terlaksana'], true) ?: [];

echo "<table border='1' cellpadding='8' style='border-collapse:collapse; width:100%;'>";
echo "<tr style='background:#eee;'><th>Proker</th><th>Caption</th><th>DB Path</th><th>Resolved URL</th><th>Status (Curl)</th><th>Rendered Image</th></tr>";

foreach ($proker as $idx => $pk) {
    $docs = $pk['dokumentasi'] ?? [];
    foreach ($docs as $d) {
        $path = $d['file_path'];
        $url = uploadUrl($path);
        
        // Cek status HTTP dari URL tersebut
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $color = ($httpcode == 200) ? 'green' : 'red';
        
        echo "<tr>";
        echo "<td>" . htmlspecialchars($pk['Nama Program Kerja'] ?? '') . "</td>";
        echo "<td>" . htmlspecialchars($d['caption'] ?? '') . "</td>";
        echo "<td><code style='font-size:11px;'>" . htmlspecialchars($path) . "</code></td>";
        echo "<td><a href='" . htmlspecialchars($url) . "' target='_blank'>" . htmlspecialchars($url) . "</a></td>";
        echo "<td style='color:$color; font-weight:bold;'>" . $httpcode . "</td>";
        echo "<td><img src='" . htmlspecialchars($url) . "' style='max-width:100px; max-height:100px;'></td>";
        echo "</tr>";
    }
}
echo "</table>";
?>
