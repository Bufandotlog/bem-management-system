<?php
// admin/upload-struktur.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: Hapus gambar pindah dari GET link di modal ke POST form
//   CHANGED: sanitizeText() untuk judul dan deskripsi
//   CHANGED: uploadFile() dipanggil tanpa parameter ketiga (tidak didukung)
//   CHANGED: Error message generic (tidak bocorkan detail exception)
//   CHANGED: XSS di flash message — echo sekarang lewat htmlspecialchars()
//   CHANGED: Redirect ke admin/upload-struktur.php bukan root
//   CHANGED: $periode_info di-escape dengan htmlspecialchars()
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

echo '<link rel="stylesheet" href="css/upload-struktur.css?v=' . filemtime(__DIR__ . '/css/upload-struktur.css') . '">';

$struktur = dbFetchOne(
    "SELECT * FROM struktur_organisasi WHERE periode_id = ?",
    [$active_periode], "i"
);

$error = '';

// ============================================
// PROSES HAPUS GAMBAR — POST + CSRF
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_hapus_gambar'])) {
    if (!csrfVerify()) {
        redirect('admin/upload-struktur.php', 'Request tidak valid.', 'error');
        exit();
    }
    if ($struktur && !empty($struktur['gambar'])) {
        deleteFile($struktur['gambar']);
        dbQuery("UPDATE struktur_organisasi SET gambar = NULL WHERE periode_id = ?",
                [$active_periode], "i");
    }
    redirect('admin/upload-struktur.php', 'Gambar berhasil dihapus.', 'success');
    exit();
}

// ============================================
// PROSES UPLOAD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action_hapus_gambar'])) {

    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } elseif (!isset($_FILES['gambar']) || $_FILES['gambar']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Silakan pilih file gambar terlebih dahulu';
    } else {
        // uploadFile() hanya terima 2 parameter — parameter ketiga dihapus
        $upload_result = uploadFile($_FILES['gambar'], 'struktur_organisasi');

        if ($upload_result) {
            $judul     = sanitizeText($_POST['judul']     ?? 'Struktur Organisasi BEM', 200);
            $deskripsi = sanitizeText($_POST['deskripsi'] ?? '', 500);
            $user_id   = (int) ($_SESSION['admin_id'] ?? 1);

            if (empty($judul)) $judul = 'Struktur Organisasi BEM';

            if ($struktur && !empty($struktur['gambar'])) {
                deleteFile($struktur['gambar']);
            }

            dbBeginTransaction();
            try {
                if ($struktur) {
                    dbQuery(
                        "UPDATE struktur_organisasi SET judul=?, gambar=?, deskripsi=?, updated_by=? WHERE periode_id=?",
                        [$judul, $upload_result, $deskripsi, $user_id, $active_periode],
                        "sssii"
                    );
                } else {
                    dbQuery(
                        "INSERT INTO struktur_organisasi (periode_id, judul, gambar, deskripsi, updated_by) VALUES (?, ?, ?, ?, ?)",
                        [$active_periode, $judul, $upload_result, $deskripsi, $user_id],
                        "isssi"
                    );
                }
                dbCommit();
                auditLog('UPDATE', 'struktur_organisasi', $active_periode, 'Upload struktur organisasi periode ' . $active_periode);
                redirect('admin/upload-struktur.php', 'Gambar struktur organisasi berhasil diupload!', 'success');
                exit();
            } catch (Exception $e) {
                dbRollback();
                error_log("[UPLOAD STRUKTUR] " . $e->getMessage());
                $error = 'Gagal menyimpan. Silakan coba lagi.';
            }
        } else {
            $error = $_SESSION['error'] ?? 'Gagal upload gambar';
            unset($_SESSION['error']);
        }
    }
}

// Refresh data setelah update
$struktur = dbFetchOne(
    "SELECT * FROM struktur_organisasi WHERE periode_id = ?",
    [$active_periode], "i"
);

$periode_info = htmlspecialchars(
    "Periode: " . ($periode_data['nama'] ?? 'Astawidya')
    . " (" . ($periode_data['tahun_mulai'] ?? '2025')
    . "/" . ($periode_data['tahun_selesai'] ?? '2026') . ")",
    ENT_QUOTES, 'UTF-8'
);
?>

<?php /* Form hapus gambar — di LUAR form upload agar tidak nested */ ?>
<?php if ($struktur && !empty($struktur['gambar'])): ?>
<form method="POST" id="formHapusGambar"
      onsubmit="return confirm('Yakin ingin menghapus gambar struktur untuk periode ini?')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_hapus_gambar" value="1">
