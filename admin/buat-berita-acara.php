<?php
// admin/buat-berita-acara.php
$page_css = 'arsip-surat';
require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();
$error = '';
$success = '';

// Helper Bulan Romawi
function getRomawiBulan($bulan) {
    $romawi = ['1'=>'I', '2'=>'II', '3'=>'III', '4'=>'IV', '5'=>'V', '6'=>'VI', '7'=>'VII', '8'=>'VIII', '9'=>'IX', '10'=>'X', '11'=>'XI', '12'=>'XII'];
    return $romawi[(int)$bulan] ?? 'I';
}

// Mode Edit Setup
$edit_id = (isset($_GET['edit']) && is_numeric($_GET['edit'])) ? (int)$_GET['edit'] : 0;
$clone_id = (isset($_GET['clone']) && is_numeric($_GET['clone'])) ? (int)$_GET['clone'] : 0;
$is_edit = false;
$is_clone = false;
$edit_data = [];

$target_id = $edit_id > 0 ? $edit_id : $clone_id;

if ($target_id > 0) {
    $existing = dbFetchOne("SELECT * FROM arsip_berita_acara WHERE id = ? AND periode_id = ?", [$target_id, $periode_id], "ii");
    if ($existing) {
        if ($edit_id > 0) $is_edit = true;
        if ($clone_id > 0) $is_clone = true;
        $konten = json_decode($existing['konten_json'], true) ?: [];
        $edit_data = array_merge($existing, $konten);
        
        // Extract sequence number from nomor_berita (e.g. 020/BA-KULIAHUMUM/BEM/VI/2026 -> 020)
        $parts = explode('/', $existing['nomor_berita']);
        $edit_data['nomor_urut'] = $parts[0] ?? '';
        $edit_data['kode_kegiatan'] = isset($parts[1]) ? str_replace('BA-', '', $parts[1]) : '';
    } else {
        $error = "Data arsip berita acara tidak ditemukan atau hak akses ditolak.";
    }
}

function getLastSequence($periode_id) {
    // Ambil nomor urut tertinggi dari tabel arsip_berita_acara
    $last = dbFetchOne(
        "SELECT MAX(CAST(SUBSTRING_INDEX(nomor_berita, '/', 1) AS UNSIGNED)) AS max_urut 
         FROM arsip_berita_acara 
         WHERE periode_id = ?",
        [$periode_id], "i"
    );
    return ($last && $last['max_urut']) ? (int)$last['max_urut'] : 0;
}

$count_BA = getLastSequence($periode_id);
$next_urut_default = str_pad($count_BA + 1, 3, '0', STR_PAD_LEFT);
if ($is_edit || $is_clone) {
    $next_urut_default = $edit_data['nomor_urut'] ?? $next_urut_default;
}

$bulan_romawi = getRomawiBulan(date('n'));
$tahun = date('Y');

