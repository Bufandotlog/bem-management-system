<?php
require_once "/var/www/html/bem/includes/functions.php";
$periode_id = 1;

$kegiatan = dbFetchAll("SELECT * FROM kegiatan WHERE periode_id = ? AND status = 'persiapan' ORDER BY id DESC", [$periode_id], "i");
foreach ($kegiatan as $kg) {
    echo "Kegiatan: {$kg['nama_kegiatan']}\n";
    $kp = dbFetchOne("SELECT users.nama FROM kegiatan_panitia kp JOIN users ON kp.user_id = users.id WHERE kp.kegiatan_id = ? AND kp.event_role = 'ketuplat' LIMIT 1", [$kg['id']], "i");
    if ($kp) {
        echo "  Ketuplat (from users): {$kp['nama']}\n";
    } else {
        echo "  No ketuplat found.\n";
    }
}

// Cek all_members
$bph_inti_members = dbFetchAll("SELECT nama, jabatan, posisi FROM struktur_bph WHERE periode_id = ? AND posisi IN ('ketua', 'wakil_ketua')", [$periode_id], "i");
$bph_anggota_members = dbFetchAll("SELECT a.nama, a.jabatan, s.posisi FROM anggota_bph a JOIN struktur_bph s ON a.bph_id = s.id WHERE a.periode_id = ?", [$periode_id], "i");
$kementerian_members = dbFetchAll("SELECT a.nama, a.jabatan, k.nama as nama_kementerian FROM anggota_kementerian a JOIN kementerian k ON a.kementerian_id = k.id WHERE a.periode_id = ?", [$periode_id], "i");

$all_members_temp = [];
foreach ($bph_inti_members as $m) $all_members_temp[$m['nama']][] = "BPH Inti";
foreach ($bph_anggota_members as $m) $all_members_temp[$m['nama']][] = "Sek/Ben";
foreach ($kementerian_members as $m) $all_members_temp[$m['nama']][] = $m['nama_kementerian'];

echo "\nAll Members List:\n";
foreach (array_keys($all_members_temp) as $n) {
    echo "  - $n\n";
}
