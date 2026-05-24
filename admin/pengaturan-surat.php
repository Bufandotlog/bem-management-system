<?php
// admin/pengaturan-surat.php
$page_css = 'arsip-surat';
require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();
$error = '';
$success = '';

// Proses Hapus Template
if (isset($_GET['hapus']) && is_numeric($_GET['hapus'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_hapus = (int)$_GET['hapus'];
        dbQuery("DELETE FROM surat_templates WHERE id = ? AND periode_id = ?", [$id_hapus, $periode_id], "ii");
        $success = "Template berhasil dihapus.";
    } else {
        $error = "Token keamanan tidak valid saat menghapus.";
    }
}

// Proses Update Pengaturan Tanda Tangan Tetap
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ttd') {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $w_name = sanitizeText($_POST['ttd_warek_name'] ?? '');
        $w_jab = sanitizeText($_POST['ttd_warek_jabatan'] ?? '');
        $p_name = sanitizeText($_POST['ttd_presma_name'] ?? '');
        $p_jab = sanitizeText($_POST['ttd_presma_jabatan'] ?? '');

        dbUpsertPengaturan('ttd_warek_name', $w_name);
        dbUpsertPengaturan('ttd_warek_jabatan', $w_jab);
        dbUpsertPengaturan('ttd_presma_name', $p_name);
        dbUpsertPengaturan('ttd_presma_jabatan', $p_jab);

        // Handle File Uploads
        if (!empty($_FILES['ttd_warek_image']['name'])) {
            $uploaded = uploadFile($_FILES['ttd_warek_image'], 'umum');
            if ($uploaded) dbUpsertPengaturan('ttd_warek_image', $uploaded);
            else $error = "Gagal upload ttd_warek_image.";
        }
        if (!empty($_FILES['ttd_presma_image']['name'])) {
            $uploaded = uploadFile($_FILES['ttd_presma_image'], 'umum');
            if ($uploaded) dbUpsertPengaturan('ttd_presma_image', $uploaded);
            else $error = "Gagal upload ttd_presma_image.";
        }
        if (!empty($_FILES['cap_panitia_image']['name'])) {
            $uploaded = uploadFile($_FILES['cap_panitia_image'], 'umum');
            if ($uploaded) dbUpsertPengaturan('cap_panitia_image', $uploaded);
        }
        if (!empty($_FILES['cap_warek_image']['name'])) {
            $uploaded = uploadFile($_FILES['cap_warek_image'], 'umum');
            if ($uploaded) dbUpsertPengaturan('cap_warek_image', $uploaded);
        }
        if (!empty($_FILES['cap_presma_image']['name'])) {
            $uploaded = uploadFile($_FILES['cap_presma_image'], 'umum');
            if ($uploaded) dbUpsertPengaturan('cap_presma_image', $uploaded);
        }
        if(empty($error)) $success = "Pengaturan Tanda Tangan & Stempel berhasil diperbarui.";
    }
}

// Proses Tambah Panitia Tetap
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tambah_panitia') {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $nama    = strtoupper(sanitizeText($_POST['nama_panitia'] ?? '', 100));
        $jabatan = $_POST['jabatan_panitia'] === 'sekretaris' ? 'sekretaris' : 'ketua';
        
        if (empty($nama) || empty($_FILES['file_ttd']['name'])) {
            $error = "Nama dan File Tanda Tangan wajib diisi.";
        } else {
            $uploaded = uploadFile($_FILES['file_ttd'], 'ttd');
            if ($uploaded) {
                dbQuery("INSERT INTO panitia_tetap (periode_id, nama, jabatan, file_ttd) VALUES (?, ?, ?, ?)", [$periode_id, $nama, $jabatan, $uploaded], "isss");
                $success = "Tanda Tangan Panitia berhasil disimpan.";
            } else {
                $error = "Gagal mengunggah tanda tangan.";
            }
        }
    }
}

// Proses Hapus Panitia Tetap
if (isset($_GET['hapus_panitia']) && is_numeric($_GET['hapus_panitia'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_hapus = (int)$_GET['hapus_panitia'];
        $data = dbFetchOne("SELECT file_ttd FROM panitia_tetap WHERE id = ? AND periode_id = ?", [$id_hapus, $periode_id], "ii");
        if ($data) {
            $old_path = UPLOAD_PATH . '/' . $data['file_ttd'];
            if (file_exists($old_path)) unlink($old_path);
            dbQuery("DELETE FROM panitia_tetap WHERE id = ? AND periode_id = ?", [$id_hapus, $periode_id], "ii");
            $success = "Data panitia berhasil dihapus.";
        }
    }
}

// Proses Tambah / Update Template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['tambah', 'update'])) {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $action = $_POST['action'];
        $jenis    = sanitizeText($_POST['jenis'], 20);
        $label    = sanitizeText($_POST['label'], 100);
        $isi_teks = strip_tags(trim($_POST['isi_teks'] ?? '')); 
        $perihal_default = strtoupper(str_replace(' ', '', sanitizeText($_POST['perihal_default'] ?? '', 50)));
        
        if (empty($label)) {
            $error = "Nama/Label tidak boleh kosong.";
        } else if (in_array($jenis, ['perihal', 'tujuan']) && empty($isi_teks)) {
            $error = "Isi Teks tidak boleh kosong untuk jenis Perihal/Tujuan.";
        } else {
            if ($action === 'tambah') {
                dbQuery(
                    "INSERT INTO surat_templates (periode_id, jenis, label, isi_teks, perihal_default) VALUES (?, ?, ?, ?, ?)",
                    [$periode_id, $jenis, $label, $isi_teks, $perihal_default], "issss"
                );
                $success = "Template " . ucfirst($jenis) . " berhasil disimpan!";
            } else {
                $id_update = (int)$_POST['template_id'];
                dbQuery(
                    "UPDATE surat_templates SET jenis = ?, label = ?, isi_teks = ?, perihal_default = ? WHERE id = ? AND periode_id = ?",
                    [$jenis, $label, $isi_teks, $perihal_default, $id_update, $periode_id], "ssssii"
                );
                $success = "Template " . ucfirst($jenis) . " berhasil diperbarui!";
            }
        }
    }
}