// Helper to save base64 signature
function saveSignatureToFile($base64String, $prefix = 'ttd') {
    if (empty($base64String)) return '';
    if (strpos($base64String, 'data:image') === false) return $base64String; // If already a path
    $dir = rtrim(UPLOAD_PATH, '/\\') . '/ttd/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $data = explode(',', $base64String);
    if (!isset($data[1])) return '';
    $imgData = base64_decode($data[1]);
    $filename = $prefix . '_' . uniqid() . '.png';
    file_put_contents($dir . $filename, $imgData);
    return 'ttd/' . $filename;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid. Silakan muat ulang halaman.';
    } else {
        $action_type = $_POST['action_type'] ?? 'insert';
        $nomor_urut = sanitizeText($_POST['nomor_urut'], 10);
        $kode_keg = strtoupper(str_replace(' ', '', sanitizeText($_POST['kode_kegiatan'], 50)));
        $nomor_berita = "{$nomor_urut}/BA-{$kode_keg}/BEM/{$bulan_romawi}/{$tahun}";
        
        $nama_kegiatan = sanitizeText($_POST['nama_kegiatan'], 255);
        
        $tanggal_kegiatan_raw = sanitizeText($_POST['tanggal_kegiatan'] ?? '', 100);
        $tanggal_kegiatan = '';
        $hari_kegiatan = 'Senin';
        if (!empty($tanggal_kegiatan_raw)) {
            $timestamp = strtotime($tanggal_kegiatan_raw);
            if ($timestamp !== false) {
                $tanggal_kegiatan = tanggalIndonesia($timestamp);
                $hari_array = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
                $hari_kegiatan = $hari_array[(int)date('w', $timestamp)];
            } else {
                $tanggal_kegiatan = $tanggal_kegiatan_raw;
            }
        }
        
        $tempat = sanitizeText($_POST['tempat'], 255);
        $waktu = sanitizeText($_POST['waktu'], 100);
        
        // Extract start time automatically using regex (e.g. "08.00" or "08:00")
        $waktu_mulai = '08.00';
        if (preg_match('/(\d{2}[\.:]\d{2})/', $waktu, $matches)) {
            $waktu_mulai = $matches[1];
        }
        
        // Dynamic lists
        $rincian_kegiatan = $_POST['rincian_kegiatan'] ?? [];
        $rincian_kegiatan = array_filter(array_map('sanitizeText', $rincian_kegiatan));
        
        $tujuan = $_POST['tujuan'] ?? [];
        $tujuan = array_filter(array_map('sanitizeText', $tujuan));
        
        $manfaat = $_POST['manfaat'] ?? [];
        $manfaat = array_filter(array_map('sanitizeText', $manfaat));
        
        // Signatures name and NUPTK
        $ketua_bem_nama = sanitizeText($_POST['ketua_bem_nama'] ?? '', 100);
        $sekretaris_bem_nama = sanitizeText($_POST['sekretaris_bem_nama'] ?? '', 100);
        $warek_nama = sanitizeText($_POST['warek_nama'] ?? '', 100);
        $warek_nuptk = sanitizeText($_POST['warek_nuptk'] ?? '', 50);
        
        $use_ttd_presma = isset($_POST['use_ttd_presma']) ? '1' : '0';
        $use_cap_presma = isset($_POST['use_cap_presma']) ? '1' : '0';
        $use_ttd_sekretaris = isset($_POST['use_ttd_sekretaris']) ? '1' : '0';
        $use_ttd_warek = isset($_POST['use_ttd_warek']) ? '1' : '0';
        $use_cap_warek = isset($_POST['use_cap_warek']) ? '1' : '0';
        
        // Laporan fields (Hardcoded & Server Time)
        $pelaksana_tipe = sanitizeText($_POST['pelaksana_tipe'] ?? 'BEM', 100);
        $kolaborasi_instbunas = isset($_POST['kolaborasi_instbunas']) ? '1' : '0';
        
        if ($pelaksana_tipe === 'BEM') {
            $base_pelaksana = 'Badan Eksekutif Mahasiswa (BEM)';
        } elseif ($pelaksana_tipe === 'BPH') {
            $base_pelaksana = 'Badan Pengurus Harian (BPH) BEM';
        } else {
            $base_pelaksana = $pelaksana_tipe . ' BEM';
        }
        
        if ($kolaborasi_instbunas === '1') {
            $pelaksana_kegiatan = $base_pelaksana . ' & INSTBUNAS Majalengka';
        } else {
            $pelaksana_kegiatan = $base_pelaksana;
        }

        $bentuk_kegiatan = sanitizeHtml($_POST['bentuk_kegiatan'] ?? '');
        $tempat_pembuatan = 'Majalengka';
        $tanggal_pembuatan = tanggalIndonesia();
        
        // Process Dokumentasi Photos
        $dokumentasi = [];
        $doc_indices = isset($_POST['doc_caption']) ? array_keys($_POST['doc_caption']) : [];
        foreach ($doc_indices as $i) {
            $caption = sanitizeText($_POST['doc_caption'][$i] ?? '', 255);
            $existing_img = $_POST['doc_existing_img'][$i] ?? '';
            
            $delete_existing = isset($_POST['doc_delete_existing'][$i]) && $_POST['doc_delete_existing'][$i] === '1';
            $img_path = $existing_img;
            
            if ($delete_existing && !empty($existing_img)) {
                deleteFile($existing_img);
                $img_path = '';
            }
            
            if (isset($_FILES['doc_img']['name'][$i]) && !empty($_FILES['doc_img']['name'][$i])) {
                $file_err = $_FILES['doc_img']['error'][$i];
                if ($file_err === UPLOAD_ERR_OK) {
                    if (!empty($img_path)) {
                        deleteFile($img_path);
                    }
                    $file_array = [
                        'name' => $_FILES['doc_img']['name'][$i],
                        'type' => $_FILES['doc_img']['type'][$i],
                        'tmp_name' => $_FILES['doc_img']['tmp_name'][$i],
                        'error' => $_FILES['doc_img']['error'][$i],
                        'size' => $_FILES['doc_img']['size'][$i]
                    ];
                    $uploaded = uploadFile($file_array, 'umum');
                    if ($uploaded) {
                        $img_path = $uploaded;
                    } else {
                        $error = "Gagal mengunggah foto slot " . ($i + 1) . ". Format tidak didukung atau terjadi kesalahan server.";
                    }
                } elseif ($file_err !== UPLOAD_ERR_NO_FILE) {
                    if ($file_err === UPLOAD_ERR_INI_SIZE) {
                        $server_limit = ini_get('upload_max_filesize');
                        $error = "Foto slot " . ($i + 1) . " terlalu besar. (Batas Server: $server_limit, silahkan hubungi administrator).";
                    } elseif ($file_err === UPLOAD_ERR_FORM_SIZE) {
                        $maxMB = round(MAX_FILE_SIZE / 1024 / 1024, 2);
                        $error = "Foto slot " . ($i + 1) . " terlalu besar. Ukuran maksimal file adalah {$maxMB}MB.";
                    } else {
                        $error = "Gagal mengunggah foto slot " . ($i + 1) . " (Kode Error: $file_err).";
                    }
                }
            }
            
            if (!empty($img_path)) {
                $dokumentasi[] = [
                    'image' => $img_path,
                    'caption' => $caption
                ];
            }
        }
        
        // Compare with old dokumentasi to delete orphaned files from disk
        if (empty($error) && $is_edit && isset($edit_data['dokumentasi'])) {
            $old_imgs = [];
            foreach ($edit_data['dokumentasi'] as $old_doc) {
                if (!empty($old_doc['image'])) {
                    $old_imgs[] = $old_doc['image'];
                }
            }
            
            $new_imgs = [];
            foreach ($dokumentasi as $new_doc) {
                if (!empty($new_doc['image'])) {
                    $new_imgs[] = $new_doc['image'];
                }
            }
            
            foreach ($old_imgs as $old_img) {
                if (!in_array($old_img, $new_imgs)) {
                    deleteFile($old_img);
                }
            }
        }
        
        if (empty($error)) {
            // Assemble JSON Content
            $konten_data = [
                'tema_kegiatan' => sanitizeText($_POST['tema_kegiatan'] ?? '', 255),
                'hari_kegiatan' => $hari_kegiatan,
                'waktu_mulai' => $waktu_mulai,
                'nama_rektor' => 'Dr. H. Sudibyo BO, S.Sos., S.E., M.M.',
                'nama_bupati' => 'Drs. H. Eman Suherman, M.M.',
                'rincian_kegiatan' => array_values($rincian_kegiatan),
                'tempat_pembuatan' => $tempat_pembuatan,
                'tanggal_pembuatan' => $tanggal_pembuatan,
                'ketua_bem_nama' => $ketua_bem_nama,
                'use_ttd_presma' => $use_ttd_presma,
                'use_cap_presma' => $use_cap_presma,
                'sekretaris_bem_nama' => $sekretaris_bem_nama,
                'use_ttd_sekretaris' => $use_ttd_sekretaris,
                'warek_nama' => $warek_nama,
                'warek_nuptk' => $warek_nuptk,
                'use_ttd_warek' => $use_ttd_warek,
                'use_cap_warek' => $use_cap_warek,
                'pelaksana_kegiatan' => $pelaksana_kegiatan,
                'pelaksana_tipe' => $pelaksana_tipe,
                'program_kerja' => sanitizeText($_POST['program_kerja'] ?? '', 255),
                'penanggung_jawab' => sanitizeText($_POST['penanggung_jawab'] ?? '', 255),
                'kolaborasi_instbunas' => $kolaborasi_instbunas,
                'tujuan' => array_values($tujuan),
                'manfaat' => array_values($manfaat),
                'bentuk_kegiatan' => $bentuk_kegiatan,
                'dokumentasi' => $dokumentasi,
                'tanggal_kegiatan' => $tanggal_kegiatan_raw
            ];
            
            $konten_json = json_encode($konten_data);
            $created_by = $_SESSION['admin_id'];
            
            if ($konten_json === false) {
                $error = 'Gagal memproses data (JSON Error).';
            } else {
                try {
                    if ($action_type === 'update' && $is_edit) {
                        dbQuery(
                            "UPDATE arsip_berita_acara SET nomor_berita = ?, tanggal_kegiatan = ?, nama_kegiatan = ?, tempat = ?, waktu = ?, konten_json = ? WHERE id = ? AND periode_id = ?",
                            [$nomor_berita, $tanggal_kegiatan, $nama_kegiatan, $tempat, $waktu, $konten_json, $edit_id, $periode_id],
                            "ssssssii"
                        );
                        auditLog('UPDATE', 'arsip_berita_acara', $edit_id, 'Mengubah arsip berita acara: ' . $nomor_berita);
                        
                        // === TWO-WAY SYNC: BA → LPJ ===
                        // Cari semua LPJ yang memiliki proker dengan berita_acara_id == edit_id
                        $all_lpjs = dbFetchAll("SELECT id, proker_terlaksana FROM lpj_dokumen WHERE periode_id = ?", [$periode_id], "i");
                        foreach ($all_lpjs as $lpj_row) {
                            $pt_arr = json_decode($lpj_row['proker_terlaksana'], true) ?: [];
                            $modified = false;
                            foreach ($pt_arr as &$pt_item) {
                                if (isset($pt_item['berita_acara_id']) && (int)$pt_item['berita_acara_id'] === (int)$edit_id) {
                                    // Sync field dari BA ke LPJ
                                    $pt_item['Nama Kegiatan'] = $nama_kegiatan;
                                    $pt_item['Tempat Kegiatan'] = $tempat;
                                    $pt_item['Tema Kegiatan'] = $konten_data['tema_kegiatan'] ?? $pt_item['Tema Kegiatan'];
                                    $pt_item['Nama Program Kerja'] = $konten_data['program_kerja'] ?? $pt_item['Nama Program Kerja'];
                                    $pt_item['Penanggung Jawab'] = $konten_data['penanggung_jawab'] ?? $pt_item['Penanggung Jawab'];
                                    if (!empty($konten_data['tujuan']) && is_array($konten_data['tujuan'])) {
                                        $pt_item['Tujuan'] = $konten_data['tujuan'];
                                    }
                                    // Sync tanggal (hari + tanggal)
                                    $pt_item['Tanggal Kegiatan'] = ($konten_data['hari_kegiatan'] ?? '') . ', ' . $tanggal_kegiatan;
                                    
                                    // Sync dokumentasi foto dari BA ke LPJ
                                    if (!empty($konten_data['dokumentasi']) && is_array($konten_data['dokumentasi'])) {
                                        $sync_docs = [];
                                        foreach ($konten_data['dokumentasi'] as $ba_dok) {
                                            $img_path = $ba_dok['image'] ?? '';
                                            if (strpos($img_path, '/var/www/html') === false) {
                                                $img_path = UPLOAD_PATH . '/' . ltrim($img_path, '/');
                                            }
                                            $sync_docs[] = [
                                                'file_path' => $img_path,
                                                'caption' => $ba_dok['caption'] ?? 'Dokumentasi'
                                            ];
                                        }
                                        $pt_item['dokumentasi'] = $sync_docs;
                                    }
                                    
                                    $modified = true;
                                }
                            }
                            unset($pt_item);
                            if ($modified) {
                                $new_pt_json = json_encode($pt_arr);
                                // Also re-aggregate dokumentasi
                                $new_dok = [];
                                foreach ($pt_arr as $ptx) {
                                    foreach (($ptx['dokumentasi'] ?? []) as $dok) {
                                        $new_dok[] = $dok;
                                    }
                                }
                                $new_dok_json = json_encode($new_dok);
                                dbQuery("UPDATE lpj_dokumen SET proker_terlaksana = ?, dokumentasi = ? WHERE id = ?", 
                                    [$new_pt_json, $new_dok_json, $lpj_row['id']], "ssi");
                            }
                        }
                        
                        redirect('admin/cetak-berita-acara.php?id=' . $edit_id, 'Berita Acara berhasil diperbarui!', 'success');
                    } else {
                        dbQuery(
                            "INSERT INTO arsip_berita_acara (periode_id, created_by, nomor_berita, tanggal_kegiatan, nama_kegiatan, tempat, waktu, konten_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                            [$periode_id, $created_by, $nomor_berita, $tanggal_kegiatan, $nama_kegiatan, $tempat, $waktu, $konten_json],
                            "iissssss"
                        );
                        $new_id = dbLastId();
                        auditLog('CREATE', 'arsip_berita_acara', $new_id, 'Membuat berita acara baru: ' . $nomor_berita);
                        redirect('admin/cetak-berita-acara.php?id=' . $new_id, 'Berita Acara berhasil dibuat!', 'success');
                    }
                    exit();
                } catch (Exception $e) {
                    $error = 'Terjadi kesalahan saat menyimpan ke database: ' . $e->getMessage();
                }
            }
        }
    }
}

