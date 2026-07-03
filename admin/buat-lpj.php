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
            'proker_belum_terlaksana' => $proker_belum_terlaksana
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

$page_css = 'arsip-surat';
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
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $kementerian_id = (int)($_POST['kementerian_id'] ?? 0);
        $triwulan = sanitizeText($_POST['triwulan'] ?? '');
        $status = sanitizeText($_POST['status'] ?? 'draft');
        $keadaan_objektif = sanitizeText($_POST['keadaan_objektif'] ?? '', 2000);
        
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
                    'dokumentasi' => $dokumentasi_list
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
                dbQuery("UPDATE lpj_dokumen SET status = ?, keanggotaan = ?, keadaan_objektif = ?, proker_terlaksana = ?, proker_belum_terlaksana = ?, anggaran = ?, dokumentasi = ? WHERE id = ?", 
                    [$status, $keanggotaan_json, $keadaan_objektif, $proker_terlaksana_json, $proker_belum_terlaksana_json, $anggaran_json, $dokumentasi_json, $lpj_id], "sssssssi");
            } else {
                dbQuery("INSERT INTO lpj_dokumen (periode_id, kementerian_id, triwulan, status, keanggotaan, keadaan_objektif, proker_terlaksana, proker_belum_terlaksana, anggaran, dokumentasi) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$periode_id, $kementerian_id, $triwulan, $status, $keanggotaan_json, $keadaan_objektif, $proker_terlaksana_json, $proker_belum_terlaksana_json, $anggaran_json, $dokumentasi_json], "iissssssss");
                $lpj_id = dbLastId();
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
                'keanggotaan' => $keanggotaan,
                'tugas_pokok' => $k_tugas,
                'fungsi' => $k_fungsi,
                'visi' => $visi,
                'misi' => $misi,
                'proker_terlaksana' => $proker_terlaksana,
                'proker_belum_terlaksana' => $proker_belum_terlaksana,
                'anggaran' => $anggaran,
                'dokumentasi' => $dokumentasi
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
        }
    }
}
if (empty($pts)) {
    $pts = [[
        'Nama Program Kerja' => '',
        'Nama Kegiatan' => '',
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

    /* New Photo Row Premium Styling */
    .new-photo-row {
        background: rgba(15, 18, 23, 0.6);
        border: 1px solid #2a3545;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 12px;
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
        position: relative;
        transition: all 0.3s ease;
    }
    @media(min-width: 576px) {
        .new-photo-row {
            grid-template-columns: 160px 1fr;
            align-items: center;
        }
    }
    .new-photo-row:hover {
        border-color: #4A90E2;
        box-shadow: 0 4px 15px rgba(74, 144, 226, 0.15);
    }
    .new-photo-row .btn-remove-img {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 28px;
        height: 28px;
        background: rgba(220, 53, 69, 0.15) !important;
        border: 1px solid rgba(220, 53, 69, 0.4) !important;
        color: #ff6b6b !important;
        border-radius: 50% !important;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }
    .new-photo-row .btn-remove-img:hover {
        background: #dc3545 !important;
        color: #fff !important;
        transform: scale(1.05);
    }
    .photo-upload-zone {
        border: 2px dashed #2a3545;
        border-radius: 8px;
        background: rgba(0, 0, 0, 0.2);
        padding: 10px;
        text-align: center;
        cursor: pointer;
        position: relative;
        height: 110px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        transition: all 0.2s;
        color: #888;
    }
    .photo-upload-zone:hover {
        border-color: #4A90E2;
        background: rgba(74, 144, 226, 0.05);
        color: #4A90E2;
    }
    .photo-upload-zone input[type="file"] {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        opacity: 0;
        cursor: pointer;
        z-index: 2;
    }
    .photo-upload-zone .preview-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        position: absolute;
        top: 0;
        left: 0;
        z-index: 1;
    }
    .photo-upload-zone .upload-spinner {
        display: none;
        font-size: 1.5rem;
        color: #4A90E2;
        z-index: 3;
    }
    .photo-upload-zone .upload-icon {
        font-size: 1.6rem;
        margin-bottom: 5px;
        z-index: 1;
    }
    .photo-upload-zone .upload-text {
        font-size: 0.75rem;
        font-weight: 500;
        z-index: 1;
    }
    
    .new-photo-row input.proker-new-photo-caption {
        background: #0f1217 !important;
        border: 1px solid #2a3545 !important;
        color: #fff !important;
        padding: 10px 14px !important;
        border-radius: 8px !important;
        height: auto !important;
        font-size: 0.9rem !important;
    }
    .new-photo-row input.proker-new-photo-caption:focus {
        border-color: #4A90E2 !important;
        box-shadow: 0 0 8px rgba(74,144,226,0.2) !important;
        outline: none !important;
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
                    <label>Ketua Menteri / Kepala Departemen</label>
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
                <p style="font-size: 0.85rem; color: #aaa; margin-bottom: 20px;">Masukkan program kerja yang telah berhasil dilaksanakan pada triwulan ini. Minimal harus ada 1 proker terlaksana.</p>
                
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
                        <?php if ($idx > 0): ?>
                            <button type="button" class="btn-remove-row" onclick="this.closest('.dynamic-row').remove(); reindexProkers();">Hapus Proker</button>
                        <?php endif; ?>
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
                            <div class="form-group">
                                <label>Tanggal Kegiatan</label>
                                <input type="text" name="pt_tanggal[]" class="form-control" value="<?php echo htmlspecialchars($pt['Tanggal Kegiatan'] ?? ''); ?>" placeholder="Cth: 12 April 2026">
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
                            
                            <div class="proker-existing-photos-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-bottom: 15px;">
                                <?php foreach ($pt_dokumentasi_list as $photo): ?>
                                    <div class="photo-item" data-path="<?php echo htmlspecialchars($photo['file_path']); ?>" style="background: rgba(0,0,0,0.3); border: 1px solid #2a3545; border-radius: 8px; padding: 10px; text-align: center; position: relative;">
                                        <img src="<?php echo file_exists($photo['file_path']) ? str_replace('/var/www/html/bem/', BASE_URL, $photo['file_path']) : uploadUrl(basename($photo['file_path'])); ?>" style="max-height: 80px; max-width: 100%; border-radius: 4px; object-fit: contain; margin-bottom: 8px;">
                                        <input type="text" class="form-control photo-caption-input" style="font-size: 0.8rem; padding: 4px 8px;" value="<?php echo htmlspecialchars($photo['caption'] ?? 'Dokumentasi'); ?>" oninput="serializeProkerPhotos(this)">
                                        <button type="button" class="btn-remove-img" style="position: absolute; top: 5px; right: 5px; width: 24px; height: 24px;" onclick="removeExistingPhoto(this)"><i class="fas fa-trash"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="pt_existing_dok[]" class="pt-existing-dok-hidden" value="<?php echo htmlspecialchars($pt_dokumentasi_json); ?>">
                            
                            <div class="proker-new-photos-container" style="margin-top: 10px;"></div>
                            <div class="btn-add-row-mini" onclick="addProkerNewPhotoRow(this)"><i class="fas fa-plus"></i> Tambah Foto Baru</div>
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
                
                <div id="pbtContainer">
                    <?php
                    $pbts = json_decode($edit_data['proker_belum_terlaksana'] ?? '', true) ?: [];
                    foreach ($pbts as $idx => $pbt):
                    ?>
                    <div class="dynamic-row pbt-row">
                        <button type="button" class="btn-remove-row" onclick="this.closest('.dynamic-row').remove();">Hapus</button>
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
                                <input type="text" name="pbt_tanggal[]" class="form-control" value="<?php echo htmlspecialchars($pbt['Tanggal Kegiatan'] ?? ''); ?>" placeholder="Cth: Juni 2026">
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
                    <?php endforeach; ?>
                </div>
                
                <div class="btn-add-row" onclick="addProkerBelumTerlaksana()"><i class="fas fa-plus"></i> Tambah Proker Belum Terealisasi</div>
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
</form>

<script>
    let currentStep = 1;
    const totalSteps = 4;
    
    let lastFetchedKementerianId = <?php echo $edit_data ? (int)$edit_data['kementerian_id'] : 'null'; ?>;

    function showStep(step) {
        document.querySelectorAll('.wizard-panel').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.step-progress .step').forEach(s => s.classList.remove('active'));
        
        document.querySelector(`.wizard-panel[data-step="${step}"]`).classList.add('active');
        document.querySelector(`.step-progress .step[data-step="${step}"]`).classList.add('active');
        
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
    }
    
    function nextStep() {
        if (currentStep < totalSteps) {
            // Validate basic inputs in current step before moving forward
            const activePanel = document.querySelector(`.wizard-panel[data-step="${currentStep}"]`);
            const inputs = activePanel.querySelectorAll('input[required], select[required], textarea[required]');
            let valid = true;
            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.style.borderColor = '#dc3545';
                    valid = false;
                } else {
                    input.style.borderColor = '#2a3545';
                }
            });
            
            if (!valid) {
                alert('Silakan lengkapi kolom yang wajib diisi terlebih dahulu.');
                return;
            }

            // Custom Validation for Step 3 (Proker Terlaksana)
            if (currentStep === 3) {
                let multipointValid = true;
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
                        multipointValid = false;
                        const tWrapper = tujuanHidden.closest('.multipoint-wrapper');
                        if (tWrapper) tWrapper.style.borderColor = '#dc3545';
                    } else {
                        const tWrapper = tujuanHidden.closest('.multipoint-wrapper');
                        if (tWrapper) tWrapper.style.borderColor = '#2a3545';
                    }
                    
                    if (filledEvaluasi.length === 0) {
                        multipointValid = false;
                        const eWrapper = evaluasiHidden.closest('.multipoint-wrapper');
                        if (eWrapper) eWrapper.style.borderColor = '#dc3545';
                    } else {
                        const eWrapper = evaluasiHidden.closest('.multipoint-wrapper');
                        if (eWrapper) eWrapper.style.borderColor = '#2a3545';
                    }
                });
                
                if (!multipointValid) {
                    alert('Setiap program kerja wajib memiliki minimal 1 Tujuan dan 1 Evaluasi & Saran.');
                    return;
                }
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
            // Only allow jumping to steps if basic validation passes
            if (clickedStep < currentStep || this.classList.contains('completed') || clickedStep === currentStep + 1) {
                showStep(clickedStep);
            }
        });
    });

    // --- Schritt 3: Proker Terlaksana Dynamic Rows ---
    function addProkerTerlaksana(ptData = null) {
        const container = document.getElementById('ptContainer');
        const div = document.createElement('div');
        div.className = 'dynamic-row pt-row';
        div.innerHTML = `
            <button type="button" class="btn-remove-row" onclick="this.closest('.dynamic-row').remove(); reindexProkers();">Hapus Proker</button>
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
                <div class="form-group">
                    <label>Tanggal Kegiatan</label>
                    <input type="text" name="pt_tanggal[]" class="form-control" placeholder="Cth: 12 April 2026">
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
                
                <div class="proker-existing-photos-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-bottom: 15px;"></div>
                <input type="hidden" name="pt_existing_dok[]" class="pt-existing-dok-hidden" value="[]">
                
                <div class="proker-new-photos-container" style="margin-top: 10px;"></div>
                <div class="btn-add-row-mini" onclick="addProkerNewPhotoRow(this)"><i class="fas fa-plus"></i> Tambah Foto Baru</div>
            </div>
        `;
        container.appendChild(div);
        
        if (ptData) {
            div.querySelector('input[name="pt_name[]"]').value = ptData['Nama Program Kerja'] || '';
            div.querySelector('input[name="pt_kegiatan[]"]').value = ptData['Nama Kegiatan'] || '';
            div.querySelector('input[name="pt_tempat[]"]').value = ptData['Tempat Kegiatan'] || ptData['Tempat'] || '';
            div.querySelector('input[name="pt_sifat[]"]').value = ptData['Sifat'] || 'Internal';
            div.querySelector('input[name="pt_tema[]"]').value = ptData['Tema Kegiatan'] || '';
            div.querySelector('input[name="pt_tanggal[]"]').value = ptData['Tanggal Kegiatan'] || '';
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
                photoCard.className = 'photo-item';
                photoCard.dataset.path = dok.file_path;
                photoCard.style.cssText = 'background: rgba(0,0,0,0.3); border: 1px solid #2a3545; border-radius: 8px; padding: 10px; text-align: center; position: relative;';
                photoCard.innerHTML = `
                    <img src="${pathUrl}" style="max-height: 80px; max-width: 100%; border-radius: 4px; object-fit: contain; margin-bottom: 8px;">
                    <input type="text" class="form-control photo-caption-input" style="font-size: 0.8rem; padding: 4px 8px;" value="${escapeHtml(dok.caption)}" oninput="serializeProkerPhotos(this)">
                    <button type="button" class="btn-remove-img" style="position: absolute; top: 5px; right: 5px; width: 24px; height: 24px; background: #e74c3c; border: none; color: #fff; border-radius: 3px; cursor: pointer; display: flex; align-items: center; justify-content: center;" onclick="removeExistingPhoto(this)"><i class="fas fa-trash"></i></button>
                `;
                photosGrid.appendChild(photoCard);
            });
        }
        
        reindexProkers();
        initializeAllMultipoints();
    }

    // --- Schritt 4: Proker Belum Terlaksana Dynamic Rows ---
    function addProkerBelumTerlaksana(pbtData = null) {
        const container = document.getElementById('pbtContainer');
        const div = document.createElement('div');
        div.className = 'dynamic-row pbt-row';
        div.innerHTML = `
            <button type="button" class="btn-remove-row" onclick="this.closest('.dynamic-row').remove();">Hapus</button>
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
                    <input type="text" name="pbt_tanggal[]" class="form-control" placeholder="Cth: Juni 2026">
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
        const div = document.createElement('div');
        div.className = 'new-photo-row';
        div.innerHTML = `
            <div class="photo-upload-zone">
                <i class="fas fa-spinner fa-spin upload-spinner"></i>
                <i class="fas fa-camera upload-icon"></i>
                <span class="upload-text">Pilih Gambar</span>
                <input type="file" class="proker-new-photo-file" accept="image/*" required onchange="handleNewPhotoUpload(this)">
                <img class="preview-img" style="display: none;">
            </div>
            <div style="display: flex; flex-direction: column; justify-content: center; width: 100%; box-sizing: border-box; padding-right: 25px;">
                <label style="font-size: 0.78rem; color: #aaa; margin-bottom: 6px; font-weight: 600;">Keterangan Foto</label>
                <input type="text" class="form-control proker-new-photo-caption" placeholder="Masukkan keterangan atau caption foto kegiatan..." required>
            </div>
            <button type="button" class="btn-remove-img" onclick="this.parentElement.remove();"><i class="fas fa-times"></i></button>
        `;
        container.appendChild(div);
        reindexProkers();
    }

    function handleNewPhotoUpload(input) {
        const file = input.files[0];
        if (!file) return;
        
        const zone = input.closest('.photo-upload-zone');
        const spinner = zone.querySelector('.upload-spinner');
        const icon = zone.querySelector('.upload-icon');
        const text = zone.querySelector('.upload-text');
        const preview = zone.querySelector('.preview-img');
        
        // Show spinner
        spinner.style.display = 'inline-block';
        icon.style.display = 'none';
        text.style.display = 'none';
        
        const reader = new FileReader();
        reader.onload = function(e) {
            // Hide spinner and show preview
            spinner.style.display = 'none';
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
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
        });
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

        // Setup btnAddAnggota
        const btnAdd = document.getElementById('btnAddAnggota');
        if (btnAdd) {
            btnAdd.addEventListener('click', function() {
                addAnggotaRow('');
                markAnggotaAsModified();
            });
        }

        // Setup initial inputs input listener
        document.querySelectorAll('.anggota-item-input').forEach(input => {
            input.addEventListener('input', function() {
                markAnggotaAsModified();
            });
        });
    });
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