// Mode Edit Setup
$edit_id = (isset($_GET['edit']) && is_numeric($_GET['edit'])) ? (int)$_GET['edit'] : 0;
$edit_data = null;
if ($edit_id > 0) {
    $edit_data = dbFetchOne("SELECT * FROM surat_templates WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
}

// Ambil semua template
$templates = dbFetchAll("SELECT * FROM surat_templates WHERE periode_id = ? ORDER BY jenis DESC, label ASC", [$periode_id], "i");
$list_perihal  = array_filter($templates, fn($t) => $t['jenis'] === 'perihal');
$list_tujuan   = array_filter($templates, fn($t) => $t['jenis'] === 'tujuan');
$list_kegiatan = array_filter($templates, fn($t) => $t['jenis'] === 'kegiatan');
$list_tempat   = array_filter($templates, fn($t) => $t['jenis'] === 'tempat');

// Ambil Pengaturan Umum (Tanda Tangan)
$db_pengaturan = dbFetchAll("SELECT kunci, nilai FROM pengaturan");
$pengaturan = [];
foreach($db_pengaturan as $p) {
    $pengaturan[$p['kunci']] = $p['nilai'];
}
$def_warek_name = $pengaturan['ttd_warek_name'] ?? 'II MUHAMAD MISBAH, S.Pd.I., SE., MM.';
$def_warek_jab  = $pengaturan['ttd_warek_jabatan'] ?? 'WAREK III Bid. Kemahasiswaan';
$def_warek_img  = $pengaturan['ttd_warek_image'] ?? '';
$ketua = getKetua($periode_id);
$fallback_presma = $ketua ? $ketua['nama_lengkap'] : '';
$def_presma_name = $pengaturan['ttd_presma_name'] ?? $fallback_presma;
$def_presma_jab  = $pengaturan['ttd_presma_jabatan'] ?? 'Ketua BEM INSTBUNAS Majalengka';
$def_presma_img  = $pengaturan['ttd_presma_image'] ?? '';
$def_cap_panitia = $pengaturan['cap_panitia_image'] ?? '';
$def_cap_warek   = $pengaturan['cap_warek_image'] ?? '';
$def_cap_presma  = $pengaturan['cap_presma_image'] ?? '';
?>

<style>
    /* ===== BASE STYLE ===== */
    .admin-table { border-collapse: separate; border-spacing: 0; width: 100%; }
    .admin-table tr td { border-bottom: 1px solid #2a3545; padding: 12px 10px; }
    .admin-table tr:last-child td { border-bottom: none; }
    .admin-table thead tr th { border-bottom: 2px solid #4A90E2; padding-bottom: 10px; }

    /* ===== ACCORDION STYLES ===== */
    .accordion-item {
        background: #0f1217;
        border: 1px solid #2a3545;
        border-radius: 12px;
        margin-bottom: 16px;
        overflow: hidden;
    }
    .accordion-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        cursor: pointer;
        background: rgba(74, 144, 226, 0.05);
        transition: background 0.2s;
    }
    .accordion-header:hover { background: rgba(74, 144, 226, 0.1); }
    .accordion-header h3 {
        margin: 0;
        font-size: 1.1rem;
        color: #8BB9F0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .accordion-header .badge-count {
        background: #4A90E2;
        color: white;
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        margin-left: 8px;
    }
    .accordion-header .chevron {
        transition: transform 0.3s ease;
        color: #4A90E2;
        font-size: 1.2rem;
    }
    .accordion-header.open .chevron { transform: rotate(180deg); }
    .accordion-body {
        display: none;
        padding: 20px;
        border-top: 1px solid #2a3545;
        background: #0a0c10;
    }
    .accordion-body.open { display: block; }

    /* ===== CARD DENGAN BORDER BIRU ===== */
    .blue-border-card {
        border: 1px solid #4A90E2;
        border-radius: 16px;
        background: #0f1217;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .blue-border-card:hover { box-shadow: 0 8px 20px rgba(74, 144, 226, 0.2); }
    .blue-border-card .card-header {
        background: rgba(74, 144, 226, 0.1);
        border-bottom: 1px solid #4A90E2;
        padding: 16px;
        font-weight: bold;
        color: #4A90E2;
    }
    .blue-border-card .card-body { padding: 20px; }

    /* ===== UPLOAD CARD STYLE (seragam) ===== */
    .upload-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 24px;
        margin-bottom: 20px;
    }
    .upload-card {
        background: #12161b;
        border: 1px solid #2a3545;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.2s;
    }
    .upload-card:hover {
        border-color: #4A90E2;
        box-shadow: 0 4px 12px rgba(74,144,226,0.2);
    }
    .upload-card-header {
        background: rgba(74, 144, 226, 0.1);
        padding: 12px 16px;
        font-weight: bold;
        color: #4A90E2;
        border-bottom: 1px solid #2a3545;
    }
    .upload-card-header i { margin-right: 8px; }
    .upload-card-body { padding: 20px; }
    .upload-area { margin-top: 15px; }
    .drop-zone {
        border: 2px dashed #4A90E2;
        border-radius: 12px;
        padding: 20px 16px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
        background: rgba(74,144,226,0.02);
        margin-bottom: 12px;
    }
    .drop-zone.dragover {
        background: rgba(74,144,226,0.1);
        border-color: #8BB9F0;
    }
    .drop-zone .upload-icon {
        font-size: 2.5rem;
        color: #4A90E2;
        margin-bottom: 8px;
    }
    .drop-zone p {
        color: #aaa;
        font-size: 0.85rem;
        margin: 8px 0;
    }
    .btn-select-file {
        background: #2a3545;
        border: none;
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 0.8rem;
        margin-top: 8px;
        transition: background 0.2s;
    }
    .btn-select-file:hover { background: #4A90E2; }
    .preview-container {
        display: flex;
        align-items: center;
        gap: 12px;
        background: rgba(0,0,0,0.3);
        padding: 12px;
        border-radius: 12px;
        margin-top: 10px;
        flex-wrap: wrap;
    }
    .preview-img {
        max-width: 100px;
        max-height: 80px;
        object-fit: contain;
        background: white;
        padding: 4px;
        border-radius: 8px;
        border: 1px solid #ddd;
    }
    .btn-remove-img {
        background: #dc3545;
        border: none;
        color: white;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .btn-remove-img:hover {
        background: #c82333;
        transform: scale(1.05);
    }
    small { display: block; margin-top: 8px; font-size: 0.7rem; color: #888; }

    /* ===== RESPONSIVE MOBILE ===== */
    @media (max-width: 768px) {
        .card { margin-bottom: 20px !important; }
        .card-body { padding: 1rem !important; }
        .page-header h1 { font-size: 1.6rem; }
        input, select, textarea { font-size: 16px !important; padding: 10px 12px !important; }
        .btn-primary, .btn-buat, .btn-edit, .btn-delete, .btn-copy {
            width: 100%;
            justify-content: center;
            margin-bottom: 6px;
        }
        .btn-group-mobile { display: flex; flex-direction: column; gap: 6px; }
        .template-layout { flex-direction: column !important; align-items: center !important; }
        .template-layout > div { width: 100%; max-width: 500px; margin-bottom: 20px; }
        .upload-grid { grid-template-columns: 1fr; }
        .preview-img { max-width: 80px; max-height: 60px; }
        .accordion-header { padding: 12px 16px; }
        .accordion-body { padding: 12px; }
    }

    /* ===== TABEL MENJADI CARD DI MOBILE ===== */
    @media (max-width: 768px) {
        .admin-table, .admin-table thead, .admin-table tbody, .admin-table tr, .admin-table th, .admin-table td { display: block; }
        .admin-table thead { display: none; }
        .admin-table tr {
            margin-bottom: 20px;
            border: 1px solid #2a3545;
            border-radius: 12px;
            background: #0f1217;
            padding: 12px 0;
        }
        .admin-table td {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            flex-wrap: wrap;
        }
        .admin-table td:last-child { border-bottom: none; }
        .admin-table td::before {
            content: attr(data-label);
            font-weight: bold;
            color: #8BB9F0;
            min-width: 110px;
            font-size: 0.8rem;
            text-transform: uppercase;
        }
        .admin-table td[data-label="Nama & Jabatan"]::before { content: "Nama & Jabatan"; }
        .admin-table td[data-label="Pratinjau TTD"]::before { content: "Pratinjau TTD"; }
        .admin-table td[data-label="Aksi"]::before { content: "Aksi"; }
        .admin-table td[data-label="Label"]::before { content: "Label"; }
        .admin-table td[data-label="Isi Teks"]::before { content: "Isi Teks"; }
        .admin-table td[data-label="Isi Perihal"]::before { content: "Isi Perihal"; }
        .admin-table td[data-label="Nama"]::before { content: "Nama Kegiatan"; }
        .admin-table td[data-label="Kode"]::before { content: "Kode Kegiatan"; }
        .admin-table td[data-label="Tempat"]::before { content: "Nama Tempat"; }
        .admin-table td img { max-width: 80px; mix-blend-mode: multiply; background: white; padding: 4px; border-radius: 6px; }
    }
</style>

<div class="page-header">
    <h1><i class="fas fa-cogs"></i> Pengaturan Template Surat</h1>
    <p>Kelola kumpulan teks Perihal dan Tujuan (Kepada Yth) agar pembuatan surat otomatis lebih cepat tanpa harus selalu mengetik ulang.</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- ========== 1. PENGATURAN TANDA TANGAN & STEMPEL ========== -->
<div class="card" style="margin-bottom:30px;">
    <div class="card-header" style="background:#1e2633;"><i class="fas fa-file-signature"></i> Pengaturan Tanda Tangan & Stempel (Cetak PDF)</div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" id="ttdStempelForm">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_ttd">
            
            <!-- Baris Tanda Tangan Pejabat -->
            <div class="upload-grid">
                <div class="upload-card">
                    <div class="upload-card-header"><i class="fas fa-user-tie"></i> Pihak Rektorat / WAREK</div>
                    <div class="upload-card-body">
                        <div class="form-group"><label>Nama Rektor / Warek</label><input type="text" name="ttd_warek_name" class="form-control" value="<?php echo htmlspecialchars($def_warek_name); ?>" required></div>
                        <div class="form-group"><label>Jabatan (Tampil di Surat)</label><input type="text" name="ttd_warek_jabatan" class="form-control" value="<?php echo htmlspecialchars($def_warek_jab); ?>" required></div>
                        <div class="upload-area" data-target="warek">
                            <div class="drop-zone" id="dropzone_warek">
                                <i class="fas fa-cloud-upload-alt upload-icon"></i>
                                <p>Seret & lepas gambar di sini<br>atau klik untuk memilih</p>
                                <input type="file" id="file_warek" name="ttd_warek_image" accept="image/png, image/jpeg, image/webp" style="display:none;">
                                <button type="button" class="btn-select-file" onclick="document.getElementById('file_warek').click()">Pilih File</button>
                            </div>
                            <div class="preview-container" id="preview_warek_ctn" style="<?php echo $def_warek_img ? 'display:flex' : 'display:none'; ?>">
                                <img id="preview_warek_img" class="preview-img" src="<?php echo $def_warek_img ? uploadUrl($def_warek_img) : '#'; ?>">
                                <button type="button" class="btn-remove-img" data-target="warek" title="Hapus Gambar"><i class="fas fa-trash-alt"></i></button>
                            </div>
                            <small>PNG/JPG transparan direkomendasikan. Kosongkan jika tidak ingin mengubah.</small>
                        </div>
                    </div>
                </div>
                <div class="upload-card">
                    <div class="upload-card-header"><i class="fas fa-user-graduate"></i> Presiden Mahasiswa (BEM)</div>
                    <div class="upload-card-body">
                        <div class="form-group"><label>Nama Presma</label><input type="text" name="ttd_presma_name" class="form-control" value="<?php echo htmlspecialchars($def_presma_name); ?>" required></div>
                        <div class="form-group"><label>Jabatan</label><input type="text" name="ttd_presma_jabatan" class="form-control" value="<?php echo htmlspecialchars($def_presma_jab); ?>" required></div>
                        <div class="upload-area" data-target="presma">
                            <div class="drop-zone" id="dropzone_presma">
                                <i class="fas fa-cloud-upload-alt upload-icon"></i>
                                <p>Seret & lepas gambar di sini<br>atau klik untuk memilih</p>
                                <input type="file" id="file_presma" name="ttd_presma_image" accept="image/png, image/jpeg, image/webp" style="display:none;">
                                <button type="button" class="btn-select-file" onclick="document.getElementById('file_presma').click()">Pilih File</button>
                            </div>
                            <div class="preview-container" id="preview_presma_ctn" style="<?php echo $def_presma_img ? 'display:flex' : 'display:none'; ?>">
                                <img id="preview_presma_img" class="preview-img" src="<?php echo $def_presma_img ? uploadUrl($def_presma_img) : '#'; ?>">
                                <button type="button" class="btn-remove-img" data-target="presma" title="Hapus Gambar"><i class="fas fa-trash-alt"></i></button>
                            </div>
                            <small>PNG/JPG transparan direkomendasikan. Kosongkan jika tidak ingin mengubah.</small>
                        </div>
                    </div>
                </div>
            </div>

            <hr style="border:1px solid #2a3545; margin:40px 0;">
            <h3 style="margin-top:0; color:#8BB9F0; margin-bottom:20px;"><i class="fas fa-stamp"></i> Pengaturan Stempel / Cap Instansi</h3>
            <div class="upload-grid stempel-grid">
                <div class="upload-card">
                    <div class="upload-card-header"><i class="fas fa-users"></i> Cap PANITIA KEGIATAN</div>
                    <div class="upload-card-body">
                        <div class="upload-area" data-target="cap_panitia">
                            <div class="drop-zone" id="dropzone_cap_panitia">
                                <i class="fas fa-cloud-upload-alt upload-icon"></i><p>Seret & lepas cap di sini</p>
                                <input type="file" id="file_cap_panitia" name="cap_panitia_image" accept="image/png, image/jpeg, image/webp" style="display:none;">
                                <button type="button" class="btn-select-file" onclick="document.getElementById('file_cap_panitia').click()">Pilih File</button>
                            </div>
                            <div class="preview-container" id="preview_cap_panitia_ctn" style="<?php echo $def_cap_panitia ? 'display:flex' : 'display:none'; ?>">
                                <img id="preview_cap_panitia_img" class="preview-img" src="<?php echo $def_cap_panitia ? uploadUrl($def_cap_panitia) : '#'; ?>">
                                <button type="button" class="btn-remove-img" data-target="cap_panitia" title="Hapus Cap"><i class="fas fa-trash-alt"></i></button>
                            </div>
                            <small>PNG/JPG transparan.</small>
                        </div>
                    </div>
                </div>
                <div class="upload-card">
                    <div class="upload-card-header"><i class="fas fa-university"></i> Cap REKTORAT / LEMBAGA</div>
                    <div class="upload-card-body">
                        <div class="upload-area" data-target="cap_warek">
                            <div class="drop-zone" id="dropzone_cap_warek">
                                <i class="fas fa-cloud-upload-alt upload-icon"></i><p>Seret & lepas cap di sini</p>
                                <input type="file" id="file_cap_warek" name="cap_warek_image" accept="image/png, image/jpeg, image/webp" style="display:none;">
                                <button type="button" class="btn-select-file" onclick="document.getElementById('file_cap_warek').click()">Pilih File</button>
                            </div>
                            <div class="preview-container" id="preview_cap_warek_ctn" style="<?php echo $def_cap_warek ? 'display:flex' : 'display:none'; ?>">
                                <img id="preview_cap_warek_img" class="preview-img" src="<?php echo $def_cap_warek ? uploadUrl($def_cap_warek) : '#'; ?>">
                                <button type="button" class="btn-remove-img" data-target="cap_warek" title="Hapus Cap"><i class="fas fa-trash-alt"></i></button>
                            </div>
                            <small>PNG/JPG transparan.</small>
                        </div>
                    </div>
                </div>
                <div class="upload-card">
                    <div class="upload-card-header"><i class="fas fa-gavel"></i> Cap BEM / BEMCUP</div>
                    <div class="upload-card-body">
                        <div class="upload-area" data-target="cap_presma">
                            <div class="drop-zone" id="dropzone_cap_presma">
                                <i class="fas fa-cloud-upload-alt upload-icon"></i><p>Seret & lepas cap di sini</p>
                                <input type="file" id="file_cap_presma" name="cap_presma_image" accept="image/png, image/jpeg, image/webp" style="display:none;">
                                <button type="button" class="btn-select-file" onclick="document.getElementById('file_cap_presma').click()">Pilih File</button>
                            </div>
                            <div class="preview-container" id="preview_cap_presma_ctn" style="<?php echo $def_cap_presma ? 'display:flex' : 'display:none'; ?>">
                                <img id="preview_cap_presma_img" class="preview-img" src="<?php echo $def_cap_presma ? uploadUrl($def_cap_presma) : '#'; ?>">
                                <button type="button" class="btn-remove-img" data-target="cap_presma" title="Hapus Cap"><i class="fas fa-trash-alt"></i></button>
                            </div>
                            <small>PNG/JPG transparan.</small>
                        </div>
                    </div>
                </div>
            </div>
            <div style="margin-top:30px; text-align:right;">
                <button type="submit" class="btn-primary" style="padding:12px 24px;"><i class="fas fa-save"></i> Simpan Semua Pengaturan</button>
            </div>
        </form>
    </div>
</div>

<!-- ========== 2. DATABASE PANITIA TETAP ========== -->
<div class="card" style="margin-bottom:30px; border: 1px solid #4A90E2;">
    <div class="card-header" style="background: rgba(74, 144, 226, 0.1); color: #4A90E2;"><i class="fas fa-users-cog"></i> Database Tanda Tangan Kepanitiaan (Ketuplak & Sekretaris)</div>
    <div class="card-body">
        <p style="font-size: 0.9rem; color: #aaa; margin-bottom: 20px;">Simpan data Ketua dan Sekretaris Pelaksana di sini agar saat pembuatan surat nanti Anda tinggal memilih dari dropdown.</p>
        <div style="display:flex; gap:30px; flex-wrap:wrap;">
            <div style="flex:1; min-width:300px; background:rgba(0,0,0,0.2); padding:20px; border-radius:8px;">
                <h4 style="margin-top:0;"><i class="fas fa-user-plus"></i> Tambah Panitia Baru</h4>
                <form method="POST" enctype="multipart/form-data">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="tambah_panitia">
                    <div class="form-group"><label>Nama Lengkap (UPPERCASE)</label><input type="text" name="nama_panitia" class="form-control" required></div>
                    <div class="form-group"><label>Jabatan</label><select name="jabatan_panitia" class="form-control"><option value="ketua">Ketua Pelaksana</option><option value="sekretaris">Sekretaris Pelaksana</option></select></div>
                    <div class="form-group"><label>Unggah Tanda Tangan (PNG)</label><input type="file" name="file_ttd" class="form-control" accept="image/png" required></div>
                    <button type="submit" class="btn-primary" style="width:100%;"><i class="fas fa-plus"></i> Simpan</button>
                </form>
            </div>
            <div style="flex:2; min-width:350px;">
                <h4><i class="fas fa-database"></i> Daftar Panitia Tersimpan</h4>
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="admin-table responsive-card-table">
                        <thead><tr><th>Nama & Jabatan</th><th style="text-align:center;">Pratinjau TTD</th><th style="text-align:center;">Aksi</th></tr></thead>
                        <tbody>
                            <?php $list_panitia = dbFetchAll("SELECT * FROM panitia_tetap WHERE periode_id = ? ORDER BY jabatan ASC, nama ASC", [$periode_id], "i");
                            if (empty($list_panitia)): ?>
                                <tr><td colspan="3" style="text-align:center;">Belum ada panitia tersimpan.</td></tr>
                            <?php else: foreach ($list_panitia as $pt): ?>
                                <tr>
                                    <td data-label="Nama & Jabatan"><div style="font-weight:bold; color:#fff;"><?php echo htmlspecialchars($pt['nama']); ?></div><div style="font-size:0.75rem; color:#4A90E2;"><?php echo $pt['jabatan'] === 'ketua' ? 'Ketua Pelaksana' : 'Sekretaris'; ?></div></td>
                                    <td data-label="Pratinjau TTD" style="text-align:center;"><div style="background:#fff; padding:5px; border-radius:4px; display:inline-block;"><img src="<?php echo uploadUrl($pt['file_ttd']); ?>" style="max-height:40px; mix-blend-mode:multiply;"></div></td>
                                    <td data-label="Aksi" style="text-align:center;"><a href="?hapus_panitia=<?php echo $pt['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" class="btn-delete" onclick="return confirm('Hapus?')"><i class="fas fa-trash"></i> Hapus</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<hr style="border:1px solid #2a3545; margin:40px 0;">

<!-- ========== 3. PENGATURAN TEMPLATE REDAKSI (border biru, accordion, tengah) ========== -->
<div class="template-layout" style="display:flex; gap:20px; flex-wrap:wrap; justify-content:center; align-items:flex-start;">
    
    <!-- Form Tambah Template -->
    <div class="blue-border-card" style="flex:1; min-width:300px; max-width:450px;">
        <div class="card-header"><i class="fas <?php echo $edit_data ? 'fa-edit' : 'fa-plus-circle'; ?>"></i> <?php echo $edit_data ? 'Edit Template' : 'Tambah Template Baru'; ?></div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="<?php echo $edit_data ? 'update' : 'tambah'; ?>">
                <?php if ($edit_data): ?><input type="hidden" name="template_id" value="<?php echo $edit_data['id']; ?>"><?php endif; ?>
                
                <div class="form-group">
                    <label style="color:#8BB9F0;">Jenis Template</label>
                    <select name="jenis" class="form-control" required id="jenis_select">
                        <option value="perihal" <?php echo ($edit_data['jenis'] ?? '') === 'perihal' ? 'selected' : ''; ?>>Perihal (Subjek Surat)</option>
                        <option value="tujuan" <?php echo ($edit_data['jenis'] ?? '') === 'tujuan' ? 'selected' : ''; ?>>Tujuan (Kepada Yth)</option>
                        <option value="kegiatan" <?php echo ($edit_data['jenis'] ?? '') === 'kegiatan' ? 'selected' : ''; ?>>Nama & Kode Kegiatan</option>
                        <option value="tempat" <?php echo ($edit_data['jenis'] ?? '') === 'tempat' ? 'selected' : ''; ?>>Tempat Pelaksanaan</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nama Label (Singkat)</label>
                    <input type="text" name="label" class="form-control" required placeholder="Cth: Undangan Rapat" value="<?php echo htmlspecialchars($edit_data['label'] ?? ''); ?>">
                    <small>Ditampilkan sebagai opsi di dropdown form.</small>
                </div>
                <div class="form-group" id="wrap_kode_keg" style="<?php echo ($edit_data['jenis'] ?? '') === 'kegiatan' ? '' : 'display:none;'; ?>">
                    <label>Kode Kegiatan (Cth: BEMCUP)</label>
                    <input type="text" name="perihal_default" class="form-control" placeholder="BEMCUP" value="<?php echo htmlspecialchars($edit_data['perihal_default'] ?? ''); ?>" oninput="this.value = this.value.replace(/\s+/g, '').toUpperCase()" style="text-transform: uppercase;">
                    <small>Kode ini akan muncul di nomor surat (001/L/[KODE]/...).</small>
                </div>
                <div class="form-group" id="wrap_isi_teks" style="<?php echo in_array(($edit_data['jenis'] ?? ''), ['kegiatan','tempat']) ? 'display:none;' : ''; ?>">
                    <label>Isi Teks / Redaksi</label>
                    <textarea name="isi_teks" id="isi_teks" rows="<?php echo ($edit_data['jenis'] ?? '') === 'tujuan' ? '4' : '3'; ?>" class="form-control" placeholder="Cth: Permohonan Peminjaman Ruangan"><?php echo htmlspecialchars($edit_data['isi_teks'] ?? ''); ?></textarea>
                    <small id="hint_teks" style="display:block; margin-top:6px;">Ketik perihal surat di sini.</small>
                </div>
                <button type="submit" class="btn-primary" style="width:100%;"><i class="fas fa-save"></i> <?php echo $edit_data ? 'Update Template' : 'Simpan Template'; ?></button>
                <?php if ($edit_data): ?>
                    <a href="pengaturan-surat.php" class="btn-buat" style="width:100%; margin-top:10px; background:#444; justify-content:center;"><i class="fas fa-times"></i> Batal Edit</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <!-- Daftar Template (Accordion) -->
    <div class="blue-border-card" style="flex:2; min-width:350px;">
        <div class="card-header"><i class="fas fa-list-ul"></i> Daftar Template Tersimpan</div>
        <div class="card-body" style="padding:16px;">
            <!-- Tujuan -->
            <div class="accordion-item">
                <div class="accordion-header" data-target="accordion-tujuan">
                    <h3><i class="fas fa-list-ul"></i> Template "Tujuan" (Kepada Yth) <span class="badge-count"><?php echo count($list_tujuan); ?></span></h3>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="accordion-body" id="accordion-tujuan">
                    <div style="overflow-x:auto;">
                        <table class="admin-table responsive-card-table">
                            <thead><tr><th>Label</th><th>Isi Teks Tujuan</th><th style="text-align:center;">Aksi</th></tr></thead>
                            <tbody>
                                <?php if (empty($list_tujuan)): ?><tr><td colspan="3">Belum ada template tujuan.</td></tr>
                                <?php else: foreach ($list_tujuan as $tpl): ?>
                                    <tr>
                                        <td data-label="Label"><strong><?php echo htmlspecialchars($tpl['label']); ?></strong></td>
                                        <td data-label="Isi Teks"><?php echo nl2br(htmlspecialchars($tpl['isi_teks'])); ?></td>
                                        <td data-label="Aksi" style="text-align:center;"><div class="btn-group-mobile"><a href="?edit=<?php echo $tpl['id']; ?>#form-template" class="btn-edit">Edit</a><a href="?hapus=<?php echo $tpl['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" onclick="return confirm('Yakin hapus?')" class="btn-delete">Hapus</a></div></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- Perihal -->
            <div class="accordion-item">
                <div class="accordion-header" data-target="accordion-perihal">
                    <h3><i class="fas fa-info-circle"></i> Template "Perihal" <span class="badge-count"><?php echo count($list_perihal); ?></span></h3>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="accordion-body" id="accordion-perihal">
                    <div style="overflow-x:auto;">
                        <table class="admin-table responsive-card-table">
                            <thead><tr><th>Label</th><th>Isi Perihal</th><th style="text-align:center;">Aksi</th></tr></thead>
                            <tbody>
                                <?php if (empty($list_perihal)): ?><tr><td colspan="3">Belum ada template perihal.</td></tr>
                                <?php else: foreach ($list_perihal as $tpl): ?>
                                    <tr>
                                        <td data-label="Label"><strong><?php echo htmlspecialchars($tpl['label']); ?></strong></td>
                                        <td data-label="Isi Perihal"><?php echo htmlspecialchars($tpl['isi_teks']); ?></td>
                                        <td data-label="Aksi" style="text-align:center;"><div class="btn-group-mobile"><a href="?edit=<?php echo $tpl['id']; ?>#form-template" class="btn-edit">Edit</a><a href="?hapus=<?php echo $tpl['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" onclick="return confirm('Yakin hapus?')" class="btn-delete">Hapus</a></div></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- Kegiatan -->
            <div class="accordion-item">
                <div class="accordion-header" data-target="accordion-kegiatan">
                    <h3><i class="fas fa-calendar-alt"></i> Template "Kegiatan" (Nama & Kode) <span class="badge-count"><?php echo count($list_kegiatan); ?></span></h3>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="accordion-body" id="accordion-kegiatan">
                    <div style="overflow-x:auto;">
                        <table class="admin-table responsive-card-table">
                            <thead><tr><th>Nama Kegiatan</th><th>Kode Kegiatan</th><th style="text-align:center;">Aksi</th></tr></thead>
                            <tbody>
                                <?php if (empty($list_kegiatan)): ?><tr><td colspan="3">Belum ada template kegiatan.</td></tr>
                                <?php else: foreach ($list_kegiatan as $tpl): ?>
                                    <tr>
                                        <td data-label="Nama"><strong><?php echo htmlspecialchars($tpl['label']); ?></strong></td>
                                        <td data-label="Kode"><code><?php echo htmlspecialchars($tpl['perihal_default']); ?></code></td>
                                        <td data-label="Aksi" style="text-align:center;"><div class="btn-group-mobile"><a href="?edit=<?php echo $tpl['id']; ?>#form-template" class="btn-edit">Edit</a><a href="?hapus=<?php echo $tpl['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" onclick="return confirm('Yakin hapus?')" class="btn-delete">Hapus</a></div></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <!-- Tempat -->
            <div class="accordion-item">
                <div class="accordion-header" data-target="accordion-tempat">
                    <h3><i class="fas fa-map-marker-alt"></i> Template "Tempat Pelaksanaan" <span class="badge-count"><?php echo count($list_tempat); ?></span></h3>
                    <i class="fas fa-chevron-down chevron"></i>
                </div>
                <div class="accordion-body" id="accordion-tempat">
                    <div style="overflow-x:auto;">
                        <table class="admin-table responsive-card-table">
                            <thead><tr><th>Nama Tempat</th><th style="text-align:center;">Aksi</th></tr></thead>
                            <tbody>
                                <?php if (empty($list_tempat)): ?><tr><td colspan="2">Belum ada template tempat.</td></tr>
                                <?php else: foreach ($list_tempat as $tpl): ?>
                                    <tr>
                                        <td data-label="Tempat"><strong><?php echo htmlspecialchars($tpl['label']); ?></strong></td>
                                        <td data-label="Aksi" style="text-align:center;"><div class="btn-group-mobile"><a href="?edit=<?php echo $tpl['id']; ?>#form-template" class="btn-edit">Edit</a><a href="?hapus=<?php echo $tpl['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" onclick="return confirm('Yakin hapus?')" class="btn-delete">Hapus</a></div></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ========== DRAG & DROP DAN PREVIEW GAMBAR ==========
function setupDragAndDrop(dropZoneId, fileInputId, previewContainerId, previewImgId) {
    const dropZone = document.getElementById(dropZoneId);
    const fileInput = document.getElementById(fileInputId);
    const previewCtn = document.getElementById(previewContainerId);
    const previewImg = document.getElementById(previewImgId);
    if (!dropZone || !fileInput) return;

    dropZone.addEventListener('click', (e) => {
        if (e.target === dropZone || e.target.classList.contains('upload-icon') || e.target.tagName === 'P') {
            fileInput.click();
        }
    });
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => { e.preventDefault(); e.stopPropagation(); });
    });
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'));
    });
    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'));
    });
    dropZone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length) {
            fileInput.files = files;
            updatePreview(fileInput, previewImg, previewCtn);
        }
    });
    fileInput.addEventListener('change', () => updatePreview(fileInput, previewImg, previewCtn));
}