// Ambil Pengaturan Umum (Tanda Tangan)
$db_pengaturan = dbFetchAll("SELECT kunci, nilai FROM pengaturan");
$pengaturan = [];
foreach($db_pengaturan as $p) {
    $pengaturan[$p['kunci']] = $p['nilai'];
}
$list_kegiatan = dbFetchAll("SELECT * FROM surat_templates WHERE periode_id = ? AND jenis = 'kegiatan' ORDER BY label ASC", [$periode_id], "i");
$list_kementerian = dbFetchAll("SELECT nama, proker FROM kementerian WHERE periode_id = ? ORDER BY urutan ASC", [$periode_id], "i");

$proker_map = [];
foreach ($list_kementerian as $kem) {
    $proker_map[$kem['nama']] = !empty($kem['proker']) ? (json_decode($kem['proker'], true) ?: []) : [];
}

$list_anggota_bph = dbFetchAll("SELECT nama FROM anggota_bph WHERE periode_id = ?", [$periode_id], "i");
$list_anggota_kem = dbFetchAll("
    SELECT k.nama as kem_nama, ak.nama as anggota_nama 
    FROM anggota_kementerian ak
    JOIN kementerian k ON ak.kementerian_id = k.id
    WHERE ak.periode_id = ?
", [$periode_id], "i");

$anggota_map = [
    'BEM' => [],
    'BPH' => []
];

foreach($list_anggota_bph as $m) {
    $anggota_map['BEM'][] = $m['nama'];
    $anggota_map['BPH'][] = $m['nama'];
}

foreach($list_anggota_kem as $m) {
    if(!isset($anggota_map[$m['kem_nama']])) {
        $anggota_map[$m['kem_nama']] = [];
    }
    $anggota_map[$m['kem_nama']][] = $m['anggota_nama'];
    $anggota_map['BEM'][] = $m['anggota_nama'];
}

foreach ($list_kementerian as $kem) {
    if(!isset($anggota_map[$kem['nama']])) {
        $anggota_map[$kem['nama']] = [];
    }
}

$ketua = getKetua($periode_id);
$fallback_presma = $ketua ? ($ketua['nama'] ?? $ketua['nama_lengkap'] ?? '') : '';
$def_presma_name = $pengaturan['ttd_presma_name'] ?? $fallback_presma;

$sekretaris = getSekretarisUmum($periode_id);
$fallback_sekretaris = '';
if ($sekretaris) {
    $fallback_sekretaris = $sekretaris['nama'] ?? '';
    if (empty($fallback_sekretaris) && !empty($sekretaris['anggota'])) {
        $fallback_sekretaris = $sekretaris['anggota'][0]['nama'] ?? '';
    }
}
$def_sekretaris_name = $pengaturan['ttd_sekretaris_name'] ?? $fallback_sekretaris;
$def_warek_name = $pengaturan['ttd_warek_name'] ?? 'II MUHAMAD MISBAH, S.Pd.I., SE., MM.';

// Defaults
$def = [
    'nomor_urut' => $next_urut_default,
    'kode_kegiatan' => '',
    'nama_kegiatan' => '',
    'tema_kegiatan' => '',
    'tanggal_kegiatan' => tanggalIndonesia(),
    'hari_kegiatan' => tanggalIndonesia(null, true) ? explode(',', tanggalIndonesia(null, true))[0] : 'Senin',
    'tempat' => 'Institut Budi Utomo Nasional Majalengka',
    'waktu' => '08.00 WIB s.d Selesai',
    'waktu_mulai' => '08.00',
    'nama_rektor' => 'Dr. H. Sudibyo BO, S.Sos., S.E., M.M.',
    'nama_bupati' => 'Drs. H. Eman Suherman, M.M.',
    'rincian_kegiatan' => [
        'Pembukaan',
        'Menyanyikan Lagu Kebangsaan Indonesia Raya',
        'Sambutan Rektor INSTBUNAS',
        'Doa dan Penutup'
    ],
    'tempat_pembuatan' => 'Majalengka',
    'tanggal_pembuatan' => tanggalIndonesia(),
    'ketua_bem_nama' => $def_presma_name,
    'use_ttd_presma' => '1',
    'use_cap_presma' => '1',
    'sekretaris_bem_nama' => $def_sekretaris_name,
    'use_ttd_sekretaris' => '1',
    'warek_nama' => $def_warek_name,
    'warek_nuptk' => '7756762662200002',
    'use_ttd_warek' => '1',
    'use_cap_warek' => '1',
    'pelaksana_kegiatan' => 'Badan Eksekutif Mahasiswa (BEM) & INSTBUNAS Majalengka',
    'tujuan' => [
        'Meningkatkan tali persaudaraan antar mahasiswa.',
        'Meningkatkan jiwa kepemimpinan dan rasa tanggung jawab.'
    ],
    'manfaat' => [
        'Meningkatkan kerja sama antar tim.',
        'Menambah wawasan dan pengalaman kepanitiaan.'
    ],
    'bentuk_kegiatan' => '<p>Kegiatan ini diawali dengan pembukaan oleh pembawa acara, dilanjutkan dengan menyanyikan lagu Indonesia Raya secara khidmat. Acara kemudian dilanjutkan dengan sambutan-sambutan hangat dari Rektor INSTBUNAS and ditutup dengan doa bersama. Seluruh audiens mengikuti rangkaian acara dengan penuh antusiasme.</p>',
    'dokumentasi' => []
];

if ($is_edit || $is_clone) {
    foreach($def as $k=>$v) {
        if(!isset($edit_data[$k])) $edit_data[$k] = $v;
    }
    if ($is_clone) {
        $edit_data['nomor_urut'] = $next_urut_default;
    }
} else {
    $edit_data = $def;
}

$pelaksana_tipe_val = $edit_data['pelaksana_tipe'] ?? 'BEM';
$kolaborasi_val = $edit_data['kolaborasi_instbunas'] ?? '1';

if (!isset($edit_data['pelaksana_tipe']) && isset($edit_data['pelaksana_kegiatan'])) {
    $pk = $edit_data['pelaksana_kegiatan'];
    if (strpos($pk, '& INSTBUNAS Majalengka') !== false) {
        $kolaborasi_val = '1';
        $base = trim(str_replace('& INSTBUNAS Majalengka', '', $pk));
    } else {
        $kolaborasi_val = '0';
        $base = trim($pk);
    }
    
    if ($base === 'Badan Eksekutif Mahasiswa (BEM)') {
        $pelaksana_tipe_val = 'BEM';
    } elseif ($base === 'Badan Pengurus Harian (BPH) BEM') {
        $pelaksana_tipe_val = 'BPH';
    } else {
        $pelaksana_tipe_val = trim(preg_replace('/\s+BEM$/i', '', $base));
    }
}

// Convert tanggal_kegiatan to YYYY-MM-DD for html5 calendar date input
$tanggal_kegiatan_val = $edit_data['tanggal_kegiatan'] ?? '';
if (!empty($tanggal_kegiatan_val)) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_kegiatan_val)) {
        $ts = strtotime($tanggal_kegiatan_val);
        if ($ts !== false) {
            $tanggal_kegiatan_val = date('Y-m-d', $ts);
        } else {
            $ind_months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            $eng_months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            $english_date = str_replace($ind_months, $eng_months, $tanggal_kegiatan_val);
            $ts = strtotime($english_date);
            if ($ts !== false) {
                $tanggal_kegiatan_val = date('Y-m-d', $ts);
            } else {
                $tanggal_kegiatan_val = date('Y-m-d');
            }
        }
    }
} else {
    $tanggal_kegiatan_val = date('Y-m-d');
}
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --secondary-bg: #0f1217;
    --accent-color: #4A90E2;
    --card-bg: rgba(15, 18, 23, 0.95);
    --input-bg: #0a0c10;
    --border-color: #2a3545;
    --text-muted: #aaa;
    --text-main: #fff;
    --shadow-premium: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
}

