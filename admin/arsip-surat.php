<?php
// admin/arsip-surat.php
$page_css = 'arsip-surat';
require_once __DIR__ . '/header.php';

requireSekretaris();

$periode_id = getUserPeriode();
$jenis = $_GET['jenis'] ?? 'L';
if (!in_array($jenis, ['L', 'D', 'M'])) $jenis = 'L';

$error = '';
$success = '';

// Handle Update Tanggal Dikirim (GET biasa - menghindari form nesting)
// Update juga seluruh anggota grup yang sama (nomor_surat yang sama)
if (isset($_GET['set_tgl']) && is_numeric($_GET['set_tgl']) && !empty($_GET['tanggal'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_surat = (int)$_GET['set_tgl'];
        $tgl_raw  = trim($_GET['tanggal']);
        
        // Konversi DD/MM/YYYY ke YYYY-MM-DD untuk MySQL
        $tgl_db = NULL;
        $p = explode('/', $tgl_raw);
        if (count($p) === 3 && checkdate((int)$p[1], (int)$p[0], (int)$p[2])) {
            $tgl_db = "$p[2]-$p[1]-$p[0]";
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_raw)) {
            $tgl_db = $tgl_raw;
        } else {
            $error = "Format tanggal salah. Gunakan DD/MM/YYYY (contoh: 24/04/2026).";
        }
        
        if (empty($error) && $tgl_db) {
            // Cari nomor_surat dari ID ini, lalu update seluruh grup
            $surat = dbFetchOne("SELECT nomor_surat FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id_surat, $periode_id]);
            if ($surat) {
                $res = dbQuery("UPDATE arsip_surat SET tanggal_dikirim = ? WHERE nomor_surat = ? AND periode_id = ?", [$tgl_db, $surat['nomor_surat'], $periode_id]);
            } else {
                $res = dbQuery("UPDATE arsip_surat SET tanggal_dikirim = ? WHERE id = ? AND periode_id = ?", [$tgl_db, $id_surat, $periode_id]);
            }
            if ($res) {
                redirect("admin/arsip-surat.php?jenis=$jenis", 'Tanggal Dikirim berhasil diperbarui untuk seluruh grup.', 'success');
            } else {
                $error = "Gagal memperbarui tanggal.";
            }
        }
    } else {
        $error = "CSRF token tidak valid.";
    }
}

// Handle Hapus Tanggal Dikirim (Reset) - juga untuk seluruh grup
if (isset($_GET['reset_tgl']) && is_numeric($_GET['reset_tgl'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_reset = (int)$_GET['reset_tgl'];
        // Cari nomor_surat, lalu reset seluruh grup
        $surat = dbFetchOne("SELECT nomor_surat FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id_reset, $periode_id]);
        if ($surat) {
            $res = dbQuery("UPDATE arsip_surat SET tanggal_dikirim = NULL WHERE nomor_surat = ? AND periode_id = ?", [$surat['nomor_surat'], $periode_id]);
        } else {
            $res = dbQuery("UPDATE arsip_surat SET tanggal_dikirim = NULL WHERE id = ? AND periode_id = ?", [$id_reset, $periode_id]);
        }
        if ($res) {
            redirect("admin/arsip-surat.php?jenis=$jenis", 'Tanggal Dikirim berhasil direset untuk seluruh grup.', 'success');
        } else {
            $error = "Gagal mereset tanggal.";
        }
    } else {
        $error = "CSRF token tidak valid.";
    }
}

// Handle upload Kop Surat Global
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_kop') {
    if (!csrfVerify()) {
        $error = "CSRF tidak valid.";
    } else {
        $kop_path = rtrim(UPLOAD_PATH, '/\\') . '/kop_surat.png';
        if (isset($_FILES['kop_surat']) && $_FILES['kop_surat']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['kop_surat']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png', 'jpg', 'jpeg'])) {
                if (move_uploaded_file($_FILES['kop_surat']['tmp_name'], $kop_path)) {
                    $success = "Template Kop Surat berhasil disimpan/diperbarui.";
                } else {
                    $error = "Gagal memindahkan file yang diupload. Cek permissions.";
                }
            } else {
                $error = "Hanya mendung format gambar PNG, JPG, JPEG.";
            }
        } else {
            $error = "File gagal diunggah atau tidak ada file yang dipilih.";
        }
    }
}

// Handle Hapus Arsip
if (isset($_GET['hapus']) && is_numeric($_GET['hapus'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_hapus = (int)$_GET['hapus'];
        $surat_target = dbFetchOne("SELECT * FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id_hapus, $periode_id], "ii");
        
        if ($surat_target) {
            $can_delete = true;
            // Validasi: Jika surat keluar/dalam, hanya boleh hapus yang ID-nya paling terakhir (mencegah loncat nomor)
            // EXCEPTION: Surat dalam grup boleh dihapus kapan saja karena sibling lain masih memegang nomor yang sama
            if (in_array($surat_target['jenis_surat'], ['L', 'D'])) {
                $max_id_keluar = dbFetchOne("SELECT MAX(id) as last_id FROM arsip_surat WHERE periode_id = ? AND jenis_surat = ?", [$periode_id, $surat_target['jenis_surat']], "is")['last_id'];
                if ($id_hapus != $max_id_keluar) {
                    // Cek apakah surat ini punya sibling (grup) — jika ya, nomor aman
                    $sibling_count = (int)dbFetchOne(
                        "SELECT COUNT(*) as cnt FROM arsip_surat WHERE nomor_surat = ? AND periode_id = ? AND id != ?",
                        [$surat_target['nomor_surat'], $periode_id, $id_hapus], "sii"
                    )['cnt'];
                    if ($sibling_count === 0) {
                        // Tidak ada sibling — nomor akan hilang jika dihapus
                        $can_delete = false;
                        $error = "Hanya arsip surat keluar urutan PALING AKHIR yang diizinkan untuk dihapus guna menjaga integritas nomor urut. Gunakan fitur 'Edit' untuk merevisi surat di tengah urutan.";
                    }
                }
            }
            
            if ($can_delete) {
                // 1. Hapus file utama (untuk manual/masuk)
                if (!empty($surat_target['file_surat'])) {
                    $file_path = UPLOAD_PATH . DIRECTORY_SEPARATOR . ltrim($surat_target['file_surat'], '/\\');
                    if (file_exists($file_path)) @unlink($file_path);
                }

                // 2. Hapus lampiran-lampiran (untuk surat otomatis)
                if (!empty($surat_target['konten_surat'])) {
                    $konten = json_decode((string)$surat_target['konten_surat'], true);
                    if (isset($konten['lampiran_files']) && is_array($konten['lampiran_files'])) {
                        foreach ($konten['lampiran_files'] as $rel_path) {
                            $lampiran_path = UPLOAD_PATH . DIRECTORY_SEPARATOR . ltrim($rel_path, '/\\');
                            if (file_exists($lampiran_path)) @unlink($lampiran_path);
                        }
                    }
                }

                dbQuery("DELETE FROM arsip_surat WHERE id = ?", [$id_hapus], "i");
                $success = "Arsip surat beserta seluruh file asetnya berhasil dihapus secara permanen.";
            }
        } else {
            $error = "Surat tidak ditemukan atau akses ditolak.";
        }
    } else {
        $error = "Token keamanan tidak valid saat menghapus arsip.";
    }
}

// Ambil data arsip
$surat_list_raw = dbFetchAll(
    "SELECT * FROM arsip_surat WHERE periode_id = ? AND jenis_surat = ? ORDER BY id ASC",
    [$periode_id, $jenis], "is"
);

// Grouping logic: Group by nomor_surat
$grouped_surat = [];
foreach ($surat_list_raw as $s) {
    $grouped_surat[$s['nomor_surat']][] = $s;
}

// Get latest ID for Keluar/Dalam UI rendering
// Get latest ID for current Tab UI rendering
$latest_keluar_id = 0;
if ($jenis !== 'M') {
    $latest_keluar_id = dbFetchOne("SELECT MAX(id) as last_id FROM arsip_surat WHERE periode_id = ? AND jenis_surat = ?", [$periode_id, $jenis], "is")['last_id'];
}

// HANDLE EXPORT EXCEL
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $filename = "Arsip_Surat_{$jenis}_" . date('Y-m-d') . ".xls";
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Pragma: public");
    
    echo "<table border='1'>";
    echo "<tr><th colspan='5'>Arsip Surat Jenis: {$jenis}</th></tr>";
    echo "<tr><th>No</th><th>Tanggal</th><th>Nomor Surat</th><th>Perihal</th><th>Tujuan/Asal</th></tr>";
    if (empty($surat_list_raw)) {
        echo "<tr><td colspan='5'>Belum ada data arsip</td></tr>";
    } else {
        $no = 1;
        foreach ($surat_list_raw as $surat) {
            echo "<tr>";
            echo "<td>" . $no++ . "</td>";
            echo "<td>" . htmlspecialchars($surat['tanggal_dikirim']) . "</td>";
            echo "<td>" . htmlspecialchars($surat['nomor_surat']) . "</td>";
            echo "<td>" . htmlspecialchars($surat['perihal']) . "</td>";
            echo "<td>" . htmlspecialchars($surat['tujuan']) . "</td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    exit();
}

$css = "
.surat-actions { margin-top: 20px; display: flex; justify-content: space-between; align-items: center; }
.btn-buat { background: #4A90E2; color: white; padding: 10px 20px; border-radius: 5px; text-decoration: none; font-weight: bold; display:inline-flex; align-items:center; gap:8px; }
.btn-buat:hover { background: #357ABD; }
.tab-container { display: flex; gap: 10px; margin-bottom: 20px; }
.tab-btn { padding: 10px 20px; background: #1a1a2e; color: #8BB9F0; text-decoration: none; border-radius: 5px; border: 1px solid #4A90E2; }
.tab-btn:hover { background: #4A90E2; color: white; }
.tab-btn.active { background: #4A90E2; color: white; font-weight: bold; }

/* Grouping Styles */
.group-parent { background: rgba(74, 144, 226, 0.05) !important; }
.group-toggle { cursor: pointer; color: #4A90E2; font-size: 1.1rem; transition: transform 0.2s; display: inline-block; vertical-align: middle; margin-right: 5px; }
.group-toggle.open { transform: rotate(90deg); }
.child-row { display: none; background: rgba(0, 0, 0, 0.2) !important; }
.child-row.show { display: table-row; }

/* Accordion Dropdown Styles */
.btn-accordion-toggle {
    display: flex;
    justify-content: space-between;
    align-items: center;
    width: 100%;
    background: rgba(74, 144, 226, 0.1);
    border: 1px solid rgba(74, 144, 226, 0.3);
    color: #4A90E2;
    padding: 12px 16px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.3s ease;
    margin-top: 10px;
}
.btn-accordion-toggle:hover {
    background: rgba(74, 144, 226, 0.2);
}
.btn-accordion-toggle .chevron-icon {
    transition: transform 0.3s ease;
}
.btn-accordion-toggle.open .chevron-icon {
    transform: rotate(180deg);
}

@media (max-width: 768px) {
    .child-row {
        display: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
    }
    .child-row.show { 
        display: block !important; 
        margin-top: 0 !important;
        border-top-left-radius: 0 !important;
        border-top-right-radius: 0 !important;
        border-top: 1px dashed rgba(255,255,255,0.15) !important;
        background: #171717 !important; /* Lebih gelap sedikit untuk efek kedalaman laci */
        box-shadow: inset 0 5px 15px rgba(0,0,0,0.2) !important; /* Bayangan ke dalam */
        margin-bottom: 1.2rem !important;
        padding-top: 0 !important;
    }
    .group-parent.open {
        border-bottom-left-radius: 0 !important;
        border-bottom-right-radius: 0 !important;
        margin-bottom: 0 !important;
        border-bottom: none !important; /* Hapus border bawah parent */
    }
    .group-parent.open .td-aksi {
        padding-bottom: 8px !important; /* Kurangi jarak bawah ke garis dashed */
    }
    .child-row.show .td-aksi {
        padding-top: 20px !important;
    }
}
@media (min-width: 769px) {
    .child-row.show td {
        background: rgba(0, 0, 0, 0.3);
        border-bottom: 1px dashed rgba(255,255,255,0.05);
    }
}
.child-indicator { display: inline-block; width: 20px; height: 1px; background: #333; margin-right: 10px; vertical-align: middle; position: relative; top: -2px; }
.badge-count { background: #4A90E2; color: white; padding: 2px 6px; border-radius: 10px; font-size: 0.7rem; margin-left: 5px; vertical-align: middle; }
.btn-copy { background: #673AB7; color: white; padding: 8px 12px; border-radius: 4px; text-decoration: none; font-size: 0.85rem; font-weight: bold; display: inline-flex; align-items: center; gap: 6px; border: none; cursor: pointer; transition: background 0.2s; }
.btn-copy:hover { background: #5E35B1; }

/* CSS untuk indikator */
.indicator-badge { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; white-space: nowrap; width: 100%; box-sizing: border-box; font-weight: 500; text-decoration: none; margin-top: 4px; transition: background 0.2s; }
.badge-manual { background: rgba(255,255,255,0.05); color: #ccc; border: 1px solid rgba(255,255,255,0.1); }
.badge-sistem { background: rgba(74,144,226,0.1); color: #4A90E2; border: 1px solid rgba(74,144,226,0.2); }
.badge-recipient { background: rgba(46,125,50,0.1); color: #2E7D32; border: 1px solid rgba(46,125,50,0.2); }
.badge-link { background: rgba(139,185,240,0.05); color: #8BB9F0; border: 1px solid rgba(139,185,240,0.3); }
.badge-link:hover { background: rgba(139,185,240,0.15); }
.badge-multi { background: rgba(156,39,176,0.1); color: #9C27B0; border: 1px solid rgba(156,39,176,0.2); }
@media (min-width: 769px) {
    .indicator-badge { width: fit-content; justify-content: flex-start; }
}

/* Upload Drop Zone */
.upload-drop-zone { border: 2px dashed rgba(74,144,226,0.4); border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.3s ease; position: relative; background: rgba(255,255,255,0.02); overflow: hidden; }
.upload-drop-zone:hover, .upload-drop-zone.dragover { background: rgba(74,144,226,0.08); border-color: #4A90E2; }
.upload-drop-zone input[type='file'] { position: absolute; width: 100%; height: 100%; top: 0; left: 0; opacity: 0; cursor: pointer; z-index: 2; }
.upload-preview { max-width: 100%; max-height: 120px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); display: block; margin: 0 auto 15px; }
.upload-icon { font-size: 2.5rem; color: #4A90E2; margin-bottom: 10px; display: block; }

/* CSS untuk fitur bulk select */
.bulk-checkbox-column { display: none; }
.bulk-active .bulk-checkbox-column { display: table-cell; }
.bulk-active .header-row th.bulk-checkbox-column { display: table-cell; }

/* Inline Edit Tanggal */
.inline-tgl-form {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    justify-content: center;
}
.inline-tgl-form input[type='text'] {
    width: 110px;
    padding: 5px 8px;
    background: rgba(0,0,0,0.3);
    border: 1px solid rgba(74,144,226,0.3);
    border-radius: 8px;
    color: #fff;
    font-size: 0.8rem;
    text-align: center;
}
.inline-tgl-form input[type='text']::placeholder {
    color: #555;
    font-size: 0.75rem;
}
.inline-tgl-form input[type='text']:focus {
    outline: none;
    border-color: #4A90E2;
    box-shadow: 0 0 6px rgba(74,144,226,0.3);
}
.btn-tgl-save {
    background: rgba(46,204,113,0.15);
    border: 1px solid rgba(46,204,113,0.4);
    color: #2ecc71;
    padding: 4px 8px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.75rem;
    transition: all 0.2s;
}
.btn-tgl-save:hover {
    background: rgba(46,204,113,0.3);
}
.tgl-display {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 0.85rem;
}
.tgl-display.has-date {
    background: rgba(74,144,226,0.1);
    border: 1px solid rgba(74,144,226,0.2);
    color: #4A90E2;
}
.tgl-display.no-date {
    background: rgba(243,156,18,0.1);
    border: 1px solid rgba(243,156,18,0.3);
    color: #f39c12;
}
.btn-tgl-reset {
    background: none;
    border: none;
    color: #e74c3c;
    cursor: pointer;
    font-size: 0.75rem;
    opacity: 0.6;
    transition: 0.2s;
    text-decoration: none;
    padding: 2px 4px;
}
.btn-tgl-reset:hover {
    opacity: 1;
}
";
?>
<style><?php echo $css; ?></style>

<div class="page-header">
    <h1><i class="fas fa-folder-open"></i> Arsip Surat</h1>
    <p>Manajemen arsip surat Keluar, surat Dalam, dan surat Masuk.</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom: 20px;">
    <div class="card-header"><i class="fas fa-image"></i> Template Kop Surat Output</div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:15px;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="upload_kop">
            
            <?php $kop_exists = file_exists(rtrim(UPLOAD_PATH, '/\\') . '/kop_surat.png'); ?>
            
            <div class="upload-drop-zone" id="dropZone">
                <input type="file" name="kop_surat" id="kopInput" accept="image/png, image/jpeg" required>
                <?php if ($kop_exists): ?>
                    <img src="<?php echo uploadUrl('kop_surat.png') . '?v=' . time(); ?>" id="kopPreview" class="upload-preview" alt="Preview Kop Surat">
                    <i class="fas fa-cloud-upload-alt upload-icon" id="uploadIcon" style="display:none;"></i>
                    <p id="uploadText" style="color:#ccc; margin-bottom:5px;">Seret & Lepas file di sini atau klik untuk mengganti.</p>
                <?php else: ?>
                    <img src="" id="kopPreview" class="upload-preview" alt="Preview Kop Surat" style="display:none;">
                    <i class="fas fa-cloud-upload-alt upload-icon" id="uploadIcon"></i>
                    <p id="uploadText" style="color:#ccc; margin-bottom:5px;">Seret & Lepas file di sini atau klik untuk memilih gambar.</p>
                <?php endif; ?>
                <small style="color:#666;">Format: JPG/PNG. Rekomendasi lebar: 2480px.</small>
            </div>
            
            <div style="display:flex; justify-content:flex-end; align-items:center; gap: 15px;">
                <?php if ($kop_exists): ?>
                    <span class="badge" style="background:#4caf50; color:white;"><i class="fas fa-check-circle"></i> Tersedia (Aktif)</span>
                <?php endif; ?>
                <button type="submit" class="btn-primary" style="padding: 10px 20px;"><i class="fas fa-upload"></i> Simpan Kop Surat</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        
        <div class="surat-actions" style="display:flex; flex-wrap:wrap; gap:10px; border-bottom: 1px solid #333; padding-bottom: 15px; margin-bottom: 15px;">
            <div class="tab-container" style="flex-wrap:nowrap; margin-bottom:0; display:flex; gap:5px; flex: 1; min-width: 250px;">
                <a href="arsip-surat.php?jenis=L" class="tab-btn <?php echo $jenis === 'L' ? 'active' : ''; ?>" style="flex:1; text-align:center; padding: 10px 5px; font-size: 0.85rem;">Keluar (L)</a>
                <a href="arsip-surat.php?jenis=D" class="tab-btn <?php echo $jenis === 'D' ? 'active' : ''; ?>" style="flex:1; text-align:center; padding: 10px 5px; font-size: 0.85rem;">Dalam (D)</a>
                <a href="arsip-surat.php?jenis=M" class="tab-btn <?php echo $jenis === 'M' ? 'active' : ''; ?>" style="flex:1; text-align:center; padding: 10px 5px; font-size: 0.85rem;">Masuk (M)</a>
            </div>
            
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; flex:1; min-width: 250px;">
                <a href="arsip-surat.php?jenis=<?php echo $jenis; ?>&export=excel" class="btn-buat" style="background:#2E7D32; flex:1; justify-content:center; white-space: nowrap;"><i class="fas fa-file-excel"></i> Excel</a>
                
                <?php if ($jenis === 'M'): ?>
                    <a href="arsip-manual.php?type=M" class="btn-buat" style="background:#f39c12; flex:1; justify-content:center; white-space: nowrap;"><i class="fas fa-file-import"></i> Catat Manual</a>
                <?php else: ?>
                    <a href="buat-surat.php" class="btn-buat" style="flex:1.2; justify-content:center; white-space: nowrap;"><i class="fas fa-plus"></i> Buat Otomatis</a>
                    <a href="arsip-manual.php?type=<?php echo $jenis; ?>" class="btn-buat" style="background:#f39c12; flex:1; justify-content:center; white-space: nowrap;"><i class="fas fa-file-import"></i> Catat Manual</a>
                <?php endif; ?>
            </div>
        </div>

        <div style="overflow-x:auto;" id="table-wrapper">
            <form id="bulkForm" action="bulk-download.php" method="POST" target="_blank">
                <?php echo csrfField(); ?>
                <table class="admin-table responsive-card-table">
                    <thead>
                        <tr class="header-row">
                            <th width="5%" style="text-align:center;">No</th>
                            <th width="15%" style="text-align:center;">Tanggal <?php echo $jenis==='M' ? 'Diterima' : 'Dikirim'; ?></th>
                            <th width="20%">Nomor Surat</th>
                            <th width="20%">Perihal</th>
                            <th width="25%"><?php echo $jenis==='M' ? 'Asal Instansi' : 'Dituju Kepada'; ?></th>
                            <th style="text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                <tbody>
                    <?php if (empty($grouped_surat)): ?>
                        <tr>
                            <td colspan="6" style="padding: 30px; text-align:center;">Belum ada arsip surat.</td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($grouped_surat as $nomor_surat => $items): 
                            $parent = $items[0];
                            $has_children = count($items) > 1;
                            $group_id = "group_" . md5($nomor_surat);
                        ?>
                        <tr class="<?php echo $has_children ? 'group-parent' : ''; ?>">
                            <td data-label="No" style="text-align:center;">
                                <strong style="color:#fff;"><?php echo $no++; ?></strong>
                            </td>
                            <td data-label="Tanggal" style="text-align:center;">
                                <?php 
                                    $tgl_val = (string)$parent['tanggal_dikirim'];
                                    $is_empty = (empty($tgl_val) || $tgl_val === 'Belum Di kirim' || $tgl_val === '0000-00-00' || $tgl_val === NULL);
                                    
                                    $display_val = '';
                                    if (!$is_empty) {
                                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_val)) {
                                            $p = explode('-', $tgl_val);
                                            $display_val = "$p[2]/$p[1]/$p[0]";
                                        } else {
                                            $display_val = $tgl_val;
                                        }
                                    }
                                ?>
                                <?php if ($is_empty): ?>
                                    <a href="javascript:void(0)" class="tgl-display no-date" 
                                       onclick="var t=prompt('Masukkan Tanggal Dikirim (DD/MM/YYYY):',''); if(t) window.location='arsip-surat.php?jenis=<?php echo $jenis; ?>&set_tgl=<?php echo $parent['id']; ?>&tanggal='+encodeURIComponent(t)+'&csrf_token=<?php echo csrfToken(); ?>';">
                                        <i class="fas fa-calendar-plus"></i>
                                        <span>Set Tanggal</span>
                                    </a>
                                <?php else: ?>
                                    <div class="tgl-display has-date">
                                        <i class="fas fa-calendar-check"></i>
                                        <span><?php echo htmlspecialchars($display_val); ?></span>
                                        <a href="javascript:void(0)" class="btn-tgl-reset" title="Edit Tanggal"
                                           onclick="var t=prompt('Edit Tanggal Dikirim (DD/MM/YYYY):','<?php echo $display_val; ?>'); if(t) window.location='arsip-surat.php?jenis=<?php echo $jenis; ?>&set_tgl=<?php echo $parent['id']; ?>&tanggal='+encodeURIComponent(t)+'&csrf_token=<?php echo csrfToken(); ?>';">
                                            <i class="fas fa-edit" style="color:#4A90E2;"></i>
                                        </a>
                                        <a href="arsip-surat.php?jenis=<?php echo $jenis; ?>&reset_tgl=<?php echo $parent['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                           class="btn-tgl-reset" title="Hapus Tanggal"
                                           onclick="return confirm('Reset tanggal dikirim?')">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Nomor Surat">
                                <div class="surat-header-mobile">
                                    <strong style="color:#fff; display: block; margin-bottom: 5px;"><?php echo htmlspecialchars($parent['nomor_surat']); ?></strong>
                                    
                                    <div class="surat-indicators">
                                        <?php if (!empty($parent['file_surat'])): ?>
                                            <span class="indicator-badge badge-manual"><i class="fas fa-hand-paper"></i> Manual</span>
                                        <?php else: ?>
                                            <span class="indicator-badge badge-sistem"><i class="fas fa-robot"></i> Sistem</span>
                                        <?php endif; ?>

                                        <?php if ($has_children): ?>
                                            <span class="indicator-badge badge-recipient"><i class="fas fa-users"></i> <?php echo count($items); ?> Recipient</span>
                                        <?php endif; ?>

                                        <?php 
                                            $view_link = !empty($parent['file_surat']) ? uploadUrl($parent['file_surat']) : "cetak-surat.php?id={$parent['id']}";
                                            $view_label = !empty($parent['file_surat']) ? "Lihat File" : "Lihat/Cetak";
                                        ?>
                                        <a href="<?php echo $view_link; ?>" target="_blank" class="indicator-badge badge-link">
                                            <i class="fas fa-file-pdf"></i> <?php echo $view_label; ?>
                                        </a>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Perihal"><span><?php echo htmlspecialchars($parent['perihal']); ?></span></td>
                            <td data-label="<?php echo $jenis==='M' ? 'Asal Instansi' : 'Dituju Kepada'; ?>">
                                <div>
                                    <div style="margin-bottom: 8px;">
                                        <?php echo nl2br(htmlspecialchars($parent['tujuan'])); ?>
                                    </div>
                                    <?php if ($has_children): ?>
                                        <div class="surat-indicators">
                                            <span class="indicator-badge badge-multi"><i class="fas fa-layer-group"></i> Multi-recipient</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td data-label="Aksi" class="td-aksi" style="text-align:center; vertical-align:middle;">
                                <div class="btn-group-mobile">
                                    <?php 
                                    $is_manual = !empty($parent['file_surat']);
                                    $edit_link = $is_manual ? "arsip-manual.php?edit={$parent['id']}" : "buat-surat.php?edit={$parent['id']}"; 
                                    ?>
                                    <a href="<?php echo $edit_link; ?>" class="btn-edit" title="Edit Surat"><i class="fas fa-edit"></i> Edit</a>
                                    <?php if ($jenis !== 'M'): ?>
                                        <?php 
                                        $konten = json_decode((string)$parent['konten_surat'], true) ?? [];
                                        $tujuan_first = trim(explode("\n", $parent['tujuan'])[0]);
                                        $copy_data = [
                                            'tujuan' => $parent['tujuan'],
                                            'tujuan_short' => $tujuan_first,
                                            'perihal' => $parent['perihal'],
                                            'kegiatan' => !empty($konten['nama_kegiatan']) ? $konten['nama_kegiatan'] : '',
                                            'hari' => $konten['pelaksanaan_hari_tanggal'] ?? '',
                                            'waktu' => $konten['pelaksanaan_waktu'] ?? '',
                                            'tempat' => $konten['pelaksanaan_tempat'] ?? '',
                                            'konteks' => $konten['konteks'] ?? ''
                                        ];
                                        ?>
                                        <button type="button" class="btn-copy" 
                                                onclick="copyRedaksi(<?php echo htmlspecialchars(json_encode($copy_data), ENT_QUOTES, 'UTF-8'); ?>, this)" title="Salin Redaksi untuk WA">
                                            <i class="fas fa-share-alt"></i> Salin
                                        </button>
                                        <a href="buat-surat.php?clone=<?php echo $parent['id']; ?>" class="btn-buat" style="background: #27ae60;" title="Buat salinan surat ini untuk tujuan lain"><i class="fas fa-copy"></i> Duplikat</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($jenis === 'M' || $parent['id'] == $latest_keluar_id): ?>
                                        <button type="button" 
                                           onclick="handleHapusArsip(<?php echo $parent['id']; ?>, '<?php echo addslashes($parent['nomor_surat']); ?>')"
                                           class="btn-delete" title="Hapus"><i class="fas fa-trash-alt"></i> Hapus</button>
                                    <?php else: ?>
                                        <span class="btn-locked" 
                                              title="Hanya surat paling akhir yang bisa dihapus. Hapus satu per satu dari yang terbaru."
                                              onclick="alert('❌ Tidak dapat dihapus!\n\nHanya surat dengan nomor urut PALING AKHIR yang boleh dihapus.\nHapus satu per satu mulai dari surat terbaru untuk menjaga urutan nomor surat.')">
                                            <i class="fas fa-lock"></i> Terkunci
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($has_children): ?>
                                    <button type="button" class="btn-accordion-toggle toggle-group" 
                                         data-group="<?php echo $group_id; ?>" 
                                         onclick="toggleGroup('<?php echo $group_id; ?>', this)">
                                        <span><i class="fas fa-users"></i> Lihat Anggota Grup (<?php echo count($items); ?>)</span>
                                        <i class="fas fa-chevron-down chevron-icon"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>

                        <?php if ($has_children): ?>
                            <?php foreach (array_slice($items, 1) as $child): ?>
                            <tr class="child-row <?php echo $group_id; ?>">
                                <td data-label="Grup" style="text-align:center; color: #555;">└</td>
                                <td data-label="Tanggal" style="text-align:center;">
                                    <?php 
                                        $ctgl_val = (string)$child['tanggal_dikirim'];
                                        $c_is_empty = (empty($ctgl_val) || $ctgl_val === 'Belum Di kirim' || $ctgl_val === '0000-00-00' || $ctgl_val === NULL);
                                        
                                        $c_display_val = '';
                                        if (!$c_is_empty) {
                                            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ctgl_val)) {
                                                $cp = explode('-', $ctgl_val);
                                                $c_display_val = "$cp[2]/$cp[1]/$cp[0]";
                                            } else {
                                                $c_display_val = $ctgl_val;
                                            }
                                        }
                                    ?>
                                    <?php if ($c_is_empty): ?>
                                        <a href="javascript:void(0)" class="tgl-display no-date" 
                                           onclick="var t=prompt('Masukkan Tanggal Dikirim (DD/MM/YYYY):',''); if(t) window.location='arsip-surat.php?jenis=<?php echo $jenis; ?>&set_tgl=<?php echo $child['id']; ?>&tanggal='+encodeURIComponent(t)+'&csrf_token=<?php echo csrfToken(); ?>';">
                                            <i class="fas fa-calendar-plus"></i>
                                            <span>Set Tanggal</span>
                                        </a>
                                    <?php else: ?>
                                        <div class="tgl-display has-date">
                                            <i class="fas fa-calendar-check"></i>
                                            <span><?php echo htmlspecialchars($c_display_val); ?></span>
                                            <a href="javascript:void(0)" class="btn-tgl-reset" title="Edit Tanggal"
                                               onclick="var t=prompt('Edit Tanggal Dikirim (DD/MM/YYYY):','<?php echo $c_display_val; ?>'); if(t) window.location='arsip-surat.php?jenis=<?php echo $jenis; ?>&set_tgl=<?php echo $child['id']; ?>&tanggal='+encodeURIComponent(t)+'&csrf_token=<?php echo csrfToken(); ?>';">
                                                <i class="fas fa-edit" style="color:#4A90E2;"></i>
                                            </a>
                                            <a href="arsip-surat.php?jenis=<?php echo $jenis; ?>&reset_tgl=<?php echo $child['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                               class="btn-tgl-reset" title="Hapus Tanggal"
                                               onclick="return confirm('Reset tanggal dikirim?')">
                                                <i class="fas fa-times"></i>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Nomor Surat" class="child-nomor-surat">
                                    <div class="surat-header-mobile">
                                        <strong style="color:#fff; display: block; margin-bottom: 5px;"><?php echo htmlspecialchars($child['nomor_surat']); ?></strong>
                                        
                                        <div class="surat-indicators">
                                            <?php $child_is_manual = !empty($child['file_surat']); ?>
                                            <?php if ($child_is_manual): ?>
                                                <span class="indicator-badge badge-manual"><i class="fas fa-hand-paper"></i> Manual</span>
                                            <?php else: ?>
                                                <span class="indicator-badge badge-sistem"><i class="fas fa-robot"></i> Sistem</span>
                                            <?php endif; ?>

                                            <?php 
                                                $c_view_link = !empty($child['file_surat']) ? uploadUrl($child['file_surat']) : "cetak-surat.php?id={$child['id']}";
                                                $c_view_label = !empty($child['file_surat']) ? "Lihat File" : "Lihat/Cetak";
                                            ?>
                                            <a href="<?php echo $c_view_link; ?>" target="_blank" class="indicator-badge badge-link">
                                                <i class="fas fa-file-pdf"></i> <?php echo $c_view_label; ?>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Perihal" style="color: #888; font-size: 0.85rem;"><span><?php echo htmlspecialchars((string)$child['perihal']); ?></span></td>
                                <td data-label="Dituju Kepada" style="border-left: 2px solid #4A90E2; padding-left: 15px;">
                                    <div><?php echo nl2br(htmlspecialchars((string)$child['tujuan'])); ?></div>
                                </td>
                                <td data-label="Aksi" class="td-aksi" style="text-align:center; vertical-align:middle;">
                                    <div class="btn-group-mobile">
                                        <?php 
                                        $child_is_manual = !empty($child['file_surat']);
                                        $child_edit = $child_is_manual ? "arsip-manual.php?edit={$child['id']}" : "buat-surat.php?edit={$child['id']}"; 
                                        ?>
                                        <a href="<?php echo $child_edit; ?>" class="btn-edit" title="Edit Surat"><i class="fas fa-edit"></i> Edit</a>
                                        
                                        <?php if ($jenis !== 'M'): ?>
                                            <?php 
                                            $ckonten = json_decode((string)$child['konten_surat'], true) ?? [];
                                            $ctujuan_first = trim(explode("\n", $child['tujuan'])[0]);
                                            $ccopy_data = [
                                                'tujuan' => $child['tujuan'],
                                                'tujuan_short' => $ctujuan_first,
                                                'perihal' => $child['perihal'],
                                                'kegiatan' => !empty($ckonten['nama_kegiatan']) ? $ckonten['nama_kegiatan'] : '',
                                                'hari' => $ckonten['pelaksanaan_hari_tanggal'] ?? '',
                                                'waktu' => $ckonten['pelaksanaan_waktu'] ?? '',
                                                'tempat' => $ckonten['pelaksanaan_tempat'] ?? '',
                                                'konteks' => $ckonten['konteks'] ?? ''
                                            ];
                                            ?>
                                            <button type="button" class="btn-copy" 
                                                    onclick="copyRedaksi(<?php echo htmlspecialchars(json_encode($ccopy_data), ENT_QUOTES, 'UTF-8'); ?>, this)" title="Salin Redaksi">
                                                <i class="fas fa-share-alt"></i> Salin
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        // Child dalam grup selalu bisa dihapus karena sibling lain masih memegang nomor yang sama
                                        ?>
                                        <button type="button" 
                                           onclick="handleHapusArsip(<?php echo $child['id']; ?>, '<?php echo addslashes($child['nomor_surat']); ?>')"
                                           class="btn-delete" title="Hapus"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                </table>
            </form>
        </div>
    </div>
</div>

<!-- Modal Konfirmasi Hapus Custom -->
<div id="deleteModal" class="custom-modal">
    <div class="modal-content">
        <div class="modal-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h4>Konfirmasi Hapus</h4>
        </div>
        <div class="modal-body">
            <p>Apakah Anda yakin ingin menghapus arsip surat nomor:</p>
            <p style="margin-top:10px; color:#4A90E2; font-weight:bold;" id="nomorSuratHapus"></p>
            <p style="margin-top:10px; font-size:0.85rem; color:#888;">Tindakan ini permanen dan akan menghapus file fisik surat di server.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeDeleteModal()">Batal</button>
            <a href="#" id="finalDeleteBtn" class="btn-modal-confirm" style="text-decoration:none;">Ya, Hapus Permanen</a>
        </div>
    </div>
</div>

<style>
.custom-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.7);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
}
.modal-content {
    background: #222;
    border: 1px solid #444;
    border-radius: 12px;
    width: 90%;
    max-width: 400px;
    padding: 25px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.4);
    animation: modalSlide 0.3s ease-out;
}
@keyframes modalSlide {
    from { transform: translateY(-30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
    color: #ffc107;
}
.modal-header h4 { margin: 0; font-size: 1.2rem; }
.modal-body p { color: #ccc; line-height: 1.5; margin: 0; }
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 25px;
}
.btn-modal-cancel {
    background: #444;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}
.btn-modal-confirm {
    background: #f44336;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}
.btn-modal-confirm:hover { background: #d32f2f; }
</style>

<script>
let idHapus = 0;
let csrfToken = '<?php echo csrfToken(); ?>';
let jenisSurat = '<?php echo $jenis; ?>';

function handleHapusArsip(id, nomor) {
    idHapus = id;
    document.getElementById('nomorSuratHapus').innerText = nomor;
    document.getElementById('finalDeleteBtn').href = `arsip-surat.php?jenis=${jenisSurat}&hapus=${id}&csrf_token=${csrfToken}`;
    document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('deleteModal').style.display = 'none';
}

function toggleGroup(groupId, btn) {
    const rows = document.querySelectorAll('.' + groupId);
    btn.classList.toggle('open');
    const parentRow = btn.closest('.group-parent');
    if (parentRow) parentRow.classList.toggle('open');
    
    rows.forEach(row => {
        row.classList.toggle('show');
    });
}

function copyRedaksi(data, btn) {
    let perihal = data.perihal;
    let kegiatan = data.kegiatan || "Kegiatan BEM";
    let tujuan = data.tujuan;
    let tujuanShort = data.tujuan_short;
    
    // Tentukan kata kerja aksi (mengundang/permohonan/dll)
    let actionWord = "menyampaikan " + perihal.toLowerCase() + " kepada " + tujuanShort;
    if(perihal.toLowerCase().includes("undangan")) {
        actionWord = "mengundang " + tujuanShort;
    }

    // Bersihkan HTML tag dari data.konteks
    let cleanKonteks = "";
    if (data.konteks) {
        let tempDiv = document.createElement("div");
        tempDiv.innerHTML = data.konteks;
        cleanKonteks = (tempDiv.textContent || tempDiv.innerText || "").trim();
    }

    let text = `Assalamu'alaikum Wr. Wb.
Yth. 
${tujuan}

Sehubungan dengan diadakanya ${kegiatan}. Dengan ini kami ${actionWord} pada kegiatan tersebut, yang akan dilaksanakan pada :

🗓️ | ${data.hari || '-'}
🕘 | ${data.waktu || '-'}
🏢 | ${data.tempat || '-'}

${cleanKonteks ? cleanKonteks + '\n\n' : ''}Demikian informasi ini kami sampaikan, atas perhatian dan kerjasamanya kami ucapkan terima kasih.

Wassalamu’alaikum Wr. Wb.`;

    navigator.clipboard.writeText(text).then(() => {
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Tersalin!';
        btn.style.background = '#2e7d32';
        setTimeout(() => {
            btn.innerHTML = originalHtml;
            btn.style.background = '';
        }, 2000);
    }).catch(err => {
        alert('Gagal menyalin teks: ' + err);
    });
}

// Upload Preview Logic
const kopInput = document.getElementById('kopInput');
const kopPreview = document.getElementById('kopPreview');
const uploadIcon = document.getElementById('uploadIcon');
const uploadText = document.getElementById('uploadText');
const dropZone = document.getElementById('dropZone');

if (kopInput) {
    kopInput.addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                kopPreview.src = e.target.result;
                kopPreview.style.display = 'block';
                uploadIcon.style.display = 'none';
                uploadText.textContent = 'Klik atau seret gambar lain untuk mengganti.';
            }
            reader.readAsDataURL(file);
        }
    });

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, preventDefaults, false);
    });

    function preventDefaults (e) {
        e.preventDefault();
        e.stopPropagation();
    }

    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
    });

    dropZone.addEventListener('drop', handleDrop, false);

    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length) {
            kopInput.files = files;
            // trigger change event manually
            const event = new Event('change');
            kopInput.dispatchEvent(event);
        }
    }
}


</script>

<?php require_once __DIR__ . '/footer.php'; ?>
