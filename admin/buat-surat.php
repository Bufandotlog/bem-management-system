<?php
// admin/buat-surat.php
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

// Defaults untuk Create Baru
$kode_kegiatan = '';
$jenis_surat_val = 'L';
$is_group = false;
$group_count = 0;

$target_id = $edit_id > 0 ? $edit_id : $clone_id;

if ($target_id > 0) {
    $existing = dbFetchOne("SELECT * FROM arsip_surat WHERE id = ? AND periode_id = ? AND jenis_surat IN ('L', 'D')", [$target_id, $periode_id], "ii");
    if ($existing) {
        if ($edit_id > 0) $is_edit = true;
        if ($clone_id > 0) $is_clone = true;
        $konten = json_decode($existing['konten_surat'], true) ?: [];
        $edit_data = array_merge($existing, $konten);
        $parts = explode('/', $existing['nomor_surat']);
        $edit_data['nomor_urut']    = $parts[0] ?? '';
        $jenis_surat_val            = $parts[1] ?? 'L';
        $kode_kegiatan              = $parts[2] ?? '';
        $group_count = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE nomor_surat = ? AND periode_id = ?", [$existing['nomor_surat'], $periode_id], "si")['total'];
        $is_group = $group_count > 1;
    } else {
        $error = "Data arsip surat tidak ditemukan atau hak akses ditolak.";
    }
}

function getLastSequence($jenis, $periode_id) {
    // Ambil nomor urut TERTINGGI (bukan terakhir di-insert)
    // CAST prefix sebelum '/' pertama sebagai angka, lalu ambil MAX-nya
    $last = dbFetchOne(
        "SELECT MAX(CAST(SUBSTRING_INDEX(nomor_surat, '/', 1) AS UNSIGNED)) AS max_urut 
         FROM arsip_surat 
         WHERE periode_id = ? AND jenis_surat = ?",
        [$periode_id, $jenis], "is"
    );
    return ($last && $last['max_urut']) ? (int)$last['max_urut'] : 0;
}

$count_L = getLastSequence('L', $periode_id);
$count_D = getLastSequence('D', $periode_id);
$next_L = str_pad($count_L + 1, 3, '0', STR_PAD_LEFT);
$next_D = str_pad($count_D + 1, 3, '0', STR_PAD_LEFT);
$next_urut_default = ($jenis_surat_val === 'D') ? $next_D : $next_L;
if ($is_edit || $is_clone) $next_urut_default = $edit_data['nomor_urut'];

$bulan_romawi = getRomawiBulan(date('n'));
$tahun = date('Y');

// Ambil data template & panitia
$templates = dbFetchAll("SELECT * FROM surat_templates WHERE periode_id = ?", [$periode_id], "i");
$list_perihal  = array_filter($templates, fn($t) => $t['jenis'] === 'perihal');
$list_tujuan   = array_filter($templates, fn($t) => $t['jenis'] === 'tujuan');
$list_kegiatan = array_filter($templates, fn($t) => $t['jenis'] === 'kegiatan');
$list_tempat   = array_filter($templates, fn($t) => $t['jenis'] === 'tempat');

$list_panitia_all = dbFetchAll("SELECT * FROM panitia_tetap WHERE periode_id = ? ORDER BY nama ASC", [$periode_id], "i");
$panitia_ketua_list = array_filter($list_panitia_all, fn($p) => $p['jabatan'] === 'ketua');
$panitia_sekretaris_list = array_filter($list_panitia_all, fn($p) => $p['jabatan'] === 'sekretaris');

// Ambil data lampiran internal (Peminjaman Barang)
$lampiran_internal_list = dbFetchAll("SELECT id, nama_acara, tanggal_kegiatan, tahun FROM lampiran_pinjam WHERE periode_id = ? ORDER BY created_at DESC", [$periode_id], "i");

// Ambil data arsip rundown (Susunan Acara)
$rundown_internal_list = dbFetchAll("SELECT id, nama_acara, tanggal_mulai, durasi_hari, tahun FROM arsip_rundown WHERE periode_id = ? ORDER BY created_at DESC", [$periode_id], "i");