.buat-ba-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.buat-ba-container .page-header h1 {
    font-weight: 700;
    letter-spacing: -0.5px;
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 30px;
}

.buat-ba-container .card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    margin-bottom: 24px;
    backdrop-filter: blur(10px);
    box-shadow: var(--shadow-premium);
    transition: border-color 0.3s ease;
}

.buat-ba-container .card:hover {
    border-color: rgba(74, 144, 226, 0.4);
}

.buat-ba-container .card-header {
    background: rgba(74, 144, 226, 0.05);
    padding: 20px 24px;
    border-bottom: 1px solid var(--border-color);
    font-weight: 600;
    font-size: 1.1rem;
    color: #8BB9F0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.buat-ba-container .card-body {
    padding: 24px;
}

.buat-ba-container .form-group {
    margin-bottom: 1.5rem;
}

.buat-ba-container label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-muted);
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.buat-ba-container input,
.buat-ba-container select,
.buat-ba-container textarea {
    background: var(--input-bg);
    border: 1.5px solid var(--border-color);
    border-radius: 14px;
    padding: 12px 16px;
    color: var(--text-main);
    width: 100%;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 0.95rem;
}

.buat-ba-container input:focus,
.buat-ba-container select:focus,
.buat-ba-container textarea:focus {
    border-color: var(--accent-color);
    outline: none;
    box-shadow: 0 0 0 4px rgba(74, 144, 226, 0.15);
}

.buat-ba-container .grid-2 {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

@media (min-width: 768px) {
    .buat-ba-container .grid-2 {
        grid-template-columns: repeat(2, 1fr);
    }
}

.buat-ba-container .grid-3 {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

@media (min-width: 768px) {
    .buat-ba-container .grid-3 {
        grid-template-columns: repeat(3, 1fr);
    }
}

.dynamic-list-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}

.btn-remove-row {
    background: #e74c3c;
    color: white;
    border: none;
    border-radius: 10px;
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: background 0.2s;
}

.btn-remove-row:hover {
    background: #c0392b;
}

.btn-add-row {
    background: rgba(74, 144, 226, 0.1);
    color: #8BB9F0;
    border: 1px dashed var(--accent-color);
    border-radius: 12px;
    padding: 10px;
    width: 100%;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 10px;
}

.btn-add-row:hover {
    background: rgba(74, 144, 226, 0.2);
}

.signature-box {
    background: #0f131a;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 15px;
}

.signature-title {
    font-size: 0.85rem;
    font-weight: bold;
    color: #8BB9F0;
    margin-bottom: 12px;
    text-transform: uppercase;
}

.buat-ba-container canvas {
    border: 1.5px dashed var(--border-color);
    background: #07090c;
    border-radius: 12px;
    cursor: crosshair;
    display: block;
    margin: 0 auto;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border-color);
    color: #ccc;
    padding: 8px 16px;
    border-radius: 10px;
    cursor: pointer;
    font-size: 0.85rem;
    transition: all 0.2s;
}

.btn-outline:hover {
    border-color: var(--accent-color);
    color: #fff;
}

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
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 15px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.photo-preview-wrap {
    width: 100%;
    height: 150px;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    overflow: hidden;
    background: #05070a;
    display: flex;
    align-items: center;
    justify-content: center;
}

.photo-preview-wrap img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    margin-top: 30px;
}

.btn-primary-gradient {
    background: var(--primary-gradient);
    color: #111;
    border: none;
    font-weight: 700;
    padding: 14px 28px;
    border-radius: 14px;
    cursor: pointer;
    transition: transform 0.2s, box-shadow 0.2s;
    box-shadow: 0 4px 15px rgba(0, 242, 254, 0.3);
}

.btn-primary-gradient:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(0, 242, 254, 0.5);
}

.btn-secondary-dark {
    background: #1e2430;
    color: white;
    border: 1px solid var(--border-color);
    font-weight: 600;
    padding: 14px 28px;
    border-radius: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-secondary-dark:hover {
    background: #2b3344;
}

/* Template Picker refinement for Berita Acara */
.buat-ba-container .tpl-picker {
    position: relative;
}
.buat-ba-container .tpl-search-input {
    padding-left: 44px !important;
}
.buat-ba-container .tpl-search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--accent-color);
    font-size: 1rem;
    pointer-events: none;
    z-index: 5;
}
.buat-ba-container .tpl-results {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    right: 0;
    background: #121822;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    max-height: 280px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 10px 40px rgba(0,0,0,0.5);
    animation: fadeInDown 0.2s ease-out;
    padding-bottom: 8px;
}
@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.buat-ba-container .tpl-item {
    padding: 12px 18px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: background 0.2s;
}
.buat-ba-container .tpl-item:last-child { border-bottom: none; }
.buat-ba-container .tpl-item:hover {
    background: rgba(74, 144, 226, 0.1);
}
.buat-ba-container .tpl-item-label {
    font-weight: 700;
    color: #8BB9F0;
    font-size: 0.9rem;
    margin-bottom: 2px;
}
.buat-ba-container .tpl-item-text {
    font-size: 0.75rem;
    color: #777;
    display: block;
}
.buat-ba-container .tpl-empty {
    padding: 12px 18px;
    color: #777;
    text-align: center;
    font-size: 0.9rem;
}

