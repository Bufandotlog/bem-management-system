<?php
// admin/arsip-manual.php
$page_css = 'arsip-surat';
require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();
$error = '';
$success = '';

// Mode Edit
$edit_id = (isset($_GET['edit']) && is_numeric($_GET['edit'])) ? (int)$_GET['edit'] : 0;
$default_type = sanitizeText($_GET['type'] ?? 'M', 1);
$is_edit = false;
$edit_data = [
    'jenis_surat' => $default_type,
    'tanggal_dikirim' => date('Y-m-d'),
    'nomor_surat' => '',
    'perihal' => '',
    'tujuan' => '',
    'file_surat' => ''
];

if ($edit_id > 0) {
    $existing = dbFetchOne("SELECT * FROM arsip_surat WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
    if ($existing) {
        $is_edit = true;
        $edit_data = $existing;
    } else {
        $error = "Data arsip tidak ditemukan atau akses ditolak.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $jenis_surat      = sanitizeText($_POST['jenis_surat'], 1);
        $tanggal_dikirim  = sanitizeText($_POST['tanggal_dikirim'], 50);
        $nomor_surat      = sanitizeText($_POST['nomor_surat'], 100);
        $perihal          = sanitizeText($_POST['perihal'], 255);
        $tujuan           = sanitizeText($_POST['tujuan'], 255);
        $action_type      = $_POST['action_type'] ?? 'insert';
        
        $file_path = $is_edit ? $edit_data['file_surat'] : null;
        if (isset($_FILES['file_surat']) && $_FILES['file_surat']['error'] !== UPLOAD_ERR_NO_FILE) {
            $folder = ($jenis_surat === 'M') ? 'surat_masuk' : 'surat_keluar_manual';
            $uploaded = uploadFile($_FILES['file_surat'], $folder);
            if ($uploaded) {
                // Hapus file lama jika ada
                if ($is_edit && !empty($edit_data['file_surat'])) {
                    $old_path = UPLOAD_PATH . '/' . $edit_data['file_surat'];
                    if(file_exists($old_path)) unlink($old_path);
                }
                $file_path = $uploaded;
            } else {
                $error = $_SESSION['error'] ?? 'Gagal upload file surat.';
            }
        } elseif (!$is_edit && empty($_FILES['file_surat']['name'])) {
            $error = 'File surat manual (PDF/Gambar) wajib diunggah untuk arsip manual.';
        }
        
        if (!$error) {
            try {
                if ($action_type === 'update' && $is_edit) {
                    dbQuery(
                        "UPDATE arsip_surat SET jenis_surat=?, tanggal_dikirim=?, nomor_surat=?, perihal=?, tujuan=?, file_surat=? WHERE id=? AND periode_id=?",
                        [$jenis_surat, $tanggal_dikirim, $nomor_surat, $perihal, $tujuan, $file_path, $edit_id, $periode_id],
                        "ssssssii"
                    );
                    auditLog('UPDATE', 'arsip_surat', $edit_id, 'Update Arsip Manual ('.$jenis_surat.'): ' . $nomor_surat);
                    redirect('admin/arsip-surat.php?jenis='.$jenis_surat, 'Perubahan arsip berhasil disimpan!', 'success');
                } else {
                    dbQuery(
                        "INSERT INTO arsip_surat (periode_id, jenis_surat, tanggal_dikirim, nomor_surat, perihal, tujuan, file_surat, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                         [$periode_id, $jenis_surat, $tanggal_dikirim, $nomor_surat, $perihal, $tujuan, $file_path, $_SESSION['admin_id']],
                         "issssssi"
                    );
                    $new_id = dbLastId();
                    auditLog('CREATE', 'arsip_surat', $new_id, 'Mencatat Arsip Manual ('.$jenis_surat.'): ' . $nomor_surat);
                    redirect('admin/arsip-surat.php?jenis='.$jenis_surat, 'Arsip manual berhasil dicatat!', 'success');
                }
                exit();
            } catch (Exception $e) {
                error_log("Gagal memproses arsip manual: " . $e->getMessage());
                $error = 'Terjadi kesalahan saat menyimpan data.';
            }
        }
    }
}
?>

<div class="page-header">
    <h1><i class="fas fa-file-import"></i> <?php echo $is_edit ? 'Edit Arsip Manual' : 'Catat Arsip Manual'; ?></h1>
    <p><?php echo $is_edit ? 'Modifikasi arsip surat yang dibuat secara manual (luar sistem).' : 'Gunakan menu ini jika surat dibuat secara manual (misal: via Word/Canva) dan Anda ingin mengarsipkan filenya ke sistem.'; ?></p>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="card" style="max-width: 700px; margin: 0 auto;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_type" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">
    
    <div class="card-header">
        <i class="fas fa-edit"></i> Form Arsip Manual
    </div>
    <div class="card-body">
        
        <div class="form-group">
            <label>Jenis Surat</label>
            <select name="jenis_surat" class="form-control" required onchange="updateLabels(this.value)">
                <option value="M" <?php echo $edit_data['jenis_surat'] === 'M' ? 'selected' : ''; ?>>Surat Masuk (M)</option>
                <option value="L" <?php echo $edit_data['jenis_surat'] === 'L' ? 'selected' : ''; ?>>Surat Keluar Luar (L)</option>
                <option value="D" <?php echo $edit_data['jenis_surat'] === 'D' ? 'selected' : ''; ?>>Surat Keluar Dalam (D)</option>
            </select>
        </div>

        <div class="form-group">
            <label id="label_tanggal">Tanggal</label>
            <input type="date" name="tanggal_dikirim" class="form-control" required value="<?php echo htmlspecialchars(date('Y-m-d', strtotime($edit_data['tanggal_dikirim']))); ?>">
        </div>

        <div class="form-group">
            <label>Nomor Surat</label>
            <input type="text" name="nomor_surat" class="form-control" required placeholder="Contoh: 123/L/BEM/IV/2026" value="<?php echo htmlspecialchars($edit_data['nomor_surat']); ?>">
        </div>

        <div class="form-group">
            <label>Perihal Surat</label>
            <input type="text" name="perihal" class="form-control" required placeholder="Permohonan Peminjaman Gedung" value="<?php echo htmlspecialchars($edit_data['perihal']); ?>">
        </div>

        <div class="form-group">
            <label id="label_tujuan">Penerima / Pengirim</label>
            <textarea name="tujuan" class="form-control" rows="2" required placeholder="Nama Instansi / Jabatan Penerima"><?php echo htmlspecialchars($edit_data['tujuan']); ?></textarea>
        </div>

        <div class="form-group">
            <label>Upload File Surat (PDF/Gambar)</label>
            <?php if($is_edit && !empty($edit_data['file_surat'])): ?>
                <div style="margin-bottom:10px; font-size:0.9rem;">
                    <i class="fas fa-file-pdf"></i> File Saat ini: <a href="<?php echo uploadUrl($edit_data['file_surat']); ?>" target="_blank" style="color:#8BB9F0;">Lihat Arsip Terlampir</a>
                </div>
            <?php endif; ?>
            <input type="file" name="file_surat" class="form-control" accept="image/*,.pdf">
            <small><?php echo $is_edit ? 'Abaikan jika tidak ingin mengubah file arsip. ' : ''; ?>Wajib diunggah untuk pencatatan manual.</small>
        </div>

        <button type="submit" class="btn-primary" style="width: 100%; padding: 12px; margin-top: 10px;">
            <i class="fas fa-save"></i> <?php echo $is_edit ? 'Simpan Perubahan Arsip' : 'Simpan Arsip Manual'; ?>
        </button>
        <a href="arsip-surat.php" style="display:block; text-align:center; margin-top:15px; color:#555;">Kembali ke Arsip</a>
        
    </div>
</form>

<script>
function updateLabels(val) {
    const labelTgl = document.getElementById('label_tanggal');
    const labelTujuan = document.getElementById('label_tujuan');
    const inputTujuan = document.getElementsByName('tujuan')[0];

    if (val === 'M') {
        labelTgl.innerText = 'Tanggal Diterima';
        labelTujuan.innerText = 'Asal Instansi (Pengirim)';
        inputTujuan.placeholder = 'Universitas Majalengka / BEM...';
    } else {
        labelTgl.innerText = 'Tanggal Dikirim';
        labelTujuan.innerText = 'Tujuan (Kepada Yth)';
        inputTujuan.placeholder = 'Nama Instansi / Jabatan Penerima...';
    }
}
// Run once on load
updateLabels(document.getElementsByName('jenis_surat')[0].value);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
