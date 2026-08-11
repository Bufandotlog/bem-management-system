<?php
require_once "/var/www/html/bem/includes/functions.php";
$periode_id = 1;

// Sinkronisasi arsip_panitia ke kegiatan_panitia
$arsip = dbFetchAll("SELECT * FROM arsip_panitia WHERE periode_id = ?", [$periode_id], "i");
foreach ($arsip as $a) {
    $kegiatan = dbFetchOne("SELECT id FROM kegiatan WHERE nama_kegiatan = ? AND periode_id = ?", [$a['nama_kegiatan'], $periode_id], "si");
    if (!$kegiatan) continue;
    $keg_id = $kegiatan['id'];
    
    // Hapus semua kecuali ketuplat (krn ketuplat di-assign di awal master-kegiatan)
    dbQuery("DELETE FROM kegiatan_panitia WHERE kegiatan_id = ? AND event_role != 'ketuplat'", [$keg_id], "i");
    
    $json = json_decode($a['panitia_json'], true) ?: [];
    
    // Fungsi pembantu buat get user_id dari nama
    function getUserIdByNama($nama) {
        if (!$nama) return null;
        $u = dbFetchOne("SELECT id FROM users WHERE nama = ?", [$nama], "s");
        return $u ? $u['id'] : null;
    }
    
    function insertPanitiaRole($keg_id, $nama, $role) {
        $u_id = getUserIdByNama($nama);
        if ($u_id) {
            // Cek apakah sdh ada di kegiatan ini dgn role ini
            $cek = dbFetchOne("SELECT * FROM kegiatan_panitia WHERE kegiatan_id = ? AND user_id = ? AND event_role = ?", [$keg_id, $u_id, $role], "iis");
            if (!$cek) {
                dbQuery("INSERT INTO kegiatan_panitia (kegiatan_id, user_id, event_role, ditunjuk_oleh) VALUES (?, ?, ?, 2)", [$keg_id, $u_id, $role], "iis");
            }
        }
    }
    
    if (!empty($json['ketua_pelaksana'])) {
        // Hapus ketuplat lama
        dbQuery("DELETE FROM kegiatan_panitia WHERE kegiatan_id = ? AND event_role = 'ketuplat'", [$keg_id], "i");
        insertPanitiaRole($keg_id, $json['ketua_pelaksana'], 'ketuplat');
    }
    if (!empty($json['sekretaris'])) {
        foreach ($json['sekretaris'] as $sek) {
            insertPanitiaRole($keg_id, $sek, 'sekretaris_panitia');
        }
    }
    if (!empty($json['seksi_seksi'])) {
        foreach ($json['seksi_seksi'] as $sek) {
            $n_sek = strtolower($sek['nama_seksi']);
            $db_role = 'anggota_panitia';
            if (strpos($n_sek, 'acara') !== false) $db_role = 'sie_acara';
            elseif (strpos($n_sek, 'logistik') !== false) $db_role = 'sie_logistik';
            elseif (strpos($n_sek, 'konsumsi') !== false) $db_role = 'sie_konsumsi';
            elseif (strpos($n_sek, 'humas') !== false || strpos($n_sek, 'pdd') !== false || strpos($n_sek, 'kominfo') !== false) $db_role = 'sie_humas';
            
            foreach ($sek['anggota'] as $ang) {
                insertPanitiaRole($keg_id, $ang, $db_role);
            }
        }
    }
}
echo "Sinkronisasi selesai.\n";