/* WAKTU PELAKSANAAN (PRESERVED) */
.buat-ba-container .wakpel-card { background: rgba(0,0,0,0.2); border-radius: 20px; padding: 20px; border: 1px solid var(--border-color); }
.buat-ba-container .preview-bar { background: rgba(74,144,226,0.08); border-radius: 12px; padding: 12px 16px; font-size: 0.85rem; margin-top: 15px; color: #8BB9F0; border-left: 4px solid var(--accent-color); }

/* Drum Picker Refinement (Wheel Effect) */
.drum-col { width: 58px; height: 168px; background: #080808; border-radius: 12px; overflow: hidden; position: relative; cursor: ns-resize; border: 1px solid #222; }
.drum-inner { position: absolute; top: 0; left: 0; width: 100%; transition: transform 0.2s cubic-bezier(0.1, 0.7, 1.0, 0.1); will-change: transform; padding: 4px 0; }
.drum-item { height: 40px; line-height: 40px; text-align: center; font-size: 1.1rem; color: #444; transition: all 0.2s; opacity: 0.3; filter: blur(1px); }
.drum-item.sel { color: #fff; font-weight: 700; opacity: 1; transform: scale(1.1); filter: blur(0); }
.drum-item.near1 { opacity: 0.6; filter: blur(0.5px); }
.drum-item.near2 { opacity: 0.3; filter: blur(1px); }
.drum-highlight { position: absolute; top: 64px; left: 4px; right: 4px; height: 40px; background: rgba(74, 144, 226, 0.15); border-radius: 8px; border: 1px solid rgba(74, 144, 226, 0.3); pointer-events: none; z-index: 5; }
.drum-group { display: flex; align-items: center; gap: 8px; }
.drum-arrow { background: #1a1a1a; border: 1px solid #333; color: #777; font-size: 0.8rem; cursor: pointer; padding: 4px 10px; border-radius: 8px; transition: all 0.2s; display: block; width: 100%; }
.drum-arrow-up { margin-bottom: 5px; }
.drum-arrow-down { margin-top: 5px; }
.drum-arrow:hover { background: #333; color: #fff; }
.drum-time-label { font-size: 0.7rem; color: #555; text-transform: uppercase; margin-bottom: 8px; font-weight: 700; }
.drum-groups-wrap { display: flex; gap: 20px; align-items: flex-start; margin-top: 15px; flex-wrap: wrap; }
.drum-colon { color: var(--accent-color); font-weight: 700; font-size: 1.2rem; padding-top: 104px; }
@media (max-width: 600px) {
    .buat-ba-container .drum-groups-wrap { justify-content: center; }
    .drum-colon { display: none; }
}
</style>

<div class="buat-ba-container">
    <div class="page-header">
        <h1>
            <i class="fas fa-pen-nib"></i>
            <span><?php echo $is_edit ? 'Edit Berita Acara & Laporan' : 'Buat Berita Acara Otomatis'; ?></span>
        </h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 20px; background: rgba(231,76,60,0.1); border: 1px solid #e74c3c; color: #ff6b6b; padding: 15px; border-radius: 12px; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" id="form-berita-acara">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action_type" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">

        <!-- CARD 1: INFORMASI NOMOR & KEGIATAN (PAGE 1) -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-id-card"></i>
                <span>Bagian 1: Berita Acara & Rincian Acara</span>
            </div>
            <div class="card-body">
                <div class="grid-3">
                    <div class="form-group">
                        <label>Nomor Urut Berita Acara</label>
                        <input type="text" name="nomor_urut" value="<?php echo htmlspecialchars($edit_data['nomor_urut']); ?>" placeholder="Cth: 020" required>
                    </div>
                    <div class="form-group">
                        <label>Kode / Slug Kegiatan</label>
                        <div class="tpl-picker" id="picker-kegiatan">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" id="kode_kegiatan_input" name="kode_kegiatan" class="tpl-search-input" placeholder="Cari atau ketik kode..." value="<?php echo htmlspecialchars($edit_data['kode_kegiatan']); ?>" required onfocus="showTplResults('kegiatan')" onkeyup="filterTpl('kegiatan')">
                            <div class="tpl-results" id="results-kegiatan">
                                <?php foreach($list_kegiatan as $k): ?>
                                <div class="tpl-item" onclick='selectKegiatan(<?php echo json_encode(["nama" => $k["label"], "kode" => $k["perihal_default"]]); ?>)'>
                                    <div class="tpl-item-label"><?php echo htmlspecialchars($k['label']); ?></div>
                                    <div class="tpl-item-text">Kode: <?php echo htmlspecialchars($k['perihal_default']); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <span style="font-size: 0.75rem; color: #666; margin-top: 4px; display: block;">Format nomor: XXX/BA-[KODE]/BEM/<?php echo $bulan_romawi; ?>/<?php echo $tahun; ?></span>
                    </div>
                    <div class="form-group">
                        <label>Nama Kegiatan</label>
                        <input type="text" id="input_nama_kegiatan" name="nama_kegiatan" value="<?php echo htmlspecialchars($edit_data['nama_kegiatan']); ?>" placeholder="Cth: Kuliah Umum" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Tema Kegiatan</label>
                        <input type="text" name="tema_kegiatan" value="<?php echo htmlspecialchars($edit_data['tema_kegiatan']); ?>" placeholder="Cth: Arah Transformasi Majalengka..." required>
                    </div>
                    <div class="form-group">
                        <label>Tempat Pelaksanaan</label>
                        <input type="text" name="tempat" value="<?php echo htmlspecialchars($edit_data['tempat']); ?>" placeholder="Cth: Pendopo Majalengka" required>
                    </div>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label>Tanggal Kegiatan</label>
                        <input type="date" name="tanggal_kegiatan" value="<?php echo htmlspecialchars($tanggal_kegiatan_val); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Waktu Kegiatan</label>
                        <div class="wakpel-card">
                            <input type="hidden" id="out-waktu" name="waktu" value="<?php echo htmlspecialchars($edit_data['waktu'] ?: '08.00 s.d Selesai'); ?>">
                            <div class="drum-groups-wrap" style="margin-top: 0;">
                                <div>
                                    <div class="drum-time-label">Mulai</div>
                                    <div class="drum-group">
                                        <div>
                                            <button type="button" class="drum-arrow drum-arrow-up" onclick="drumHS.scrollBy(-1)">▲</button>
                                            <div class="drum-col" id="drum-h-start"></div>
                                            <button type="button" class="drum-arrow drum-arrow-down" onclick="drumHS.scrollBy(1)">▼</button>
                                        </div>
                                        <span class="drum-colon">:</span>
                                        <div>
                                            <button type="button" class="drum-arrow drum-arrow-up" onclick="drumMS.scrollBy(-1)">▲</button>
                                            <div class="drum-col" id="drum-m-start"></div>
                                            <button type="button" class="drum-arrow drum-arrow-down" onclick="drumMS.scrollBy(1)">▼</button>
                                        </div>
                                    </div>
                                </div>
                                <div style="padding-top:24px; color:var(--text-muted); font-size:0.8rem;">s.d</div>
                                <div id="drum-end-wrap">
                                    <div class="drum-time-label">Selesai</div>
                                    <div class="drum-group">
                                        <div>
                                            <button type="button" class="drum-arrow drum-arrow-up" onclick="drumHE.scrollBy(-1)">▲</button>
                                            <div class="drum-col" id="drum-h-end"></div>
                                            <button type="button" class="drum-arrow drum-arrow-down" onclick="drumHE.scrollBy(1)">▼</button>
                                        </div>
                                        <span class="drum-colon">:</span>
                                        <div>
                                            <button type="button" class="drum-arrow drum-arrow-up" onclick="drumME.scrollBy(-1)">▲</button>
                                            <div class="drum-col" id="drum-m-end"></div>
                                            <button type="button" class="drum-arrow drum-arrow-down" onclick="drumME.scrollBy(1)">▼</button>
                                        </div>
                                    </div>
                                </div>
                                <div style="padding-top:24px;">
                                    <div class="toggle-switch-wrap" id="toggle-selesai-wrap" onclick="doToggleSelesai()" style="background: rgba(255,255,255,0.05); padding: 10px 14px; border-radius: 12px; border: 1px solid var(--border-color); cursor: pointer; display: flex; align-items: center; gap: 10px;">
                                        <div class="toggle-switch" id="ts-switch" style="position:relative; width:36px; height:20px; background:#222; border-radius:10px; transition: .3s;"><div class="toggle-knob" style="position:absolute; top:2px; left:2px; width:16px; height:16px; background:#fff; border-radius:50%; transition:.3s;"></div></div>
                                        <span class="toggle-label" id="ts-label" style="font-size:0.75rem; color:#888;">Tanpa waktu akhir</span>
                                    </div>
                                </div>
                            </div>
                            <div class="preview-bar" id="preview-waktu"><?php echo htmlspecialchars($edit_data['waktu'] ?: '08.00 s.d Selesai'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="grid-2" style="margin-top: 20px;">
                    <div class="form-group">
                        <label>Pelaksana Kegiatan (Organisasi / Kementerian)</label>
                        <div class="tpl-picker" id="picker-pelaksana">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" id="pelaksana_tipe_input" name="pelaksana_tipe" class="tpl-search-input" placeholder="Pilih Pelaksana Kegiatan..." value="<?php echo htmlspecialchars($pelaksana_tipe_val); ?>" required readonly onclick="showTplResults('pelaksana')" style="cursor:pointer; background-color: var(--input-bg);">
                            <div class="tpl-results" id="results-pelaksana">
                                <div class="tpl-item" onclick="selectPelaksana('BEM')">
                                    <div class="tpl-item-label">Badan Eksekutif Mahasiswa (BEM)</div>
                                </div>
                                <div class="tpl-item" onclick="selectPelaksana('BPH')">
                                    <div class="tpl-item-label">Badan Pengurus Harian (BPH)</div>
                                </div>
                                <?php foreach ($list_kementerian as $kem): ?>
                                    <div class="tpl-item" onclick="selectPelaksana('<?php echo htmlspecialchars($kem['nama'], ENT_QUOTES); ?>')">
                                        <div class="tpl-item-label"><?php echo htmlspecialchars($kem['nama']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Program Kerja</label>
                        <div class="tpl-picker" id="picker-proker">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" id="program_kerja_input" name="program_kerja" class="tpl-search-input" placeholder="Pilih Pelaksana Kegiatan terlebih dahulu..." value="<?php echo htmlspecialchars($edit_data['program_kerja'] ?? ''); ?>" disabled autocomplete="off" onfocus="showTplResults('proker')" onkeyup="filterTpl('proker')">
                            <div class="tpl-results" id="results-proker">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Penanggung Jawab</label>
                        <div class="tpl-picker" id="picker-pj">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" id="penanggung_jawab_input" name="penanggung_jawab" class="tpl-search-input" placeholder="Pilih Pelaksana Kegiatan terlebih dahulu..." value="<?php echo htmlspecialchars($edit_data['penanggung_jawab'] ?? ''); ?>" required disabled autocomplete="off" onfocus="showTplResults('pj')" onkeyup="filterTpl('pj')">
                            <div class="tpl-results" id="results-pj">
                            </div>
                        </div>
                    </div>
                    <div class="form-group" style="display: flex; align-items: center; padding-top: 30px;">
                        <label class="checkbox-container" style="display: flex; align-items: center; gap: 10px; cursor: pointer; user-select: none;">
                            <input type="checkbox" name="kolaborasi_instbunas" value="1" <?php echo $kolaborasi_val === '1' ? 'checked' : ''; ?> style="width: 20px; height: 20px; accent-color: var(--accent-color);">
                            <span style="font-size: 0.95rem; color: var(--text-main); font-weight: 500;">Kolaborasi dengan INSTBUNAS Majalengka</span>
                        </label>
                    </div>
                </div>

                <!-- Rincian Kegiatan (Dynamic Input List) -->
                <div class="form-group" style="margin-top: 20px;">
                    <label>Rincian Acara / Itinerary</label>
                    <div id="rincian-kegiatan-container">
                        <?php foreach ($edit_data['rincian_kegiatan'] as $item): ?>
                            <div class="dynamic-list-row">
                                <input type="text" name="rincian_kegiatan[]" value="<?php echo htmlspecialchars($item); ?>" required>
                                <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn-add-row" onclick="addRincianRow()"><i class="fas fa-plus"></i> Tambah Baris Rincian</button>
                </div>
            </div>
        </div>

        <!-- CARD 2: TANDA TANGAN (SIGNATURES) -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-pen-fancy"></i>
                <span>Bagian 2: Tanda Tangan & Pengesahan</span>
            </div>
            <div class="card-body">
                <div class="grid-3">
                    <!-- KETUA BEM -->
                    <div class="signature-box" style="padding: 20px; border-radius: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                        <div class="signature-title" style="font-weight: bold; font-size: 1.1rem; color: #8BB9F0; margin-bottom: 15px;"><i class="fas fa-user-graduate"></i> Ketua BEM</div>
                        <div class="form-group">
                            <label>Nama Lengkap</label>
                            <input type="text" name="ketua_bem_nama" value="<?php echo htmlspecialchars($edit_data['ketua_bem_nama']); ?>" required>
                        </div>
                        <div class="form-group" style="margin-top: 15px;">
                            <div class="switch-container" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <span class="switch-label" style="font-size: 0.9rem; color: var(--text-muted);">Sertakan TTD</span>
                                <label class="switch">
                                    <input type="checkbox" name="use_ttd_presma" value="1" <?php echo ($edit_data['use_ttd_presma'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="switch-container" style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="switch-label" style="font-size: 0.9rem; color: var(--text-muted);">Sertakan Cap BEM</span>
                                <label class="switch">
                                    <input type="checkbox" name="use_cap_presma" value="1" <?php echo ($edit_data['use_cap_presma'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- SEKRETARIS BEM -->
                    <div class="signature-box" style="padding: 20px; border-radius: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                        <div class="signature-title" style="font-weight: bold; font-size: 1.1rem; color: #8BB9F0; margin-bottom: 15px;"><i class="fas fa-file-signature"></i> Sekretaris BEM</div>
                        <div class="form-group">
                            <label>Nama Lengkap</label>
                            <input type="text" name="sekretaris_bem_nama" value="<?php echo htmlspecialchars($edit_data['sekretaris_bem_nama']); ?>" required>
                        </div>
                        <div class="form-group" style="margin-top: 15px;">
                            <div class="switch-container" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <span class="switch-label" style="font-size: 0.9rem; color: var(--text-muted);">Sertakan TTD</span>
                                <label class="switch">
                                    <input type="checkbox" name="use_ttd_sekretaris" value="1" <?php echo ($edit_data['use_ttd_sekretaris'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- WAREK III (MENGETAHUI) -->
                    <div class="signature-box" style="padding: 20px; border-radius: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color);">
                        <div class="signature-title" style="font-weight: bold; font-size: 1.1rem; color: #8BB9F0; margin-bottom: 15px;"><i class="fas fa-user-tie"></i> Warek III</div>
                        <div class="form-group">
                            <label>Nama Lengkap</label>
                            <input type="text" name="warek_nama" value="<?php echo htmlspecialchars($edit_data['warek_nama']); ?>" required>
                        </div>
                        <div class="form-group" style="margin-top: 10px;">
                            <label>NUPTK</label>
                            <input type="text" name="warek_nuptk" value="<?php echo htmlspecialchars($edit_data['warek_nuptk']); ?>" required>
                        </div>
                        <div class="form-group" style="margin-top: 15px;">
                            <div class="switch-container" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <span class="switch-label" style="font-size: 0.9rem; color: var(--text-muted);">Sertakan TTD</span>
                                <label class="switch">
                                    <input type="checkbox" name="use_ttd_warek" value="1" <?php echo ($edit_data['use_ttd_warek'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="switch-container" style="display: flex; justify-content: space-between; align-items: center;">
                                <span class="switch-label" style="font-size: 0.9rem; color: var(--text-muted);">Sertakan Cap Warek</span>
                                <label class="switch">
                                    <input type="checkbox" name="use_cap_warek" value="1" <?php echo ($edit_data['use_cap_warek'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD 3: LAPORAN KEGIATAN (PAGE 2) -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-file-invoice"></i>
                <span>Bagian 3: Laporan Kegiatan (Tujuan, Manfaat, & Deskripsi)</span>
            </div>
            <div class="card-body">
                <!-- Tujuan List -->
                <div class="form-group">
                    <label>Tujuan Kegiatan (List A. Tujuan)</label>
                    <div id="tujuan-container">
                        <?php foreach ($edit_data['tujuan'] as $item): ?>
                            <div class="dynamic-list-row">
                                <input type="text" name="tujuan[]" value="<?php echo htmlspecialchars($item); ?>" required>
                                <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn-add-row" onclick="addTujuanRow()"><i class="fas fa-plus"></i> Tambah Tujuan</button>
                </div>

                <!-- Manfaat List -->
                <div class="form-group" style="margin-top: 20px;">
                    <label>Manfaat Kegiatan (List B. Manfaat)</label>
                    <div id="manfaat-container">
                        <?php foreach ($edit_data['manfaat'] as $item): ?>
                            <div class="dynamic-list-row">
                                <input type="text" name="manfaat[]" value="<?php echo htmlspecialchars($item); ?>" required>
                                <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn-add-row" onclick="addManfaatRow()"><i class="fas fa-plus"></i> Tambah Manfaat</button>
                </div>

                <!-- Bentuk Kegiatan (Rich text or detailed text block) -->
                <div class="form-group" style="margin-top: 20px;">
                    <label>Bentuk Kegiatan (Penjelasan C. Bentuk Kegiatan)</label>
                    <textarea name="bentuk_kegiatan" rows="6" placeholder="Tulis rincian jalannya kegiatan..." required><?php echo htmlspecialchars($edit_data['bentuk_kegiatan']); ?></textarea>
                </div>
            </div>
        </div>

        <!-- CARD 4: DOKUMENTASI (PAGE 3) -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-images"></i>
                <span>Bagian 4: Dokumentasi Kegiatan</span>
            </div>
            <div class="card-body">
                <div class="photo-grid">
                    <?php 
                    $num_slots = max(4, count($edit_data['dokumentasi'] ?? []));
                    for ($i = 0; $i < $num_slots; $i++): 
                        $item = $edit_data['dokumentasi'][$i] ?? null;
                    ?>
                        <div class="photo-card" data-index="<?php echo $i; ?>">
                            <div class="photo-card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px;">
                                <label style="color:#8BB9F0; font-weight:bold; font-size:0.8rem; margin:0;">Foto Slot <?php echo $i + 1; ?></label>
                                <?php if ($i >= 4): ?>
                                    <button type="button" class="btn-remove-photo" style="background:none; border:none; color:#ff4d4d; cursor:pointer; font-size: 0.8rem;" onclick="removePhotoCard(this)"><i class="fas fa-times"></i> Hapus Slot</button>
                                <?php endif; ?>
                            </div>
                            
                            <div class="photo-preview-wrap">
                                <?php if ($item): ?>
                                    <img id="img_doc_preview_<?php echo $i; ?>" src="<?php echo uploadUrl($item['image']); ?>">
                                <?php else: ?>
                                    <img id="img_doc_preview_<?php echo $i; ?>" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg>">
                                <?php endif; ?>
                            </div>

                            <div class="form-group">
                                <label>Upload Foto</label>
                                <input type="file" name="doc_img[<?php echo $i; ?>]" accept="image/*" onchange="previewDocPhoto(<?php echo $i; ?>, this)">
                            </div>

                            <div class="form-group">
                                <label>Caption Foto</label>
                                <input type="text" name="doc_caption[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($item['caption'] ?? ''); ?>" placeholder="Cth: Foto Bersama Pemateri">
                            </div>

                            <?php if ($item): ?>
                                <input type="hidden" name="doc_existing_img[<?php echo $i; ?>]" value="<?php echo htmlspecialchars($item['image']); ?>">
                                <div class="switch-container" style="padding: 8px 12px; margin-top:5px; border-radius:10px;">
                                    <span class="switch-label" style="font-size:0.75rem;"><i class="fas fa-trash-alt"></i> Hapus Foto Ini?</span>
                                    <label class="switch">
                                        <input type="checkbox" name="doc_delete_existing[<?php echo $i; ?>]" value="1">
                                        <span class="slider"></span>
                                    </label>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn-primary-gradient" style="padding: 10px 15px; font-size: 0.9rem; border-radius: 8px; margin-top: 20px; width: 100%; display: flex; justify-content: center; align-items: center; gap: 8px;" onclick="addPhotoSlot()"><i class="fas fa-plus"></i> Tambah Slot Foto</button>
            </div>
        </div>

        <!-- ACTIONS -->
        <div class="form-actions">
            <button type="button" class="btn-secondary-dark" onclick="window.history.back()">Batal</button>
            <button type="submit" class="btn-primary-gradient">
                <i class="fas fa-save"></i> Simpan & Pratinjau Cetak
            </button>
        </div>
    </form>
</div>

<script>
// Helper functions to add list rows
function addRincianRow() {
    const container = document.getElementById('rincian-kegiatan-container');
    const div = document.createElement('div');
    div.className = 'dynamic-list-row';
    div.innerHTML = `
        <input type="text" name="rincian_kegiatan[]" placeholder="Cth: Sambutan Rektor" required>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(div);
}

function addTujuanRow() {
    const container = document.getElementById('tujuan-container');
    const div = document.createElement('div');
    div.className = 'dynamic-list-row';
    div.innerHTML = `
        <input type="text" name="tujuan[]" placeholder="Tujuan baru..." required>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(div);
}

function addManfaatRow() {
    const container = document.getElementById('manfaat-container');
    const div = document.createElement('div');
    div.className = 'dynamic-list-row';
    div.innerHTML = `
        <input type="text" name="manfaat[]" placeholder="Manfaat baru..." required>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(div);
}

function removeRow(btn) {
    const row = btn.parentElement;
    row.remove();
}

function previewDocPhoto(index, input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('img_doc_preview_' + index).src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function _elevatePickerCard(resultsEl) {
    if (resultsEl) {
        const card = resultsEl.closest('.card');
        if (card) card.style.zIndex = '50';
    }
}
function _resetPickerCards() {
    document.querySelectorAll('.buat-ba-container .card').forEach(c => c.style.zIndex = '');
}

function showTplResults(type) {
    document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
    const res = document.getElementById('results-' + type);
    if(res) {
        res.style.display = 'block';
        _elevatePickerCard(res);
    }
}

function filterTpl(type) {
    const input = document.querySelector('#picker-' + type + ' .tpl-search-input');
    if(!input) return;
    const filter = input.value.toLowerCase();
    const results = document.getElementById('results-' + type);
    const items = results.getElementsByClassName('tpl-item');
    let hasMatch = false;
    for(let i=0;i<items.length;i++) {
        const label = items[i].querySelector('.tpl-item-label').innerText.toLowerCase();
        const text = items[i].querySelector('.tpl-item-text') ? items[i].querySelector('.tpl-item-text').innerText.toLowerCase() : '';
        if(label.includes(filter) || text.includes(filter)) {
            items[i].style.display = "";
            hasMatch = true;
        } else {
            items[i].style.display = "none";
        }
    }
    let emptyMsg = results.querySelector('.tpl-empty');
    if(!hasMatch) {
        if(!emptyMsg) {
            emptyMsg = document.createElement('div');
            emptyMsg.className = 'tpl-empty';
            emptyMsg.innerText = 'Tidak ada hasil...';
            results.appendChild(emptyMsg);
        }
    } else if(emptyMsg) {
        emptyMsg.remove();
    }
}

function selectKegiatan(data) {
    document.getElementById('input_nama_kegiatan').value = data.nama;
    document.getElementById('kode_kegiatan_input').value = data.kode;
    document.getElementById('results-kegiatan').style.display = 'none';
    _resetPickerCards();
}

// Close picker results when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.tpl-picker')) {
        document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
        _resetPickerCards();
    }
});

class DrumPicker {
    constructor(elId, values, initVal, onChange) {
        this.el       = document.getElementById(elId);
        this.values   = values;
        this.idx      = Math.max(0, values.indexOf(initVal));
        this.onChange = onChange;
        this.ITEM     = 40;
        this._build();
        this._bind();
        this._render(false);
    }
    _build() {
        const hl = document.createElement('div');
        hl.className = 'drum-highlight';
        this.el.appendChild(hl);
        this.inner = document.createElement('div');
        this.inner.className = 'drum-inner';
        const pad = () => { const d=document.createElement('div'); d.className='drum-item'; return d; };
        [0,1,2].forEach(() => this.inner.appendChild(pad()));
        this.values.forEach((v, i) => {
            const d = document.createElement('div');
            d.className = 'drum-item'; d.dataset.i = i; d.textContent = v;
            this.inner.appendChild(d);
        });
        [0,1,2].forEach(() => this.inner.appendChild(pad()));
        this.el.appendChild(this.inner);
    }
    _render(animate = true) {
        const offset = -56 - this.idx * this.ITEM;
        this.inner.style.transition = animate ? 'transform 0.18s cubic-bezier(0.25,0.46,0.45,0.94)' : 'none';
        this.inner.style.transform  = `translateY(${offset}px)`;
        this.inner.querySelectorAll('[data-i]').forEach(el => {
            const diff = Math.abs(parseInt(el.dataset.i) - this.idx);
            const len = this.values.length;
            const wrapDiff = Math.min(diff, len - diff);
            el.className = 'drum-item' + (wrapDiff===0?' sel':wrapDiff===1?' near1':wrapDiff===2?' near2':'');
        });
        if (this.onChange) setTimeout(() => this.onChange(this.values[this.idx]), 0);
    }
    scrollBy(delta) {
        const oldIdx = this.idx;
        const len = this.values.length;
        this.idx = (this.idx + delta) % len;
        if (this.idx < 0) this.idx += len;
        this._render(Math.abs(this.idx - oldIdx) <= 1);
    }
    _bind() {
        this.el.addEventListener('wheel', e => { e.preventDefault(); this.scrollBy(e.deltaY > 0 ? 1 : -1); }, { passive: false });
        let ty = 0;
        this.el.addEventListener('touchstart', e => { ty = e.touches[0].clientY; }, { passive: true });
        this.el.addEventListener('touchmove', e => {
            const d = ty - e.touches[0].clientY;
            if (Math.abs(d) > 20) { this.scrollBy(d > 0 ? 1 : -1); ty = e.touches[0].clientY; }
        }, { passive: true });
    }
    val() { return this.values[this.idx]; }
}

const hours = Array.from({length:24}, (_,i) => String(i).padStart(2,'0'));
const mins  = Array.from({length:60}, (_,i) => String(i).padStart(2,'0'));
const existingWaktu = document.getElementById('out-waktu') ? document.getElementById('out-waktu').value || '08.00 s.d Selesai' : '08.00 s.d Selesai';
const wParts  = existingWaktu.split(' s.d ');
const startT  = (wParts[0] || '08.00').replace('.', ':').split(':');
const isSelesai = !wParts[1] || wParts[1] === 'Selesai';
const endT    = !isSelesai ? wParts[1].replace('.', ':').split(':') : null;

let drumHS, drumMS, drumHE, drumME, _selesaiMode = isSelesai;

document.addEventListener('DOMContentLoaded', () => {
    drumHS = new DrumPicker('drum-h-start', hours, startT[0]||'08', updateWaktu);
    drumMS = new DrumPicker('drum-m-start', mins,  startT[1]||'00', updateWaktu);
    drumHE = new DrumPicker('drum-h-end',   hours, endT?endT[0]:'17', updateWaktu);
    drumME = new DrumPicker('drum-m-end',   mins,  endT?endT[1]:'00', updateWaktu);
    if (isSelesai) applyToggleSelesai(true);
});

function updateWaktu() {
    if (!drumHS || !drumMS || !drumHE || !drumME) return;
    const start  = drumHS.val() + '.' + drumMS.val();
    const end    = _selesaiMode ? 'Selesai' : drumHE.val() + '.' + drumME.val();
    const result = start + ' s.d ' + end;
    document.getElementById('out-waktu').value   = result;
    document.getElementById('preview-waktu').innerText = result;
}

function doToggleSelesai() {
    _selesaiMode = !_selesaiMode;
    applyToggleSelesai(_selesaiMode);
}

function applyToggleSelesai(on) {
    _selesaiMode = on;
    const sw   = document.getElementById('ts-switch');
    const wrap = document.getElementById('toggle-selesai-wrap');
    const lbl  = document.getElementById('ts-label');
    const end  = document.getElementById('drum-end-wrap');
    const knob = sw.querySelector('.toggle-knob');
    
    if (sw) sw.style.background = on ? 'var(--accent-color)' : '#222';
    if (knob) knob.style.transform = on ? 'translateX(16px)' : 'translateX(0)';
    if (lbl) lbl.textContent  = on ? 'Tanpa waktu akhir' : 'Dengan waktu akhir';
    if (end) {
        end.style.opacity       = on ? '0.2' : '1';
        end.style.pointerEvents = on ? 'none' : '';
    }
    updateWaktu();
}

// ========== Tanda Tangan Pads Logic ==========

// ========== Dynamic Photo Documentation Slots ==========
let photoSlotCount = <?php echo isset($num_slots) ? $num_slots : 4; ?>;
function addPhotoSlot() {
    const container = document.querySelector('.photo-grid');
    const index = photoSlotCount++;
    
    const card = document.createElement('div');
    card.className = 'photo-card';
    card.setAttribute('data-index', index);
    card.innerHTML = `
        <div class="photo-card-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px;">
            <label style="color:#8BB9F0; font-weight:bold; font-size:0.8rem; margin:0;">Foto Slot ${index + 1}</label>
            <button type="button" class="btn-remove-photo" style="background:none; border:none; color:#ff4d4d; cursor:pointer; font-size: 0.8rem;" onclick="removePhotoCard(this)"><i class="fas fa-times"></i> Hapus Slot</button>
        </div>
        
        <div class="photo-preview-wrap">
            <img id="img_doc_preview_${index}" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='100' height='100' viewBox='0 0 24 24' fill='none' stroke='%23333' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2' ry='2'/><circle cx='8.5' cy='8.5' r='1.5'/><polyline points='21 15 16 10 5 21'/></svg>">
        </div>

        <div class="form-group">
            <label>Upload Foto</label>
            <input type="file" name="doc_img[${index}]" accept="image/*" onchange="previewDocPhoto(${index}, this)">
        </div>

        <div class="form-group">
            <label>Caption Foto</label>
            <input type="text" name="doc_caption[${index}]" placeholder="Cth: Foto Bersama Pemateri">
        </div>
    `;
    container.appendChild(card);
}

function removePhotoCard(button) {
    const card = button.closest('.photo-card');
    card.remove();
}

// ========== Dynamic Picker Logic ==========
const prokerMap = <?php echo json_encode($proker_map ?? []); ?>;
const anggotaMap = <?php echo json_encode($anggota_map ?? []); ?>;

function selectPelaksana(val) {
    document.getElementById('pelaksana_tipe_input').value = val;
    document.getElementById('results-pelaksana').style.display = 'none';
    _resetPickerCards();
    updateDependentDropdowns();
}

function selectTpl(targetId, value, type) {
    document.getElementById(targetId).value = value;
    document.getElementById('results-' + type).style.display = 'none';
    _resetPickerCards();
}

function updateDependentDropdowns() {
    const tipe = document.getElementById('pelaksana_tipe_input').value;
    const prokerInput = document.getElementById('program_kerja_input');
    const pjInput = document.getElementById('penanggung_jawab_input');
    const prokerResults = document.getElementById('results-proker');
    const pjResults = document.getElementById('results-pj');
    
    if (tipe) {
        prokerInput.disabled = false;
        prokerInput.placeholder = "Cari atau ketik program kerja...";
        pjInput.disabled = false;
        pjInput.placeholder = "Cari atau ketik penanggung jawab...";
        
        // Populate Proker
        prokerResults.innerHTML = '';
        if (prokerMap[tipe] && prokerMap[tipe].length > 0) {
            prokerMap[tipe].forEach(p => {
                const div = document.createElement('div');
                div.className = 'tpl-item';
                div.onclick = function() { selectTpl('program_kerja_input', p, 'proker'); };
                div.innerHTML = `<div class="tpl-item-label">${p}</div>`;
                prokerResults.appendChild(div);
            });
        }
        
        // Populate PJ
        pjResults.innerHTML = '';
        if (anggotaMap[tipe] && anggotaMap[tipe].length > 0) {
            anggotaMap[tipe].forEach(a => {
                const div = document.createElement('div');
                div.className = 'tpl-item';
                div.onclick = function() { selectTpl('penanggung_jawab_input', a, 'pj'); };
                div.innerHTML = `<div class="tpl-item-label">${a}</div>`;
                pjResults.appendChild(div);
            });
        }
    } else {
        prokerInput.disabled = true;
        prokerInput.placeholder = "Pilih Pelaksana Kegiatan terlebih dahulu...";
        pjInput.disabled = true;
        pjInput.placeholder = "Pilih Pelaksana Kegiatan terlebih dahulu...";
    }
}

document.addEventListener('DOMContentLoaded', () => {
    updateDependentDropdowns();
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