function updatePreview(fileInput, previewImg, previewCtn) {
    if (fileInput.files && fileInput.files[0]) {
        const reader = new FileReader();
        reader.onload = (e) => { previewImg.src = e.target.result; previewCtn.style.display = 'flex'; };
        reader.readAsDataURL(fileInput.files[0]);
    }
}

document.querySelectorAll('.btn-remove-img').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const target = this.getAttribute('data-target');
        const previewCtn = document.getElementById(`preview_${target}_ctn`);
        if (previewCtn) previewCtn.style.display = 'none';
        const fileInput = document.getElementById(`file_${target}`);
        if (fileInput) fileInput.value = '';
        alert('Gambar akan dihapus saat Anda menyimpan pengaturan. Klik "Simpan" untuk menerapkan perubahan.');
    });
});

// Inisialisasi semua drag & drop
document.addEventListener('DOMContentLoaded', () => {
    setupDragAndDrop('dropzone_warek', 'file_warek', 'preview_warek_ctn', 'preview_warek_img');
    setupDragAndDrop('dropzone_presma', 'file_presma', 'preview_presma_ctn', 'preview_presma_img');
    setupDragAndDrop('dropzone_cap_panitia', 'file_cap_panitia', 'preview_cap_panitia_ctn', 'preview_cap_panitia_img');
    setupDragAndDrop('dropzone_cap_warek', 'file_cap_warek', 'preview_cap_warek_ctn', 'preview_cap_warek_img');
    setupDragAndDrop('dropzone_cap_presma', 'file_cap_presma', 'preview_cap_presma_ctn', 'preview_cap_presma_img');
});