</form>
<?php endif; ?>

<div class="upload-container">
    <div class="upload-header">
        <h1><i class="bi bi-image"></i> Upload Struktur Organisasi</h1>
        <p>Upload gambar struktur organisasi BEM untuk ditampilkan di halaman kepengurusan</p>
        <div class="periode-info">
            <i class="bi bi-calendar"></i>
            <span><?php echo $periode_info; ?></span>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            <button class="btn-close" onclick="this.parentElement.remove()">&times;</button>
        </div>
    <?php endif; ?>

    <?php flashMessage(); ?>

    <div class="upload-grid">
        <!-- Kolom Kiri: Form Upload -->
        <div class="card">
            <div class="card-header">
                <i class="bi bi-upload"></i>
                Form Upload Gambar
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data" id="uploadForm">

                    <?php echo csrfField(); ?>

                    <div class="form-group">
                        <label for="judul" class="form-label">
                            <i class="bi bi-tag"></i> Judul Gambar
                        </label>
                        <input type="text"
                               class="form-control"
                               id="judul"
                               name="judul"
                               value="<?php echo htmlspecialchars($struktur['judul'] ?? 'Struktur Organisasi BEM Kabinet Astawidya', ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="Masukkan judul gambar">
                        <div class="form-text">
                            <i class="bi bi-info-circle"></i>
                            Judul yang akan ditampilkan di halaman publik
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="gambar" class="form-label">
                            <i class="bi bi-file-image"></i> File Gambar
                        </label>
                        <div class="file-input-wrapper">
                            <input type="file"
                                   id="gambar"
                                   name="gambar"
                                   accept="image/jpeg,image/png,image/gif,image/webp"
                                   required>
                            <label for="gambar" class="file-label" id="fileLabel">
                                <i class="bi bi-cloud-arrow-up"></i>
                                <div>
                                    <span>Pilih file atau drag & drop</span>
                                    <small class="file-name" id="fileName"></small>
                                </div>
                            </label>
                        </div>
                        <div class="file-info" id="fileInfo" style="display: none;">
                            <div class="file-info-detail">
                                <i class="bi bi-check-circle-fill"></i>
                                <span id="fileDisplayName"></span>
                            </div>
                            <span class="file-info-size" id="fileSize"></span>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-info-circle"></i>
                            Format: JPG, PNG, GIF, WEBP. Maksimal <?php echo round(MAX_FILE_SIZE/1024/1024); ?>MB.
                            Rekomendasi rasio 16:9 (1920x1080)
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="deskripsi" class="form-label">
                            <i class="bi bi-text-paragraph"></i> Deskripsi (Opsional)
                        </label>
                        <textarea class="form-control"
                                  id="deskripsi"
                                  name="deskripsi"
                                  rows="4"
                                  placeholder="Tambahkan deskripsi singkat tentang gambar ini"><?php echo htmlspecialchars($struktur['deskripsi'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <div class="action-buttons">
                        <a href="kepengurusan.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Kembali
                        </a>
                        <button type="submit" class="btn btn-primary" id="btnSubmit">
                            <i class="bi bi-cloud-upload"></i> Upload Gambar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Kolom Kanan: Preview dan Info -->
        <div>
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-eye"></i>
                    Preview Gambar
                </div>
                <div class="card-body">
                    <div class="preview-container">
                        <?php if ($struktur && !empty($struktur['gambar'])): ?>
                            <img src="<?php echo uploadUrl($struktur['gambar']); ?>"
                                 alt="<?php echo htmlspecialchars($struktur['judul'], ENT_QUOTES, 'UTF-8'); ?>"
                                 class="preview-image" id="currentPreview">
                            <div class="preview-info">
                                <strong><?php echo htmlspecialchars($struktur['judul'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if (!empty($struktur['deskripsi'])): ?>
                                    <p class="text-muted small mt-1"><?php echo htmlspecialchars($struktur['deskripsi'], ENT_QUOTES, 'UTF-8'); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="preview-empty">
                                <i class="bi bi-image"></i>
                                <p>Belum ada gambar struktur untuk periode ini</p>
                                <p class="small">Upload gambar menggunakan form di samping</p>
                            </div>
                        <?php endif; ?>

                        <div id="newPreviewArea" style="display: none;">
                            <hr>
                            <h6 class="text-primary">Preview Gambar Baru:</h6>
                            <img id="newPreview" class="preview-image" style="max-height: 150px;">
                        </div>
                    </div>

                    <div class="d-grid gap-2 mt-3">
                        <?php if ($struktur && !empty($struktur['gambar'])): ?>
                            <?php /* Tombol hapus submit ke formHapusGambar di luar — tidak nested */ ?>
                            <button type="submit" form="formHapusGambar" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-trash"></i> Hapus Gambar Periode Ini
                            </button>
                        <?php endif; ?>
                        <a href="<?php echo baseUrl('kepengurusan.php?periode=' . $active_periode); ?>"
                           target="_blank" rel="noopener noreferrer"
                           class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-box-arrow-up-right"></i> Lihat di Halaman Publik
                        </a>
                    </div>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <i class="bi bi-info-circle"></i>
                    Informasi
                </div>
                <div class="card-body">
                    <div class="info-box">
                        <div class="info-box-title">
                            <i class="bi bi-folder"></i>
                            Lokasi Penyimpanan
                        </div>
                        <ul class="info-list">
                            <li><i class="bi bi-hdd"></i><span>Folder:</span><code>uploads/struktur_organisasi/</code></li>
                            <li><i class="bi bi-database"></i><span>Database:</span><code>path relatif + periode_id</code></li>
                        </ul>
                    </div>
                    <div class="info-box mt-3">
                        <div class="info-box-title">
                            <i class="bi bi-image"></i>
                            Rekomendasi Ukuran
                        </div>
                        <ul class="info-list">
                            <li><i class="bi bi-arrows-angle-expand"></i><span>Rasio 16:9</span><code>1920x1080</code></li>
                            <li><i class="bi bi-file-earmark"></i><span>Max Size</span><code><?php echo round(MAX_FILE_SIZE/1024/1024); ?> MB</code></li>
                        </ul>
                    </div>
                    <div class="info-box mt-3">
                        <div class="info-box-title">
                            <i class="bi bi-exclamation-triangle"></i>
                            Catatan
                        </div>
                        <ul class="info-list">
                            <li><i class="bi bi-check-circle text-success"></i>Setiap periode punya gambar sendiri</li>
                            <li><i class="bi bi-check-circle text-success"></i>Gambar lama otomatis terhapus per periode</li>
                            <li><i class="bi bi-check-circle text-success"></i>Format: JPG, PNG, GIF, WEBP</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript — tidak diubah -->
<script>
document.getElementById('gambar').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const fileNameSpan = document.getElementById('fileName');
    const fileLabel = document.getElementById('fileLabel');
    const fileInfo = document.getElementById('fileInfo');
    const fileDisplayName = document.getElementById('fileDisplayName');
    const fileSize = document.getElementById('fileSize');
    const newPreviewArea = document.getElementById('newPreviewArea');
    const newPreview = document.getElementById('newPreview');

    if (file) {
        fileNameSpan.textContent = file.name;
        fileLabel.classList.add('has-file');
        fileDisplayName.textContent = file.name;
        const sizeInKB = (file.size / 1024).toFixed(2);
        const sizeInMB = (file.size / (1024 * 1024)).toFixed(2);
        fileSize.textContent = sizeInMB > 1 ? `${sizeInMB} MB` : `${sizeInKB} KB`;
        fileInfo.style.display = 'flex';
        const reader = new FileReader();
        reader.onload = function(e) {
            newPreview.src = e.target.result;
            newPreview.alt = 'Preview: ' + file.name;
            newPreviewArea.style.display = 'block';
        }
        reader.readAsDataURL(file);
    } else {
        fileNameSpan.textContent = '';
        fileLabel.classList.remove('has-file');
        fileInfo.style.display = 'none';
        newPreviewArea.style.display = 'none';
        newPreview.src = '';
    }
});

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    const fileInput = document.getElementById('gambar');
    const file = fileInput.files[0];
    const btnSubmit = document.getElementById('btnSubmit');

    if (file) {
        const maxSize = <?php echo MAX_FILE_SIZE; ?>;
        if (file.size > maxSize) {
            e.preventDefault();
            alert('❌ Ukuran file terlalu besar! Maksimal <?php echo round(MAX_FILE_SIZE/1024/1024); ?>MB.');
            return;
        }
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            e.preventDefault();
            alert('❌ Tipe file tidak diizinkan! Gunakan JPG, PNG, GIF, atau WEBP.');
            return;
        }
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span class="spinner"></span> Mengupload...';
    }
});

setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.transition = 'opacity 1s';
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 1000);
    });
}, 5000);
</script>

<?php require_once __DIR__ . '/footer.php'; ?>