// Proses Simpan / Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid. Silakan muat ulang halaman.';
    } else {
        $action_type   = $_POST['action_type'] ?? 'insert';
        $jenis_surat   = $_POST['jenis_surat'] === 'D' ? 'D' : 'L';
        $nomor_urut    = sanitizeText($_POST['nomor_urut'], 10);
        $kode_keg      = strtoupper(str_replace(' ', '', sanitizeText($_POST['kode_kegiatan'], 50)));
        $nomor_surat   = "{$nomor_urut}/{$jenis_surat}/{$kode_keg}/BEM/{$bulan_romawi}/{$tahun}";
        $tanggal_dikirim_raw = sanitizeText($_POST['tanggal_dikirim'] ?? '', 50);
        $tanggal_dikirim = null;
        if (!empty($tanggal_dikirim_raw) && $tanggal_dikirim_raw !== 'Belum Di kirim') {
            // Pastikan format YYYY-MM-DD untuk database (PostgreSQL/MySQL)
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_dikirim_raw)) {
                $tanggal_dikirim = $tanggal_dikirim_raw;
            } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $tanggal_dikirim_raw)) {
                $p = explode('/', $tanggal_dikirim_raw);
                $tanggal_dikirim = "{$p[2]}-{$p[1]}-{$p[0]}";
            }
        }
        $perihal        = sanitizeText($_POST['perihal'], 255);
        $tempat_tanggal = sanitizeText($_POST['tempat_tanggal'], 255);
        $tujuan         = strip_tags(trim($_POST['tujuan']));
        
        function saveSignatureToFile($base64String, $prefix = 'ttd') {
            if (empty($base64String) || strpos($base64String, 'data:image') === false) return $base64String;
            $dir = rtrim(UPLOAD_PATH, '/\\') . '/ttd/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $data = explode(',', $base64String);
            $imgData = base64_decode($data[1]);
            $filename = $prefix . '_' . uniqid() . '.png';
            file_put_contents($dir . $filename, $imgData);
            return 'ttd/' . $filename;
        }

        $konten_data = [
            'sapaan_tujuan'           => sanitizeText($_POST['sapaan_tujuan'] ?? '', 50),
            'nama_kegiatan'           => strip_tags(trim($_POST['nama_kegiatan'] ?? ''), '<b><strong><i><em><span>'),
            'tema'                    => strip_tags(trim($_POST['tema'] ?? ''), '<b><strong><i><em><span>'),
            'tema_kegiatan'           => strip_tags(trim($_POST['tema_kegiatan'] ?? '')),
            'pelaksanaan_hari_tanggal'=> sanitizeText($_POST['pelaksanaan_hari_tanggal'], 100),
            'pelaksanaan_waktu'       => sanitizeText($_POST['pelaksanaan_waktu'], 100),
            'pelaksanaan_tempat'      => sanitizeText($_POST['pelaksanaan_tempat'], 100),
            'konteks'                 => strip_tags(trim($_POST['konteks'] ?? ''), '<b><strong><i><em><span>'),
            'label_panitia'           => strtoupper(sanitizeText($_POST['label_panitia'] ?? '', 200)),
            'panitia_ketua'           => strtoupper(sanitizeText($_POST['panitia_ketua'], 100)),
            'panitia_ketua_ttd'       => saveSignatureToFile($_POST['panitia_ketua_ttd'] ?? '', 'ketua'),
            'panitia_sekretaris'      => strtoupper(sanitizeText($_POST['panitia_sekretaris'], 100)),
            'panitia_sekretaris_ttd'  => saveSignatureToFile($_POST['panitia_sekretaris_ttd'] ?? '', 'sekretaris'),
            'use_ttd_warek'           => isset($_POST['use_ttd_warek']) ? '1' : '0',
            'use_ttd_presma'          => isset($_POST['use_ttd_presma']) ? '1' : '0',
            'use_cap_panitia'         => isset($_POST['use_cap_panitia']) ? '1' : '0',
            'use_cap_warek'           => isset($_POST['use_cap_warek']) ? '1' : '0',
            'use_cap_presma'          => isset($_POST['use_cap_presma']) ? '1' : '0',
            'tembusan'                => strip_tags(trim($_POST['tembusan'] ?? ''))
        ];

        $lampiran_files = [];
        if ($is_edit && !empty($konten['lampiran_files'])) $lampiran_files = $konten['lampiran_files'];
        
        // Handle deletions of existing files
        if (isset($_POST['deleted_existing_files']) && !empty($_POST['deleted_existing_files'])) {
            $to_delete = explode(',', $_POST['deleted_existing_files']);
            $lampiran_files = array_filter($lampiran_files, function($file) use ($to_delete) {
                return !in_array($file, $to_delete);
            });
            $lampiran_files = array_values($lampiran_files); // Reset index
        }
        
        if (isset($_FILES['lampiran_surat'])) {
            $upload_lampiran_dir = rtrim(UPLOAD_PATH, '/\\') . '/umum/lampiran/';
            if (!is_dir($upload_lampiran_dir)) mkdir($upload_lampiran_dir, 0755, true);
            foreach ($_FILES['lampiran_surat']['name'] as $key => $filename) {
                if ($_FILES['lampiran_surat']['error'][$key] === UPLOAD_ERR_OK && !empty($filename)) {
                    $tmp_name = $_FILES['lampiran_surat']['tmp_name'][$key];
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    if ($ext === 'pdf') {
                        $new_name = uniqid('lamp_', true) . '.pdf';
                        if (move_uploaded_file($tmp_name, $upload_lampiran_dir . $new_name)) {
                            $lampiran_files[] = 'umum/lampiran/' . $new_name;
                        }
                    }
                }
            }
        }

        // --- HANDLER LAMPIRAN INTERNAL (JSON DATA) ---
        $lampiran_internal_ids = $_POST['lampiran_internal'] ?? [];
        $konten_data['lampiran_internal_ids'] = $lampiran_internal_ids;
        
        // --- HANDLER RUNDOWN INTERNAL (JSON DATA) ---
        $rundown_internal_ids = $_POST['rundown_internal'] ?? [];
        $konten_data['rundown_internal_ids'] = $rundown_internal_ids;
        
        $konten_data['lampiran_files'] = $lampiran_files;
        $konten_json = json_encode($konten_data);
        
        if ($konten_json === false) {
            $error = 'Gagal memproses data surat (JSON Error).';
        } else {
            $created_by = $_SESSION['admin_id'];
            try {
                if ($action_type === 'update' && $is_edit) {
                    $old_nomor_surat = $existing['nomor_surat'];
                    $connected = dbFetchAll("SELECT id, konten_surat, tujuan FROM arsip_surat WHERE nomor_surat = ? AND periode_id = ?", [$old_nomor_surat, $periode_id], "si");
                    foreach ($connected as $conn_surat) {
                        $conn_id = $conn_surat['id'];
                        $conn_konten_old = json_decode($conn_surat['konten_surat'], true) ?: [];
                        $new_konten = $konten_data;
                        if ($conn_id == $edit_id) $final_tujuan = $tujuan;
                        else {
                            $final_tujuan = $conn_surat['tujuan'];
                            $new_konten['sapaan_tujuan'] = $conn_konten_old['sapaan_tujuan'] ?? '';
                        }
                        $final_konten_json = json_encode($new_konten);
                        dbQuery("UPDATE arsip_surat SET jenis_surat=?, tanggal_dikirim=?, nomor_surat=?, perihal=?, tujuan=?, tempat_tanggal=?, konten_surat=? WHERE id=? AND periode_id=?", [$jenis_surat, $tanggal_dikirim, $nomor_surat, $perihal, $final_tujuan, $tempat_tanggal, $final_konten_json, $conn_id, $periode_id], "sssssssii");
                    }
                    auditLog('UPDATE', 'arsip_surat', $edit_id, 'Mengubah arsip surat: ' . $nomor_surat);
                    redirect('admin/cetak-surat.php?id=' . $edit_id, 'Surat berhasil diperbarui!', 'success');
                } else {
                    dbQuery("INSERT INTO arsip_surat (periode_id, jenis_surat, tanggal_dikirim, nomor_surat, perihal, tujuan, tempat_tanggal, konten_surat, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [$periode_id, $jenis_surat, $tanggal_dikirim, $nomor_surat, $perihal, $tujuan, $tempat_tanggal, $konten_json, $created_by], "isssssssi");
                    $new_id = dbLastId();
                    auditLog('CREATE', 'arsip_surat', $new_id, 'Membuat surat baru: ' . $nomor_surat);
                    redirect('admin/cetak-surat.php?id=' . $new_id, 'Surat berhasil dibuat!', 'success');
                }
                exit();
            } catch (Exception $e) {
                $error = 'Terjadi kesalahan saat menyimpan ke database: ' . $e->getMessage();
            }
        }
    }
}

// Definisikan data default
$def = [
    'sapaan_tujuan'            => '',
    'tanggal_dikirim'          => 'Belum Di kirim',
    'perihal'                  => '',
    'tempat_tanggal'           => 'Majalengka, ' . tanggalIndonesia(),
    'tujuan'                   => "",
    'nama_kegiatan'            => '',
    'tema'                     => '',
    'tema_kegiatan'            => '',
    'pelaksanaan_hari_tanggal' => "",
    'pelaksanaan_waktu'        => '',
    'pelaksanaan_tempat'       => '',
    'konteks'                  => '',
    'panitia_ketua'            => '',
    'panitia_sekretaris'       => '',
    'tembusan'                 => ''
];
if ($is_edit || $is_clone) {
    foreach($def as $k=>$v) if(!isset($edit_data[$k])) $edit_data[$k] = $v;
    if ($is_clone) {
        $edit_data['tujuan'] = '';
        $edit_data['sapaan_tujuan'] = '';
    }
} else {
    $edit_data = $def;
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

.buat-surat-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.buat-surat-container .page-header h1 {
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

.buat-surat-container .card {
    position: relative;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    margin-bottom: 24px;
    overflow: visible;
    backdrop-filter: blur(10px);
    box-shadow: var(--shadow-premium);
    transition: transform 0.3s ease, border-color 0.3s ease;
}

.buat-surat-container .card:hover {
    border-color: rgba(74, 144, 226, 0.4);
}

.buat-surat-container .card-header {
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

.buat-surat-container .card-body {
    padding: 24px;
}

/* Form group & input */
.buat-surat-container .form-group {
    margin-bottom: 1.5rem;
}

.buat-surat-container label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-muted);
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.buat-surat-container input,
.buat-surat-container select,
.buat-surat-container textarea {
    background: var(--input-bg);
    border: 1.5px solid var(--border-color);
    border-radius: 14px;
    padding: 12px 16px;
    color: var(--text-main);
    width: 100%;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    font-size: 0.95rem;
}

.buat-surat-container input:focus,
.buat-surat-container select:focus,
.buat-surat-container textarea:focus {
    border-color: var(--accent-color);
    outline: none;
    box-shadow: 0 0 0 4px rgba(74, 144, 226, 0.15), 0 0 20px rgba(74, 144, 226, 0.1);
    transform: translateY(-1px);
}

/* Grid layout */
.buat-surat-container .grid-2 {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
}

@media (min-width: 768px) {
    .buat-surat-container .grid-2 {
        grid-template-columns: repeat(2, 1fr);
    }
}

/* Template Picker refinement */
.buat-surat-container .tpl-picker {
    position: relative;
}

.buat-surat-container .tpl-search-input {
    padding-left: 44px !important;
}

.buat-surat-container .tpl-search-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--accent-color);
    font-size: 1rem;
    pointer-events: none;
    z-index: 5;
}

.buat-surat-container .tpl-results {
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

.buat-surat-container .tpl-item {
    padding: 12px 18px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: background 0.2s;
}

.buat-surat-container .tpl-item:last-child { border-bottom: none; }

.buat-surat-container .tpl-item:hover {
    background: rgba(74, 144, 226, 0.1);
}

.buat-surat-container .tpl-item-label {
    font-weight: 700;
    color: #8BB9F0;
    font-size: 0.9rem;
    margin-bottom: 2px;
}

.buat-surat-container .tpl-item-text {
    font-size: 0.75rem;
    color: #777;
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Switches */
.buat-surat-container .switch-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(255, 255, 255, 0.02);
    padding: 14px 20px;
    border-radius: 16px;
    border: 1px solid var(--border-color);
    margin-bottom: 12px;
    transition: all 0.3s;
}

.buat-surat-container .switch-container:hover {
    background: rgba(74, 144, 226, 0.05);
    border-color: rgba(74, 144, 226, 0.3);
}

.buat-surat-container .switch-label {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #ccc;
    font-size: 0.88rem;
    font-weight: 500;
}

.buat-surat-container .switch-label i {
    color: var(--accent-color);
    width: 20px;
    text-align: center;
}

.buat-surat-container .switch {
    position: relative;
    display: inline-block;
    width: 48px;
    height: 24px;
}

.buat-surat-container .switch input { opacity: 0; width: 0; height: 0; }

.buat-surat-container .slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #2a2a2a;
    transition: .4s;
    border-radius: 24px;
}

