<?php
// admin/buat-lpj.php

// 1. Tangani AJAX request di awal sebelum me-require header.php agar output JSON bersih tanpa HTML
if (isset($_GET['ajax_kementerian_id'])) {
    require_once __DIR__ . '/config.php';
    if (!isLoggedIn()) {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    
    header('Content-Type: application/json');
    $k_id = (int)$_GET['ajax_kementerian_id'];
    $periode_id = getUserPeriode();
    
    // Fetch kementerian description
    $k_info = dbFetchOne("SELECT deskripsi FROM kementerian WHERE id = ?", [$k_id], "i");
    $deskripsi = $k_info ? $k_info['deskripsi'] : '';
    
    // Fetch members
    $members = dbFetchAll("SELECT nama, jabatan FROM anggota_kementerian WHERE kementerian_id = ? AND periode_id = ? ORDER BY urutan ASC", [$k_id, $periode_id], "ii");
    
    $ketua = '';
    $sekretaris = '';
    $bendahara = '';
    $anggota = [];
    
    foreach ($members as $m) {
        $jab = strtolower($m['jabatan']);
        if (strpos($jab, 'anggota') !== false) {
            $anggota[] = $m['nama'];
        } elseif (strpos($jab, 'sekretaris') !== false || strpos($jab, 'sekertaris') !== false || strpos($jab, 'sekre') !== false) {
            $sekretaris = $m['nama'];
        } elseif (strpos($jab, 'bendahara') !== false || strpos($jab, 'bendum') !== false) {
            $bendahara = $m['nama'];
        } elseif (strpos($jab, 'menteri') !== false || strpos($jab, 'mentri') !== false || strpos($jab, 'ketua') !== false || strpos($jab, 'kepala') !== false) {
            $ketua = $m['nama'];
        } else {
            $anggota[] = $m['nama'];
        }
    }
    
    $triwulan_param = sanitizeText($_GET['triwulan'] ?? '');
    
    if ($triwulan_param === 'MUBESMA') {
        // Merge Triwulan I & II
        $lpj1 = dbFetchOne("SELECT * FROM lpj_dokumen WHERE periode_id = ? AND kementerian_id = ? AND triwulan = 'I'", [$periode_id, $k_id], "ii");
        $lpj2 = dbFetchOne("SELECT * FROM lpj_dokumen WHERE periode_id = ? AND kementerian_id = ? AND triwulan = 'II'", [$periode_id, $k_id], "ii");
        
        $keanggotaan_decoded = [];
        $keadaan_objektif = '';
        $proker_terlaksana = [];
        $proker_belum_terlaksana = [];
        
        if ($lpj2) {
            $keanggotaan_decoded = json_decode($lpj2['keanggotaan'], true) ?: [];
            $keadaan_objektif = $lpj2['keadaan_objektif'] ?: '';
        } elseif ($lpj1) {
            $keanggotaan_decoded = json_decode($lpj1['keanggotaan'], true) ?: [];
            $keadaan_objektif = $lpj1['keadaan_objektif'] ?: '';
        }
        
        // Populate keanggotaan default values
        $ketua = $keanggotaan_decoded['ketua'] ?? $ketua;
        $sekretaris = $keanggotaan_decoded['sekretaris'] ?? $sekretaris;
        $bendahara = $keanggotaan_decoded['bendahara'] ?? $bendahara;
        $anggota = $keanggotaan_decoded['anggota'] ?? $anggota;
        
        // 1. Process Executed Prokers
        $pt1 = $lpj1 ? (json_decode($lpj1['proker_terlaksana'], true) ?: []) : [];
        $pt2 = $lpj2 ? (json_decode($lpj2['proker_terlaksana'], true) ?: []) : [];
        
        // Merge all executed prokers from both quarters (do not deduplicate)
        $proker_terlaksana = array_merge($pt1, $pt2);
        
        // Build map of executed program names to check against unimplemented ones
        $executed_names = [];
        foreach ($proker_terlaksana as $p) {
            $name = trim(strtolower($p['Nama Program Kerja'] ?? $p['Nama Kegiatan'] ?? ''));
            if ($name !== '') {
                $executed_names[$name] = true;
            }
        }
        
        // 2. Process Unimplemented Prokers
        $pbt1 = $lpj1 ? (json_decode($lpj1['proker_belum_terlaksana'], true) ?: []) : [];
        $pbt2 = $lpj2 ? (json_decode($lpj2['proker_belum_terlaksana'], true) ?: []) : [];
        
        $unimplemented_names = [];
        foreach ($pbt1 as $p) {
            $name = trim(strtolower($p['Nama Kegiatan'] ?? $p['Nama Program Kerja'] ?? ''));
            if ($name !== '') {
                // Check if it was executed in Triwulan 2
                $executed_in_t2 = false;
                foreach ($pt2 as $p2) {
                    $p2_name = trim(strtolower($p2['Nama Program Kerja'] ?? $p2['Nama Kegiatan'] ?? ''));
                    if ($p2_name === $name) {
                        $executed_in_t2 = true;
                        break;
                    }
                }
                if (!$executed_in_t2) {
                    $proker_belum_terlaksana[] = $p;
                    $unimplemented_names[$name] = true;
                }
            }
        }
        
        foreach ($pbt2 as $p) {
            $name = trim(strtolower($p['Nama Kegiatan'] ?? $p['Nama Program Kerja'] ?? ''));
            if ($name !== '' && !isset($unimplemented_names[$name])) {
                if (!isset($executed_names[$name])) {
                    $proker_belum_terlaksana[] = $p;
                    $unimplemented_names[$name] = true;
                }
            }
        }
        
        $evaluasi_kinerja_pribadi = '';
        $evaluasi_anggota_internal = [];
        
        if ($lpj2) {
            $evaluasi_kinerja_pribadi = $lpj2['evaluasi_kinerja_pribadi'] ?: '';
            $evaluasi_anggota_internal = json_decode($lpj2['evaluasi_anggota_internal'] ?? '', true) ?: [];
        } elseif ($lpj1) {
            $evaluasi_kinerja_pribadi = $lpj1['evaluasi_kinerja_pribadi'] ?: '';
            $evaluasi_anggota_internal = json_decode($lpj1['evaluasi_anggota_internal'] ?? '', true) ?: [];
        }
        
        echo json_encode([
            'deskripsi' => $keadaan_objektif ?: $deskripsi,
            'keanggotaan' => [
                'ketua' => $ketua,
                'sekretaris' => $sekretaris,
                'bendahara' => $bendahara,
                'anggota' => $anggota
            ],
            'is_mubesma' => true,
            'proker_terlaksana' => $proker_terlaksana,
            'proker_belum_terlaksana' => $proker_belum_terlaksana,
            'evaluasi_kinerja_pribadi' => $evaluasi_kinerja_pribadi,
            'evaluasi_anggota_internal' => $evaluasi_anggota_internal
        ]);
        exit();
    }
    
    echo json_encode([
        'deskripsi' => $deskripsi,
        'keanggotaan' => [
            'ketua' => $ketua,
            'sekretaris' => $sekretaris,
            'bendahara' => $bendahara,
            'anggota' => $anggota
        ]
    ]);
    exit();
}

if (isset($_GET['ajax_get_berita_acara_kementerian'])) {
    require_once __DIR__ . '/config.php';
    if (!isLoggedIn()) {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Content-Type: application/json');
    $k_id = (int)$_GET['ajax_get_berita_acara_kementerian'];
    $periode_id = getUserPeriode();
    $kem = dbFetchOne("SELECT nama FROM kementerian WHERE id = ?", [$k_id], "i");
    $kem_nama = $kem ? $kem['nama'] : '';
    
    $bas = dbFetchAll("SELECT id, nomor_berita, nama_kegiatan, tanggal_kegiatan, konten_json FROM arsip_berita_acara WHERE periode_id = ? ORDER BY id DESC", [$periode_id], "i");
    $filtered = [];
    foreach ($bas as $ba) {
        $konten = json_decode($ba['konten_json'], true);
        if ($konten && isset($konten['pelaksana_tipe']) && $konten['pelaksana_tipe'] === $kem_nama) {
            $filtered[] = [
                'id' => $ba['id'],
                'judul' => $ba['nomor_berita'] . ' - ' . $ba['nama_kegiatan'],
                'nama_kegiatan' => $ba['nama_kegiatan'],
                'tanggal' => $ba['tanggal_kegiatan']
            ];
        }
    }
    echo json_encode($filtered);
    exit();
}

if (isset($_GET['ajax_get_berita_acara_id'])) {
    require_once __DIR__ . '/config.php';
    if (!isLoggedIn()) {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Content-Type: application/json');
    $ba_id = (int)$_GET['ajax_get_berita_acara_id'];
    $periode_id = getUserPeriode();
    $ba = dbFetchOne("SELECT * FROM arsip_berita_acara WHERE id = ? AND periode_id = ?", [$ba_id, $periode_id], "ii");
    if ($ba) {
        $konten = json_decode($ba['konten_json'], true) ?: [];
        $dokumentasi = [];
        if (!empty($konten['dokumentasi']) && is_array($konten['dokumentasi'])) {
            foreach ($konten['dokumentasi'] as $dok) {
                $img_path = $dok['image'] ?? '';
                if (strpos($img_path, '/var/www/html') === false) {
                    $file_path = UPLOAD_PATH . '/' . ltrim($img_path, '/');
                } else {
                    $file_path = $img_path;
                }
                $dokumentasi[] = [
                    'file_path' => $file_path,
                    'caption' => $dok['caption'] ?? 'Dokumentasi'
                ];
            }
        }
        
        $res = [
            'nama_kegiatan' => $ba['nama_kegiatan'],
            'tempat' => $ba['tempat'],
            'tanggal' => $konten['tanggal_kegiatan'] ?? '',
            'tema' => $konten['tema_kegiatan'] ?? '',
            'tujuan' => $konten['tujuan'] ?? [],
            'program_kerja' => $konten['program_kerja'] ?? '',
            'penanggung_jawab' => $konten['penanggung_jawab'] ?? '',
            'dokumentasi' => $dokumentasi
        ];
        echo json_encode($res);
    } else {
        echo json_encode(['error' => 'Not found']);
    }
    exit();
}

require_once __DIR__ . '/header.php';

function get_points_array($val) {
    if (empty($val)) {
        return [];
    }
    if (is_string($val) && strpos($val, '[') === 0) {
        $decoded = json_decode($val, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    if (is_array($val)) {
        return $val;
    }
    $str_val = trim((string)$val);
    if ($str_val === '') {
        return [];
    }
    if (strpos($str_val, "\n") !== false) {
        return array_filter(array_map('trim', explode("\n", $str_val)));
    }
    return [$str_val];
}

function normalize_points_input($val) {
    if (empty($val)) {
        return [];
    }
    if (is_string($val) && strpos($val, '[') === 0) {
        $decoded = json_decode($val, true);
        if (is_array($decoded)) {
            $cleaned = [];
            foreach ($decoded as $item) {
                $item_str = trim((string)$item);
                if ($item_str !== '') {
                    $cleaned[] = sanitizeText($item_str, 500);
                }
            }
            return $cleaned;
        }
    }
    if (is_array($val)) {
        $cleaned = [];
        foreach ($val as $item) {
            $item_str = trim((string)$item);
            if ($item_str !== '') {
                $cleaned[] = sanitizeText($item_str, 500);
            }
        }
        return $cleaned;
    }
    $str_val = trim((string)$val);
    if ($str_val === '') {
        return [];
    }
    if (strpos($str_val, "\n") !== false) {
        $lines = explode("\n", $str_val);
        $cleaned = [];
        foreach ($lines as $line) {
            $line_str = trim($line);
            if ($line_str !== '') {
                $cleaned[] = sanitizeText($line_str, 500);
            }
        }
        return $cleaned;
    }
    return [sanitizeText($str_val, 500)];
}

$periode_id = getUserPeriode();
$error = '';
$success = '';

// Handle Form Submission (Draft or Submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $kementerian_id = (int)($_POST['kementerian_id'] ?? 0);
        $triwulan = sanitizeText($_POST['triwulan'] ?? '');
        $status = sanitizeText($_POST['status'] ?? 'draft');
        $keadaan_objektif = sanitizeText($_POST['keadaan_objektif'] ?? '', 2000);
        $penutup = sanitizeText($_POST['penutup'] ?? '', 2000);
        
        $anggota_post = $_POST['anggota_lain'] ?? '';
        $anggota_cleaned = [];
        if (is_array($anggota_post)) {
            foreach ($anggota_post as $agt_item) {
                $trimmed = trim($agt_item);
                if ($trimmed !== '') {
                    $anggota_cleaned[] = sanitizeText($trimmed);
                }
            }
        } else {
            $parts = explode(',', $anggota_post);
            foreach ($parts as $p_item) {
                $trimmed = trim($p_item);
                if ($trimmed !== '') {
                    $anggota_cleaned[] = sanitizeText($trimmed);
                }
            }
        }

        $keanggotaan = [
            'ketua' => sanitizeText($_POST['anggota_ketua'] ?? ''),
            'sekretaris' => sanitizeText($_POST['anggota_sekretaris'] ?? ''),
            'bendahara' => sanitizeText($_POST['anggota_bendahara'] ?? ''),
            'anggota' => $anggota_cleaned
        ];
        
        // Proker Terlaksana
        $pt_names = $_POST['pt_name'] ?? [];
        $pt_kegiatans = $_POST['pt_kegiatan'] ?? [];
        $pt_tempats = $_POST['pt_tempat'] ?? [];
        $pt_sifats = $_POST['pt_sifat'] ?? [];
        $pt_temas = $_POST['pt_tema'] ?? [];
        $pt_tujuans = $_POST['pt_tujuan'] ?? [];
        $pt_tanggals = $_POST['pt_tanggal'] ?? [];
        $pt_pjs = $_POST['pt_pj'] ?? [];
        $pt_pesertas = $_POST['pt_peserta'] ?? [];
        $pt_evaluasis = $_POST['pt_evaluasi'] ?? [];
        
        $pt_no_budgets = $_POST['pt_no_budget'] ?? [];
        $pt_anggarans_json = $_POST['pt_anggaran'] ?? [];
        $pt_existing_dok_json = $_POST['pt_existing_dok'] ?? [];
        
        $proker_terlaksana = [];
        for ($i = 0; $i < count($pt_names); $i++) {
            if (!empty($pt_names[$i])) {
                $tidak_menggunakan_anggaran = (int)($pt_no_budgets[$i] ?? 0) === 1;
                $anggaran_txs = [];
                if (!$tidak_menggunakan_anggaran && !empty($pt_anggarans_json[$i])) {
                    $anggaran_txs = json_decode($pt_anggarans_json[$i], true) ?: [];
                    foreach ($anggaran_txs as &$tx) {
                        $tx['tanggal'] = sanitizeText($tx['tanggal'] ?? '');
                        $tx['keterangan'] = sanitizeText($tx['keterangan'] ?? '');
                        $tx['uraian'] = sanitizeText($tx['uraian'] ?? '');
                        $tx['debet'] = (float)($tx['debet'] ?? 0);
                        $tx['kredit'] = (float)($tx['kredit'] ?? 0);
                    }
                    unset($tx);
                }
                
                $dokumentasi_list = [];
                if (!empty($pt_existing_dok_json[$i])) {
                    $dokumentasi_list = json_decode($pt_existing_dok_json[$i], true) ?: [];
                    foreach ($dokumentasi_list as &$dok) {
                        $dok['file_path'] = sanitizeText($dok['file_path'] ?? '');
                        $dok['caption'] = sanitizeText($dok['caption'] ?? 'Dokumentasi');
                    }
                    unset($dok);
                }
                
                $new_file_input_name = "pt_new_dok_file_{$i}";
                if (isset($_FILES[$new_file_input_name])) {
                    $files = $_FILES[$new_file_input_name];
                    $captions = $_POST["pt_new_dok_caption_{$i}"] ?? [];
                    for ($j = 0; $j < count($files['name']); $j++) {
                        if ($files['error'][$j] === UPLOAD_ERR_OK) {
                            $single_file = [
                                'name' => $files['name'][$j],
                                'type' => $files['type'][$j],
                                'tmp_name' => $files['tmp_name'][$j],
                                'error' => $files['error'][$j],
                                'size' => $files['size'][$j]
                            ];
                            $uploaded = uploadFile($single_file, 'lpj');
                            if ($uploaded) {
                                $full_upload_path = UPLOAD_PATH . '/' . $uploaded;
                                $dokumentasi_list[] = [
                                    'file_path' => $full_upload_path,
                                    'caption' => sanitizeText($captions[$j] ?? 'Dokumentasi')
                                ];
                            }
                        }
                    }
                }
                
                $ba_id_val = isset($_POST['pt_ba_id'][$i]) ? (int)$_POST['pt_ba_id'][$i] : 0;
                $proker_terlaksana[] = [
                    'Nama Program Kerja' => sanitizeText($pt_names[$i]),
                    'Nama Kegiatan' => sanitizeText($pt_kegiatans[$i] ?? ''),
                    'Tempat Kegiatan' => sanitizeText($pt_tempats[$i] ?? ''),
                    'Sifat' => sanitizeText($pt_sifats[$i] ?? 'Internal'),
                    'Tema Kegiatan' => sanitizeText($pt_temas[$i] ?? ''),
                    'Tujuan' => normalize_points_input($pt_tujuans[$i] ?? ''),
                    'Tanggal Kegiatan' => sanitizeText($pt_tanggals[$i] ?? ''),
                    'Penanggung Jawab' => sanitizeText($pt_pjs[$i] ?? ''),
                    'Peserta Kegiatan' => normalize_points_input($pt_pesertas[$i] ?? ''),
                    'Evaluasi' => normalize_points_input($pt_evaluasis[$i] ?? ''),
                    'tidak_menggunakan_anggaran' => $tidak_menggunakan_anggaran,
                    'anggaran' => $anggaran_txs,
                    'dokumentasi' => $dokumentasi_list,
                    'berita_acara_id' => $ba_id_val
                ];
            }
        }
        
        // Proker Belum Terlaksana
        $pbt_names = $_POST['pbt_name'] ?? [];
        $pbt_sifats = $_POST['pbt_sifat'] ?? [];
        $pbt_temas = $_POST['pbt_tema'] ?? [];
        $pbt_tujuans = $_POST['pbt_tujuan'] ?? [];
        $pbt_tanggals = $_POST['pbt_tanggal'] ?? [];
        $pbt_pjs = $_POST['pbt_pj'] ?? [];
        $pbt_pesertas = $_POST['pbt_peserta'] ?? [];
        $pbt_anggarans = $_POST['pbt_anggaran'] ?? [];
        $pbt_dokuments = $_POST['pbt_dokumentasi'] ?? [];
        
        $proker_belum_terlaksana = [];
        for ($i = 0; $i < count($pbt_names); $i++) {
            if (!empty($pbt_names[$i])) {
                $proker_belum_terlaksana[] = [
                    'Nama Kegiatan' => sanitizeText($pbt_names[$i]),
                    'Sifat' => sanitizeText($pbt_sifats[$i] ?? ''),
                    'Tema Kegiatan' => sanitizeText($pbt_temas[$i] ?? ''),
                    'Tujuan Kegiatan' => sanitizeText($pbt_tujuans[$i] ?? ''),
                    'Tanggal Kegiatan' => sanitizeText($pbt_tanggals[$i] ?? ''),
                    'Penanggung Jawab' => sanitizeText($pbt_pjs[$i] ?? ''),
                    'Peserta Kegiatan' => sanitizeText($pbt_pesertas[$i] ?? ''),
                    'Anggaran' => sanitizeText($pbt_anggarans[$i] ?? ''),
                    'Dokumentasi' => sanitizeText($pbt_dokuments[$i] ?? '')
                ];
            }
        }
        
        // Evaluasi Kinerja Pribadi
        $evaluasi_kinerja_pribadi = sanitizeText($_POST['evaluasi_kinerja_pribadi'] ?? '');

        // Evaluasi Anggota dan Internal Menteri
        $eva_nama = $_POST['eva_nama'] ?? [];
        $eva_kepribadian = $_POST['eva_kepribadian'] ?? [];
        $eva_kinerja = $_POST['eva_kinerja'] ?? [];
        
        $evaluasi_anggota_internal = [];
        for ($i = 0; $i < count($eva_nama); $i++) {
            if (!empty($eva_nama[$i])) {
                $evaluasi_anggota_internal[] = [
                    'nama' => sanitizeText($eva_nama[$i]),
                    'kepribadian' => sanitizeText($eva_kepribadian[$i] ?? ''),
                    'kinerja' => sanitizeText($eva_kinerja[$i] ?? '')
                ];
            }
        }
        $evaluasi_anggota_internal_json = json_encode($evaluasi_anggota_internal);

        // Aggregate Anggaran and Dokumentasi globally for backward compatibility
        $anggaran = [];
        $dokumentasi = [];
        foreach ($proker_terlaksana as $pt) {
            if (empty($pt['tidak_menggunakan_anggaran'])) {
                foreach ($pt['anggaran'] as $tx) {
                    $anggaran[] = $tx;
                }
            }
            foreach ($pt['dokumentasi'] as $dok) {
                $dokumentasi[] = $dok;
            }
        }
        
        if ($kementerian_id <= 0 || empty($triwulan)) {
            $error = "Kementerian dan Triwulan wajib diisi.";
        } else {
            // Check if record exists
            $existing_lpj = dbFetchOne("SELECT id FROM lpj_dokumen WHERE periode_id = ? AND kementerian_id = ? AND triwulan = ?", [$periode_id, $kementerian_id, $triwulan], "iis");
            
            $keanggotaan_json = json_encode($keanggotaan);
            $proker_terlaksana_json = json_encode($proker_terlaksana);
            $proker_belum_terlaksana_json = json_encode($proker_belum_terlaksana);
            $anggaran_json = json_encode($anggaran);
            $dokumentasi_json = json_encode($dokumentasi);
            
            if ($existing_lpj) {
                $lpj_id = $existing_lpj['id'];
                try {
                    dbQuery("UPDATE lpj_dokumen SET status = ?, keanggotaan = ?, keadaan_objektif = ?, penutup = ?, proker_terlaksana = ?, proker_belum_terlaksana = ?, anggaran = ?, dokumentasi = ?, evaluasi_kinerja_pribadi = ?, evaluasi_anggota_internal = ? WHERE id = ?", 
                        [$status, $keanggotaan_json, $keadaan_objektif, $penutup, $proker_terlaksana_json, $proker_belum_terlaksana_json, $anggaran_json, $dokumentasi_json, $evaluasi_kinerja_pribadi, $evaluasi_anggota_internal_json, $lpj_id], "ssssssssssi");
                } catch (Throwable $e) {
                    if (strpos($e->getMessage(), 'evaluasi_kinerja_pribadi') !== false || strpos($e->getMessage(), 'penutup') !== false) {
                        try { dbQuery("ALTER TABLE lpj_dokumen ADD COLUMN evaluasi_kinerja_pribadi TEXT NULL"); } catch(Throwable $te){}
                        try { dbQuery("ALTER TABLE lpj_dokumen ADD COLUMN evaluasi_anggota_internal TEXT NULL"); } catch(Throwable $te){}
                        try { dbQuery("ALTER TABLE lpj_dokumen ADD COLUMN penutup TEXT NULL"); } catch(Throwable $te){}
                        dbQuery("UPDATE lpj_dokumen SET status = ?, keanggotaan = ?, keadaan_objektif = ?, penutup = ?, proker_terlaksana = ?, proker_belum_terlaksana = ?, anggaran = ?, dokumentasi = ?, evaluasi_kinerja_pribadi = ?, evaluasi_anggota_internal = ? WHERE id = ?", 
                            [$status, $keanggotaan_json, $keadaan_objektif, $penutup, $proker_terlaksana_json, $proker_belum_terlaksana_json, $anggaran_json, $dokumentasi_json, $evaluasi_kinerja_pribadi, $evaluasi_anggota_internal_json, $lpj_id], "ssssssssssi");
                    } else {
                        throw $e;
                    }
                }
            } else {
                try {
                    dbQuery("INSERT INTO lpj_dokumen (periode_id, kementerian_id, triwulan, status, keanggotaan, keadaan_objektif, penutup, proker_terlaksana, proker_belum_terlaksana, anggaran, dokumentasi, evaluasi_kinerja_pribadi, evaluasi_anggota_internal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$periode_id, $kementerian_id, $triwulan, $status, $keanggotaan_json, $keadaan_objektif, $penutup, $proker_terlaksana_json, $proker_belum_terlaksana_json, $anggaran_json, $dokumentasi_json, $evaluasi_kinerja_pribadi, $evaluasi_anggota_internal_json], "iisssssssssss");
                } catch (Throwable $e) {
                    if (strpos($e->getMessage(), 'evaluasi_kinerja_pribadi') !== false || strpos($e->getMessage(), 'penutup') !== false) {
                        try { dbQuery("ALTER TABLE lpj_dokumen ADD COLUMN evaluasi_kinerja_pribadi TEXT NULL"); } catch(Throwable $te){}
                        try { dbQuery("ALTER TABLE lpj_dokumen ADD COLUMN evaluasi_anggota_internal TEXT NULL"); } catch(Throwable $te){}
                        try { dbQuery("ALTER TABLE lpj_dokumen ADD COLUMN penutup TEXT NULL"); } catch(Throwable $te){}
                        dbQuery("INSERT INTO lpj_dokumen (periode_id, kementerian_id, triwulan, status, keanggotaan, keadaan_objektif, penutup, proker_terlaksana, proker_belum_terlaksana, anggaran, dokumentasi, evaluasi_kinerja_pribadi, evaluasi_anggota_internal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$periode_id, $kementerian_id, $triwulan, $status, $keanggotaan_json, $keadaan_objektif, $penutup, $proker_terlaksana_json, $proker_belum_terlaksana_json, $anggaran_json, $dokumentasi_json, $evaluasi_kinerja_pribadi, $evaluasi_anggota_internal_json], "iisssssssssss");
                    } else {
                        throw $e;
                    }
                }
                $lpj_id = dbLastId();
            }
            
            // === TWO-WAY SYNC: LPJ → Berita Acara ===
            // Untuk setiap proker terlaksana yang memiliki berita_acara_id,
            // update data di arsip_berita_acara agar konsisten
            foreach ($proker_terlaksana as $pt) {
                $linked_ba_id = (int)($pt['berita_acara_id'] ?? 0);
                if ($linked_ba_id > 0) {
                    $ba_row = dbFetchOne("SELECT id, konten_json FROM arsip_berita_acara WHERE id = ?", [$linked_ba_id], "i");
                    if ($ba_row) {
                        $ba_konten = json_decode($ba_row['konten_json'], true) ?: [];
                        
                        // Sync field-field yang relevan dari LPJ ke BA
                        $ba_konten['tema_kegiatan'] = $pt['Tema Kegiatan'] ?? ($ba_konten['tema_kegiatan'] ?? '');
                        $ba_konten['program_kerja'] = $pt['Nama Program Kerja'] ?? ($ba_konten['program_kerja'] ?? '');
                        $ba_konten['penanggung_jawab'] = $pt['Penanggung Jawab'] ?? ($ba_konten['penanggung_jawab'] ?? '');
                        if (!empty($pt['Tujuan']) && is_array($pt['Tujuan'])) {
                            $ba_konten['tujuan'] = $pt['Tujuan'];
                        }
                        
                        $ba_konten_json = json_encode($ba_konten);
                        $ba_nama_kegiatan = $pt['Nama Kegiatan'] ?? '';
                        $ba_tempat = $pt['Tempat Kegiatan'] ?? '';
                        
                        dbQuery("UPDATE arsip_berita_acara SET nama_kegiatan = ?, tempat = ?, konten_json = ? WHERE id = ?", 
                            [$ba_nama_kegiatan, $ba_tempat, $ba_konten_json, $linked_ba_id], "sssi");
                    }
                }
            }
            
            // Generate Word (.docx) Document
            $k_row = dbFetchOne("SELECT nama, tugas, fungsi FROM kementerian WHERE id = ?", [$kementerian_id], "i");
            $k_name = $k_row ? $k_row['nama'] : 'Kementerian';
            $k_tugas = $k_row && !empty($k_row['tugas']) ? json_decode($k_row['tugas'], true) : [];
            $k_fungsi = $k_row && !empty($k_row['fungsi']) ? json_decode($k_row['fungsi'], true) : [];
            
            $periode_row = dbFetchOne("SELECT nama, tahun_mulai, tahun_selesai FROM periode_kepengurusan WHERE id = ?", [$periode_id], "i");
            $p_name = $periode_row ? ($periode_row['nama'] . ' (' . $periode_row['tahun_mulai'] . '-' . $periode_row['tahun_selesai'] . ')') : '2025-2026';
            
            $visi_misi_row = dbFetchOne("SELECT visi, misi FROM visi_misi WHERE id = 1");
            $visi = $visi_misi_row ? ($visi_misi_row['visi'] ?? '') : '';
            $misi = $visi_misi_row && !empty($visi_misi_row['misi']) ? json_decode($visi_misi_row['misi'], true) : [];
            
            // Construct input JSON for the python generator
            $config_data = [
                'cover' => [
                    'triwulan' => $triwulan,
                    'kementerian' => $k_name,
                    'periode' => $p_name
                ],
                'keadaan_objektif' => $keadaan_objektif,
                'penutup' => $penutup,
                'keanggotaan' => $keanggotaan,
                'tugas_pokok' => $k_tugas,
                'fungsi' => $k_fungsi,
                'visi' => $visi,
                'misi' => $misi,
                'proker_terlaksana' => $proker_terlaksana,
                'proker_belum_terlaksana' => $proker_belum_terlaksana,
                'anggaran' => $anggaran,
                'dokumentasi' => $dokumentasi,
                'evaluasi_kinerja_pribadi' => $evaluasi_kinerja_pribadi,
                'evaluasi_anggota_internal' => $evaluasi_anggota_internal
            ];
            
            $tmp_json_path = tempnam(sys_get_temp_dir(), 'lpj_') . '.json';
            file_put_contents($tmp_json_path, json_encode($config_data));
            
            $output_filename = 'LPJ_' . str_replace(' ', '_', $k_name) . '_Triwulan_' . $triwulan . '_' . time() . '.docx';
            $output_filepath = UPLOAD_PATH . '/lpj/' . $output_filename;
            
            // Ensure lpj folder exists
            if (!file_exists(UPLOAD_PATH . '/lpj')) {
                mkdir(UPLOAD_PATH . '/lpj', 0777, true);
            }
            
            $manager_script = escapeshellarg(__DIR__ . '/../scratch/bem_lpj_manager.py');
            $command = "python3 {$manager_script} generate " . escapeshellarg($output_filepath) . " " . escapeshellarg($tmp_json_path) . " 2>&1";
            $output = shell_exec($command);
            
            unlink($tmp_json_path); // Clean up JSON
            
            if (file_exists($output_filepath)) {
                $db_file_path = 'lpj/' . $output_filename;
                dbQuery("UPDATE lpj_dokumen SET file_path = ? WHERE id = ?", [$db_file_path, $lpj_id], "si");
                
                // If status is submitted, let's run validation check
                if ($status === 'submitted') {
                    $val_command = "python3 {$manager_script} validate " . escapeshellarg($output_filepath) . " --json 2>&1";
                    $val_output = shell_exec($val_command);
                    $val_res = json_decode($val_output, true);
                    
                    if ($val_res && $val_res['status'] === 'SUCCESS') {
                        $success = "LPJ berhasil dibuat, divalidasi dengan status LULUS, dan disimpan!";
                    } else {
                        // Keep it saved, but warn the user about validation failures
                        $val_errors = isset($val_res['errors']) ? implode(', ', $val_res['errors']) : 'Validasi gagal.';
                        $success = "LPJ berhasil disimpan, namun status validasi: DITOLAK/GAGAL. Catatan: " . $val_errors;
                    }
                } else {
                    $success = "Draft LPJ berhasil disimpan.";
                }
                
                redirect('admin/arsip-lpj.php', $success, 'success');
                exit();
            } else {
                $error = "Gagal membuat dokumen Word (.docx). Output: " . $output;
            }
        }
        }
    } catch (Throwable $e) {
        $error = "Terjadi kesalahan internal saat menyimpan: " . $e->getMessage();
        error_log("[LPJ Save Error] " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }
}

// Check if we are loading an existing draft
$edit_id = (int)($_GET['id'] ?? 0);
$edit_data = null;
$pts = [];
if ($edit_id > 0) {
    $edit_data = dbFetchOne("SELECT * FROM lpj_dokumen WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
    if ($edit_data) {
        $pts = json_decode($edit_data['proker_terlaksana'] ?? '', true) ?: [];
        $global_anggaran = json_decode($edit_data['anggaran'] ?? '', true) ?: [];
        $global_dokumentasi = json_decode($edit_data['dokumentasi'] ?? '', true) ?: [];
        
        // Migrate old format to nested if needed
        if (!empty($pts)) {
            $has_nested = false;
            foreach ($pts as $pt) {
                if (isset($pt['anggaran']) || isset($pt['dokumentasi'])) {
                    $has_nested = true;
                    break;
                }
            }
            if (!$has_nested) {
                $pts[0]['tidak_menggunakan_anggaran'] = empty($global_anggaran);
                $pts[0]['anggaran'] = $global_anggaran;
                $pts[0]['dokumentasi'] = $global_dokumentasi;
                for ($i = 1; $i < count($pts); $i++) {
                    $pts[$i]['tidak_menggunakan_anggaran'] = true;
                    $pts[$i]['anggaran'] = [];
                    $pts[$i]['dokumentasi'] = [];
                }
            }
            // Migrate: ensure Tempat Kegiatan key exists in all entries
            foreach ($pts as &$pt_item) {
                if (!isset($pt_item['Tempat Kegiatan'])) {
                    $pt_item['Tempat Kegiatan'] = $pt_item['Tempat'] ?? '';
                }
            }
            unset($pt_item);
        }
    }
}
if (empty($pts)) {
    $pts = [[
        'Nama Program Kerja' => '',
        'Nama Kegiatan' => '',
        'Tempat Kegiatan' => '',
        'Sifat' => 'Internal',
        'Tema Kegiatan' => '',
        'Tujuan' => '',
        'Tanggal Kegiatan' => '',
        'Penanggung Jawab' => '',
        'Peserta Kegiatan' => '',
        'Evaluasi' => '',
        'tidak_menggunakan_anggaran' => true,
        'anggaran' => [],
        'dokumentasi' => []
    ]];
}

// Fetch all ministries for selection
$kementerian_list = dbFetchAll("SELECT id, nama FROM kementerian WHERE periode_id = ? ORDER BY urutan ASC", [$periode_id], "i");
$selected_triwulan = $edit_data['triwulan'] ?? (sanitizeText($_GET['triwulan'] ?? ''));
?>

<style>
    /* ===== DATE INPUT STYLING ===== */
    input[type="date"].form-control {
        color-scheme: dark;
        background: #11151c;
        border: 1px solid #35445b;
        color: #e0e0e0;
        border-radius: 4px;
        padding: 8px 12px;
    }
    input[type="date"].form-control::-webkit-calendar-picker-indicator {
        filter: invert(0.8);
        cursor: pointer;
    }
    input[type="date"].form-control:focus {
        border-color: #4A90E2;
        outline: none;
    }
    /* ===== AUTOFILL INDICATORS ===== */
    .autofill-indicator {
        font-size: 0.75rem;
        margin-top: 5px;
        transition: all 0.2s;
    }
    .autofill-indicator.autofilled {
        color: #8BB9F0;
    }
    .autofill-indicator.modified {
        color: #ffc107;
    }

    /* ===== DATE RANGE PICKER STYLING ===== */
    .date-range-wrap { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .preview-bar.pt-preview-tanggal { background: rgba(74,144,226,0.08); border-radius: 12px; padding: 12px 16px; font-size: 0.85rem; margin-top: 10px; color: #8BB9F0; border-left: 4px solid #4A90E2; min-height: 38px; display: flex; align-items: center; word-break: break-all; }

    /* ===== STEP PROGRESS BAR ===== */
    .step-progress {
        display: flex;
        justify-content: space-between;
        background: #0f1217;
        border: 1px solid #2a3545;
        border-radius: 12px;
        padding: 15px 20px;
        margin-bottom: 30px;
        overflow-x: auto;
    }
    .step-progress .step {
        flex: 1;
        text-align: center;
        font-size: 0.85rem;
        color: #666;
        position: relative;
        cursor: pointer;
        padding: 5px 10px;
        transition: all 0.2s;
        border-bottom: 2px solid transparent;
        white-space: nowrap;
    }
    .step-progress .step.active {
        color: #4A90E2;
        font-weight: bold;
        border-bottom-color: #4A90E2;
    }
    .step-progress .step.completed {
        color: #8BB9F0;
    }

    /* ===== WIZARD PANELS ===== */
    .wizard-panel {
        display: none;
    }
    .wizard-panel.active {
        display: block;
    }

    /* ===== DYNAMIC ROW STYLES ===== */
    .dynamic-row {
        background: #0f131a;
        border: 1px solid #35445b;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 35px;
        position: relative;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        transition: border-color 0.3s, box-shadow 0.3s;
    }
    .dynamic-row .btn-remove-row {
        position: absolute;
        top: 15px;
        right: 15px;
        background: #dc3545;
        color: white;
        border: none;
        border-radius: 4px;
        padding: 5px 10px;
        cursor: pointer;
        font-size: 0.75rem;
    }
    .dynamic-row .btn-remove-row:hover {
        background: #c82333;
    }

    /* ===== REORDER BUTTONS FOR PROKER ROWS ===== */
    .dynamic-row .row-reorder-controls {
        display: flex;
        gap: 6px;
        position: absolute;
        top: 15px;
        right: 120px;
        z-index: 5;
    }
    .dynamic-row .btn-reorder-row {
        background: rgba(74, 144, 226, 0.1);
        border: 1px solid rgba(74, 144, 226, 0.3);
        color: #4A90E2;
        padding: 4px 10px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 0.75rem;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        transition: all 0.2s ease;
    }
    .dynamic-row .btn-reorder-row:hover:not(:disabled) {
        background: rgba(74, 144, 226, 0.25);
        color: #fff;
    }
    .dynamic-row .btn-reorder-row:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }
    .dynamic-row .row-number-badge {
        position: absolute;
        top: -12px;
        left: 20px;
        background: #4A90E2;
        color: #fff;
        font-size: 0.72rem;
        font-weight: bold;
        padding: 2px 12px;
        border-radius: 12px;
        z-index: 5;
    }

    /* ===== PROKER SEARCH BAR ===== */
    .proker-search-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #0f1217;
        border: 1px solid #2a3545;
        border-radius: 8px;
        padding: 10px 15px;
        margin-bottom: 20px;
        transition: border-color 0.2s;
    }
    .proker-search-bar:focus-within {
        border-color: #4A90E2;
        box-shadow: 0 0 10px rgba(74, 144, 226, 0.15);
    }
    .proker-search-bar i {
        color: #666;
        font-size: 0.9rem;
    }
    .proker-search-bar input {
        flex: 1;
        background: transparent;
        border: none;
        outline: none;
        color: #fff;
        font-size: 0.88rem;
    }
    .proker-search-bar input::placeholder {
        color: #555;
    }
    .proker-search-bar .search-clear {
        background: transparent;
        border: none;
        color: #666;
        cursor: pointer;
        font-size: 0.85rem;
        padding: 2px 6px;
        border-radius: 4px;
        display: none;
    }
    .proker-search-bar .search-clear:hover {
        color: #dc3545;
        background: rgba(220, 53, 69, 0.1);
    }
    .proker-search-count {
        font-size: 0.78rem;
        color: #666;
        white-space: nowrap;
    }
    .dynamic-row.search-hidden {
        display: none !important;
    }

    /* ===== VALIDATION HIGHLIGHT & SHAKE ===== */
    @keyframes shakeHighlight {
        0%, 100% { transform: translateX(0); }
        10%, 30%, 50%, 70%, 90% { transform: translateX(-3px); }
        20%, 40%, 60%, 80% { transform: translateX(3px); }
    }
    .validation-error {
        border-color: #dc3545 !important;
        box-shadow: 0 0 12px rgba(220, 53, 69, 0.35) !important;
        animation: shakeHighlight 0.5s ease;
    }
    .validation-error-label {
        color: #dc3545 !important;
        font-weight: bold;
    }
    .validation-error-msg {
        display: block;
        color: #dc3545;
        font-size: 0.78rem;
        margin-top: 4px;
        animation: fadeIn 0.2s ease-out;
    }

    .btn-add-row-mini {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(74, 144, 226, 0.05);
        border: 1px dashed #4A90E2;
        color: #4A90E2;
        padding: 8px 16px;
        font-size: 0.8rem;
        font-weight: 600;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-top: 10px;
        width: fit-content;
    }
    .btn-add-row-mini:hover {
        background: rgba(74, 144, 226, 0.15);
        color: #fff;
        border-color: #4A90E2;
    }

    .form-row-grid {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }
    .form-row-grid + .form-row-grid {
        margin-top: 20px !important;
    }
    .dynamic-row > .form-group {
        margin-top: 20px !important;
        margin-bottom: 0;
    }

    .btn-add-row {
        background: rgba(74, 144, 226, 0.1);
        border: 1px dashed #4A90E2;
        color: #4A90E2;
        padding: 12px;
        text-align: center;
        border-radius: 8px;
        cursor: pointer;
        margin-bottom: 20px;
        transition: all 0.2s;
        font-weight: bold;
    }
    .btn-add-row:hover {
        background: rgba(74, 144, 226, 0.2);
    }

    /* ===== CARD MULTI-POIN STYLES ===== */
    .multipoint-wrapper {
        display: flex;
        flex-direction: column;
        gap: 8px;
        width: 100%;
        background: rgba(255, 255, 255, 0.01);
        border: 1px solid #2a3545;
        border-radius: 8px;
        padding: 12px;
        box-sizing: border-box;
    }
    .multipoint-list-container {
        display: flex;
        flex-direction: column;
        gap: 8px;
        min-height: 40px;
    }
    .multipoint-row {
        background: #12161f;
        border: 1px solid #2a3545;
        border-radius: 6px;
        padding: 8px 12px;
        transition: all 0.2s ease;
        position: relative;
    }
    .multipoint-row:hover {
        border-color: #4A90E2;
        background: #171d26;
    }
    .multipoint-row.dragging {
        opacity: 0.5;
        border-style: dashed;
    }
    .multipoint-row-content {
        display: flex;
        align-items: center;
        gap: 10px;
        width: 100%;
    }
    .drag-handle {
        color: #666;
        cursor: grab;
        padding: 0 4px;
        font-size: 0.9rem;
        display: flex;
        align-items: center;
        user-select: none;
    }
    .drag-handle:active {
        cursor: grabbing;
    }
    .reorder-btn-group {
        display: flex;
        flex-direction: column;
        gap: 2px;
        user-select: none;
    }
    .btn-reorder {
        background: transparent;
        border: none;
        color: #666;
        font-size: 0.65rem;
        padding: 2px;
        cursor: pointer;
        line-height: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 3px;
        transition: all 0.1s;
    }
    .btn-reorder:hover:not(:disabled) {
        color: #4A90E2;
        background: rgba(74, 144, 226, 0.1);
    }
    .btn-reorder:disabled {
        opacity: 0.2;
        cursor: not-allowed;
    }
    .point-badge {
        background: #2a3545;
        color: #fff;
        font-size: 0.75rem;
        font-weight: bold;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        user-select: none;
    }
    .point-text-container {
        flex: 1;
        display: flex;
        align-items: center;
    }
    .point-input {
        width: 100%;
        background: transparent;
        border: none;
        outline: none;
        color: #fff;
        font-size: 0.85rem;
        padding: 4px 0;
        border-bottom: 1px solid transparent;
        transition: all 0.15s;
    }
    .point-input:focus {
        border-bottom-color: #4A90E2;
    }
    .point-actions {
        display: flex;
        gap: 6px;
        align-items: center;
    }
    .point-actions button, .confirm-buttons button {
        background: transparent;
        border: none;
        color: #888;
        font-size: 0.8rem;
        cursor: pointer;
        padding: 4px;
        border-radius: 4px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s;
    }
    .point-actions button:hover {
        color: #fff;
        background: rgba(255,255,255,0.05);
    }
    .point-actions button.btn-delete-point:hover {
        color: #dc3545;
        background: rgba(220, 53, 69, 0.1);
    }
    .btn-add-point {
        align-self: flex-start;
        background: rgba(74, 144, 226, 0.05);
        border: 1px dashed #4A90E2;
        color: #4A90E2;
        border-radius: 6px;
        padding: 6px 12px;
        font-size: 0.78rem;
        font-weight: bold;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
        margin-top: 4px;
    }
    .btn-add-point:hover {
        background: rgba(74, 144, 226, 0.15);
    }
    .multipoint-row-confirm {
        display: flex;
        justify-content: space-between;
        align-items: center;
        width: 100%;
        animation: fadeIn 0.15s ease-out;
    }
    .confirm-msg {
        font-size: 0.8rem;
        color: #dc3545;
        font-weight: bold;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .confirm-buttons {
        display: flex;
        gap: 6px;
    }
    .btn-confirm-yes {
        background: #dc3545 !important;
        color: white !important;
        font-weight: bold;
        font-size: 0.75rem !important;
        padding: 4px 8px !important;
    }
    .btn-confirm-yes:hover {
        background: #bd2130 !important;
    }
    .btn-confirm-no {
        background: #2a3545 !important;
        color: white !important;
        font-size: 0.75rem !important;
        padding: 4px 8px !important;
    }
    .btn-confirm-no:hover {
        background: #354459 !important;
    }
    .required-star {
        color: #dc3545;
        font-weight: bold;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-3px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* ===== DYNAMIC ANGGOTA STYLES ===== */
    #anggotaListContainer {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 10px;
    }
    .anggota-row {
        display: flex;
        align-items: center;
        background: #12161f;
        border: 1px solid #2a3545;
        border-radius: 6px;
        padding: 4px 8px;
        transition: all 0.2s ease;
    }
    .anggota-row:hover {
        border-color: #4A90E2;
        background: #171d26;
    }
    .anggota-row input.anggota-item-input {
        background: transparent !important;
        border: none !important;
        color: #fff !important;
        padding: 4px 8px !important;
        box-shadow: none !important;
        flex: 1;
    }
    .anggota-row input.anggota-item-input:focus {
        outline: none !important;
    }
    .btn-remove-anggota {
        background: transparent !important;
        border: none !important;
        color: #e74c3c !important;
        opacity: 0.7;
        transition: all 0.2s;
        padding: 6px 10px !important;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        margin-left: auto;
    }
    .btn-remove-anggota:hover {
        opacity: 1;
        background: rgba(231, 76, 60, 0.1) !important;
        color: #ff6b6b !important;
    }
    #btnAddAnggota {
        background: rgba(74, 144, 226, 0.1) !important;
        border: 1px dashed #4A90E2 !important;
        color: #4A90E2 !important;
        padding: 6px 12px !important;
        border-radius: 6px !important;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: 500;
        margin-top: 5px;
    }
    #btnAddAnggota:hover {
        background: rgba(74, 144, 226, 0.2) !important;
    }

    .admin-form, .page-header, .step-progress, .alert {
        max-width: 1100px !important;
        margin-left: auto !important;
        margin-right: auto !important;
    }
    .proker-budget-table-wrapper {
        overflow-x: auto;
        width: 100%;
        margin-bottom: 15px;
        border: 1px solid #2a3545;
        border-radius: 8px;
    }
    .proker-budget-table {
        min-width: 850px;
        margin-bottom: 0 !important;
    }
    .proker-budget-table input.form-control {
        padding: 6px 10px;
        font-size: 0.85rem;
        height: auto;
    }

    /* New Photo Grid UI */
    .photo-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    @media (min-width: 600px) {
        .photo-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    .photo-card {
        background: #0d1015;
        border: 1px solid #2a3545;
        border-radius: 16px;
        padding: 15px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        position: relative;
    }
    
    .photo-preview-wrap {
        width: 100%;
        height: 150px;
        border: 1px solid #2a3545;
        border-radius: 10px;
        overflow: hidden;
        background: #05070a;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
    }
    
    .photo-preview-wrap img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
    }
    
    .photo-card-label {
        font-size: 0.75rem; 
        color: #aaa; 
        margin-bottom: 5px; 
        display: block; 
        font-weight: bold; 
        text-transform: uppercase;
    }
    
    /* Caption input for existing photos */
    .photo-caption-input {
        background: #0f1217 !important;
        border: 1px solid #2a3545 !important;
        color: #fff !important;
        margin-top: 5px;
    }
    .photo-caption-input:focus {
        border-color: #4A90E2 !important;
        outline: none !important;
    }
    
    /* Submit overlay/spinner */
    .submit-overlay {
        display: none;
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.75);
        backdrop-filter: blur(8px);
        z-index: 99999;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        color: #fff;
    }
    .submit-overlay-content {
        text-align: center;
        background: #121620;
        border: 1px solid #2a3545;
        border-radius: 16px;
        padding: 30px 40px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }
    .submit-overlay .spinner {
        font-size: 3rem;
        color: #4A90E2;
        margin-bottom: 20px;
    }
    .submit-overlay h3 {
        margin: 0 0 10px 0;
        font-size: 1.25rem;
        color: #fff;
    }
    .submit-overlay p {
        margin: 0;
        font-size: 0.88rem;
        color: #888;
    }
</style>

<div class="page-header">
    <h1><i class="fas fa-file-signature"></i> <?php echo $edit_data ? 'Edit' : 'Buat'; ?> Laporan Pertanggungjawaban (LPJ)</h1>
    <p>Isi formulir bertahap di bawah ini untuk menghasilkan dokumen LPJ resmi secara otomatis.</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Step Indicator -->
<div class="step-progress" id="stepProgress">
    <div class="step active" data-step="1">1. Informasi Dasar</div>
    <div class="step" data-step="2">2. Keanggotaan</div>
    <div class="step" data-step="3">3. Proker Terlaksana</div>
    <div class="step" data-step="4">4. Proker Belum Terlaksana</div>
    <div class="step step-evaluasi" data-step="5" style="display: <?php echo ($selected_triwulan === 'MUBESMA') ? 'block' : 'none'; ?>;">5. Evaluasi</div>
</div>

<form method="POST" enctype="multipart/form-data" id="lpjForm" class="admin-form">
    <?php echo csrfField(); ?>
    <input type="hidden" name="status" id="lpjStatus" value="draft">
    
    <!-- STEP 1: INFORMASI DASAR -->
    <div class="wizard-panel active" data-step="1">
        <div class="card">
            <div class="card-header"><i class="fas fa-info-circle"></i> Langkah 1: Informasi Dasar & Keadaan Objektif</div>
            <div class="card-body">
                <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 280px;">
                        <label>Kementerian</label>
                        <select name="kementerian_id" id="kementerianSelect" class="form-control" required <?php echo $edit_data ? 'disabled' : ''; ?>>
                            <option value="">-- Pilih Kementerian --</option>
                            <?php foreach ($kementerian_list as $k): ?>
                                <option value="<?php echo $k['id']; ?>" <?php echo (($edit_data['kementerian_id'] ?? 0) == $k['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($k['nama']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($edit_data): ?>
                            <input type="hidden" name="kementerian_id" value="<?php echo $edit_data['kementerian_id']; ?>">
                        <?php endif; ?>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 280px;">
                        <label>Triwulan Periode</label>
                        <select name="triwulan" id="triwulanSelect" class="form-control" required <?php echo $edit_data ? 'disabled' : ''; ?>>
                            <option value="">-- Pilih Triwulan --</option>
                            <option value="I" <?php echo ($selected_triwulan === 'I') ? 'selected' : ''; ?>>TRIWULAN I</option>
                            <option value="II" <?php echo ($selected_triwulan === 'II') ? 'selected' : ''; ?>>TRIWULAN II</option>
                            <option value="MUBESMA" <?php echo ($selected_triwulan === 'MUBESMA') ? 'selected' : ''; ?>>MUBESMA (gabungan TRIWULAN I dan TRIWULAN II)</option>
                        </select>
                        <?php if ($edit_data): ?>
                            <input type="hidden" name="triwulan" value="<?php echo htmlspecialchars($edit_data['triwulan']); ?>">
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Keadaan Objektif / Deskripsi Fungsi</label>
                    <textarea name="keadaan_objektif" id="keadaanObjektif" rows="8" class="form-control" placeholder="Deskripsikan visi, strategi, dan keadaan objektif kementerian..." required><?php echo htmlspecialchars($edit_data['keadaan_objektif'] ?? ''); ?></textarea>
                    <small>Default akan terisi visi & fungsi dari pengaturan kementerian jika Anda memilih kementerian baru.</small>
                </div>
                
                <div class="form-group" style="margin-top: 15px;">
                    <label>Deskripsi Penutup LPJ</label>
                    <textarea name="penutup" id="penutupText" rows="6" class="form-control" placeholder="Tuliskan kata penutup untuk laporan pertanggungjawaban..."><?php echo htmlspecialchars($edit_data['penutup'] ?? "Demikian Laporan Pertanggungjawaban ini kami susun sebagai bentuk pertanggungjawaban atas amanah yang telah diberikan selama satu periode kepengurusan. Kami menyadari masih banyak kekurangan dalam pelaksanaan program kerja maupun dalam koordinasi internal, namun hal tersebut menjadi bahan evaluasi dan pembelajaran untuk ke depannya.\n\nTerima kasih kepada seluruh pihak yang telah mendukung dan bekerja sama, baik dari internal maupun pihak eksternal. Semoga apa yang telah dijalankan dapat memberikan manfaat bagi mahasiswa dan lingkungan kampus secara luas."); ?></textarea>
                    <small>Penutup ini akan dicetak pada bagian akhir dokumen LPJ.</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- STEP 2: KEANGGOTAAN -->
    <div class="wizard-panel" data-step="2">
        <div class="card">
            <div class="card-header"><i class="fas fa-users"></i> Langkah 2: Data Keanggotaan BPH Kementerian</div>
            <div class="card-body">
                <p style="font-size: 0.85rem; color: #aaa; margin-bottom: 20px;">Tentukan nama penanggung jawab struktur kepemimpinan kementerian Anda.</p>
                <div id="kepengurusanAlert" style="margin-bottom: 15px; display: none;"></div>
                <?php
                $keanggotaan_val = json_decode($edit_data['keanggotaan'] ?? '', true) ?: [];
                ?>
                <div class="form-group">
                    <label>Ketua Menteri / Kepala Menteri</label>
                    <input type="text" name="anggota_ketua" id="anggotaKetua" class="form-control field-keanggotaan" value="<?php echo htmlspecialchars($keanggotaan_val['ketua'] ?? ''); ?>" required placeholder="Nama Ketua Menteri...">
                    <div class="autofill-indicator" id="indicator_ketua"></div>
                </div>
                <div class="form-group">
                    <label>Sekretaris Kementerian</label>
                    <input type="text" name="anggota_sekretaris" id="anggotaSekretaris" class="form-control field-keanggotaan" value="<?php echo htmlspecialchars($keanggotaan_val['sekretaris'] ?? ''); ?>" required placeholder="Nama Sekretaris...">
                    <div class="autofill-indicator" id="indicator_sekretaris"></div>
                </div>
                <div class="form-group">
                    <label>Bendahara Kementerian</label>
                    <input type="text" name="anggota_bendahara" id="anggotaBendahara" class="form-control field-keanggotaan" value="<?php echo htmlspecialchars($keanggotaan_val['bendahara'] ?? ''); ?>" required placeholder="Nama Bendahara...">
                    <div class="autofill-indicator" id="indicator_bendahara"></div>
                </div>
                <div class="form-group">
                    <label>Anggota Kementerian</label>
                    <div id="anggotaListContainer">
                        <?php
                        $anggota_list = [];
                        if (!empty($keanggotaan_val['anggota'])) {
                            if (is_array($keanggotaan_val['anggota'])) {
                                $anggota_list = $keanggotaan_val['anggota'];
                            } else {
                                $anggota_list = array_filter(array_map('trim', explode(',', $keanggotaan_val['anggota'])));
                            }
                        }
                        
                        if (empty($anggota_list)) {
                            ?>
                            <div class="anggota-row">
                                <input type="text" name="anggota_lain[]" class="form-control field-keanggotaan anggota-item-input" placeholder="Nama Anggota...">
                                <button type="button" class="btn-remove-anggota" onclick="removeAnggotaRow(this)"><i class="fas fa-trash-alt"></i></button>
                            </div>
                            <?php
                        } else {
                            foreach ($anggota_list as $agt):
                                ?>
                                <div class="anggota-row">
                                    <input type="text" name="anggota_lain[]" class="form-control field-keanggotaan anggota-item-input" value="<?php echo htmlspecialchars($agt); ?>" placeholder="Nama Anggota...">
                                    <button type="button" class="btn-remove-anggota" onclick="removeAnggotaRow(this)"><i class="fas fa-trash-alt"></i></button>
                                </div>
                                <?php
                            endforeach;
                        }
                        ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm mt-1" id="btnAddAnggota"><i class="fas fa-plus"></i> Tambah Anggota</button>
                    <div class="autofill-indicator" id="indicator_anggota"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- STEP 3: PROGRAM KERJA TERLAKSANA -->
    <div class="wizard-panel" data-step="3">
        <div class="card">
            <div class="card-header"><i class="fas fa-check-double"></i> Langkah 3: Program Kerja yang Terealisasi</div>
            <div class="card-body">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <p style="font-size: 0.85rem; color: #aaa; margin: 0;">Masukkan program kerja yang telah berhasil dilaksanakan pada triwulan ini. Minimal harus ada 1 proker terlaksana.</p>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openBaModal()"><i class="fas fa-file-import"></i> Ambil Data Berita Acara</button>
                </div>
                
                <div class="proker-search-bar" id="ptSearchBar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="ptSearchInput" placeholder="Cari nama program kerja terlaksana..." oninput="filterProkerRows('pt')">
                    <span class="proker-search-count" id="ptSearchCount"></span>
                    <button type="button" class="search-clear" id="ptSearchClear" onclick="clearProkerSearch('pt')">&times;</button>
                </div>
                
                <div id="ptContainer">
                    <?php
                    foreach ($pts as $idx => $pt):
                        $pt_tujuan_arr = get_points_array($pt['Tujuan'] ?? '');
                        $pt_tujuan_json = json_encode($pt_tujuan_arr);
                        $pt_peserta_arr = get_points_array($pt['Peserta Kegiatan'] ?? '');
                        $pt_peserta_json = json_encode($pt_peserta_arr);
                        $pt_evaluasi_arr = get_points_array($pt['Evaluasi'] ?? $pt['Evaluasi & Saran'] ?? '');
                        $pt_evaluasi_json = json_encode($pt_evaluasi_arr);
                        
                        $pt_no_budget = !empty($pt['tidak_menggunakan_anggaran']) ? 1 : 0;
                        $pt_anggaran_list = $pt['anggaran'] ?? [];
                        $pt_anggaran_json = json_encode($pt_anggaran_list);
                        
                        $pt_dokumentasi_list = $pt['dokumentasi'] ?? [];
                        $pt_dokumentasi_json = json_encode($pt_dokumentasi_list);
                    ?>
                    <div class="dynamic-row pt-row">
                        <div class="row-number-badge">Proker #<?php echo $idx + 1; ?></div>
                        <div class="proker-header" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px dashed #35445b; padding-bottom: 10px;" onclick="toggleProker(this)">
                            <div class="row-reorder-controls" style="position: static; display: flex; gap: 6px;">
                                <button type="button" class="btn-reorder-row" onclick="event.stopPropagation(); moveRowUp(this, 'pt')" title="Geser ke atas"><i class="fas fa-arrow-up"></i> Atas</button>
                                <button type="button" class="btn-reorder-row" onclick="event.stopPropagation(); moveRowDown(this, 'pt')" title="Geser ke bawah"><i class="fas fa-arrow-down"></i> Bawah</button>
                            </div>
                            <div class="proker-summary" style="flex: 1; margin: 0 15px; color: #8BB9F0; font-size: 0.9rem; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: none;"></div>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <?php if ($idx > 0): ?>
                                    <button type="button" class="btn-remove-row" style="position: static; margin: 0; padding: 5px 10px;" onclick="event.stopPropagation(); this.closest('.dynamic-row').remove(); reindexProkers(); updateRowReorderButtons('pt');">Hapus Proker</button>
                                <?php endif; ?>
                                <i class="fas fa-chevron-up toggle-icon" style="color: #4A90E2; font-size: 1.2rem; transition: transform 0.3s;"></i>
                            </div>
                        </div>
                        <div class="proker-body">
                        <input type="hidden" name="pt_ba_id[]" class="pt-ba-id-hidden" value="<?php echo (int)($pt['berita_acara_id'] ?? 0); ?>">
                        <div class="form-row-grid">
                            <div class="form-group">
                                <label>Nama Program Kerja</label>
                                <input type="text" name="pt_name[]" class="form-control" value="<?php echo htmlspecialchars($pt['Nama Program Kerja'] ?? ''); ?>" required placeholder="Cth: JALIN RELASI">
                            </div>
                            <div class="form-group">
                                <label>Nama Kegiatan</label>
                                <input type="text" name="pt_kegiatan[]" class="form-control" value="<?php echo htmlspecialchars($pt['Nama Kegiatan'] ?? ''); ?>" required placeholder="Cth: Menghadiri Undangan Bemnus">
                            </div>
                            <div class="form-group">
                                <label>Tempat Kegiatan</label>
                                <input type="text" name="pt_tempat[]" class="form-control" value="<?php echo htmlspecialchars($pt['Tempat Kegiatan'] ?? $pt['Tempat'] ?? ''); ?>" required placeholder="Cth: Aula Kampus / Zoom Meeting">
                            </div>
                            <div class="form-group">
                                <label>Sifat</label>
                                <input type="text" name="pt_sifat[]" class="form-control" value="<?php echo htmlspecialchars($pt['Sifat'] ?? 'Internal'); ?>" placeholder="Cth: Internal / Eksternal">
                            </div>
                            <div class="form-group">
                                <label>Tema Kegiatan</label>
                                <input type="text" name="pt_tema[]" class="form-control" value="<?php echo htmlspecialchars($pt['Tema Kegiatan'] ?? ''); ?>" placeholder="Tema...">
                            </div>
                        </div>
                        <div class="form-row-grid" style="margin-top: 10px;">
                            <div class="form-group" style="grid-column: span 2;">
                                <label>Tujuan Kegiatan <span class="required-star">*</span></label>
                                <div class="multipoint-wrapper">
                                    <input type="hidden" name="pt_tujuan[]" class="pt-tujuan-hidden" value="<?php echo htmlspecialchars($pt_tujuan_json); ?>">
                                    <div class="multipoint-list-container" data-placeholder="Tuliskan satu tujuan kegiatan..."></div>
                                    <button type="button" class="btn-add-point" onclick="addNewPointField(this)"><i class="fas fa-plus"></i> Tambah Poin</button>
                                </div>
                            </div>
                            <div class="form-group pt-date-group">
                                <label>Tanggal Kegiatan</label>
                                <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap;">
                                    <input type="date" class="form-control pt-tgl-mulai" onchange="formatTanggalRangePt(this)" style="flex: 1; min-width: 130px;">
                                    <span style="color:var(--text-muted); font-size: 0.8rem;">selama</span>
                                    <div style="display:flex; gap:5px; align-items:center;">
                                        <select class="form-control pt-durasi-hari" onchange="handleDurasiChangePt(this)" style="padding: 8px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: #fff; cursor: pointer; outline:none; height: auto;">
                                            <option value="1">1 Hari</option>
                                            <option value="2">2 Hari</option>
                                            <option value="3">3 Hari</option>
                                            <option value="4">4 Hari</option>
                                            <option value="5">5 Hari</option>
                                            <option value="custom">Custom...</option>
                                        </select>
                                        <input type="number" class="form-control pt-custom-hari" min="1" value="1" oninput="formatTanggalRangePt(this)" style="display:none; width: 60px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: #fff; outline:none; height: auto;">
                                        <span class="pt-label-hari" style="color:var(--text-muted); font-size: 0.8rem; display:none;">Hari</span>
                                    </div>
                                </div>
                                <input type="hidden" name="pt_tanggal[]" class="pt-out-tanggal" value="<?php echo htmlspecialchars($pt['Tanggal Kegiatan'] ?? ''); ?>">
                                <div class="preview-bar pt-preview-tanggal" style="margin-top: 10px;"><?php echo htmlspecialchars($pt['Tanggal Kegiatan'] ?? '—'); ?></div>
                            </div>
                        </div>
                        <div class="form-row-grid" style="margin-top: 10px;">
                            <div class="form-group">
                                <label>Penanggung Jawab</label>
                                <input type="text" name="pt_pj[]" class="form-control" value="<?php echo htmlspecialchars($pt['Penanggung Jawab'] ?? ''); ?>" placeholder="Nama PJ...">
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 10px;">
                            <label>Peserta Kegiatan <span class="required-star">*</span></label>
                            <div class="multipoint-wrapper">
                                <input type="hidden" name="pt_peserta[]" class="pt-peserta-hidden" value="<?php echo htmlspecialchars($pt_peserta_json); ?>">
                                <div class="multipoint-list-container" data-placeholder="Tuliskan satu jenis peserta kegiatan..."></div>
                                <button type="button" class="btn-add-point" onclick="addNewPointField(this)"><i class="fas fa-plus"></i> Tambah Poin</button>
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 10px; margin-bottom: 0;">
                            <label>Evaluasi & Saran <span class="required-star">*</span></label>
                            <div class="multipoint-wrapper">
                                <input type="hidden" name="pt_evaluasi[]" class="pt-evaluasi-hidden" value="<?php echo htmlspecialchars($pt_evaluasi_json); ?>">
                                <div class="multipoint-list-container" data-placeholder="Tuliskan satu poin evaluasi atau saran..."></div>
                                <button type="button" class="btn-add-point" onclick="addNewPointField(this)"><i class="fas fa-plus"></i> Tambah Poin</button>
                            </div>
                        </div>
                        
                        <!-- Realisasi Anggaran Sub-section -->
                        <hr style="border: 0; border-top: 1px solid #2a3545; margin: 20px 0;">
                        <div class="proker-sub-section">
                            <h4 style="color: #8BB9F0; margin-bottom: 10px;"><i class="fas fa-wallet"></i> Realisasi Anggaran Proker</h4>
                            
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label class="checkbox-container" style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #ccc;">
                                    <input type="checkbox" class="pt-no-budget-check" <?php echo $pt_no_budget ? 'checked' : ''; ?> onchange="toggleProkerBudget(this)">
                                    Proker ini tidak menggunakan anggaran
                                </label>
                                <input type="hidden" name="pt_no_budget[]" class="pt-no-budget-hidden" value="<?php echo $pt_no_budget; ?>">
                            </div>
                            
                            <div class="proker-budget-table-wrapper" style="<?php echo $pt_no_budget ? 'display: none;' : ''; ?>">
                                <table class="admin-table proker-budget-table" style="width: 100%;">
                                    <thead>
                                        <tr>
                                            <th style="width: 120px;">Tanggal</th>
                                            <th>Keterangan</th>
                                            <th>Uraian Transaksi</th>
                                            <th style="width: 120px;">Debet (Pemasukan)</th>
                                            <th style="width: 120px;">Kredit (Pengeluaran)</th>
                                            <th style="width: 120px; text-align: right;">Saldo</th>
                                            <th style="width: 40px; text-align: center;">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="proker-budget-container">
                                        <?php
                                        if (empty($pt_anggaran_list)) {
                                            $pt_anggaran_list = [['tanggal' => '', 'keterangan' => '', 'uraian' => 'Saldo Awal', 'debet' => 0, 'kredit' => 0]];
                                        }
                                        foreach ($pt_anggaran_list as $a_idx => $ang):
                                        ?>
                                        <tr class="pt-budget-row">
                                            <td><input type="text" class="form-control pt-bud-tanggal" value="<?php echo htmlspecialchars($ang['tanggal'] ?? ''); ?>" placeholder="Tanggal" oninput="serializeProkerBudgetTable(this)"></td>
                                            <td><input type="text" class="form-control pt-bud-keterangan" value="<?php echo htmlspecialchars($ang['keterangan'] ?? ''); ?>" placeholder="Keterangan" oninput="serializeProkerBudgetTable(this)"></td>
                                            <td><input type="text" class="form-control pt-bud-uraian" value="<?php echo htmlspecialchars($ang['uraian'] ?? ''); ?>" placeholder="Uraian" required oninput="serializeProkerBudgetTable(this)"></td>
                                            <td><input type="number" step="1" class="form-control pt-bud-debet" value="<?php echo (float)($ang['debet'] ?? 0); ?>" oninput="serializeProkerBudgetTable(this)"></td>
                                            <td><input type="number" step="1" class="form-control pt-bud-kredit" value="<?php echo (float)($ang['kredit'] ?? 0); ?>" oninput="serializeProkerBudgetTable(this)"></td>
                                            <td style="text-align: right; font-weight: bold; color: #8BB9F0; font-family: monospace;" class="pt-bud-saldo-text">Rp 0</td>
                                            <td style="text-align: center;">
                                                <?php if ($a_idx > 0): ?>
                                                    <button type="button" class="btn-remove-img" style="width: 28px; height: 28px; background: #dc3545;" onclick="removeBudgetRow(this)"><i class="fas fa-times"></i></button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                                    <div class="btn-add-row-mini" onclick="addProkerBudgetRow(this)"><i class="fas fa-plus"></i> Tambah Transaksi</div>
                                    <div class="btn-import-excel-mini" onclick="toggleExcelImport(this)" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(40, 167, 69, 0.1); border: 1px dashed #28a745; color: #28a745; padding: 6px 12px; border-radius: 6px; font-size: 0.78rem; font-weight: bold; cursor: pointer; transition: all 0.2s; user-select: none;"><i class="fas fa-file-excel"></i> Import Excel / Word</div>
                                </div>
                                <div class="excel-import-wrapper" style="display: none; margin-top: 15px; background: rgba(0, 0, 0, 0.2); border: 1px solid #2a3545; border-radius: 8px; padding: 15px; box-sizing: border-box; width: 100%;">
                                    <label style="display: block; font-size: 0.85rem; color: #aaa; margin-bottom: 8px;">
                                        Salin tabel dari Excel atau Word, lalu tempel (Ctrl+V) di bawah ini (Kolom: Tanggal, Keterangan, Uraian, Debet, Kredit):
                                    </label>
                                    <textarea class="form-control excel-import-textarea" rows="4" placeholder="Tempel tabel di sini...&#10;Contoh format baris:&#10;12 April 2026&#9;Internal&#9;Konsumsi Panitia&#9;0&#9;150000" style="font-family: monospace; font-size: 0.8rem; background: #0f1217; color: #fff; border-color: #2a3545; margin-bottom: 10px; width: 100%; box-sizing: border-box; resize: vertical;"></textarea>
                                    <div style="display: flex; gap: 10px;">
                                        <button type="button" class="btn-primary btn-small" onclick="processExcelImport(this)" style="padding: 6px 12px; font-size: 0.8rem; background: #28a745; border-color: #28a745; color: #fff;">Proses Import</button>
                                        <button type="button" class="btn-secondary btn-small" onclick="toggleExcelImport(this)" style="padding: 6px 12px; font-size: 0.8rem; background: #333; border-color: #444; color: #ccc;">Batal</button>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="pt_anggaran[]" class="pt-anggaran-hidden" value="<?php echo htmlspecialchars($pt_anggaran_json); ?>">
                        </div>
                        
                        <!-- Dokumentasi Kegiatan Sub-section -->
                        <hr style="border: 0; border-top: 1px solid #2a3545; margin: 20px 0;">
                        <div class="proker-sub-section">
                            <h4 style="color: #8BB9F0; margin-bottom: 10px;"><i class="fas fa-camera"></i> Dokumentasi Kegiatan</h4>
                            
                            <div class="proker-existing-photos-grid photo-grid" style="margin-bottom: 15px;">
                                <?php foreach ($pt_dokumentasi_list as $p_idx => $photo): ?>
                                    <div class="photo-item photo-card" data-path="<?php echo htmlspecialchars($photo['file_path']); ?>">
                                        <div class="photo-card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px;">
                                            <label style="color:#8BB9F0; font-weight:bold; font-size:0.8rem; margin:0;">Foto Slot <?php echo $p_idx + 1; ?></label>
                                            <button type="button" class="btn-remove-photo" style="background:none; border:none; color:#ff4d4d; cursor:pointer; font-size: 0.8rem;" onclick="removeExistingPhoto(this)"><i class="fas fa-times"></i> Hapus Slot</button>
                                        </div>
                                        <div class="photo-preview-wrap">
                                            <img src="<?php echo file_exists($photo['file_path']) ? str_replace('/var/www/html/bem/', BASE_URL, $photo['file_path']) : uploadUrl(basename($photo['file_path'])); ?>">
                                        </div>
                                        <div class="form-group" style="margin-top: 15px;">
                                            <label class="photo-card-label">Caption Foto</label>
                                            <input type="text" class="form-control photo-caption-input" style="font-size: 0.8rem; padding: 10px;" value="<?php echo htmlspecialchars($photo['caption'] ?? 'Dokumentasi'); ?>" oninput="serializeProkerPhotos(this)">
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="pt_existing_dok[]" class="pt-existing-dok-hidden" value="<?php echo htmlspecialchars($pt_dokumentasi_json); ?>">
                            
                            <div class="proker-new-photos-container" style="margin-top: 10px;"></div>
                            <div class="btn-add-row-mini" onclick="addProkerNewPhotoRow(this)"><i class="fas fa-plus"></i> Tambah Foto Baru</div>
                        </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="btn-add-row" onclick="addProkerTerlaksana()"><i class="fas fa-plus"></i> Tambah Program Kerja Terlaksana</div>
            </div>
        </div>
    </div>
    
    <!-- STEP 4: PROGRAM KERJA BELUM TEREALISASI -->
    <div class="wizard-panel" data-step="4">
        <div class="card">
            <div class="card-header"><i class="fas fa-calendar-times"></i> Langkah 4: Program Kerja Belum Terealisasi</div>
            <div class="card-body">
                <p style="font-size: 0.85rem; color: #aaa; margin-bottom: 20px;">Masukkan program kerja yang belum terlaksana atau tertunda beserta target rencana atau alasan kendala.</p>
                
                <div class="proker-search-bar" id="pbtSearchBar">
                    <i class="fas fa-search"></i>
                    <input type="text" id="pbtSearchInput" placeholder="Cari nama program kerja belum terlaksana..." oninput="filterProkerRows('pbt')">
                    <span class="proker-search-count" id="pbtSearchCount"></span>
                    <button type="button" class="search-clear" id="pbtSearchClear" onclick="clearProkerSearch('pbt')">&times;</button>
                </div>
                
                <div id="pbtContainer">
                    <?php
                    $pbts = json_decode($edit_data['proker_belum_terlaksana'] ?? '', true) ?: [];
                    foreach ($pbts as $idx => $pbt):
                    ?>
                    <div class="dynamic-row pbt-row">
                        <div class="row-number-badge">Proker #<?php echo $idx + 1; ?></div>
                        <div class="proker-header" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px dashed #35445b; padding-bottom: 10px;" onclick="toggleProker(this)">
                            <div class="row-reorder-controls" style="position: static; display: flex; gap: 6px;">
                                <button type="button" class="btn-reorder-row" onclick="event.stopPropagation(); moveRowUp(this, 'pbt')" title="Geser ke atas"><i class="fas fa-arrow-up"></i> Atas</button>
                                <button type="button" class="btn-reorder-row" onclick="event.stopPropagation(); moveRowDown(this, 'pbt')" title="Geser ke bawah"><i class="fas fa-arrow-down"></i> Bawah</button>
                            </div>
                            <div class="proker-summary" style="flex: 1; margin: 0 15px; color: #f39c12; font-size: 0.9rem; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: none;"></div>
                            <div style="display: flex; gap: 10px; align-items: center;">
                                <button type="button" class="btn-remove-row" style="position: static; margin: 0; padding: 5px 10px;" onclick="event.stopPropagation(); this.closest('.dynamic-row').remove(); updateRowReorderButtons('pbt');">Hapus</button>
                                <i class="fas fa-chevron-up toggle-icon" style="color: #4A90E2; font-size: 1.2rem; transition: transform 0.3s;"></i>
                            </div>
                        </div>
                        <div class="proker-body">
                        <div class="form-row-grid">
                            <div class="form-group">
                                <label>Nama Kegiatan</label>
                                <input type="text" name="pbt_name[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Nama Kegiatan'] ?? ''); ?>" required placeholder="Nama kegiatan...">
                            </div>
                            <div class="form-group">
                                <label>Sifat</label>
                                <input type="text" name="pbt_sifat[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Sifat'] ?? ''); ?>" placeholder="Sifat...">
                            </div>
                            <div class="form-group">
                                <label>Tema Kegiatan</label>
                                <input type="text" name="pbt_tema[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Tema Kegiatan'] ?? ''); ?>" placeholder="Tema...">
                            </div>
                        </div>
                        <div class="form-row-grid" style="margin-top: 10px;">
                            <div class="form-group" style="grid-column: span 2;">
                                <label>Tujuan Kegiatan</label>
                                <input type="text" name="pbt_tujuan[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Tujuan Kegiatan'] ?? ''); ?>" placeholder="Tujuan...">
                            </div>
                            <div class="form-group">
                                <label>Target Tanggal Rencana</label>
                                <input type="date" name="pbt_tanggal[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Tanggal Kegiatan'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-row-grid" style="margin-top: 10px;">
                            <div class="form-group">
                                <label>Penanggung Jawab</label>
                                <input type="text" name="pbt_pj[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Penanggung Jawab'] ?? ''); ?>" placeholder="PJ...">
                            </div>
                            <div class="form-group">
                                <label>Peserta Rencana</label>
                                <input type="text" name="pbt_peserta[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Peserta Kegiatan'] ?? ''); ?>" placeholder="Peserta...">
                            </div>
                            <div class="form-group">
                                <label>Anggaran Rencana</label>
                                <input type="text" name="pbt_anggaran[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Anggaran'] ?? ''); ?>" placeholder="Anggaran...">
                            </div>
                        </div>
                        <div class="form-group" style="margin-top: 10px; margin-bottom: 0;">
                            <label>Hambatan & Kendala Dokumentasi</label>
                            <textarea name="pbt_dokumentasi[]" rows="2" class="form-control" placeholder="Jelaskan alasan kendala penundaan kegiatan..."><?php echo htmlspecialchars($pbt['Dokumentasi'] ?? ''); ?></textarea>
                        </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="btn-add-row" onclick="addProkerBelumTerlaksana()"><i class="fas fa-plus"></i> Tambah Proker Belum Terealisasi</div>
            </div>
        </div>
    </div>
    

    <!-- STEP 5: EVALUASI KINERJA & ANGGOTA (Only for MUBESMA) -->
    <div class="wizard-panel wizard-panel-evaluasi" data-step="5">
        <div class="card">
            <div class="card-header"><i class="fas fa-users-cog"></i> Langkah 5: Evaluasi Kinerja Pribadi & Anggota</div>
            <div class="card-body">
                <div class="form-group">
                    <label for="evaluasiKinerjaPribadi" style="font-weight: bold;">Evaluasi Kinerja Pribadi</label>
                    <p style="font-size: 0.85rem; color: #aaa; margin-bottom: 10px;">Berikan deskripsi evaluasi kinerja pribadi Anda sebagai Menteri/Ketua selama periode triwulan berjalan.</p>
                    <textarea name="evaluasi_kinerja_pribadi" id="evaluasiKinerjaPribadi" rows="5" class="form-control" placeholder="Tuliskan evaluasi kinerja pribadi secara rinci..."><?php echo htmlspecialchars($edit_data['evaluasi_kinerja_pribadi'] ?? ''); ?></textarea>
                </div>
                
                <hr style="border: 0; border-top: 1px solid #3a4555; margin: 30px 0;">
                
                <div class="form-group">
                    <label style="font-weight: bold; margin-bottom: 5px; display: block;">Evaluasi Anggota & Internal Menteri</label>
                    <p style="font-size: 0.85rem; color: #aaa; margin-bottom: 15px;">Tabel evaluasi ini otomatis memuat nama-nama pengurus kementerian dari Langkah 2. Berikan penilaian deskriptif mengenai Kepribadian dan Kinerja masing-masing anggota.</p>
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped" style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                            <thead>
                                <tr style="background-color: #2a3545; color: #fff;">
                                    <th style="width: 50px; text-align: center; padding: 10px; border: 1px solid #3a4555;">No</th>
                                    <th style="width: 250px; text-align: left; padding: 10px; border: 1px solid #3a4555;">Nama Anggota</th>
                                    <th style="text-align: left; padding: 10px; border: 1px solid #3a4555;">Kepribadian</th>
                                    <th style="text-align: left; padding: 10px; border: 1px solid #3a4555;">Kinerja</th>
                                </tr>
                            </thead>
                            <tbody id="evaluasiAnggotaTableBody">
                                <!-- Will be populated dynamically by JavaScript syncEvaluasiAnggota() -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Wizard Actions Buttons -->
    <div style="display: flex; justify-content: space-between; margin-top: 30px;">
        <button type="button" class="btn-secondary" id="btnPrev" style="visibility: hidden;" onclick="prevStep()"><i class="fas fa-arrow-left"></i> Sebelumnya</button>
        
        <div style="display: flex; gap: 10px;">
            <button type="button" class="btn-secondary" onclick="saveDraft()"><i class="fas fa-save"></i> Simpan Draft</button>
            <button type="button" class="btn-primary" id="btnNext" onclick="nextStep()">Selanjutnya <i class="fas fa-arrow-right"></i></button>
            <button type="submit" class="btn-primary" id="btnSubmitForm" style="display: none;" onclick="submitLpj()"><i class="fas fa-paper-plane"></i> Simpan & Kirim LPJ</button>
        </div>
    </div>
    <!-- MODAL AMBIL DATA BERITA ACARA -->
    <div id="baModal" class="submit-overlay" style="display:none; align-items:center; justify-content:center; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:99999;">
        <div class="submit-overlay-content" style="background:#1a202c; padding:30px; border-radius:12px; width:450px; max-width:90%; text-align:left;">
            <h3 style="margin-top:0;"><i class="fas fa-file-import"></i> Pilih Berita Acara</h3>
            <p style="font-size:0.9rem; color:#aaa; margin-bottom: 20px;">Pilih data dari arsip Berita Acara untuk ditambahkan sebagai Proker Terlaksana baru.</p>
            <div class="form-group">
                <select id="baSelect" class="form-control" style="font-size:0.9rem; padding:10px;">
                    <option value="">-- Memuat Data... --</option>
                </select>
            </div>
            <div style="margin-top: 25px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('baModal').style.display='none'">Batal</button>
                <button type="button" class="btn-primary" id="btnImportBa" onclick="importBaData()">Gunakan Data</button>
            </div>
        </div>
    </div>

</div>

<script>
    const HARI_ID  = ['Minggu','Senin','Selasa','Rabu','Kamis',"Jum'at",'Sabtu'];
    const BULAN_ID = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    const MONTH_MAP = {
        'januari': 0, 'februari': 1, 'maret': 2, 'april': 3, 'mei': 4, 'juni': 5,
        'juli': 6, 'agustus': 7, 'september': 8, 'oktober': 9, 'november': 10, 'desember': 11
    };

    function parseTanggalRange(str) {
        if (!str) return null;
        str = str.trim();

        // 1. Check if YYYY-MM-DD
        if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
            return { start: str, duration: 1 };
        }

        // 2. Check if it's the long range format with " – " or " - " (across months/years)
        const partsDouble = str.split(/\s+[\–\-]\s+/);
        if (partsDouble.length === 2) {
            const p1 = parseSingleDateString(partsDouble[0]);
            const p2 = parseSingleDateString(partsDouble[1]);
            if (p1 && p2) {
                const d1 = new Date(p1.year, p1.month, p1.day);
                const d2 = new Date(p2.year, p2.month, p2.day);
                const diffTime = Math.abs(d2 - d1);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
                return {
                    start: formatDateIso(d1),
                    duration: diffDays
                };
            }
        }

        // 3. Check if it's single day or same-month range format
        const commaParts = str.split(',');
        if (commaParts.length >= 2) {
            const datePart = commaParts.slice(1).join(',').trim();
            const spaceParts = datePart.split(/\s+/);
            if (spaceParts.length >= 3) {
                const dayRange = spaceParts[0];
                const monthName = spaceParts[1].toLowerCase();
                const year = parseInt(spaceParts[2]);
                const month = MONTH_MAP[monthName];

                if (month !== undefined && !isNaN(year)) {
                    const days = dayRange.split(/[\-\–]/);
                    if (days.length === 2) {
                        const startDay = parseInt(days[0]);
                        const endDay = parseInt(days[1]);
                        if (!isNaN(startDay) && !isNaN(endDay)) {
                            const d1 = new Date(year, month, startDay);
                            const duration = endDay - startDay + 1;
                            return {
                                start: formatDateIso(d1),
                                duration: duration > 0 ? duration : 1
                            };
                        }
                    } else if (days.length === 1) {
                        const startDay = parseInt(days[0]);
                        if (!isNaN(startDay)) {
                            const d1 = new Date(year, month, startDay);
                            return {
                                start: formatDateIso(d1),
                                duration: 1
                            };
                        }
                    }
                }
            }
        }
        return null;
    }

    function parseSingleDateString(str) {
        const commaParts = str.split(',');
        const target = commaParts.length > 1 ? commaParts[1].trim() : commaParts[0].trim();
        const parts = target.split(/\s+/);
        if (parts.length >= 3) {
            const day = parseInt(parts[0]);
            const monthName = parts[1].toLowerCase();
            const year = parseInt(parts[2]);
            const month = MONTH_MAP[monthName];
            if (!isNaN(day) && month !== undefined && !isNaN(year)) {
                return { day, month, year };
            }
        }
        return null;
    }

    function formatDateIso(d) {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    function initDateRangePickerPt(row, rawValue) {
        const tglMulaiInput = row.querySelector('.pt-tgl-mulai');
        const durasiSelect = row.querySelector('.pt-durasi-hari');
        const customInput = row.querySelector('.pt-custom-hari');
        const labelHari = row.querySelector('.pt-label-hari');
        const outHidden = row.querySelector('.pt-out-tanggal');
        const previewDiv = row.querySelector('.pt-preview-tanggal');

        if (!outHidden || !previewDiv) return;

        outHidden.value = rawValue || '';
        previewDiv.innerText = rawValue || '—';

        if (rawValue) {
            const parsed = parseTanggalRange(rawValue);
            if (parsed) {
                tglMulaiInput.value = parsed.start;
                const durVal = parsed.duration;
                if (durVal >= 1 && durVal <= 5) {
                    durasiSelect.value = String(durVal);
                    customInput.style.display = 'none';
                    labelHari.style.display = 'none';
                } else {
                    durasiSelect.value = 'custom';
                    customInput.value = String(durVal);
                    customInput.style.display = 'inline-block';
                    labelHari.style.display = 'inline';
                }
            }
        }
    }

    function handleDurasiChangePt(selectEl) {
        const row = selectEl.closest('.pt-date-group');
        const custom = row.querySelector('.pt-custom-hari');
        const label = row.querySelector('.pt-label-hari');
        
        if (selectEl.value === 'custom') {
            custom.style.display = 'inline-block';
            label.style.display = 'inline';
        } else {
            custom.style.display = 'none';
            label.style.display = 'none';
        }
        formatTanggalRangePt(selectEl);
    }

    function formatTanggalRangePt(element) {
        const row = element.closest('.pt-date-group');
        if (!row) return;

        const tglMulaiInput = row.querySelector('.pt-tgl-mulai');
        const durasiSelect = row.querySelector('.pt-durasi-hari');
        const customInput = row.querySelector('.pt-custom-hari');
        const outHidden = row.querySelector('.pt-out-tanggal');
        const previewDiv = row.querySelector('.pt-preview-tanggal');

        const mulai = tglMulaiInput.value;
        if (!mulai) {
            outHidden.value = '';
            previewDiv.innerText = '—';
            return;
        }

        let jmlHari = parseInt(durasiSelect.value);
        if (durasiSelect.value === 'custom') {
            jmlHari = parseInt(customInput.value) || 1;
        }

        const d1 = new Date(mulai + 'T00:00:00');
        let result = '';

        if (jmlHari <= 1) {
            result = HARI_ID[d1.getDay()] + ', ' + d1.getDate() + ' ' + BULAN_ID[d1.getMonth()] + ' ' + d1.getFullYear();
        } else {
            const d2 = new Date(d1);
            d2.setDate(d1.getDate() + (jmlHari - 1));

            const hari = HARI_ID[d1.getDay()] === HARI_ID[d2.getDay()] ? HARI_ID[d1.getDay()] : HARI_ID[d1.getDay()] + '-' + HARI_ID[d2.getDay()];
            const bln1 = BULAN_ID[d1.getMonth()], bln2 = BULAN_ID[d2.getMonth()];
            const tgl  = bln1 === bln2 && d1.getFullYear() === d2.getFullYear()
                ? d1.getDate() + '-' + d2.getDate() + ' ' + bln1 + ' ' + d1.getFullYear()
                : d1.getDate() + ' ' + bln1 + ' ' + d1.getFullYear() + ' – ' + d2.getDate() + ' ' + bln2 + ' ' + d2.getFullYear();
            result = hari + ', ' + tgl;
        }

        outHidden.value = result;
        previewDiv.innerText = result;
    }

    window.initialEvaluasiAnggota = <?php echo json_encode(json_decode($edit_data['evaluasi_anggota_internal'] ?? '', true) ?: []); ?>;
    let currentStep = 1;
    
    let lastFetchedKementerianId = <?php echo $edit_data ? (int)$edit_data['kementerian_id'] : 'null'; ?>;

    // Dynamic totalSteps: 5 if MUBESMA, 4 otherwise
    function isMubesma() {
        const sel = document.getElementById('triwulanSelect');
        return sel && sel.value === 'MUBESMA';
    }
    function getTotalSteps() {
        return isMubesma() ? 5 : 4;
    }

    // Show/hide evaluasi tab and panel based on triwulan
    function updateEvaluasiVisibility() {
        const mubesma = isMubesma();
        const evalStep = document.querySelector('.step-evaluasi');
        const evalPanel = document.querySelector('.wizard-panel-evaluasi');
        if (evalStep) evalStep.style.display = mubesma ? 'block' : 'none';
        // If currently on step 5 and not mubesma, go back to step 4
        if (!mubesma && currentStep === 5) {
            showStep(4);
        }
    }

    function showStep(step) {
        const totalSteps = getTotalSteps();
        // Clamp step to valid range
        if (step > totalSteps) step = totalSteps;
        if (step < 1) step = 1;

        document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.step-progress .step').forEach(s => s.classList.remove('active'));
        
        const targetPanel = document.querySelector(`.wizard-panel[data-step="${step}"]`);
        const targetStep = document.querySelector(`.step-progress .step[data-step="${step}"]`);
        if (targetPanel) targetPanel.classList.add('active');
        if (targetStep) targetStep.classList.add('active');
        
        // Handle Prev/Next buttons
        const btnPrev = document.getElementById('btnPrev');
        const btnNext = document.getElementById('btnNext');
        const btnSubmit = document.getElementById('btnSubmitForm');
        
        btnPrev.style.visibility = step === 1 ? 'hidden' : 'visible';
        
        if (step === totalSteps) {
            btnNext.style.display = 'none';
            btnSubmit.style.display = 'inline-block';
        } else {
            btnNext.style.display = 'inline-block';
            btnSubmit.style.display = 'none';
        }
        
        currentStep = step;
        
        // Fetch membership data when Step 2 is shown
        if (step === 2) {
            const kemSelect = document.getElementById('kementerianSelect');
            const kId = kemSelect ? kemSelect.value : '';
            if (kId && kId !== lastFetchedKementerianId) {
                const triwulan = document.getElementById('triwulanSelect').value;
                if (triwulan === 'MUBESMA') {
                    checkAndFetchMubesma();
                } else {
                    fetchKepengurusan(kId);
                }
            }
        }
        
        if (step === 5 && isMubesma()) {
            syncEvaluasiAnggota(false);
        }

        // Scroll to top of form
        window.scrollTo({ top: document.getElementById('stepProgress').offsetTop - 20, behavior: 'smooth' });
    }
    
    // --- Enhanced Validation with Auto-Scroll & Highlight ---
    function clearAllValidationErrors() {
        document.querySelectorAll('.validation-error').forEach(el => {
            el.classList.remove('validation-error');
        });
        document.querySelectorAll('.validation-error-msg').forEach(el => el.remove());
        document.querySelectorAll('.validation-error-label').forEach(el => {
            el.classList.remove('validation-error-label');
        });
    }

    function markFieldInvalid(field, message) {
        field.classList.add('validation-error');
        // Find label
        const formGroup = field.closest('.form-group');
        if (formGroup) {
            const label = formGroup.querySelector('label');
            if (label) label.classList.add('validation-error-label');
            // Add error message if not already present
            if (!formGroup.querySelector('.validation-error-msg')) {
                const msg = document.createElement('small');
                msg.className = 'validation-error-msg';
                msg.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;
                field.parentNode.insertBefore(msg, field.nextSibling);
            }
        }
        // Remove error on input
        field.addEventListener('input', function onFix() {
            if (field.value.trim()) {
                field.classList.remove('validation-error');
                if (formGroup) {
                    const label = formGroup.querySelector('label');
                    if (label) label.classList.remove('validation-error-label');
                    const msg = formGroup.querySelector('.validation-error-msg');
                    if (msg) msg.remove();
                }
                field.removeEventListener('input', onFix);
            }
        }, { passive: true });
    }

    function markMultipointInvalid(wrapper, message) {
        wrapper.classList.add('validation-error');
        const formGroup = wrapper.closest('.form-group');
        if (formGroup) {
            const label = formGroup.querySelector('label');
            if (label) label.classList.add('validation-error-label');
            if (!formGroup.querySelector('.validation-error-msg')) {
                const msg = document.createElement('small');
                msg.className = 'validation-error-msg';
                msg.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + message;
                wrapper.parentNode.insertBefore(msg, wrapper.nextSibling);
            }
        }
    }

    function nextStep() {
        const totalSteps = getTotalSteps();
        if (currentStep < totalSteps) {
            clearAllValidationErrors();

            const activePanel = document.querySelector(`.wizard-panel[data-step="${currentStep}"]`);
            const inputs = activePanel.querySelectorAll('input[required], select[required], textarea[required]');
            let firstInvalid = null;
            let valid = true;

            inputs.forEach(input => {
                // Skip hidden inputs and inputs inside hidden containers
                if (input.type === 'hidden' || input.offsetParent === null) return;
                if (!input.value.trim()) {
                    markFieldInvalid(input, 'Kolom ini wajib diisi.');
                    valid = false;
                    if (!firstInvalid) firstInvalid = input;
                }
            });

            // Custom Validation for Step 3 (Proker Terlaksana)
            if (currentStep === 3) {
                const ptRows = activePanel.querySelectorAll('.pt-row');
                ptRows.forEach(row => {
                    const tujuanHidden = row.querySelector('.pt-tujuan-hidden');
                    const evaluasiHidden = row.querySelector('.pt-evaluasi-hidden');
                    
                    let tujuanPoints = [];
                    let evaluasiPoints = [];
                    
                    try {
                        if (tujuanHidden && tujuanHidden.value) {
                            tujuanPoints = JSON.parse(tujuanHidden.value);
                        }
                    } catch(e) {}
                    
                    try {
                        if (evaluasiHidden && evaluasiHidden.value) {
                            evaluasiPoints = JSON.parse(evaluasiHidden.value);
                        }
                    } catch(e) {}
                    
                    const filledTujuan = tujuanPoints.filter(p => p.trim() !== '');
                    const filledEvaluasi = evaluasiPoints.filter(p => p.trim() !== '');
                    
                    if (filledTujuan.length === 0) {
                        valid = false;
                        const tWrapper = tujuanHidden.closest('.multipoint-wrapper');
                        if (tWrapper) {
                            markMultipointInvalid(tWrapper, 'Minimal 1 tujuan kegiatan wajib diisi.');
                            if (!firstInvalid) firstInvalid = tWrapper;
                        }
                    }
                    
                    if (filledEvaluasi.length === 0) {
                        valid = false;
                        const eWrapper = evaluasiHidden.closest('.multipoint-wrapper');
                        if (eWrapper) {
                            markMultipointInvalid(eWrapper, 'Minimal 1 evaluasi & saran wajib diisi.');
                            if (!firstInvalid) firstInvalid = eWrapper;
                        }
                    }
                });
            }
            
            if (!valid) {
                // Auto-scroll to the first invalid field
                if (firstInvalid) {
                    firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    // Focus if it's an input
                    setTimeout(() => {
                        if (firstInvalid.focus) firstInvalid.focus();
                    }, 400);
                }
                return;
            }
            
            // Mark step as completed
            document.querySelector(`.step-progress .step[data-step="${currentStep}"]`).classList.add('completed');
            showStep(currentStep + 1);
        }
    }
    
    function prevStep() {
        if (currentStep > 1) {
            showStep(currentStep - 1);
        }
    }
    
    // Allow step indicators click navigation
    document.querySelectorAll('.step-progress .step').forEach(stepNode => {
        stepNode.addEventListener('click', function() {
            const clickedStep = parseInt(this.getAttribute('data-step'));
            // Skip if evaluasi step (5) and not mubesma
            if (clickedStep === 5 && !isMubesma()) return;
            const totalSteps = getTotalSteps();
            // Only allow jumping to steps if basic validation passes
            if (clickedStep < currentStep || this.classList.contains('completed') || clickedStep === currentStep + 1) {
                showStep(clickedStep);
            }
        });
    });

    function getAnggotaNamesFromStep2() {
        const names = [];
        const ketua = document.getElementById('anggotaKetua') ? document.getElementById('anggotaKetua').value.trim() : '';
        const sekretaris = document.getElementById('anggotaSekretaris') ? document.getElementById('anggotaSekretaris').value.trim() : '';
        const bendahara = document.getElementById('anggotaBendahara') ? document.getElementById('anggotaBendahara').value.trim() : '';
        
        if (ketua) names.push(ketua);
        if (sekretaris) names.push(sekretaris);
        if (bendahara) names.push(bendahara);
        
        document.querySelectorAll('.anggota-item-input').forEach(input => {
            const val = input.value.trim();
            if (val) {
                names.push(val);
            }
        });
        return names;
    }

    function syncEvaluasiAnggota(loadInitial = false) {
        const tbody = document.getElementById('evaluasiAnggotaTableBody');
        if (!tbody) return;
        
        // 1. Gather what is currently typed in Step 5's inputs
        const currentVals = {};
        tbody.querySelectorAll('tr').forEach(tr => {
            const nameVal = tr.querySelector('.eva-name-input') ? tr.querySelector('.eva-name-input').value : '';
            const kepVal = tr.querySelector('.eva-kep-input') ? tr.querySelector('.eva-kep-input').value : '';
            const kinVal = tr.querySelector('.eva-kin-input') ? tr.querySelector('.eva-kin-input').value : '';
            if (nameVal) {
                currentVals[nameVal] = { kepribadian: kepVal, kinerja: kinVal };
            }
        });
        
        // 2. Gather list of names from Step 2
        const names = getAnggotaNamesFromStep2();
        
        // 3. Clear and rebuild table body
        tbody.innerHTML = '';
        
        if (names.length === 0) {
            tbody.innerHTML = `<tr><td colspan="4" style="text-align: center; color: #aaa; padding: 15px;">Belum ada data anggota. Silakan isi data keanggotaan di Langkah 2.</td></tr>`;
            return;
        }
        
        names.forEach((name, index) => {
            let kep = '';
            let kin = '';
            
            // Match from currently typed values
            if (currentVals[name]) {
                kep = currentVals[name].kepribadian;
                kin = currentVals[name].kinerja;
            } 
            // Match from initial data loaded on edit page or AJAX
            else if (loadInitial && window.initialEvaluasiAnggota && window.initialEvaluasiAnggota.length > 0) {
                const matched = window.initialEvaluasiAnggota.find(item => item.nama === name);
                if (matched) {
                    kep = matched.kepribadian || '';
                    kin = matched.kinerja || '';
                }
            }
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td style="text-align: center; vertical-align: middle; padding: 10px; border: 1px solid #3a4555;">${index + 1}</td>
                <td style="vertical-align: middle; padding: 10px; border: 1px solid #3a4555; font-weight: bold; color: #fff;">
                    ${escapeHtml(name)}
                    <input type="hidden" name="eva_nama[]" class="eva-name-input" value="${escapeHtml(name)}">
                </td>
                <td style="padding: 5px; border: 1px solid #3a4555;">
                    <textarea name="eva_kepribadian[]" class="form-control eva-kep-input" rows="2" style="resize: vertical; width: 100%;" placeholder="Deskripsi kepribadian...">${escapeHtml(kep)}</textarea>
                </td>
                <td style="padding: 5px; border: 1px solid #3a4555;">
                    <textarea name="eva_kinerja[]" class="form-control eva-kin-input" rows="2" style="resize: vertical; width: 100%;" placeholder="Deskripsi kinerja...">${escapeHtml(kin)}</textarea>
                </td>
            `;
            tbody.appendChild(row);
        });
    }

    // --- Schritt 3: Proker Terlaksana Dynamic Rows ---
    function addProkerTerlaksana(ptData = null) {
        const container = document.getElementById('ptContainer');
        const div = document.createElement('div');
        div.className = 'dynamic-row pt-row';
        div.innerHTML = `
            <div class="row-number-badge"></div>
            <div class="proker-header" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px dashed #35445b; padding-bottom: 10px;" onclick="toggleProker(this)">
                <div class="row-reorder-controls" style="position: static; display: flex; gap: 6px;">
                    <button type="button" class="btn-reorder-row" onclick="event.stopPropagation(); moveRowUp(this, 'pt')" title="Geser ke atas"><i class="fas fa-arrow-up"></i> Atas</button>
                    <button type="button" class="btn-reorder-row" onclick="event.stopPropagation(); moveRowDown(this, 'pt')" title="Geser ke bawah"><i class="fas fa-arrow-down"></i> Bawah</button>
                </div>
                <div class="proker-summary" style="flex: 1; margin: 0 15px; color: #8BB9F0; font-size: 0.9rem; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: none;"></div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn-remove-row" style="position: static; margin: 0; padding: 5px 10px;" onclick="event.stopPropagation(); this.closest('.dynamic-row').remove(); reindexProkers(); updateRowReorderButtons('pt');">Hapus Proker</button>
                    <i class="fas fa-chevron-up toggle-icon" style="color: #4A90E2; font-size: 1.2rem; transition: transform 0.3s;"></i>
                </div>
            </div>
            <div class="proker-body">
            <input type="hidden" name="pt_ba_id[]" class="pt-ba-id-hidden" value="0">
            <div class="form-row-grid">
                <div class="form-group">
                    <label>Nama Program Kerja</label>
                    <input type="text" name="pt_name[]" class="form-control" required placeholder="Cth: JALIN RELASI">
                </div>
                <div class="form-group">
                    <label>Nama Kegiatan</label>
                    <input type="text" name="pt_kegiatan[]" class="form-control" required placeholder="Cth: Menghadiri Undangan Bemnus">
                </div>
                <div class="form-group">
                    <label>Tempat Kegiatan</label>
                    <input type="text" name="pt_tempat[]" class="form-control" required placeholder="Cth: Aula Kampus / Zoom Meeting">
                </div>
                <div class="form-group">
                    <label>Sifat</label>
                    <input type="text" name="pt_sifat[]" class="form-control" value="Internal" placeholder="Cth: Internal / Eksternal">
                </div>
                <div class="form-group">
                    <label>Tema Kegiatan</label>
                    <input type="text" name="pt_tema[]" class="form-control" placeholder="Tema...">
                </div>
            </div>
            <div class="form-row-grid" style="margin-top: 10px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label>Tujuan Kegiatan <span class="required-star">*</span></label>
                    <div class="multipoint-wrapper">
                        <input type="hidden" name="pt_tujuan[]" class="pt-tujuan-hidden" value="[]">
                        <div class="multipoint-list-container" data-placeholder="Tuliskan satu tujuan kegiatan..."></div>
                        <button type="button" class="btn-add-point" onclick="addNewPointField(this)"><i class="fas fa-plus"></i> Tambah Poin</button>
                    </div>
                </div>
                <div class="form-group pt-date-group">
                    <label>Tanggal Kegiatan</label>
                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 8px; flex-wrap: wrap;">
                        <input type="date" class="form-control pt-tgl-mulai" onchange="formatTanggalRangePt(this)" style="flex: 1; min-width: 130px;">
                        <span style="color:var(--text-muted); font-size: 0.8rem;">selama</span>
                        <div style="display:flex; gap:5px; align-items:center;">
                            <select class="form-control pt-durasi-hari" onchange="handleDurasiChangePt(this)" style="padding: 8px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: #fff; cursor: pointer; outline:none; height: auto;">
                                <option value="1">1 Hari</option>
                                <option value="2">2 Hari</option>
                                <option value="3">3 Hari</option>
                                <option value="4">4 Hari</option>
                                <option value="5">5 Hari</option>
                                <option value="custom">Custom...</option>
                            </select>
                            <input type="number" class="form-control pt-custom-hari" min="1" value="1" oninput="formatTanggalRangePt(this)" style="display:none; width: 60px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: #fff; outline:none; height: auto;">
                            <span class="pt-label-hari" style="color:var(--text-muted); font-size: 0.8rem; display:none;">Hari</span>
                        </div>
                    </div>
                    <input type="hidden" name="pt_tanggal[]" class="pt-out-tanggal">
                    <div class="preview-bar pt-preview-tanggal" style="margin-top: 10px;">—</div>
                </div>
            </div>
            <div class="form-row-grid" style="margin-top: 10px;">
                <div class="form-group">
                    <label>Penanggung Jawab</label>
                    <input type="text" name="pt_pj[]" class="form-control" placeholder="Nama PJ...">
                </div>
            </div>
            <div class="form-group" style="margin-top: 10px;">
                <label>Peserta Kegiatan <span class="required-star">*</span></label>
                <div class="multipoint-wrapper">
                    <input type="hidden" name="pt_peserta[]" class="pt-peserta-hidden" value="[]">
                    <div class="multipoint-list-container" data-placeholder="Tuliskan satu jenis peserta kegiatan..."></div>
                    <button type="button" class="btn-add-point" onclick="addNewPointField(this)"><i class="fas fa-plus"></i> Tambah Poin</button>
                </div>
            </div>
            <div class="form-group" style="margin-top: 10px; margin-bottom: 0;">
                <label>Evaluasi & Saran <span class="required-star">*</span></label>
                <div class="multipoint-wrapper">
                    <input type="hidden" name="pt_evaluasi[]" class="pt-evaluasi-hidden" value="[]">
                    <div class="multipoint-list-container" data-placeholder="Tuliskan satu poin evaluasi atau saran..."></div>
                    <button type="button" class="btn-add-point" onclick="addNewPointField(this)"><i class="fas fa-plus"></i> Tambah Poin</button>
                </div>
            </div>
            
            <!-- Realisasi Anggaran Sub-section -->
            <hr style="border: 0; border-top: 1px solid #2a3545; margin: 20px 0;">
            <div class="proker-sub-section">
                <h4 style="color: #8BB9F0; margin-bottom: 10px;"><i class="fas fa-wallet"></i> Realisasi Anggaran Proker</h4>
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label class="checkbox-container" style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #ccc;">
                         <input type="checkbox" class="pt-no-budget-check" checked onchange="toggleProkerBudget(this)">
                         Proker ini tidak menggunakan anggaran
                    </label>
                    <input type="hidden" name="pt_no_budget[]" class="pt-no-budget-hidden" value="1">
                </div>
                
                <div class="proker-budget-table-wrapper" style="display: none;">
                    <table class="admin-table proker-budget-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th style="width: 120px;">Tanggal</th>
                                <th>Keterangan</th>
                                <th>Uraian Transaksi</th>
                                <th style="width: 120px;">Debet (Pemasukan)</th>
                                <th style="width: 120px;">Kredit (Pengeluaran)</th>
                                <th style="width: 120px; text-align: right;">Saldo</th>
                                <th style="width: 40px; text-align: center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="proker-budget-container">
                            <tr class="pt-budget-row">
                                <td><input type="text" class="form-control pt-bud-tanggal" placeholder="Tanggal" oninput="serializeProkerBudgetTable(this)"></td>
                                <td><input type="text" class="form-control pt-bud-keterangan" placeholder="Keterangan" oninput="serializeProkerBudgetTable(this)"></td>
                                <td><input type="text" class="form-control pt-bud-uraian" value="Saldo Awal" placeholder="Uraian" required oninput="serializeProkerBudgetTable(this)"></td>
                                <td><input type="number" step="1" class="form-control pt-bud-debet" value="0" oninput="serializeProkerBudgetTable(this)"></td>
                                <td><input type="number" step="1" class="form-control pt-bud-kredit" value="0" oninput="serializeProkerBudgetTable(this)"></td>
                                <td style="text-align: right; font-weight: bold; color: #8BB9F0; font-family: monospace;" class="pt-bud-saldo-text">Rp 0</td>
                                <td style="text-align: center;"></td>
                            </tr>
                        </tbody>
                    </table>
                    <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 10px; margin-top: 10px;">
                        <div class="btn-add-row-mini" onclick="addProkerBudgetRow(this)"><i class="fas fa-plus"></i> Tambah Transaksi</div>
                        <div class="btn-import-excel-mini" onclick="toggleExcelImport(this)" style="display: inline-flex; align-items: center; gap: 6px; background: rgba(40, 167, 69, 0.1); border: 1px dashed #28a745; color: #28a745; padding: 6px 12px; border-radius: 6px; font-size: 0.78rem; font-weight: bold; cursor: pointer; transition: all 0.2s; user-select: none;"><i class="fas fa-file-excel"></i> Import Excel / Word</div>
                    </div>
                    <div class="excel-import-wrapper" style="display: none; margin-top: 15px; background: rgba(0, 0, 0, 0.2); border: 1px solid #2a3545; border-radius: 8px; padding: 15px; box-sizing: border-box; width: 100%;">
                        <label style="display: block; font-size: 0.85rem; color: #aaa; margin-bottom: 8px;">
                            Salin tabel dari Excel atau Word, lalu tempel (Ctrl+V) di bawah ini (Kolom: Tanggal, Keterangan, Uraian, Debet, Kredit):
                        </label>
                        <textarea class="form-control excel-import-textarea" rows="4" placeholder="Tempel tabel di sini...&#10;Contoh format baris:&#10;12 April 2026&#9;Internal&#9;Konsumsi Panitia&#9;0&#9;150000" style="font-family: monospace; font-size: 0.8rem; background: #0f1217; color: #fff; border-color: #2a3545; margin-bottom: 10px; width: 100%; box-sizing: border-box; resize: vertical;"></textarea>
                        <div style="display: flex; gap: 10px;">
                            <button type="button" class="btn-primary btn-small" onclick="processExcelImport(this)" style="padding: 6px 12px; font-size: 0.8rem; background: #28a745; border-color: #28a745; color: #fff;">Proses Import</button>
                            <button type="button" class="btn-secondary btn-small" onclick="toggleExcelImport(this)" style="padding: 6px 12px; font-size: 0.8rem; background: #333; border-color: #444; color: #ccc;">Batal</button>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="pt_anggaran[]" class="pt-anggaran-hidden" value="[]">
            </div>
            
            <!-- Dokumentasi Kegiatan Sub-section -->
            <hr style="border: 0; border-top: 1px solid #2a3545; margin: 20px 0;">
            <div class="proker-sub-section">
                <h4 style="color: #8BB9F0; margin-bottom: 10px;"><i class="fas fa-camera"></i> Dokumentasi Kegiatan</h4>
                
                <div class="proker-existing-photos-grid photo-grid" style="margin-bottom: 15px;"></div>
                <input type="hidden" name="pt_existing_dok[]" class="pt-existing-dok-hidden" value="[]">
                
                <div class="proker-new-photos-container" style="margin-top: 10px;"></div>
                <div class="btn-add-row-mini" onclick="addProkerNewPhotoRow(this)"><i class="fas fa-plus"></i> Tambah Foto Baru</div>
            </div>
        `;
        container.appendChild(div);
        
        // Initialize date picker (for both empty or loaded)
        initDateRangePickerPt(div.querySelector('.pt-date-group'), ptData ? (ptData['Tanggal Kegiatan'] || '') : '');
        
        if (ptData) {
            div.querySelector('input[name="pt_name[]"]').value = ptData['Nama Program Kerja'] || '';
            div.querySelector('input[name="pt_kegiatan[]"]').value = ptData['Nama Kegiatan'] || '';
            div.querySelector('input[name="pt_tempat[]"]').value = ptData['Tempat Kegiatan'] || ptData['Tempat'] || '';
            div.querySelector('input[name="pt_sifat[]"]').value = ptData['Sifat'] || 'Internal';
            div.querySelector('input[name="pt_tema[]"]').value = ptData['Tema Kegiatan'] || '';
            div.querySelector('input[name="pt_pj[]"]').value = ptData['Penanggung Jawab'] || '';
            
            // For multipoint inputs (tujuan, peserta, evaluasi)
            const tujuanHidden = div.querySelector('.pt-tujuan-hidden');
            const tujuanArr = Array.isArray(ptData['Tujuan']) ? ptData['Tujuan'] : (ptData['Tujuan'] ? [ptData['Tujuan']] : []);
            tujuanHidden.value = JSON.stringify(tujuanArr);
            
            const pesertaHidden = div.querySelector('.pt-peserta-hidden');
            const pesertaArr = Array.isArray(ptData['Peserta Kegiatan']) ? ptData['Peserta Kegiatan'] : (ptData['Peserta Kegiatan'] ? [ptData['Peserta Kegiatan']] : []);
            pesertaHidden.value = JSON.stringify(pesertaArr);
            
            const evaluasiHidden = div.querySelector('.pt-evaluasi-hidden');
            const evaluasiArr = Array.isArray(ptData['Evaluasi']) ? ptData['Evaluasi'] : (ptData['Evaluasi'] ? [ptData['Evaluasi']] : (ptData['Evaluasi & Saran'] ? [ptData['Evaluasi & Saran']] : []));
            evaluasiHidden.value = JSON.stringify(evaluasiArr);
            
            // Budget
            const noBudgetCheck = div.querySelector('.pt-no-budget-check');
            const noBudgetHidden = div.querySelector('.pt-no-budget-hidden');
            const tableWrapper = div.querySelector('.proker-budget-table-wrapper');
            const anggaranHidden = div.querySelector('.pt-anggaran-hidden');
            
            const ptNoBudget = ptData['tidak_menggunakan_anggaran'] ? true : false;
            noBudgetCheck.checked = ptNoBudget;
            noBudgetHidden.value = ptNoBudget ? '1' : '0';
            tableWrapper.style.display = ptNoBudget ? 'none' : 'block';
            
            const anggaranList = ptData['anggaran'] || [];
            anggaranHidden.value = JSON.stringify(anggaranList);
            
            // Rebuild budget table rows if they have transactions
            if (!ptNoBudget && anggaranList.length > 0) {
                const tbody = div.querySelector('.proker-budget-container');
                tbody.innerHTML = ''; // clear initial row
                anggaranList.forEach((tx, idx) => {
                    const tr = document.createElement('tr');
                    tr.className = 'pt-budget-row';
                    tr.innerHTML = `
                        <td><input type="text" class="form-control pt-bud-tanggal" value="${escapeHtml(tx.tanggal)}" placeholder="Tanggal" oninput="serializeProkerBudgetTable(this)"></td>
                        <td><input type="text" class="form-control pt-bud-keterangan" value="${escapeHtml(tx.keterangan)}" placeholder="Keterangan" oninput="serializeProkerBudgetTable(this)"></td>
                        <td><input type="text" class="form-control pt-bud-uraian" value="${escapeHtml(tx.uraian)}" placeholder="Uraian" required oninput="serializeProkerBudgetTable(this)"></td>
                        <td><input type="number" step="1" class="form-control pt-bud-debet" value="${tx.debet || 0}" oninput="serializeProkerBudgetTable(this)"></td>
                        <td><input type="number" step="1" class="form-control pt-bud-kredit" value="${tx.kredit || 0}" oninput="serializeProkerBudgetTable(this)"></td>
                        <td style="text-align: right; font-weight: bold; color: #8BB9F0; font-family: monospace;" class="pt-bud-saldo-text">Rp 0</td>
                        <td style="text-align: center;">
                            ${idx > 0 ? `<button type="button" class="btn-remove-img" style="width: 28px; height: 28px; background: #dc3545;" onclick="removeBudgetRow(this)"><i class="fas fa-times"></i></button>` : ''}
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
                // Recalculate balance for this table
                const firstInput = tbody.querySelector('.pt-bud-uraian');
                if (firstInput) {
                    serializeProkerBudgetTable(firstInput);
                }
            }
            
            // Existing photos
            const dokList = ptData['dokumentasi'] || [];
            const dokHidden = div.querySelector('.pt-existing-dok-hidden');
            dokHidden.value = JSON.stringify(dokList);
            
            const photosGrid = div.querySelector('.proker-existing-photos-grid');
            photosGrid.innerHTML = '';
            dokList.forEach((dok) => {
                const basename = dok.file_path.split('/').pop();
                const pathUrl = `../uploads/lpj/${basename}`;
                const photoCard = document.createElement('div');
                photoCard.className = 'photo-item photo-card';
                photoCard.dataset.path = dok.file_path;
                photoCard.innerHTML = `
                    <div class="photo-card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px;">
                        <label style="color:#8BB9F0; font-weight:bold; font-size:0.8rem; margin:0;">Foto Impor</label>
                        <button type="button" class="btn-remove-photo" style="background:none; border:none; color:#ff4d4d; cursor:pointer; font-size: 0.8rem;" onclick="removeExistingPhoto(this)"><i class="fas fa-times"></i> Hapus Slot</button>
                    </div>
                    <div class="photo-preview-wrap">
                        <img src="${pathUrl}">
                    </div>
                    <div class="form-group" style="margin-top: 15px;">
                        <label class="photo-card-label">Caption Foto</label>
                        <input type="text" class="form-control photo-caption-input" style="font-size: 0.8rem; padding: 10px;" value="${escapeHtml(dok.caption)}" oninput="serializeProkerPhotos(this)">
                    </div>
                `;
                photosGrid.appendChild(photoCard);
            });
            div.querySelector('.proker-body').style.display = 'none'; // Auto-collapse newly added imported data
            div.querySelector('.toggle-icon').style.transform = 'rotate(180deg)';
        }
        
        reindexProkers();
        updateRowReorderButtons('pt');
        initializeAllMultipoints();
    }

    // --- Schritt 4: Proker Belum Terlaksana Dynamic Rows ---
    function addProkerBelumTerlaksana(pbtData = null) {
        const container = document.getElementById('pbtContainer');
        const div = document.createElement('div');
        div.className = 'dynamic-row pbt-row';
        div.innerHTML = `
            <div class="row-number-badge"></div>
            <div class="proker-header" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px dashed #35445b; padding-bottom: 10px;" onclick="toggleProker(this)">
                <div class="row-reorder-controls" style="position: static; display: flex; gap: 6px;">
                    <button type="button" class="btn-reorder-row" onclick="event.stopPropagation(); moveRowUp(this, 'pbt')" title="Geser ke atas"><i class="fas fa-arrow-up"></i> Atas</button>
                    <button type="button" class="btn-reorder-row" onclick="event.stopPropagation(); moveRowDown(this, 'pbt')" title="Geser ke bawah"><i class="fas fa-arrow-down"></i> Bawah</button>
                </div>
                <div class="proker-summary" style="flex: 1; margin: 0 15px; color: #f39c12; font-size: 0.9rem; font-style: italic; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: none;"></div>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <button type="button" class="btn-remove-row" style="position: static; margin: 0; padding: 5px 10px;" onclick="event.stopPropagation(); this.closest('.dynamic-row').remove(); reindexProkers(); updateRowReorderButtons('pbt');">Hapus Proker</button>
                    <i class="fas fa-chevron-up toggle-icon" style="color: #4A90E2; font-size: 1.2rem; transition: transform 0.3s;"></i>
                </div>
            </div>
            <div class="proker-body">
            <div class="form-row-grid">
                <div class="form-group">
                    <label>Nama Kegiatan</label>
                    <input type="text" name="pbt_name[]" class="form-control" required placeholder="Nama kegiatan...">
                </div>
                <div class="form-group">
                    <label>Sifat</label>
                    <input type="text" name="pbt_sifat[]" class="form-control" placeholder="Sifat...">
                </div>
                <div class="form-group">
                    <label>Tema Kegiatan</label>
                    <input type="text" name="pbt_tema[]" class="form-control" placeholder="Tema...">
                </div>
            </div>
            <div class="form-row-grid" style="margin-top: 10px;">
                <div class="form-group" style="grid-column: span 2;">
                    <label>Tujuan Kegiatan</label>
                    <input type="text" name="pbt_tujuan[]" class="form-control" placeholder="Tujuan...">
                </div>
                <div class="form-group">
                    <label>Target Tanggal Rencana</label>
                    <input type="date" name="pbt_tanggal[]" class="form-control">
                </div>
            </div>
            <div class="form-row-grid" style="margin-top: 10px;">
                <div class="form-group">
                    <label>Penanggung Jawab</label>
                    <input type="text" name="pbt_pj[]" class="form-control" placeholder="PJ...">
                </div>
                <div class="form-group">
                    <label>Peserta Rencana</label>
                    <input type="text" name="pbt_peserta[]" class="form-control" placeholder="Peserta...">
                </div>
                <div class="form-group">
                    <label>Anggaran Rencana</label>
                    <input type="text" name="pbt_anggaran[]" class="form-control" placeholder="Anggaran...">
                </div>
            </div>
            <div class="form-group" style="margin-top: 10px; margin-bottom: 0;">
                <label>Hambatan & Kendala Dokumentasi</label>
                <textarea name="pbt_dokumentasi[]" rows="2" class="form-control" placeholder="Jelaskan alasan kendala penundaan kegiatan..."></textarea>
            </div>
            </div>
        `;
        container.appendChild(div);
        
        if (pbtData) {
            div.querySelector('input[name="pbt_name[]"]').value = pbtData['Nama Kegiatan'] || '';
            div.querySelector('input[name="pbt_sifat[]"]').value = pbtData['Sifat'] || '';
            div.querySelector('input[name="pbt_tema[]"]').value = pbtData['Tema Kegiatan'] || '';
            div.querySelector('input[name="pbt_tujuan[]"]').value = pbtData['Tujuan Kegiatan'] || '';
            div.querySelector('input[name="pbt_tanggal[]"]').value = pbtData['Tanggal Kegiatan'] || pbtData['Target Tanggal Rencana'] || '';
            div.querySelector('input[name="pbt_pj[]"]').value = pbtData['Penanggung Jawab'] || '';
            div.querySelector('input[name="pbt_peserta[]"]').value = pbtData['Peserta Kegiatan'] || pbtData['Peserta Rencana'] || '';
            div.querySelector('input[name="pbt_anggaran[]"]').value = pbtData['Anggaran'] || pbtData['Anggaran Rencana'] || '';
            div.querySelector('textarea[name="pbt_dokumentasi[]"]').value = pbtData['Dokumentasi'] || pbtData['Hambatan & Kendala Dokumentasi'] || '';
        }
        updateRowReorderButtons('pbt');
    }
    
    function formatRupiahJs(number) {
        return 'Rp ' + Number(number).toLocaleString('id-ID');
    }

    // --- Per-proker Budget Table Functions ---
    function toggleProkerBudget(chk) {
        const wrapper = chk.closest('.proker-sub-section');
        const tableWrapper = wrapper.querySelector('.proker-budget-table-wrapper');
        const hiddenNoBudget = wrapper.querySelector('.pt-no-budget-hidden');
        if (chk.checked) {
            tableWrapper.style.display = 'none';
            hiddenNoBudget.value = '1';
        } else {
            tableWrapper.style.display = 'block';
            hiddenNoBudget.value = '0';
        }
    }

    function addProkerBudgetRow(btn) {
        const wrapper = btn.closest('.proker-sub-section');
        const tbody = wrapper.querySelector('.proker-budget-container');
        const tr = document.createElement('tr');
        tr.className = 'pt-budget-row';
        tr.innerHTML = `
            <td><input type="text" class="form-control pt-bud-tanggal" placeholder="Tanggal" oninput="serializeProkerBudgetTable(this)"></td>
            <td><input type="text" class="form-control pt-bud-keterangan" placeholder="Keterangan" oninput="serializeProkerBudgetTable(this)"></td>
            <td><input type="text" class="form-control pt-bud-uraian" placeholder="Uraian" required oninput="serializeProkerBudgetTable(this)"></td>
            <td><input type="number" step="1" class="form-control pt-bud-debet" value="0" oninput="serializeProkerBudgetTable(this)"></td>
            <td><input type="number" step="1" class="form-control pt-bud-kredit" value="0" oninput="serializeProkerBudgetTable(this)"></td>
            <td style="text-align: right; font-weight: bold; color: #8BB9F0; font-family: monospace;" class="pt-bud-saldo-text">Rp 0</td>
            <td style="text-align: center;">
                <button type="button" class="btn-remove-img" style="width: 28px; height: 28px; background: #dc3545;" onclick="removeBudgetRow(this)"><i class="fas fa-times"></i></button>
            </td>
        `;
        tbody.appendChild(tr);
        serializeProkerBudgetTable(btn);
    }

    function toggleExcelImport(btn) {
        const wrapper = btn.closest('.proker-sub-section');
        const importDiv = wrapper.querySelector('.excel-import-wrapper');
        if (importDiv.style.display === 'none' || !importDiv.style.display) {
            importDiv.style.display = 'block';
            importDiv.querySelector('.excel-import-textarea').focus();
        } else {
            importDiv.style.display = 'none';
        }
    }

    function parsePastedExcelTable(text) {
        const lines = text.split('\n');
        const rows = [];
        
        lines.forEach(line => {
            const cells = line.split('\t').map(c => c.trim());
            // Skip if row is empty
            if (cells.length === 1 && cells[0] === '') return;
            
            // Skip header rows
            const joint = cells.join(' ').toLowerCase();
            if (joint.includes('tanggal') || joint.includes('keterangan') || joint.includes('debet') || joint.includes('kredit') || joint.includes('saldo') || joint.includes('pemasukan') || joint.includes('pengeluaran')) {
                return;
            }
            
            // Parse row based on cell count
            let tanggal = "";
            let keterangan = "";
            let uraian = "";
            let debet = 0;
            let kredit = 0;
            
            const parseAmount = (val) => {
                if (!val) return 0;
                // Remove decimal part like ,00 or .00 at the end
                let cleaned = val.replace(/[,.]\d{2}$/, '');
                // Keep only digits
                cleaned = cleaned.replace(/[^\d]/g, '');
                return parseFloat(cleaned) || 0;
            };
            
            if (cells.length >= 6) {
                tanggal = cells[0];
                keterangan = cells[1];
                uraian = cells[2];
                debet = parseAmount(cells[3]);
                kredit = parseAmount(cells[4]);
            } else if (cells.length === 5) {
                let c3_num = parseAmount(cells[3]);
                let c4_num = parseAmount(cells[4]);
                tanggal = cells[0];
                keterangan = cells[1];
                uraian = cells[2];
                debet = c3_num;
                kredit = c4_num;
            } else if (cells.length === 4) {
                tanggal = cells[0];
                keterangan = "";
                uraian = cells[1];
                debet = parseAmount(cells[2]);
                kredit = parseAmount(cells[3]);
            } else if (cells.length === 3) {
                tanggal = cells[0];
                keterangan = "";
                uraian = cells[1];
                let amt = parseAmount(cells[2]);
                if (amt > 0) {
                    kredit = amt;
                }
            }
            
            if (tanggal || uraian || debet || kredit) {
                rows.push({
                    tanggal: tanggal,
                    keterangan: keterangan,
                    uraian: uraian || "Transaksi",
                    debet: debet,
                    kredit: kredit
                });
            }
        });
        
        return rows;
    }

    function escapeHtml(string) {
        return String(string).replace(/[&<>"'`=\/]/g, function (s) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
                '/': '&#x2F;',
                '=': '&#x3D;',
                '`': '&#x60;'
            }[s];
        });
    }

    function processExcelImport(btn) {
        const wrapper = btn.closest('.proker-sub-section');
        const textarea = wrapper.querySelector('.excel-import-textarea');
        const text = textarea.value.trim();
        if (!text) {
            alert('Silakan tempel data tabel terlebih dahulu.');
            return;
        }
        
        const parsedRows = parsePastedExcelTable(text);
        if (parsedRows.length === 0) {
            alert('Format data tidak dikenali atau tidak ada baris transaksi valid.');
            return;
        }
        
        const tbody = wrapper.querySelector('.proker-budget-container');
        
        // Check if there is only 1 row and it is empty
        const existingRows = tbody.querySelectorAll('.pt-budget-row');
        let isOnlyDefaultRow = false;
        if (existingRows.length === 1) {
            const row = existingRows[0];
            const tanggal = row.querySelector('.pt-bud-tanggal').value.trim();
            const keterangan = row.querySelector('.pt-bud-keterangan').value.trim();
            const uraian = row.querySelector('.pt-bud-uraian').value.trim();
            const debet = parseFloat(row.querySelector('.pt-bud-debet').value) || 0;
            const kredit = parseFloat(row.querySelector('.pt-bud-kredit').value) || 0;
            
            if (tanggal === '' && keterangan === '' && (uraian === 'Saldo Awal' || uraian === '') && debet === 0 && kredit === 0) {
                isOnlyDefaultRow = true;
            }
        }
        
        if (isOnlyDefaultRow) {
            tbody.innerHTML = '';
        }
        
        parsedRows.forEach((item, idx) => {
            const tr = document.createElement('tr');
            tr.className = 'pt-budget-row';
            const showDelete = (tbody.children.length > 0);
            
            tr.innerHTML = `
                <td><input type="text" class="form-control pt-bud-tanggal" value="${escapeHtml(item.tanggal)}" placeholder="Tanggal" oninput="serializeProkerBudgetTable(this)"></td>
                <td><input type="text" class="form-control pt-bud-keterangan" value="${escapeHtml(item.keterangan)}" placeholder="Keterangan" oninput="serializeProkerBudgetTable(this)"></td>
                <td><input type="text" class="form-control pt-bud-uraian" value="${escapeHtml(item.uraian)}" placeholder="Uraian" required oninput="serializeProkerBudgetTable(this)"></td>
                <td><input type="number" step="1" class="form-control pt-bud-debet" value="${item.debet}" oninput="serializeProkerBudgetTable(this)"></td>
                <td><input type="number" step="1" class="form-control pt-bud-kredit" value="${item.kredit}" oninput="serializeProkerBudgetTable(this)"></td>
                <td style="text-align: right; font-weight: bold; color: #8BB9F0; font-family: monospace;" class="pt-bud-saldo-text">Rp 0</td>
                <td style="text-align: center;">
                    ${showDelete ? `<button type="button" class="btn-remove-img" style="width: 28px; height: 28px; background: #dc3545;" onclick="removeBudgetRow(this)"><i class="fas fa-times"></i></button>` : ''}
                </td>
            `;
            tbody.appendChild(tr);
        });
        
        serializeProkerBudgetTable(tbody);
        textarea.value = '';
        wrapper.querySelector('.excel-import-wrapper').style.display = 'none';
    }

    function removeBudgetRow(btn) {
        const tr = btn.closest('tr');
        const container = tr.closest('.proker-budget-container');
        tr.remove();
        serializeProkerBudgetTable(container);
    }

    function serializeProkerBudgetTable(element) {
        const prokerCard = element.closest('.pt-row');
        const tbody = prokerCard.querySelector('.proker-budget-container');
        const hiddenInput = prokerCard.querySelector('.pt-anggaran-hidden');
        
        const rows = tbody.querySelectorAll('.pt-budget-row');
        let runningBalance = 0;
        const data = [];
        
        rows.forEach(row => {
            const tanggal = row.querySelector('.pt-bud-tanggal').value;
            const keterangan = row.querySelector('.pt-bud-keterangan').value;
            const uraian = row.querySelector('.pt-bud-uraian').value;
            const debet = parseFloat(row.querySelector('.pt-bud-debet').value) || 0;
            const kredit = parseFloat(row.querySelector('.pt-bud-kredit').value) || 0;
            
            runningBalance += (debet - kredit);
            row.querySelector('.pt-bud-saldo-text').textContent = formatRupiahJs(runningBalance);
            
            if (uraian.trim()) {
                data.push({
                    tanggal: tanggal,
                    keterangan: keterangan,
                    uraian: uraian,
                    debet: debet,
                    kredit: kredit
                });
            }
        });
        
        hiddenInput.value = JSON.stringify(data);
    }

    function calculateProkerBudgetBalance(prokerCard) {
        const tbody = prokerCard.querySelector('.proker-budget-container');
        if (!tbody) return;
        const rows = tbody.querySelectorAll('.pt-budget-row');
        let runningBalance = 0;
        rows.forEach(row => {
            const debet = parseFloat(row.querySelector('.pt-bud-debet').value) || 0;
            const kredit = parseFloat(row.querySelector('.pt-bud-kredit').value) || 0;
            runningBalance += (debet - kredit);
            row.querySelector('.pt-bud-saldo-text').textContent = formatRupiahJs(runningBalance);
        });
    }

    // --- Per-proker Documentation Functions ---
    function removeExistingPhoto(btn) {
        const photoItem = btn.closest('.photo-item');
        const grid = photoItem.closest('.proker-existing-photos-grid');
        photoItem.remove();
        serializeProkerPhotos(grid);
    }

    function serializeProkerPhotos(element) {
        const prokerCard = element.closest('.pt-row');
        const grid = prokerCard.querySelector('.proker-existing-photos-grid');
        const hiddenInput = prokerCard.querySelector('.pt-existing-dok-hidden');
        
        const photos = [];
        grid.querySelectorAll('.photo-item').forEach(item => {
            const path = item.getAttribute('data-path');
            const caption = item.querySelector('.photo-caption-input').value;
            photos.push({
                file_path: path,
                caption: caption
            });
        });
        
        hiddenInput.value = JSON.stringify(photos);
    }

    function addProkerNewPhotoRow(btn) {
        const prokerCard = btn.closest('.pt-row');
        const container = prokerCard.querySelector('.proker-new-photos-container');
        if (!container.classList.contains('photo-grid')) {
            container.classList.add('photo-grid');
        }
        
        const count = container.querySelectorAll('.new-photo-row').length + prokerCard.querySelectorAll('.photo-item').length + 1;
        
        const div = document.createElement('div');
        div.className = 'new-photo-row photo-card';
        div.innerHTML = `
            <div class="photo-card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px;">
                <label style="color:#8BB9F0; font-weight:bold; font-size:0.8rem; margin:0;">Foto Slot Baru</label>
                <button type="button" class="btn-remove-photo" style="background:none; border:none; color:#ff4d4d; cursor:pointer; font-size: 0.8rem;" onclick="this.closest('.photo-card').remove(); reindexProkers();"><i class="fas fa-times"></i> Hapus Slot</button>
            </div>
            <div class="photo-preview-wrap">
                <i class="fas fa-spinner fa-spin upload-spinner" style="display: none; position: absolute; z-index: 3; font-size: 2rem; color: #4A90E2;"></i>
                <img class="preview-img" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg>">
            </div>
            <div class="form-group" style="margin-top: 15px;">
                <label class="photo-card-label">Upload Foto</label>
                <input type="file" class="form-control proker-new-photo-file" accept="image/*" required onchange="handleNewPhotoUpload(this)" style="background: #0f1217; padding: 6px;">
            </div>
            <div class="form-group">
                <label class="photo-card-label">Caption Foto</label>
                <input type="text" class="form-control proker-new-photo-caption" placeholder="Cth: Foto Bersama Pemateri" required>
            </div>
        `;
        container.appendChild(div);
        reindexProkers();
    }

    function handleNewPhotoUpload(input) {
        const file = input.files[0];
        if (!file) return;
        
        const zone = input.closest('.photo-card');
        const spinner = zone.querySelector('.upload-spinner');
        const preview = zone.querySelector('.preview-img');
        
        if (spinner) spinner.style.display = 'inline-block';
        
        const reader = new FileReader();
        reader.onload = function(e) {
            if (spinner) spinner.style.display = 'none';
            preview.src = e.target.result;
        }
        reader.readAsDataURL(file);
    }

    function reindexProkers() {
        const rows = document.querySelectorAll('#ptContainer .pt-row');
        rows.forEach((row, index) => {
            const fileInputs = row.querySelectorAll('.proker-new-photo-file');
            fileInputs.forEach(input => {
                input.name = `pt_new_dok_file_${index}[]`;
            });
            const captionInputs = row.querySelectorAll('.proker-new-photo-caption');
            captionInputs.forEach(input => {
                input.name = `pt_new_dok_caption_${index}[]`;
            });
            calculateProkerBudgetBalance(row);
            // Update row number badge
            const badge = row.querySelector('.row-number-badge');
            if (badge) badge.textContent = 'Proker #' + (index + 1);
        });
        updateRowReorderButtons('pt');
        updateRowReorderButtons('pbt');
    }

    function serializeAllProkerData() {
        document.querySelectorAll('#ptContainer .pt-row').forEach(row => {
            serializeProkerBudgetTable(row);
            serializeProkerPhotos(row);
        });
    }

    // --- Save Actions ---
    function saveDraft() {
        serializeAllProkerData();
        document.getElementById('lpjStatus').value = 'draft';
        const overlay = document.getElementById('submitOverlay');
        if (overlay) overlay.style.display = 'flex';
        // Bypass step validation when saving as draft
        document.getElementById('lpjForm').submit();
    }
    
    function submitLpj() {
        serializeAllProkerData();
        document.getElementById('lpjStatus').value = 'submitted';
    }

    document.getElementById('lpjForm').addEventListener('submit', function(e) {
        serializeAllProkerData();
        const overlay = document.getElementById('submitOverlay');
        if (overlay) overlay.style.display = 'flex';
    });

    // --- AJAX default values loader ---
    document.getElementById('kementerianSelect').addEventListener('change', function() {
        const kId = this.value;
        if (!kId) return;
        
        const triwulan = document.getElementById('triwulanSelect').value;
        if (triwulan === 'MUBESMA') {
            checkAndFetchMubesma();
            return;
        }
        
        // Fetch description/keadaan objektif
        fetch(`buat-lpj.php?ajax_kementerian_id=${kId}`)
            .then(res => res.json())
            .then(data => {
                if (!document.getElementById('keadaanObjektif').value.trim()) {
                    document.getElementById('keadaanObjektif').value = data.deskripsi;
                }
            })
            .catch(err => console.error('Error fetching defaults:', err));
            
        // Fetch and prefill members immediately
        fetchKepengurusan(kId);
    });

    document.getElementById('triwulanSelect').addEventListener('change', function() {
        const triwulan = this.value;
        updateEvaluasiVisibility();
        if (triwulan === 'MUBESMA') {
            checkAndFetchMubesma();
        }
    });

    function checkAndFetchMubesma() {
        const kId = document.getElementById('kementerianSelect').value;
        const triwulan = document.getElementById('triwulanSelect').value;
        if (!kId || triwulan !== 'MUBESMA') return;
        
        const alertDiv = document.getElementById('kepengurusanAlert');
        if (alertDiv) {
            alertDiv.style.display = 'none';
            alertDiv.className = '';
            alertDiv.innerHTML = '';
        }
        
        clearIndicators();
        
        fetch(`buat-lpj.php?ajax_kementerian_id=${kId}&triwulan=MUBESMA`)
            .then(res => {
                if (!res.ok) {
                    throw new Error('Network response was not ok');
                }
                return res.json();
            })
            .then(data => {
                if (data.error) {
                    showKepengurusanError("Gagal mengambil data MUBESMA.");
                    return;
                }
                
                // Prefill Keadaan Objektif
                document.getElementById('keadaanObjektif').value = data.deskripsi || '';
                
                // Prefill Keanggotaan
                prefillField('anggotaKetua', data.keanggotaan.ketua || '', 'indicator_ketua');
                prefillField('anggotaSekretaris', data.keanggotaan.sekretaris || '', 'indicator_sekretaris');
                prefillField('anggotaBendahara', data.keanggotaan.bendahara || '', 'indicator_bendahara');
                
                if (data.keanggotaan.ketua) {
                    document.getElementById('indicator_ketua').innerHTML = '<i class="fas fa-magic"></i> Diisi otomatis dari gabungan Triwulan I & II · Ubah jika perlu';
                }
                if (data.keanggotaan.sekretaris) {
                    document.getElementById('indicator_sekretaris').innerHTML = '<i class="fas fa-magic"></i> Diisi otomatis dari gabungan Triwulan I & II · Ubah jika perlu';
                }
                if (data.keanggotaan.bendahara) {
                    document.getElementById('indicator_bendahara').innerHTML = '<i class="fas fa-magic"></i> Diisi otomatis dari gabungan Triwulan I & II · Ubah jika perlu';
                }
                
                // Clear and rebuild anggota rows
                const container = document.getElementById('anggotaListContainer');
                if (container) {
                    container.innerHTML = '';
                    if (data.keanggotaan.anggota && data.keanggotaan.anggota.length > 0) {
                        data.keanggotaan.anggota.forEach(name => {
                            addAnggotaRow(name);
                        });
                    } else {
                        addAnggotaRow('');
                    }
                }
                
                const indicator = document.getElementById('indicator_anggota');
                if (indicator) {
                    indicator.className = 'autofill-indicator autofilled';
                    indicator.innerHTML = '<i class="fas fa-magic"></i> Diisi otomatis dari gabungan Triwulan I & II · Ubah jika perlu';
                }
                
                // Prefill Proker Terlaksana
                const ptContainer = document.getElementById('ptContainer');
                if (ptContainer && data.proker_terlaksana) {
                    ptContainer.innerHTML = '';
                    if (data.proker_terlaksana.length > 0) {
                        data.proker_terlaksana.forEach(pt => {
                            addProkerTerlaksana(pt);
                        });
                    } else {
                        addProkerTerlaksana();
                    }
                }
                
                // Prefill Proker Belum Terlaksana
                const pbtContainer = document.getElementById('pbtContainer');
                if (pbtContainer && data.proker_belum_terlaksana) {
                    pbtContainer.innerHTML = '';
                    if (data.proker_belum_terlaksana.length > 0) {
                        data.proker_belum_terlaksana.forEach(pbt => {
                            addProkerBelumTerlaksana(pbt);
                        });
                    } else {
                        addProkerBelumTerlaksana();
                    }
                }
                
                if (alertDiv) {
                    alertDiv.className = 'alert alert-success';
                    alertDiv.style.padding = '10px 15px';
                    alertDiv.style.fontSize = '0.85rem';
                    alertDiv.innerHTML = '<i class="fas fa-magic"></i> Data MUBESMA (gabungan Triwulan I dan II) berhasil disalin secara otomatis. Silakan periksa kembali dan sesuaikan jika ada perubahan.';
                    alertDiv.style.display = 'block';
                }
                lastFetchedKementerianId = kId;
            })
            .catch(err => {
                console.error('Error fetching MUBESMA defaults:', err);
                showKepengurusanError("Gagal mengambil data gabungan MUBESMA. Isi manual untuk melanjutkan.");
            });
    }

    // --- Helper Functions for Dynamic Anggota ---
    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function addAnggotaRow(name = '') {
        const container = document.getElementById('anggotaListContainer');
        if (!container) return;
        const row = document.createElement('div');
        row.className = 'anggota-row';
        row.innerHTML = `
            <input type="text" name="anggota_lain[]" class="form-control field-keanggotaan anggota-item-input" value="${escapeHtml(name)}" placeholder="Nama Anggota...">
            <button type="button" class="btn-remove-anggota" onclick="removeAnggotaRow(this)"><i class="fas fa-trash-alt"></i></button>
        `;
        container.appendChild(row);
        
        const input = row.querySelector('.anggota-item-input');
        input.addEventListener('input', function() {
            markAnggotaAsModified();
        });
    }

    window.removeAnggotaRow = function(button) {
        const row = button.closest('.anggota-row');
        if (row) {
            row.remove();
            markAnggotaAsModified();
        }
    }

    function markAnggotaAsModified() {
        const indicator = document.getElementById('indicator_anggota');
        if (indicator) {
            indicator.className = 'autofill-indicator modified';
            indicator.innerHTML = '<i class="fas fa-edit"></i> Diubah manual';
        }
    }

    // --- Autofill Keanggotaan from kepengurusan.php ---
    function fetchKepengurusan(kId) {
        const alertDiv = document.getElementById('kepengurusanAlert');
        if (alertDiv) {
            alertDiv.style.display = 'none';
            alertDiv.className = '';
            alertDiv.innerHTML = '';
        }
        
        clearIndicators();
        
        fetch(`buat-lpj.php?ajax_kementerian_id=${kId}`)
            .then(res => {
                if (!res.ok) {
                    throw new Error('Network response was not ok');
                }
                return res.json();
            })
            .then(data => {
                if (data.error) {
                    showKepengurusanError("Gagal mengambil data kepengurusan. Isi manual untuk melanjutkan.");
                    return;
                }
                
                const keanggotaan = data.keanggotaan || {};
                const hasData = keanggotaan.ketua || keanggotaan.sekretaris || keanggotaan.bendahara || (keanggotaan.anggota && keanggotaan.anggota.length > 0);
                
                if (!hasData) {
                    if (alertDiv) {
                        alertDiv.className = 'alert alert-info';
                        alertDiv.style.padding = '10px 15px';
                        alertDiv.style.fontSize = '0.85rem';
                        alertDiv.innerHTML = '<i class="fas fa-info-circle"></i> Data kepengurusan kementerian ini belum tersedia. Silakan isi secara manual atau hubungi BPH BEM.';
                        alertDiv.style.display = 'block';
                    }
                    const container = document.getElementById('anggotaListContainer');
                    if (container) {
                        container.innerHTML = '';
                        addAnggotaRow('');
                    }
                    return;
                }
                
                // Prefill fields
                prefillField('anggotaKetua', keanggotaan.ketua || '', 'indicator_ketua');
                prefillField('anggotaSekretaris', keanggotaan.sekretaris || '', 'indicator_sekretaris');
                prefillField('anggotaBendahara', keanggotaan.bendahara || '', 'indicator_bendahara');
                
                // Clear and rebuild anggota rows
                const container = document.getElementById('anggotaListContainer');
                if (container) {
                    container.innerHTML = '';
                    if (keanggotaan.anggota && keanggotaan.anggota.length > 0) {
                        keanggotaan.anggota.forEach(name => {
                            addAnggotaRow(name);
                        });
                    } else {
                        addAnggotaRow('');
                    }
                }
                
                const indicator = document.getElementById('indicator_anggota');
                if (indicator) {
                    indicator.className = 'autofill-indicator autofilled';
                    indicator.innerHTML = '<i class="fas fa-magic"></i> Diisi otomatis dari data kepengurusan · Ubah jika perlu';
                }
                
                lastFetchedKementerianId = kId;
            })
            .catch(err => {
                console.error('Error fetching kepengurusan:', err);
                showKepengurusanError("Gagal mengambil data kepengurusan. Isi manual untuk melanjutkan.");
            });
    }
    
    function showKepengurusanError(message) {
        const alertDiv = document.getElementById('kepengurusanAlert');
        if (alertDiv) {
            alertDiv.className = 'alert alert-warning';
            alertDiv.style.padding = '10px 15px';
            alertDiv.style.fontSize = '0.85rem';
            alertDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${message}`;
            alertDiv.style.display = 'block';
        }
    }
    
    function prefillField(fieldId, value, indicatorId) {
        const field = document.getElementById(fieldId);
        const indicator = document.getElementById(indicatorId);
        if (!field) return;
        
        field.value = value || '';
        if (value) {
            field.setAttribute('data-autofilled', 'true');
            if (indicator) {
                indicator.className = 'autofill-indicator autofilled';
                indicator.innerHTML = '<i class="fas fa-magic"></i> Diisi otomatis dari data kepengurusan · Ubah jika perlu';
            }
        } else {
            field.removeAttribute('data-autofilled');
            if (indicator) {
                indicator.innerHTML = '';
            }
        }
    }
    
    function clearIndicators() {
        ['indicator_ketua', 'indicator_sekretaris', 'indicator_bendahara', 'indicator_anggota'].forEach(id => {
            const ind = document.getElementById(id);
            if (ind) ind.innerHTML = '';
        });
    }
    
    // --- Listeners for Manual Editing ---
    const indicatorMap = {
        'anggotaKetua': 'indicator_ketua',
        'anggotaSekretaris': 'indicator_sekretaris',
        'anggotaBendahara': 'indicator_bendahara'
    };
    
    document.querySelectorAll('.field-keanggotaan').forEach(field => {
        // Exclude inputs inside the dynamic list
        if (field.classList.contains('anggota-item-input')) return;
        field.addEventListener('input', function() {
            if (this.getAttribute('data-autofilled') === 'true') {
                this.removeAttribute('data-autofilled');
                const indicatorId = indicatorMap[this.id];
                const indicator = document.getElementById(indicatorId);
                if (indicator) {
                    indicator.className = 'autofill-indicator modified';
                    indicator.innerHTML = '<i class="fas fa-edit"></i> Diubah manual';
                }
            }
        });
    });

    // --- COMPONENT CARD MULTI-POIN ---

    function initializeAllMultipoints() {
        document.querySelectorAll('.multipoint-wrapper').forEach(function(wrapper) {
            if (wrapper.dataset.initialized === 'true') return;
            wrapper.dataset.initialized = 'true';
            
            const hiddenInput = wrapper.querySelector('input[type="hidden"]');
            const container = wrapper.querySelector('.multipoint-list-container');
            const placeholder = container.dataset.placeholder || '';
            
            let data = [];
            try {
                if (hiddenInput.value) {
                    data = JSON.parse(hiddenInput.value);
                }
            } catch(e) {
                console.error('Failed to parse multipoint JSON', e);
            }
            
            // Clear container
            container.innerHTML = '';
            
            // If data is empty, we must add at least one empty point
            if (!data || data.length === 0) {
                addPointRowElement(container, '', false, placeholder);
            } else {
                data.forEach(function(text) {
                    addPointRowElement(container, text, false, placeholder);
                });
            }
            
            // Setup drag and drop for this container
            setupDragAndDrop(container);
        });
    }

    function addNewPointField(btn) {
        const wrapper = btn.closest('.multipoint-wrapper');
        const container = wrapper.querySelector('.multipoint-list-container');
        const placeholder = container.dataset.placeholder || '';
        addPointRowElement(container, '', true, placeholder);
    }

    function addPointRowElement(container, text = '', autoFocus = false, placeholder = '') {
        const row = document.createElement('div');
        row.className = 'multipoint-row';
        row.setAttribute('draggable', 'true');
        
        row.innerHTML = `
            <div class="multipoint-row-content">
                <div class="drag-handle" title="Geser urutan"><i class="fas fa-grip-vertical"></i></div>
                <div class="reorder-btn-group">
                    <button type="button" class="btn-reorder btn-reorder-up" onclick="movePointRowUp(this)" title="Naikkan"><i class="fas fa-chevron-up"></i></button>
                    <button type="button" class="btn-reorder btn-reorder-down" onclick="movePointRowDown(this)" title="Turunkan"><i class="fas fa-chevron-down"></i></button>
                </div>
                <div class="point-badge">0</div>
                <div class="point-text-container">
                    <input type="text" class="point-input" placeholder="${placeholder}" oninput="onPointInputChange(this)" onkeydown="onPointInputKeydown(event, this)" onblur="onPointInputBlur(this)">
                </div>
                <div class="point-actions">
                    <button type="button" class="btn-edit-point" onclick="focusPointRowInput(this)" title="Edit poin"><i class="fas fa-pencil-alt"></i></button>
                    <button type="button" class="btn-delete-point" onclick="showDeleteConfirmation(this)" title="Hapus poin"><i class="fas fa-trash-alt"></i></button>
                </div>
            </div>
            <div class="multipoint-row-confirm" style="display: none;">
                <span class="confirm-msg"><i class="fas fa-exclamation-triangle"></i> Hapus poin ini?</span>
                <div class="confirm-buttons">
                    <button type="button" class="btn-confirm-yes" onclick="deletePointRowElement(this)"><i class="fas fa-check"></i> Ya</button>
                    <button type="button" class="btn-confirm-no" onclick="hideDeleteConfirmation(this)"><i class="fas fa-times"></i> Batal</button>
                </div>
            </div>
        `;
        
        const input = row.querySelector('.point-input');
        input.value = text;
        
        container.appendChild(row);
        
        recalculateBadges(container);
        updateHiddenInput(container);
        
        if (autoFocus) {
            input.focus();
        }
    }

    function focusPointRowInput(btn) {
        const row = btn.closest('.multipoint-row');
        const input = row.querySelector('.point-input');
        if (input) {
            input.focus();
        }
    }

    function showDeleteConfirmation(btn) {
        const row = btn.closest('.multipoint-row');
        row.querySelector('.multipoint-row-content').style.display = 'none';
        row.querySelector('.multipoint-row-confirm').style.display = 'flex';
    }

    function hideDeleteConfirmation(btn) {
        const row = btn.closest('.multipoint-row');
        row.querySelector('.multipoint-row-confirm').style.display = 'none';
        row.querySelector('.multipoint-row-content').style.display = 'flex';
    }

    function deletePointRowElement(btn) {
        const row = btn.closest('.multipoint-row');
        const container = row.closest('.multipoint-list-container');
        row.remove();
        recalculateBadges(container);
        updateHiddenInput(container);
    }

    function recalculateBadges(container) {
        const rows = container.querySelectorAll('.multipoint-row');
        rows.forEach((row, index) => {
            const badge = row.querySelector('.point-badge');
            if (badge) {
                badge.textContent = index + 1;
            }
            
            const btnUp = row.querySelector('.btn-reorder-up');
            const btnDown = row.querySelector('.btn-reorder-down');
            if (btnUp) btnUp.disabled = (index === 0);
            if (btnDown) btnDown.disabled = (index === rows.length - 1);
        });
    }

    function updateHiddenInput(container) {
        const wrapper = container.closest('.multipoint-wrapper');
        const hiddenInput = wrapper.querySelector('input[type="hidden"]');
        const inputs = container.querySelectorAll('.point-input');
        const values = [];
        inputs.forEach(input => {
            values.push(input.value);
        });
        hiddenInput.value = JSON.stringify(values);
        
        const filled = values.filter(v => v.trim() !== '');
        if (filled.length > 0) {
            wrapper.style.borderColor = '#2a3545';
        }
    }

    function onPointInputChange(input) {
        const container = input.closest('.multipoint-list-container');
        updateHiddenInput(container);
    }

    function onPointInputBlur(input) {
        const container = input.closest('.multipoint-list-container');
        updateHiddenInput(container);
    }

    function onPointInputKeydown(event, input) {
        const row = input.closest('.multipoint-row');
        const container = input.closest('.multipoint-list-container');
        const placeholder = container.dataset.placeholder || '';
        
        if (event.key === 'Enter') {
            event.preventDefault();
            addPointRowElement(container, '', true, placeholder);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            if (input.value.trim() === '') {
                const rows = container.querySelectorAll('.multipoint-row');
                if (rows.length > 1) {
                    row.remove();
                    recalculateBadges(container);
                    updateHiddenInput(container);
                } else {
                    input.blur();
                }
            } else {
                input.blur();
            }
        }
    }

    function movePointRowUp(btn) {
        const row = btn.closest('.multipoint-row');
        const container = row.closest('.multipoint-list-container');
        const previous = row.previousElementSibling;
        if (previous) {
            container.insertBefore(row, previous);
            recalculateBadges(container);
            updateHiddenInput(container);
        }
    }

    function movePointRowDown(btn) {
        const row = btn.closest('.multipoint-row');
        const container = row.closest('.multipoint-list-container');
        const next = row.nextElementSibling;
        if (next) {
            container.insertBefore(next, row);
            recalculateBadges(container);
            updateHiddenInput(container);
        }
    }

    // --- Drag & Drop ---
    let dragSrcEl = null;

    function setupDragAndDrop(container) {
        container.addEventListener('dragstart', handleDragStart, false);
        container.addEventListener('dragover', handleDragOver, false);
        container.addEventListener('drop', handleDrop, false);
        container.addEventListener('dragend', handleDragEnd, false);
    }

    function handleDragStart(e) {
        const row = e.target.closest('.multipoint-row');
        if (!row) return;
        
        const handle = row.querySelector('.drag-handle');
        if (handle && !handle.contains(e.target) && e.target !== handle) {
            e.preventDefault();
            return;
        }
        
        dragSrcEl = row;
        row.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/html', row.innerHTML);
    }

    function handleDragOver(e) {
        if (e.preventDefault) {
            e.preventDefault();
        }
        e.dataTransfer.dropEffect = 'move';
        
        const container = e.currentTarget;
        const draggingRow = container.querySelector('.multipoint-row.dragging');
        if (!draggingRow) return;
        
        const targetRow = e.target.closest('.multipoint-row');
        if (targetRow && targetRow !== draggingRow) {
            const rect = targetRow.getBoundingClientRect();
            const next = (e.clientY - rect.top) / (rect.bottom - rect.top) > 0.5;
            container.insertBefore(draggingRow, next ? targetRow.nextSibling : targetRow);
        }
        return false;
    }

    function handleDrop(e) {
        if (e.stopPropagation) {
            e.stopPropagation();
        }
        return false;
    }

    function handleDragEnd(e) {
        const row = e.target.closest('.multipoint-row');
        if (row) {
            row.classList.remove('dragging');
        }
        
        const container = e.currentTarget;
        recalculateBadges(container);
        updateHiddenInput(container);
        dragSrcEl = null;
    }

    // Run balance calculation and initialize multipoints on load
    document.addEventListener('DOMContentLoaded', () => {
        reindexProkers();
        initializeAllMultipoints();
        updateEvaluasiVisibility();
        updateRowReorderButtons('pt');
        updateRowReorderButtons('pbt');

        // Also add row-number-badges to initial PHP-rendered pbt rows
        document.querySelectorAll('#pbtContainer .pbt-row').forEach((row, idx) => {
            if (!row.querySelector('.row-number-badge')) {
                const badge = document.createElement('div');
                badge.className = 'row-number-badge';
                badge.textContent = 'Proker #' + (idx + 1);
                row.insertBefore(badge, row.firstChild);
            }
            if (!row.querySelector('.row-reorder-controls')) {
                const controls = document.createElement('div');
                controls.className = 'row-reorder-controls';
                controls.innerHTML = `
                    <button type="button" class="btn-reorder-row" onclick="moveRowUp(this, 'pbt')" title="Geser ke atas"><i class="fas fa-arrow-up"></i> Atas</button>
                    <button type="button" class="btn-reorder-row" onclick="moveRowDown(this, 'pbt')" title="Geser ke bawah"><i class="fas fa-arrow-down"></i> Bawah</button>
                `;
                row.insertBefore(controls, row.firstChild);
            }
        });

        // Also add row-number-badges and reorder controls to initial PHP-rendered pt rows
        document.querySelectorAll('#ptContainer .pt-row').forEach((row, idx) => {
            if (!row.querySelector('.row-number-badge')) {
                const badge = document.createElement('div');
                badge.className = 'row-number-badge';
                badge.textContent = 'Proker #' + (idx + 1);
                row.insertBefore(badge, row.firstChild);
            }
            if (!row.querySelector('.row-reorder-controls')) {
                const controls = document.createElement('div');
                controls.className = 'row-reorder-controls';
                controls.innerHTML = `
                    <button type="button" class="btn-reorder-row" onclick="moveRowUp(this, 'pt')" title="Geser ke atas"><i class="fas fa-arrow-up"></i> Atas</button>
                    <button type="button" class="btn-reorder-row" onclick="moveRowDown(this, 'pt')" title="Geser ke bawah"><i class="fas fa-arrow-down"></i> Bawah</button>
                `;
                row.insertBefore(controls, row.firstChild);
            }
            
            // Initialize date range picker for this row
            const dateGroup = row.querySelector('.pt-date-group');
            if (dateGroup) {
                const outHidden = dateGroup.querySelector('.pt-out-tanggal');
                const rawVal = outHidden ? outHidden.value : '';
                initDateRangePickerPt(dateGroup, rawVal);
            }
        });

        updateRowReorderButtons('pt');
        updateRowReorderButtons('pbt');

        // Setup btnAddAnggota
        const btnAdd = document.getElementById('btnAddAnggota');
        if (btnAdd) {
            btnAdd.addEventListener('click', function() {
                addAnggotaRow('');
                markAnggotaAsModified();
            });
        }

        // Setup initial inputs input listener
        document.querySelectorAll('.field-keanggotaan, .anggota-item-input').forEach(input => {
            input.addEventListener('input', function() {
                markAnggotaAsModified();
            });
        });
        
        syncEvaluasiAnggota(true);
    });

    // ===== PROKER ROW REORDER FUNCTIONS =====
    function moveRowUp(btn, type) {
        const row = btn.closest('.dynamic-row');
        const container = type === 'pt' ? document.getElementById('ptContainer') : document.getElementById('pbtContainer');
        const prev = row.previousElementSibling;
        if (prev && prev.classList.contains('dynamic-row')) {
            container.insertBefore(row, prev);
            reindexProkers();
            // Smooth scroll to moved row
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            // Brief highlight effect
            row.style.borderColor = '#4A90E2';
            row.style.boxShadow = '0 0 15px rgba(74, 144, 226, 0.4)';
            setTimeout(() => {
                row.style.borderColor = '';
                row.style.boxShadow = '';
            }, 600);
        }
    }

    function moveRowDown(btn, type) {
        const row = btn.closest('.dynamic-row');
        const container = type === 'pt' ? document.getElementById('ptContainer') : document.getElementById('pbtContainer');
        const next = row.nextElementSibling;
        if (next && next.classList.contains('dynamic-row')) {
            container.insertBefore(next, row);
            reindexProkers();
            // Smooth scroll to moved row
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            // Brief highlight effect
            row.style.borderColor = '#4A90E2';
            row.style.boxShadow = '0 0 15px rgba(74, 144, 226, 0.4)';
            setTimeout(() => {
                row.style.borderColor = '';
                row.style.boxShadow = '';
            }, 600);
        }
    }

    function updateRowReorderButtons(type) {
        const containerSel = type === 'pt' ? '#ptContainer' : '#pbtContainer';
        const rowClass = type === 'pt' ? '.pt-row' : '.pbt-row';
        const rows = document.querySelectorAll(`${containerSel} ${rowClass}`);
        rows.forEach((row, index) => {
            const upBtn = row.querySelector('.row-reorder-controls .btn-reorder-row:first-child');
            const downBtn = row.querySelector('.row-reorder-controls .btn-reorder-row:last-child');
            if (upBtn) upBtn.disabled = (index === 0);
            if (downBtn) downBtn.disabled = (index === rows.length - 1);
            // Update badge for pbt rows
            const badge = row.querySelector('.row-number-badge');
            if (badge) badge.textContent = 'Proker #' + (index + 1);
        });
    }

    // ===== PROKER SEARCH/FILTER FUNCTIONS =====
    function filterProkerRows(type) {
        const input = document.getElementById(type === 'pt' ? 'ptSearchInput' : 'pbtSearchInput');
        const clearBtn = document.getElementById(type === 'pt' ? 'ptSearchClear' : 'pbtSearchClear');
        const countSpan = document.getElementById(type === 'pt' ? 'ptSearchCount' : 'pbtSearchCount');
        const containerSel = type === 'pt' ? '#ptContainer' : '#pbtContainer';
        const rowClass = type === 'pt' ? '.pt-row' : '.pbt-row';
        const query = input.value.trim().toLowerCase();

        clearBtn.style.display = query ? 'inline-block' : 'none';

        const rows = document.querySelectorAll(`${containerSel} ${rowClass}`);
        let visibleCount = 0;

        rows.forEach(row => {
            if (!query) {
                row.classList.remove('search-hidden');
                visibleCount++;
                return;
            }
            // Search in name fields
            const nameInput = type === 'pt' 
                ? row.querySelector('input[name="pt_name[]"]') 
                : row.querySelector('input[name="pbt_name[]"]');
            const kegiatanInput = type === 'pt' 
                ? row.querySelector('input[name="pt_kegiatan[]"]') 
                : null;

            let text = '';
            if (nameInput) text += (nameInput.value || '').toLowerCase();
            if (kegiatanInput) text += ' ' + (kegiatanInput.value || '').toLowerCase();

            if (text.includes(query)) {
                row.classList.remove('search-hidden');
                visibleCount++;
            } else {
                row.classList.add('search-hidden');
            }
        });

        if (query) {
            countSpan.textContent = `${visibleCount} dari ${rows.length} ditampilkan`;
        } else {
            countSpan.textContent = '';
        }
    }

    function clearProkerSearch(type) {
        const input = document.getElementById(type === 'pt' ? 'ptSearchInput' : 'pbtSearchInput');
        input.value = '';
        filterProkerRows(type);
        input.focus();
    }
    // --- Schritt 4: Berita Acara Import Logic ---
    function openBaModal() {
        const kemSelect = document.getElementById('kementerianSelect');
        const kemHidden = document.querySelector('input[name="kementerian_id"][type="hidden"]');
        const kementerianId = (kemSelect && kemSelect.value) || (kemHidden && kemHidden.value);
        if (!kementerianId) {
            alert("Pilih Kementerian terlebih dahulu pada Langkah 1.");
            return;
        }
        
        document.getElementById('baModal').style.display = 'flex';
        document.getElementById('baSelect').innerHTML = '<option value="">-- Memuat Data... --</option>';
        
        fetch('buat-lpj.php?ajax_get_berita_acara_kementerian=' + kementerianId)
            .then(res => res.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                
                // Kumpulkan semua berita_acara_id yang sudah ada di form LPJ
                const usedBaIds = new Set();
                document.querySelectorAll('#ptContainer .pt-row .pt-ba-id-hidden').forEach(input => {
                    const val = parseInt(input.value);
                    if (val > 0) usedBaIds.add(val);
                });
                
                const sel = document.getElementById('baSelect');
                sel.innerHTML = '<option value="">-- Pilih Berita Acara --</option>';
                data.forEach(ba => {
                    if (usedBaIds.has(ba.id)) {
                        sel.innerHTML += `<option value="${ba.id}" disabled style="color: #666; background: #1a1a2e;">✓ ${escapeHtml(ba.judul)} (Sudah Tercatat di LPJ)</option>`;
                    } else {
                        sel.innerHTML += `<option value="${ba.id}">${escapeHtml(ba.judul)}</option>`;
                    }
                });
                if (data.length === 0) {
                    sel.innerHTML = '<option value="">-- Tidak ada data Berita Acara untuk kementerian ini --</option>';
                }
            }).catch(err => {
                console.error(err);
                document.getElementById('baSelect').innerHTML = '<option value="">-- Gagal memuat data --</option>';
            });
    }

    function importBaData() {
        const baSelect = document.getElementById('baSelect');
        const baId = baSelect.value;
        if (!baId) {
            alert("Silakan pilih Berita Acara terlebih dahulu.");
            return;
        }
        
        const selectedOption = baSelect.options[baSelect.selectedIndex];
        if (selectedOption && selectedOption.disabled) {
            alert("Berita Acara ini sudah ditambahkan ke dalam LPJ!");
            return;
        }
        
        const rows = document.querySelectorAll('#ptContainer .pt-row');
        let targetRow = rows[rows.length - 1];
        let isLastEmpty = !targetRow.querySelector('input[name="pt_name[]"]').value && !targetRow.querySelector('input[name="pt_kegiatan[]"]').value;
        
        // Prevent double clicking by disabling button
        const importBtn = document.getElementById('btnImportBa');
        if (importBtn) {
            importBtn.disabled = true;
            importBtn.innerText = 'Mengimpor...';
        }
        
        if (!isLastEmpty) {
            addProkerTerlaksana();
            const newRows = document.querySelectorAll('#ptContainer .pt-row');
            targetRow = newRows[newRows.length - 1];
        }
        
        fetch('buat-lpj.php?ajax_get_berita_acara_id=' + baId)
            .then(res => res.json())
            .then(data => {
                if (data.error) throw new Error(data.error);
                
                // Simpan referensi BA ID di hidden input
                const baIdHidden = targetRow.querySelector('.pt-ba-id-hidden');
                if (baIdHidden) baIdHidden.value = baId;
                
                targetRow.querySelector('input[name="pt_name[]"]').value = data.program_kerja || '';
                targetRow.querySelector('input[name="pt_kegiatan[]"]').value = data.nama_kegiatan || '';
                targetRow.querySelector('input[name="pt_tempat[]"]').value = data.tempat || '';
                targetRow.querySelector('input[name="pt_tema[]"]').value = data.tema || '';
                const rawTgl = data.tanggal || '';
                const dateGroup = targetRow.querySelector('.pt-date-group');
                if (dateGroup) {
                    initDateRangePickerPt(dateGroup, rawTgl);
                } else {
                    targetRow.querySelector('input[name="pt_tanggal[]"]').value = rawTgl;
                }
                targetRow.querySelector('input[name="pt_pj[]"]').value = data.penanggung_jawab || '';
                
                if (data.tujuan && data.tujuan.length > 0) {
                    const hiddenTujuan = targetRow.querySelector('.pt-tujuan-hidden');
                    if (hiddenTujuan) {
                        hiddenTujuan.value = JSON.stringify(data.tujuan);
                        const wrapper = hiddenTujuan.closest('.multipoint-wrapper');
                        const container = wrapper.querySelector('.multipoint-list-container');
                        container.innerHTML = '';
                        const placeholder = container.getAttribute('data-placeholder');
                        data.tujuan.forEach(val => {
                            const escapedVal = val.replace(/"/g, '&quot;');
                            container.innerHTML += `
                            <div class="multipoint-item" style="display:flex; gap:10px; margin-bottom:10px;">
                                <input type="text" class="form-control" value="${escapedVal}" placeholder="${placeholder}" onchange="updateMultiPointJSON(this)">
                                <button type="button" class="btn btn-danger btn-sm" style="padding:0 12px;" onclick="removeMultiPoint(this)"><i class="fas fa-trash"></i></button>
                            </div>`;
                        });
                    }
                }
                
                if (data.dokumentasi && data.dokumentasi.length > 0) {
                    const hiddenDocs = targetRow.querySelector('.pt-existing-dok-hidden');
                    const previewGrid = targetRow.querySelector('.proker-existing-photos-grid');
                    if (hiddenDocs && previewGrid) {
                        hiddenDocs.value = JSON.stringify(data.dokumentasi);
                        previewGrid.innerHTML = '';
                        data.dokumentasi.forEach(doc => {
                            let webPath = doc.file_path || '';
                            if (webPath.includes('/var/www/html/bem/')) {
                                webPath = '../' + webPath.split('/var/www/html/bem/')[1];
                            } else if (!webPath.startsWith('http') && !webPath.startsWith('../')) {
                                webPath = '../uploads/berita_acara/' + webPath.split('/').pop();
                            }
                            const div = document.createElement('div');
                            div.className = 'photo-item photo-card';
                            div.setAttribute('data-path', doc.file_path || '');
                            div.innerHTML = `
                                <div class="photo-card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px;">
                                    <label style="color:#8BB9F0; font-weight:bold; font-size:0.8rem; margin:0;">Foto Impor</label>
                                    <button type="button" class="btn-remove-photo" style="background:none; border:none; color:#ff4d4d; cursor:pointer; font-size: 0.8rem;" onclick="removeExistingPhoto(this)"><i class="fas fa-times"></i> Hapus Slot</button>
                                </div>
                                <div class="photo-preview-wrap">
                                    <img src="${webPath}">
                                </div>
                                <div class="form-group" style="margin-top: 15px;">
                                    <label class="photo-card-label">Caption Foto</label>
                                    <input type="text" class="form-control photo-caption-input" style="font-size: 0.8rem; padding: 10px;" value="${doc.caption}" oninput="serializeProkerPhotos(this)">
                                </div>
                            `;
                            previewGrid.appendChild(div);
                        });
                    }
                }
                
                document.getElementById('baModal').style.display = 'none';
                alert("Data Berita Acara berhasil diimpor!");
                targetRow.scrollIntoView({ behavior: 'smooth', block: 'start' });
                
            }).catch(err => {
                console.error(err);
                alert("Gagal mengambil detail Berita Acara.");
            }).finally(() => {
                const importBtn = document.getElementById('btnImportBa');
                if (importBtn) {
                    importBtn.disabled = false;
                    importBtn.innerText = 'Gunakan Data';
                }
            });
    }

    function toggleProker(header) {
        const body = header.nextElementSibling;
        const icon = header.querySelector('.toggle-icon');
        const summary = header.querySelector('.proker-summary');
        
        if (body.style.display === 'none') {
            body.style.display = 'block';
            icon.style.transform = 'rotate(0deg)';
            if (summary) summary.style.display = 'none';
        } else {
            // Read inputs to build summary before hiding
            if (summary) {
                const name = body.querySelector('input[name="pt_name[]"], input[name="pbt_name[]"]');
                const keg = body.querySelector('input[name="pt_kegiatan[]"], input[name="pbt_kegiatan[]"]');
                const tgl = body.querySelector('input[name="pt_tanggal[]"], input[name="pbt_tanggal[]"]');
                
                let text = (name && name.value) ? name.value : 'Tanpa Nama';
                if (keg && keg.value) text += ' - ' + keg.value;
                if (tgl && tgl.value) text += ' (' + tgl.value + ')';
                
                summary.innerText = text;
                summary.style.display = 'block';
            }
            body.style.display = 'none';
            icon.style.transform = 'rotate(180deg)';
        }
    }
</script>

<!-- Submit loading overlay -->
<div id="submitOverlay" class="submit-overlay">
    <div class="submit-overlay-content">
        <i class="fas fa-spinner fa-spin spinner"></i>
        <h3>Menyimpan Laporan...</h3>
        <p>Mohon tunggu sebentar, dokumen dan gambar sedang diunggah ke server.</p>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