// ========== ACCORDION TOGGLE ==========
document.querySelectorAll('.accordion-header').forEach(header => {
    header.addEventListener('click', () => {
        const targetId = header.getAttribute('data-target');
        const body = document.getElementById(targetId);
        const isOpen = body.classList.contains('open');
        if (!isOpen) {
            body.classList.add('open');
            header.classList.add('open');
        } else {
            body.classList.remove('open');
            header.classList.remove('open');
        }
    });
});

// Buka accordion pertama secara default
document.addEventListener('DOMContentLoaded', () => {
    const firstHeader = document.querySelector('.accordion-header');
    if (firstHeader) {
        const targetId = firstHeader.getAttribute('data-target');
        const body = document.getElementById(targetId);
        if (body) { body.classList.add('open'); firstHeader.classList.add('open'); }
    }
});

// ========== DINAMIS JENIS TEMPLATE ==========
document.getElementById('jenis_select').addEventListener('change', function() {
    let type = this.value;
    let hint = document.getElementById('hint_teks');
    let area = document.getElementById('isi_teks');
    let wrap_kode = document.getElementById('wrap_kode_keg');
    let wrap_isi = document.getElementById('wrap_isi_teks');
    if(type === 'tujuan') {
        hint.innerHTML = 'Ketik tujuan lengkap di sini (Boleh enter ke bawah).<br>Contoh:<br>Bapak Rektor Universitas X<br>Di Tempat';
        area.rows = 4;
        area.placeholder = 'Rektor Universitas X\nDi Tempat';
        if(wrap_kode) wrap_kode.style.display = 'none';
        if(wrap_isi) wrap_isi.style.display = 'block';
    } else if(type === 'kegiatan') {
        if(wrap_kode) wrap_kode.style.display = 'block';
        if(wrap_isi) wrap_isi.style.display = 'none';
    } else if(type === 'tempat') {
        if(wrap_kode) wrap_kode.style.display = 'none';
        if(wrap_isi) wrap_isi.style.display = 'none';
    } else {
        hint.innerHTML = 'Ketik perihal surat di sini. Tidak perlu enter ke bawah.<br>Contoh: Permohonan Bantuan Dana';
        area.rows = 2;
        area.placeholder = 'Permohonan Bantuan Dana';
        if(wrap_kode) wrap_kode.style.display = 'none';
        if(wrap_isi) wrap_isi.style.display = 'block';
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>