.buat-surat-container .slider:before {
    position: absolute;
    content: "";
    height: 18px; width: 18px;
    left: 3px; bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.buat-surat-container input:checked + .slider { background: var(--primary-gradient); }
.buat-surat-container input:checked + .slider:before { transform: translateX(24px); }

/* Buttons */
.buat-surat-container .btn-primary {
    background: var(--primary-gradient);
    border: none;
    padding: 16px 32px;
    border-radius: 50px;
    font-weight: 700;
    font-size: 1.1rem;
    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    cursor: pointer;
    color: white;
    box-shadow: 0 10px 20px rgba(79, 172, 254, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
}

.buat-surat-container .btn-primary:hover {
    transform: translateY(-4px) scale(1.02);
    box-shadow: 0 15px 30px rgba(79, 172, 254, 0.4);
}

.buat-surat-container .btn-outline {
    background: transparent;
    border: 1.5px solid var(--accent-color);
    color: var(--accent-color);
    padding: 8px 18px;
    border-radius: 30px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    transition: all 0.3s;
}

.buat-surat-container .btn-outline:hover {
    background: rgba(74, 144, 226, 0.1);
    transform: translateY(-1px);
}

/* WAKTU PELAKSANAAN (PRESERVED) */
.buat-surat-container .wakpel-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
@media (min-width: 768px) { .buat-surat-container .wakpel-grid { grid-template-columns: 1fr 1fr; } }
.buat-surat-container .wakpel-card { background: rgba(0,0,0,0.2); border-radius: 20px; padding: 20px; border: 1px solid var(--border-color); }
.buat-surat-container .wakpel-card-label { font-size: 0.75rem; color: #5a8fc4; text-transform: uppercase; font-weight: 700; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
.buat-surat-container .date-range-wrap { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
@media (max-width: 600px) {
    .buat-surat-container .date-range-wrap { flex-direction: column; align-items: stretch; }
    .buat-surat-container .date-range-wrap span { display: none; } /* Sembunyikan kata 'sampai' di mobile */
}
.buat-surat-container .preview-bar { background: rgba(74,144,226,0.08); border-radius: 12px; padding: 12px 16px; font-size: 0.85rem; margin-top: 15px; color: #8BB9F0; border-left: 4px solid var(--accent-color); }

/* Mini RTE Editor */
.rte-mini-wrap { border: 1px solid var(--border-color); border-radius: 12px; overflow: hidden; transition: border-color 0.2s; }
.rte-mini-wrap:focus-within { border-color: var(--accent-color); box-shadow: 0 0 8px rgba(74,144,226,0.15); }
.rte-mini-toolbar { display: flex; gap: 4px; padding: 6px 10px; background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border-color); }
.rte-mini-btn { width: 32px; height: 28px; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #aaa; cursor: pointer; font-size: 0.85rem; transition: all 0.15s; }
.rte-mini-btn:hover { background: rgba(74,144,226,0.15); color: #4A90E2; border-color: rgba(74,144,226,0.3); }
.rte-mini-btn:active, .rte-mini-btn.active { background: rgba(74,144,226,0.2); color: #4A90E2; border-color: #4A90E2; }
.rte-mini-editor { min-height: 42px; padding: 10px 14px; color: #fff; font-size: 0.95rem; line-height: 1.5; outline: none; font-weight: bold; }
.rte-mini-editor:empty::before { content: attr(data-placeholder); color: #555; pointer-events: none; font-weight: normal; }
.rte-mini-editor b, .rte-mini-editor strong { color: #fff; }
.rte-mini-editor i, .rte-mini-editor em { color: #ddd; }

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
    .buat-surat-container .drum-groups-wrap { justify-content: center; }
    .drum-colon { display: none; } /* Di mobile tidak butuh titik dua pemisah grup */
}

/* Custom RTE */
.buat-surat-container #rte-editor {
    background: #080808;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 16px;
    min-height: 120px;
    color: #ddd;
    outline: none;
    transition: all 0.3s;
}

.buat-surat-container #rte-editor:focus {
    border-color: var(--accent-color);
}

/* File Upload refinement */
.buat-surat-container .drop-zone {
    border: 2px dashed var(--border-color);
    border-radius: 20px;
    padding: 40px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s;
    background: rgba(0,0,0,0.1);
}

.buat-surat-container .drop-zone:hover {
    border-color: var(--accent-color);
    background: rgba(74, 144, 226, 0.05);
}

.buat-surat-container .drop-zone i { font-size: 2.5rem; color: var(--accent-color); margin-bottom: 15px; }

/* Signature refinement */
.buat-surat-container canvas {
    background: #fff;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    width: 100% !important;
    height: auto !important;
    max-width: 300px;
    display: block;
    margin: 0 auto;
}

/* Animations */
@keyframes slideUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.buat-surat-container .card {
    animation: slideUp 0.5s ease-out forwards;
}

.buat-surat-container .card:nth-child(1) { animation-delay: 0.1s; }
.buat-surat-container .card:nth-child(2) { animation-delay: 0.2s; }
.buat-surat-container .card:nth-child(3) { animation-delay: 0.3s; }
.buat-surat-container .card:nth-child(4) { animation-delay: 0.4s; }
.buat-surat-container .card:nth-child(5) { animation-delay: 0.5s; }
.buat-surat-container .card:nth-child(6) { animation-delay: 0.6s; }
</style>

<div class="buat-surat-container">
    <div class="page-header">
        <h1><i class="fas fa-file-signature"></i> <?php echo $is_edit ? 'Edit Surat' : ($is_clone ? 'Duplikat Surat' : 'Buat Surat Baru'); ?></h1>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" style="background: rgba(231, 76, 60, 0.1); border: 1px solid #e74c3c; color: #ff6b6b; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 12px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action_type" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">

        <!-- CARD 1: IDENTITAS SURAT -->
        <div class="card">
            <div class="card-header"><i class="fas fa-fingerprint"></i> Identitas Surat</div>
            <div class="card-body">
                <?php if($is_group && $is_edit): ?>
                    <div style="background: rgba(74, 144, 226, 0.1); border-left: 4px solid var(--accent-color); padding: 12px 18px; border-radius: 12px; margin-bottom: 24px; font-size: 0.9rem; color: #8BB9F0;">
                        <i class="fas fa-info-circle"></i> <strong>Multi-Recipient Group</strong> — Mengedit surat ini akan memperbarui <strong><?php echo $group_count - 1; ?> salinan</strong> lainnya secara otomatis.
                    </div>
                <?php endif; ?>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Nomor Urut</label>
                        <input type="text" id="nomor_urut_input" name="nomor_urut" value="<?php echo htmlspecialchars($next_urut_default); ?>" required <?php echo $is_clone ? 'readonly style="opacity:0.6;"' : ''; ?>>
                    </div>
                    <div class="form-group">
                        <label>Jenis Surat</label>
                        <select name="jenis_surat" id="jenis_surat_select" <?php echo $is_clone ? 'disabled' : ''; ?>>
                            <option value="L" <?php echo $jenis_surat_val === 'L' ? 'selected' : ''; ?>>Surat Keluar (Eksternal) [L]</option>
                            <option value="D" <?php echo $jenis_surat_val === 'D' ? 'selected' : ''; ?>>Surat Keluar (Internal) [D]</option>
                        </select>
                        <?php if($is_clone): ?><input type="hidden" name="jenis_surat" value="<?php echo $jenis_surat_val; ?>"><?php endif; ?>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Kode Kegiatan</label>
                        <div class="tpl-picker" id="picker-kegiatan">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" id="kode_kegiatan_input" name="kode_kegiatan" class="tpl-search-input" placeholder="Cari atau ketik kode..." value="<?php echo htmlspecialchars($kode_kegiatan); ?>" required <?php echo $is_clone ? 'readonly style="opacity:0.6;"' : ''; ?> onfocus="showTplResults('kegiatan')" onkeyup="filterTpl('kegiatan')">
                            <div class="tpl-results" id="results-kegiatan">
                                <?php foreach($list_kegiatan as $k): ?>
                                <div class="tpl-item" onclick='selectKegiatan(<?php echo json_encode(["nama" => $k["label"], "kode" => $k["perihal_default"]]); ?>)'>
                                    <div class="tpl-item-label"><?php echo htmlspecialchars($k['label']); ?></div>
                                    <div class="tpl-item-text">Kode: <?php echo htmlspecialchars($k['perihal_default']); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Perihal Surat</label>
                        <div class="tpl-picker" id="picker-perihal">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" id="input_perihal" name="perihal" class="tpl-search-input" placeholder="Cari atau ketik perihal..." value="<?php echo htmlspecialchars($edit_data['perihal']); ?>" required onfocus="showTplResults('perihal')" onkeyup="filterTpl('perihal')">
                            <div class="tpl-results" id="results-perihal">
                                <?php foreach($list_perihal as $p): ?>
                                <div class="tpl-item" onclick='selectTpl("input_perihal", <?php echo json_encode($p["isi_teks"]); ?>, "perihal")'>
                                    <div class="tpl-item-label"><?php echo htmlspecialchars($p['label']); ?></div>
                                    <div class="tpl-item-text"><?php echo htmlspecialchars($p['isi_teks']); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Titimangsa & Tempat Tanggal</label>
                        <input type="text" name="tempat_tanggal" value="<?php echo htmlspecialchars(convertBulanKeIndonesia($edit_data['tempat_tanggal'])); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Tanggal Dikirim (Untuk Arsip)</label>
                        <input type="date" name="tanggal_dikirim" value="<?php 
                            $tgl_val = $edit_data['tanggal_dikirim'] ?? '';
                            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $tgl_val)) {
                                $p = explode('/', $tgl_val);
                                echo "{$p[2]}-{$p[1]}-{$p[0]}";
                            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_val)) {
                                echo $tgl_val;
                            }
                        ?>">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Sapaan Tujuan</label>
                        <select name="sapaan_tujuan">
                            <option value="">-- Tanpa Sapaan --</option>
                            <option value="Bapak" <?php echo ($edit_data['sapaan_tujuan']??'') === 'Bapak' ? 'selected' : ''; ?>>Bapak</option>
                            <option value="Ibu" <?php echo ($edit_data['sapaan_tujuan']??'') === 'Ibu' ? 'selected' : ''; ?>>Ibu</option>
                            <option value="Saudara" <?php echo ($edit_data['sapaan_tujuan']??'') === 'Saudara' ? 'selected' : ''; ?>>Saudara</option>
                            <option value="Saudari" <?php echo ($edit_data['sapaan_tujuan']??'') === 'Saudari' ? 'selected' : ''; ?>>Saudari</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Kepada Yth (Tujuan)</label>
                        <div class="tpl-picker" id="picker-tujuan">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" class="tpl-search-input" placeholder="Cari template tujuan..." onfocus="showTplResults('tujuan')" onkeyup="filterTpl('tujuan')">
                            <div class="tpl-results" id="results-tujuan">
                                <?php foreach($list_tujuan as $t): ?>
                                <div class="tpl-item" onclick='selectTpl("textarea_tujuan", <?php echo json_encode($t["isi_teks"]); ?>, "tujuan")'>
                                    <div class="tpl-item-label"><?php echo htmlspecialchars($t['label']); ?></div>
                                    <div class="tpl-item-text"><?php echo htmlspecialchars($t['isi_teks']); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <textarea id="textarea_tujuan" name="tujuan" rows="3" required placeholder="Detail penerima..."><?php echo htmlspecialchars($edit_data['tujuan']); ?></textarea>
                </div>
            </div>
        </div>

        <!-- CARD 2: PARAGRAF PEMBUKA -->
        <div class="card">
            <div class="card-header"><i class="fas fa-quote-left"></i> Paragraf Pembuka</div>
            <div class="card-body">
                <?php $mode_custom_default = !empty($edit_data['tema_kegiatan']) && empty($edit_data['nama_kegiatan']); ?>
                <div style="display:flex; justify-content:flex-end; margin-bottom:20px;">
                    <button type="button" id="toggle-mode-btn" onclick="toggleModeParagraf()" class="btn-outline"><?php echo $mode_custom_default ? 'Ganti ke Mode Template' : 'Ganti ke Mode Custom'; ?></button>
                </div>
                
                <div id="blok-template" style="<?php echo $mode_custom_default ? 'display:none' : ''; ?>">
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Nama Kegiatan</label>
                            <div class="rte-mini-wrap">
                                <div class="rte-mini-toolbar">
                                    <button type="button" onclick="execMiniRTE('rte_nama_keg','bold')" class="rte-mini-btn" title="Bold"><b>B</b></button>
                                    <button type="button" onclick="execMiniRTE('rte_nama_keg','italic')" class="rte-mini-btn" title="Italic"><i>I</i></button>
                                    <button type="button" onclick="execMiniRTE('rte_nama_keg','unbold')" class="rte-mini-btn" title="Unbold Text (Normal)" style="font-weight:normal;">A</button>
                                </div>
                                <div id="rte_nama_keg" class="rte-mini-editor" contenteditable="true" data-placeholder="Cth: LDKM 2026" oninput="syncMiniRTE('rte_nama_keg','input_nama_kegiatan')"><?php echo $edit_data['nama_kegiatan'] ?? ''; ?></div>
                                <input type="hidden" id="input_nama_kegiatan" name="nama_kegiatan" value="<?php echo htmlspecialchars($edit_data['nama_kegiatan'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Tema Kegiatan</label>
                            <div class="rte-mini-wrap">
                                <div class="rte-mini-toolbar">
                                    <button type="button" onclick="execMiniRTE('rte_tema','bold')" class="rte-mini-btn" title="Bold"><b>B</b></button>
                                    <button type="button" onclick="execMiniRTE('rte_tema','italic')" class="rte-mini-btn" title="Italic"><i>I</i></button>
                                    <button type="button" onclick="execMiniRTE('rte_tema','unbold')" class="rte-mini-btn" title="Unbold Text (Normal)" style="font-weight:normal;">A</button>
                                </div>
                                <div id="rte_tema" class="rte-mini-editor" contenteditable="true" data-placeholder="Cth: Bersinergi Membangun Bangsa" oninput="syncMiniRTE('rte_tema','input_tema')"><?php echo $edit_data['tema'] ?? ''; ?></div>
                                <input type="hidden" id="input_tema" name="tema" value="<?php echo htmlspecialchars($edit_data['tema'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="preview-bar" id="preview-paragraf"><i class="fas fa-magic"></i> <span id="preview-paragraf-text">Sehubungan akan diadakannya kegiatan <strong>[Nama Kegiatan]</strong> dengan tema "<strong>[Tema]</strong>" yang akan dilaksanakan pada :</span></div>
                </div>
                
                <div id="blok-custom" style="<?php echo $mode_custom_default ? '' : 'display:none'; ?>">
                    <input type="hidden" id="input_tema_kegiatan_val" name="tema_kegiatan" value="<?php echo htmlspecialchars($edit_data['tema_kegiatan'] ?? ''); ?>">
                    <div style="border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
                        <div style="display:flex; gap:10px; padding:12px; background: rgba(255,255,255,0.03); border-bottom: 1px solid var(--border-color);">
                            <button type="button" onclick="execRTE('bold')" class="btn-outline" style="width:40px; padding:6px;">B</button>
                            <button type="button" onclick="execRTE('italic')" class="btn-outline" style="width:40px; padding:6px; font-style:italic;">I</button>
                            <button type="button" onclick="execRTE('underline')" class="btn-outline" style="width:40px; padding:6px; text-decoration:underline;">U</button>
                        </div>
                        <div id="rte-editor" contenteditable="true"><?php echo $edit_data['tema_kegiatan'] ?? ''; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD 3: WAKTU & TEMPAT PELAKSANAAN (UI PRESERVED) -->
        <div class="card">
            <div class="card-header"><i class="fas fa-calendar-alt"></i> Waktu & Tempat Pelaksanaan</div>
            <div class="card-body">
                <div class="wakpel-grid">
                    <div class="wakpel-card">
                        <div class="wakpel-card-label"><i class="fas fa-calendar-day"></i> Hari & Tanggal</div>
                        <div class="date-range-wrap">
                            <input type="date" id="tgl-mulai" onchange="formatTanggalRange()" value="<?php echo htmlspecialchars($edit_data['pelaksanaan_hari_tanggal_raw_start'] ?? ''); ?>">
                            <span style="color:var(--text-muted); font-size: 0.8rem;">selama</span>
                            <div style="display:flex; gap:5px; align-items:center;">
                                <select id="durasi-hari" onchange="handleDurasiChange()" style="padding: 8px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: #fff; cursor: pointer; outline:none;">
                                    <option value="1">1 Hari</option>
                                    <option value="2">2 Hari</option>
                                    <option value="3">3 Hari</option>
                                    <option value="4">4 Hari</option>
                                    <option value="5">5 Hari</option>
                                    <option value="custom">Custom...</option>
                                </select>
                                <input type="number" id="custom-hari" min="1" value="1" oninput="formatTanggalRange()" style="display:none; width: 60px; padding: 8px 12px; border-radius: 12px; border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: #fff; outline:none;">
                                <span id="label-hari" style="color:var(--text-muted); font-size: 0.8rem; display:none;">Hari</span>
                            </div>
                        </div>
                        <input type="hidden" id="out-tanggal" name="pelaksanaan_hari_tanggal" value="<?php echo htmlspecialchars($edit_data['pelaksanaan_hari_tanggal']); ?>">
                        <div class="preview-bar" id="preview-tanggal"><?php echo htmlspecialchars($edit_data['pelaksanaan_hari_tanggal']); ?></div>
                    </div>
                    <div class="wakpel-card">
                        <div class="wakpel-card-label"><i class="fas fa-clock"></i> Waktu Pelaksanaan</div>
                        <input type="hidden" id="out-waktu" name="pelaksanaan_waktu" value="<?php echo htmlspecialchars($edit_data['pelaksanaan_waktu']); ?>">
                        <div class="drum-groups-wrap">
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
                        <div class="preview-bar" id="preview-waktu"><?php echo htmlspecialchars($edit_data['pelaksanaan_waktu']); ?></div>
                    </div>
                </div>
                
                <div class="grid-2" style="margin-top:24px;">
                    <div class="form-group">
                        <label>Tempat Pelaksanaan</label>
                        <div class="tpl-picker" id="picker-tempat">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" id="input_tempat" name="pelaksanaan_tempat" class="tpl-search-input" placeholder="Cari atau ketik tempat..." value="<?php echo htmlspecialchars($edit_data['pelaksanaan_tempat']); ?>" required onfocus="showTplResults('tempat')" onkeyup="filterTpl('tempat')">
                            <div class="tpl-results" id="results-tempat">
                                <?php foreach($list_tempat as $t): ?>
                                <div class="tpl-item" onclick='selectTpl("input_tempat", <?php echo json_encode($t["label"]); ?>, "tempat")'>
                                    <div class="tpl-item-label"><?php echo htmlspecialchars($t['label']); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Konteks Tambahan (Kalimat Akhir)</label>
                        <div class="rte-mini-wrap">
                            <div class="rte-mini-toolbar">
                                <button type="button" onclick="execMiniRTE('rte_konteks','bold')" class="rte-mini-btn" title="Bold"><b>B</b></button>
                                <button type="button" onclick="execMiniRTE('rte_konteks','italic')" class="rte-mini-btn" title="Italic"><i>I</i></button>
                                <button type="button" onclick="execMiniRTE('rte_konteks','unbold')" class="rte-mini-btn" title="Unbold Text (Normal)" style="font-weight:normal;">A</button>
                            </div>
                            <div id="rte_konteks" class="rte-mini-editor" style="font-weight: normal;" contenteditable="true" data-placeholder="Cth: untuk mendukung terselenggaranya kegiatan tersebut dari mulai Technical Meet" oninput="syncMiniRTE('rte_konteks','input_konteks')"><?php echo $edit_data['konteks'] ?? ''; ?></div>
                            <input type="hidden" id="input_konteks" name="konteks" value="<?php echo htmlspecialchars($edit_data['konteks'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <div class="form-group"><label>Tembusan (Opsional)</label><textarea name="tembusan" rows="2" placeholder="1. Arsip..."><?php echo htmlspecialchars($edit_data['tembusan'] ?? ''); ?></textarea></div>
            </div>
        </div>

        <!-- CARD 4: LAMPIRAN BERKAS -->
        <div class="card">
            <div class="card-header"><i class="fas fa-paperclip"></i> Lampiran Berkas (Pustaka & Upload)</div>
            <div class="card-body">
                
                <!-- PILIH DARI DATA INTERNAL (Draft Peminjaman) -->
                <?php if (!empty($lampiran_internal_list)): ?>
                <div style="margin-bottom:25px; padding-bottom:15px; border-bottom:1px solid var(--border-color);">
                    <div style="font-size: 0.85rem; color: var(--accent-color); margin-bottom: 12px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px;">Pilih Dari Arsip Peminjaman:</div>
                    <div style="max-height: 200px; overflow-y: auto; display: grid; grid-template-columns: 1fr; gap: 8px;">
                        <?php foreach($lampiran_internal_list as $li): 
                            $isSelected = in_array($li['id'], ($konten['lampiran_internal_ids'] ?? []));
                        ?>
                        <label style="display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.03); padding: 12px; border-radius: 12px; cursor: pointer; transition: 0.3s; border: 1px solid transparent;" onmouseover="this.style.borderColor='var(--accent-color)'" onmouseout="this.style.borderColor='transparent'">
                            <input type="checkbox" name="lampiran_internal[]" value="<?php echo $li['id']; ?>" <?php echo $isSelected ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color: var(--accent-color);">
                            <div style="flex-grow:1;">
                                <div style="font-weight: 600; font-size: 0.95rem;"><?php echo htmlspecialchars($li['nama_acara']); ?></div>
                                <div style="font-size: 0.75rem; color: #888;"><?php echo htmlspecialchars($li['tanggal_kegiatan']); ?> <?php echo htmlspecialchars($li['tahun']); ?></div>
                            </div>
                            <i class="fas fa-database" style="color: #555;"></i>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- PILIH DARI ARSIP RUNDOWN -->
                <?php if (!empty($rundown_internal_list)): ?>
                <div style="margin-bottom:25px; padding-bottom:15px; border-bottom:1px solid var(--border-color);">
                    <div style="font-size: 0.85rem; color: #9b59b6; margin-bottom: 12px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px;">Pilih Dari Arsip Rundown:</div>
                    <div style="max-height: 200px; overflow-y: auto; display: grid; grid-template-columns: 1fr; gap: 8px;">
                        <?php foreach($rundown_internal_list as $ri): 
                            $isSelectedRundown = in_array($ri['id'], ($konten['rundown_internal_ids'] ?? []));
                            $durasi_text = (int)$ri['durasi_hari'] > 1 ? (int)$ri['durasi_hari'] . ' Hari' : '1 Hari';
                        ?>
                        <label style="display: flex; align-items: center; gap: 12px; background: rgba(155, 89, 182, 0.05); padding: 12px; border-radius: 12px; cursor: pointer; transition: 0.3s; border: 1px solid transparent;" onmouseover="this.style.borderColor='#9b59b6'" onmouseout="this.style.borderColor='transparent'">
                            <input type="checkbox" name="rundown_internal[]" value="<?php echo $ri['id']; ?>" <?php echo $isSelectedRundown ? 'checked' : ''; ?> style="width:18px; height:18px; accent-color: #9b59b6;">
                            <div style="flex-grow:1;">
                                <div style="font-weight: 600; font-size: 0.95rem;"><?php echo htmlspecialchars($ri['nama_acara']); ?></div>
                                <div style="font-size: 0.75rem; color: #888;"><?php echo htmlspecialchars($ri['tanggal_mulai']); ?> · <?php echo $durasi_text; ?> · <?php echo htmlspecialchars($ri['tahun']); ?></div>
                            </div>
                            <i class="fas fa-clipboard-list" style="color: #9b59b6; opacity: 0.5;"></i>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div style="font-size: 0.85rem; color: var(--accent-color); margin-bottom: 12px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px;">Upload PDF Baru:</div>
                <div class="drop-zone" id="lampiran_drop_zone">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p style="font-weight:600; color:#eee;">Klik atau seret file PDF ke sini</p>
                    <p style="font-size:0.75rem; color:var(--text-muted);">Dapat memilih beberapa file sekaligus</p>
                    <input type="file" name="lampiran_surat[]" id="lampiran_upload" accept=".pdf" multiple style="display:none;">
                </div>
                
                <!-- Hidden input untuk melacak file lama yang dihapus -->
                <input type="hidden" name="deleted_existing_files" id="deleted_existing_files" value="">

                <div id="file-list-preview" style="margin-top:10px;"></div>

                <?php if($is_edit && !empty($konten['lampiran_files'])): ?>
                    <div style="margin-top:20px;">
                        <div style="font-size: 0.75rem; color: #777; margin-bottom: 8px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px;">File PDF Tersimpan:</div>
                        <div id="existing-files-list">
                            <?php foreach($konten['lampiran_files'] as $idx => $filePath): 
                                $fileName = basename($filePath);
                            ?>
                                <div class="preview-bar" id="existing-file-<?php echo $idx; ?>" style="margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; background: rgba(74, 144, 226, 0.05); border-color: rgba(74, 144, 226, 0.2);">
                                    <span><i class="fas fa-check-circle" style="color:var(--accent-color);"></i> <?php echo htmlspecialchars($fileName); ?></span>
                                    <button type="button" onclick="removeExistingFile('<?php echo htmlspecialchars($filePath); ?>', 'existing-file-<?php echo $idx; ?>')" style="background:none; border:none; color:#e74c3c; cursor:pointer; font-size:1rem;"><i class="fas fa-times"></i></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- CARD 5: TANDA TANGAN PANITIA -->
        <div class="card">
            <div class="card-header"><i class="fas fa-pen-nib"></i> Penanggung Jawab / Panitia</div>
            <div class="card-body">
                <div class="form-group" style="margin-bottom:24px;">
                    <label>Label Panitia (Opsional)</label>
                    <input type="text" name="label_panitia" placeholder="Kosongkan untuk default dari Nama Kegiatan" value="<?php echo htmlspecialchars($edit_data['label_panitia'] ?? ''); ?>" style="text-transform: uppercase;">
                    <small style="color: #666; display:block; margin-top:6px;"><i class="fas fa-info-circle"></i> Akan tampil sebagai: PANITIA PELAKSANA <strong>[isian ini]</strong> 2026. Kosongkan jika ingin otomatis.</small>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label>Nama Ketua Pelaksana</label>
                        <div class="tpl-picker" id="picker-panitia-ketua">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" name="panitia_ketua" class="tpl-search-input" placeholder="Cari atau ketik nama..." value="<?php echo htmlspecialchars($edit_data['panitia_ketua'] ?? ''); ?>" required style="text-transform: uppercase;" onfocus="showTplResults('panitia-ketua')" onkeyup="filterTpl('panitia-ketua')" oninput="handleCustomName('ketua', this.value)">
                            <div class="tpl-results" id="results-panitia-ketua">
                                <?php foreach($panitia_ketua_list as $pk): ?>
                                <div class="tpl-item" onclick="selectSavedPanitia('ketua', <?php echo htmlspecialchars(json_encode(['nama' => $pk['nama'], 'ttd' => $pk['file_ttd']])); ?>)">
                                    <div class="tpl-item-label"><?php echo htmlspecialchars($pk['nama']); ?></div>
                                    <div class="tpl-item-text">Ketua Pelaksana Tersimpan</div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="margin-top:15px;">
                            <label>Mode Tanda Tangan</label>
                            <select id="ttd_mode_ketua" onchange="changeTtdMode('ketua')">
                                <option value="database" <?php echo !empty($edit_data['panitia_ketua_ttd']) ? 'selected' : ''; ?>>📂 Gunakan TTD Tersimpan</option>
                                <option value="draw">✍️ Gambar Manual</option>
                                <option value="upload">📁 Upload Gambar</option>
                                <option value="none" <?php echo empty($edit_data['panitia_ketua_ttd']) ? 'selected' : ''; ?>>🚫 Kosong / TTD Basah</option>
                            </select>
                            <div id="wrap_canvas_ketua" style="display:none; margin-top:12px;">
                                <canvas id="pad_ketua" width="300" height="150"></canvas>
                                <div style="text-align:center; margin-top:10px;"><button type="button" onclick="clearPad('ketua')" class="btn-outline">Reset Canvas</button></div>
                            </div>
                            <div id="wrap_upload_ketua" style="display:none; margin-top:12px;"><input type="file" id="upload_ketua" accept="image/png" onchange="handleTtdUpload('ketua', this)"></div>
                            <input type="hidden" name="panitia_ketua_ttd" id="ttd_ketua_val" value="<?php echo htmlspecialchars($edit_data['panitia_ketua_ttd'] ?? ''); ?>">
                            <div id="preview_ttd_ketua" style="margin-top:15px; <?php echo !empty($edit_data['panitia_ketua_ttd']) ? 'display:block;' : 'display:none;'; ?> text-align:center;">
                                <img id="img_preview_ketua" src="<?php echo !empty($edit_data['panitia_ketua_ttd']) ? (strpos($edit_data['panitia_ketua_ttd'], 'data:image') !== false ? htmlspecialchars($edit_data['panitia_ketua_ttd']) : uploadUrl($edit_data['panitia_ketua_ttd'])) : ''; ?>" style="max-height:80px; border-radius:8px; border: 1px solid var(--border-color); background: #fff; padding: 5px;">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Nama Sekretaris Pelaksana</label>
                        <div class="tpl-picker" id="picker-panitia-sekretaris">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" name="panitia_sekretaris" class="tpl-search-input" placeholder="Cari atau ketik nama..." value="<?php echo htmlspecialchars($edit_data['panitia_sekretaris'] ?? ''); ?>" required style="text-transform: uppercase;" onfocus="showTplResults('panitia-sekretaris')" onkeyup="filterTpl('panitia-sekretaris')" oninput="handleCustomName('sekretaris', this.value)">
                            <div class="tpl-results" id="results-panitia-sekretaris">
                                <?php foreach($panitia_sekretaris_list as $ps): ?>
                                <div class="tpl-item" onclick="selectSavedPanitia('sekretaris', <?php echo htmlspecialchars(json_encode(['nama' => $ps['nama'], 'ttd' => $ps['file_ttd']])); ?>)">
                                    <div class="tpl-item-label"><?php echo htmlspecialchars($ps['nama']); ?></div>
                                    <div class="tpl-item-text">Sekretaris Pelaksana Tersimpan</div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div style="margin-top:15px;">
                            <label>Mode Tanda Tangan</label>
                            <select id="ttd_mode_sekretaris" onchange="changeTtdMode('sekretaris')">
                                <option value="database" <?php echo !empty($edit_data['panitia_sekretaris_ttd']) ? 'selected' : ''; ?>>📂 Gunakan TTD Tersimpan</option>
                                <option value="draw">✍️ Gambar Manual</option>
                                <option value="upload">📁 Upload Gambar</option>
                                <option value="none" <?php echo empty($edit_data['panitia_sekretaris_ttd']) ? 'selected' : ''; ?>>🚫 Kosong / TTD Basah</option>
                            </select>
                            <div id="wrap_canvas_sekretaris" style="display:none; margin-top:12px;">
                                <canvas id="pad_sekretaris" width="300" height="150"></canvas>
                                <div style="text-align:center; margin-top:10px;"><button type="button" onclick="clearPad('sekretaris')" class="btn-outline">Reset Canvas</button></div>
                            </div>
                            <div id="wrap_upload_sekretaris" style="display:none; margin-top:12px;"><input type="file" id="upload_sekretaris" accept="image/png" onchange="handleTtdUpload('sekretaris', this)"></div>
                            <input type="hidden" name="panitia_sekretaris_ttd" id="ttd_sekretaris_val" value="<?php echo htmlspecialchars($edit_data['panitia_sekretaris_ttd'] ?? ''); ?>">
                            <div id="preview_ttd_sekretaris" style="margin-top:15px; <?php echo !empty($edit_data['panitia_sekretaris_ttd']) ? 'display:block;' : 'display:none;'; ?> text-align:center;">
                                <img id="img_preview_sekretaris" src="<?php echo !empty($edit_data['panitia_sekretaris_ttd']) ? (strpos($edit_data['panitia_sekretaris_ttd'], 'data:image') !== false ? htmlspecialchars($edit_data['panitia_sekretaris_ttd']) : uploadUrl($edit_data['panitia_sekretaris_ttd'])) : ''; ?>" style="max-height:80px; border-radius:8px; border: 1px solid var(--border-color); background: #fff; padding: 5px;">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD 6: OPSI TANDA TANGAN & STEMPEL -->
        <div class="card">
            <div class="card-header"><i class="fas fa-stamp"></i> Opsi Pengesahan & Stempel</div>
            <div class="card-body">
                <div class="grid-2">
                    <div class="switch-container"><span class="switch-label"><i class="fas fa-user-tie"></i> Sertakan TTD WAREK III</span><label class="switch"><input type="checkbox" name="use_ttd_warek" value="1" <?php echo ($edit_data['use_ttd_warek'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                    <div class="switch-container"><span class="switch-label"><i class="fas fa-user-graduate"></i> Sertakan TTD PRESMA BEM</span><label class="switch"><input type="checkbox" name="use_ttd_presma" value="1" <?php echo ($edit_data['use_ttd_presma'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                    <div class="switch-container"><span class="switch-label"><i class="fas fa-stamp"></i> Sertakan Cap PANITIA</span><label class="switch"><input type="checkbox" name="use_cap_panitia" value="1" <?php echo ($edit_data['use_cap_panitia'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                    <div class="switch-container"><span class="switch-label"><i class="fas fa-stamp"></i> Sertakan Cap WAREK</span><label class="switch"><input type="checkbox" name="use_cap_warek" value="1" <?php echo ($edit_data['use_cap_warek'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                    <div class="switch-container"><span class="switch-label"><i class="fas fa-stamp"></i> Sertakan Cap BEM</span><label class="switch"><input type="checkbox" name="use_cap_presma" value="1" <?php echo ($edit_data['use_cap_presma'] ?? '1') == '1' ? 'checked' : ''; ?>><span class="slider"></span></label></div>
                </div>
            </div>
        </div>

        <div style="margin: 40px 0; text-align: center; animation: slideUp 0.8s ease-out forwards; animation-delay: 0.7s; opacity:0;">
            <button type="submit" class="btn-primary" style="margin: 0 auto; min-width: 300px;">
                <i class="fas fa-save"></i> <?php echo $is_edit ? 'Simpan Perubahan' : 'Generate & Arsipkan'; ?>
            </button>
            <?php if($is_edit): ?>
                <a href="arsip-surat.php" style="display:inline-block; margin-top:20px; color:var(--text-muted); font-size:0.9rem; text-decoration:none;"><i class="fas fa-arrow-left"></i> Kembali ke Arsip</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- JAVASCRIPT -->
<script>
// Auto-update Nomor Urut based on Jenis Surat
const nextNumbers = {
    'L': '<?php echo $next_L; ?>',
    'D': '<?php echo $next_D; ?>'
};

document.addEventListener('DOMContentLoaded', function() {
    const jenisSuratSelect = document.getElementById('jenis_surat_select');
    const nomorUrutInput = document.getElementById('nomor_urut_input');

    if (jenisSuratSelect && nomorUrutInput) {
        jenisSuratSelect.addEventListener('change', function() {
            // Hanya update jika tidak dalam mode readonly (misal saat clone)
            if (!nomorUrutInput.readOnly && nextNumbers[this.value]) {
                nomorUrutInput.value = nextNumbers[this.value];
            }
        });
    }
});

// ========== Template Picker Logic ==========
// Helper: reset z-index semua card, lalu naikkan card yang punya dropdown aktif
function _elevatePickerCard(resultsEl) {
    document.querySelectorAll('.buat-surat-container .card').forEach(c => c.style.zIndex = '');
    if (resultsEl) {
        const card = resultsEl.closest('.card');
        if (card) card.style.zIndex = '50';
    }
}
function _resetPickerCards() {
    document.querySelectorAll('.buat-surat-container .card').forEach(c => c.style.zIndex = '');
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

function selectTpl(targetId, value, type) {
    document.getElementById(targetId).value = value;
    document.getElementById('results-' + type).style.display = 'none';
    _resetPickerCards();
}

function selectKegiatan(data) {
    document.getElementById('input_nama_kegiatan').value = data.nama;
    document.getElementById('kode_kegiatan_input').value = data.kode;
    document.getElementById('results-kegiatan').style.display = 'none';
    _resetPickerCards();
}

function selectSavedPanitia(role, data) {
    const input = document.querySelector('input[name="panitia_' + role + '"]');
    if (input) input.value = data.nama.toUpperCase();
    
    const modeSel = document.getElementById('ttd_mode_' + role);
    modeSel.value = 'database';
    changeTtdMode(role);
    
    document.getElementById('ttd_' + role + '_val').value = data.ttd;
    const previewWrap = document.getElementById('preview_ttd_' + role);
    const previewImg = document.getElementById('img_preview_' + role);
    previewWrap.style.display = 'block';
    
    // Path untuk TTD tersimpan
    const uploadBase = '<?php echo uploadUrl(""); ?>';
    previewImg.src = uploadBase + data.ttd;
    
    document.getElementById('results-panitia-' + role).style.display = 'none';
    _resetPickerCards();
}

function handleCustomName(role, val) {
    const modeSel = document.getElementById('ttd_mode_' + role);
    if (modeSel.value === 'database') {
        modeSel.value = 'none';
        changeTtdMode(role);
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.tpl-picker')) {
        document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
        _resetPickerCards();
    }
});

// ========== Paragraf Mode ==========
function toggleModeParagraf() {
    let tpl = document.getElementById('blok-template');
    let cust = document.getElementById('blok-custom');
    let btn = document.getElementById('toggle-mode-btn');
    if(tpl.style.display !== 'none') {
        tpl.style.display = 'none';
        cust.style.display = 'block';
        btn.innerText = 'Ganti ke Mode Template';
    } else {
        tpl.style.display = 'block';
        cust.style.display = 'none';
        btn.innerText = 'Ganti ke Mode Custom';
    }
}

// ========== Mini RTE Mode Template ==========
function execMiniRTE(id, cmd) {
    let el = document.getElementById(id);
    el.focus();
    if (cmd === 'unbold') {
        let sel = window.getSelection();
        if (sel.rangeCount > 0 && !sel.isCollapsed) {
            let range = sel.getRangeAt(0);
            let span = document.createElement('span');
            span.style.fontWeight = 'normal';
            span.appendChild(range.extractContents());
            range.insertNode(span);
            // Clear selection
            sel.removeAllRanges();
        }
    } else {
        document.execCommand(cmd, false, null);
    }
    syncMiniRTE(id, id.replace('rte_', 'input_'));
}

function syncMiniRTE(id, targetId) {
    let el = document.getElementById(id);
    let html = el.innerHTML;
    if (el.innerText.trim() === '') html = ''; // Clear if empty
    document.getElementById(targetId).value = html;
    updatePreviewParagraf();
}

function updatePreviewParagraf() {
    let nama = document.getElementById('input_nama_kegiatan').value.trim();
    let tema = document.getElementById('input_tema').value.trim();
    
    let isNamaEmpty = (nama === '' || nama === '<br>');
    let isTemaEmpty = (tema === '' || tema === '<br>');

    let displayNama = isNamaEmpty ? '[Nama Kegiatan]' : nama;
    let displayTema = isTemaEmpty ? '[Tema]' : tema;

    // Wrap in <b> to represent the bold default
    let html = 'Sehubungan akan diadakannya kegiatan <b>' + displayNama + '</b>' +
               (isTemaEmpty ? ' dengan tema "<b>' + displayTema + '</b>"' : ' dengan tema "<b>' + displayTema + '</b>"') +
               ' yang akan dilaksanakan pada :';
    
    document.getElementById('preview-paragraf-text').innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    if(document.getElementById('preview-paragraf-text')) {
        updatePreviewParagraf();
    }
});

function execRTE(cmd) {
    document.getElementById('rte-editor').focus();
    document.execCommand(cmd, false, null);
}

function syncRTE() {
    let html = document.getElementById('rte-editor').innerHTML;
    document.getElementById('input_tema_kegiatan_val').value = html;
}

// ========== Drum Picker Class (Preserved) ==========
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
const existingWaktu = document.getElementById('out-waktu').value || '';
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
    
    // Inisialisasi TTD jika sudah ada (Edit Mode)
    ['ketua', 'sekretaris'].forEach(role => {
        const val = document.getElementById('ttd_' + role + '_val').value;
        if (val) {
            const previewWrap = document.getElementById('preview_ttd_' + role);
            const previewImg = document.getElementById('img_preview_' + role);
            previewWrap.style.display = 'block';
            if (val.indexOf('data:image') === -1 && val.indexOf('/') !== -1) {
                previewImg.src = '<?php echo uploadUrl(""); ?>' + val;
                document.getElementById('ttd_mode_' + role).value = 'database';
            } else if (val.indexOf('data:image') !== -1) {
                previewImg.src = val;
                document.getElementById('ttd_mode_' + role).value = 'draw';
            }
        }
    });
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
    
    sw.style.background = on ? 'var(--accent-color)' : '#222';
    knob.style.transform = on ? 'translateX(16px)' : 'translateX(0)';
    lbl.textContent  = on ? 'Tanpa waktu akhir' : 'Dengan waktu akhir';
    end.style.opacity       = on ? '0.2' : '1';
    end.style.pointerEvents = on ? 'none' : '';
    updateWaktu();
}

// ========== Tanggal Range ==========
const HARI_ID  = ['Minggu','Senin','Selasa','Rabu','Kamis',"Jum'at",'Sabtu'];
const BULAN_ID = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

function handleDurasiChange() {
    const sel = document.getElementById('durasi-hari');
    const custom = document.getElementById('custom-hari');
    const label = document.getElementById('label-hari');
    
    if (sel.value === 'custom') {
        custom.style.display = 'block';
        label.style.display = 'inline';
    } else {
        custom.style.display = 'none';
        label.style.display = 'none';
    }
    formatTanggalRange();
}

function formatTanggalRange() {
    const mulai = document.getElementById('tgl-mulai').value;
    const sel = document.getElementById('durasi-hari');
    const custom = document.getElementById('custom-hari');
    
    if (!mulai) { 
        document.getElementById('preview-tanggal').innerText = '—belum dipilih—'; 
        return; 
    }

    let jmlHari = parseInt(sel.value);
    if (sel.value === 'custom') {
        jmlHari = parseInt(custom.value) || 1;
    }

    const d1 = new Date(mulai + 'T00:00:00');
    let result = '';

    if (jmlHari <= 1) {
        result = HARI_ID[d1.getDay()] + ', ' + d1.getDate() + ' ' + BULAN_ID[d1.getMonth()] + ' ' + d1.getFullYear();
    } else {
        // Hitung tanggal selesai
        const d2 = new Date(d1);
        d2.setDate(d1.getDate() + (jmlHari - 1));

        const hari = HARI_ID[d1.getDay()] === HARI_ID[d2.getDay()] ? HARI_ID[d1.getDay()] : HARI_ID[d1.getDay()] + '-' + HARI_ID[d2.getDay()];
        const bln1 = BULAN_ID[d1.getMonth()], bln2 = BULAN_ID[d2.getMonth()];
        const tgl  = bln1 === bln2 && d1.getFullYear() === d2.getFullYear()
            ? d1.getDate() + '-' + d2.getDate() + ' ' + bln1 + ' ' + d1.getFullYear()
            : d1.getDate() + ' ' + bln1 + ' ' + d1.getFullYear() + ' – ' + d2.getDate() + ' ' + bln2 + ' ' + d2.getFullYear();
        result = hari + ', ' + tgl;
    }

    document.getElementById('out-tanggal').value = result;
    document.getElementById('preview-tanggal').innerText = result;
}

// ========== Tanda Tangan Logic ==========
const sigPads = {};
function changeTtdMode(role) {
    const mode = document.getElementById('ttd_mode_' + role).value;
    document.getElementById('wrap_canvas_' + role).style.display = mode === 'draw' ? 'block' : 'none';
    document.getElementById('wrap_upload_' + role).style.display = mode === 'upload' ? 'block' : 'none';
    if (mode === 'none') {
        document.getElementById('ttd_' + role + '_val').value = '';
        document.getElementById('preview_ttd_' + role).style.display = 'none';
    } else if (mode === 'draw') {
        initSignaturePad(role);
    }
}

function handleTtdUpload(role, input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const res = e.target.result;
            document.getElementById('ttd_' + role + '_val').value = res;
            document.getElementById('preview_ttd_' + role).style.display = 'block';
            document.getElementById('img_preview_' + role).src = res;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function initSignaturePad(role) {
    const canvas = document.getElementById('pad_' + role);
    if(!canvas) return;
    const ctx = canvas.getContext('2d');
    let isDrawing = false;
    sigPads[role] = { canvas, ctx };
    
    ctx.lineWidth = 3;
    ctx.lineCap = 'round';
    ctx.strokeStyle = '#02183b';

    function getPos(e) {
        const r = canvas.getBoundingClientRect();
        let cx = e.touches && e.touches.length > 0 ? e.touches[0].clientX : e.clientX;
        let cy = e.touches && e.touches.length > 0 ? e.touches[0].clientY : e.clientY;
        return { x: (cx - r.left) * (canvas.width / r.width), y: (cy - r.top) * (canvas.height / r.height) };
    }

    function start(e) { e.preventDefault(); isDrawing = true; const p = getPos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); }
    function draw(e) { if (!isDrawing) return; e.preventDefault(); const p = getPos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
    function end() { if (isDrawing) { isDrawing = false; const url = canvas.toDataURL('image/png'); document.getElementById('ttd_' + role + '_val').value = url; document.getElementById('preview_ttd_' + role).style.display = 'block'; document.getElementById('img_preview_' + role).src = url; } }

    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', draw);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start);
    canvas.addEventListener('touchmove', draw);
    window.addEventListener('touchend', end);
}

function clearPad(role) {
    if(sigPads[role]) {
        sigPads[role].ctx.clearRect(0, 0, sigPads[role].canvas.width, sigPads[role].canvas.height);
        document.getElementById('ttd_' + role + '_val').value = '';
    }
}

// ========== Lampiran Upload Logic (Advanced) ==========
const dropZone = document.getElementById('lampiran_drop_zone');
const fileInput = document.getElementById('lampiran_upload');
const previewList = document.getElementById('file-list-preview');
const deletedExistingInput = document.getElementById('deleted_existing_files');

let newFiles = []; // Array untuk menampung file baru yang dipilih

if (dropZone && fileInput) {
    dropZone.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', (e) => {
        const added = Array.from(e.target.files);
        // Tambahkan file baru ke array (hindari duplikat nama & ukuran jika perlu)
        newFiles = [...newFiles, ...added];
        updateFilesAndPreview();
    });

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.style.borderColor = 'var(--accent-color)';
        dropZone.style.background = 'rgba(74, 144, 226, 0.1)';
    });

    ['dragleave', 'drop'].forEach(evt => {
        dropZone.addEventListener(evt, () => {
            dropZone.style.borderColor = 'var(--border-color)';
            dropZone.style.background = 'rgba(0,0,0,0.1)';
        });
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        const added = Array.from(e.dataTransfer.files).filter(f => f.type === 'application/pdf');
        if (added.length > 0) {
            newFiles = [...newFiles, ...added];
            updateFilesAndPreview();
        }
    });
}

function updateFilesAndPreview() {
    // Rebuild FileList untuk input file agar terkirim saat submit
    const dt = new DataTransfer();
    newFiles.forEach(file => dt.items.add(file));
    fileInput.files = dt.files;

    // Render Preview
    previewList.innerHTML = '';
    if (newFiles.length > 0) {
        const header = document.createElement('div');
        header.style = "font-size: 0.75rem; color: #777; margin-bottom: 8px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px;";
        header.innerText = 'File Baru yang akan diupload:';
        previewList.appendChild(header);
        
        newFiles.forEach((file, index) => {
            const item = document.createElement('div');
            item.className = 'preview-bar';
            item.style = "margin-bottom: 5px; display: flex; justify-content: space-between; align-items: center; animation: slideUp 0.3s ease-out;";
            item.innerHTML = `
                <span><i class="fas fa-file-pdf" style="color:#e74c3c;"></i> ${file.name} (${(file.size/1024).toFixed(1)} KB)</span>
                <button type="button" onclick="removeNewFile(${index})" style="background:none; border:none; color:#e74c3c; cursor:pointer; font-size:1rem;"><i class="fas fa-times"></i></button>
            `;
            previewList.appendChild(item);
        });
    }
}

function removeNewFile(index) {
    newFiles.splice(index, 1);
    updateFilesAndPreview();
}

function removeExistingFile(filePath, elementId) {
    if (confirm('Hapus lampiran yang sudah tersimpan?')) {
        const currentDeleted = deletedExistingInput.value ? deletedExistingInput.value.split(',') : [];
        currentDeleted.push(filePath);
        deletedExistingInput.value = currentDeleted.join(',');
        
        const el = document.getElementById(elementId);
        if (el) el.style.display = 'none';
    }
}

document.querySelector('form').addEventListener('submit', syncRTE);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>