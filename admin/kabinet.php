<?php
// admin/kabinet.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: 2 aksi hapus logo/foto pindah dari GET ke POST
//   CHANGED: sanitizeText() untuk nama, arti, deskripsi
//   CHANGED: Validasi range tahun di PHP (bukan hanya JavaScript)
//   CHANGED: htmlspecialchars() pada output basename logo/foto
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

$periode_aktif = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE is_active = 1");
$kabinet       = dbFetchOne("SELECT * FROM kabinet WHERE id = 1");

// ============================================
// AKSI POST: Hapus logo / foto_bersama
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrfVerify()) {
        redirect('admin/kabinet.php', 'Request tidak valid.', 'error');
        exit();
    }
    // Refresh kabinet setelah mungkin ada perubahan
    $kabinet = dbFetchOne("SELECT * FROM kabinet WHERE id = 1");

    if ($_POST['action'] === 'delete_logo' && !empty($kabinet['logo'])) {
        deleteFile($kabinet['logo']);
        dbQuery("UPDATE kabinet SET logo = NULL WHERE id = 1");
        redirect('admin/kabinet.php', 'Logo berhasil dihapus!', 'success');
        exit();
    }
    if ($_POST['action'] === 'delete_foto' && !empty($kabinet['foto_bersama'])) {
        deleteFile($kabinet['foto_bersama']);
        dbQuery("UPDATE kabinet SET foto_bersama = NULL WHERE id = 1");
        redirect('admin/kabinet.php', 'Foto bersama berhasil dihapus!', 'success');
        exit();
    }
}

// ============================================
// PROSES SUBMIT FORM UTAMA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {

    if (!csrfVerify()) {
        redirect('admin/kabinet.php', 'Request tidak valid.', 'error');
        exit();
    }

    $nama      = sanitizeText($_POST['nama']      ?? '', 100);
    $arti      = sanitizeText($_POST['arti']      ?? '', 200);
    $deskripsi = sanitizeText($_POST['deskripsi'] ?? '', 2000);

    if (empty($nama) || empty($arti)) {
        redirect('admin/kabinet.php', 'Nama kabinet dan arti nama wajib diisi.', 'error');
        exit();
    }

    // Validasi range tahun di PHP — JavaScript bisa dibypass
    $tahun_mulai   = (int) ($_POST['tahun_mulai']   ?? date('Y'));
    $tahun_selesai = (int) ($_POST['tahun_selesai'] ?? date('Y') + 1);

    if ($tahun_mulai < 2000 || $tahun_mulai > 2100
        || $tahun_selesai < 2000 || $tahun_selesai > 2100
        || $tahun_selesai <= $tahun_mulai) {
        redirect('admin/kabinet.php', 'Tahun tidak valid. Tahun selesai harus lebih besar dari tahun mulai.', 'error');
        exit();
    }

    $logo         = $kabinet['logo']         ?? '';
    $foto_bersama = $kabinet['foto_bersama'] ?? '';

    // Hapus via checkbox (hapus saat simpan)
    if (!empty($_POST['hapus_logo'])) {
        if (!empty($logo)) deleteFile($logo);
        $logo = '';
    }
    if (!empty($_POST['hapus_foto'])) {
        if (!empty($foto_bersama)) deleteFile($foto_bersama);
        $foto_bersama = '';
    }

    // Upload baru
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadFile($_FILES['logo'], 'kabinet');
        if ($upload) { if (!empty($logo)) deleteFile($logo); $logo = $upload; }
    }
    if (isset($_FILES['foto_bersama']) && $_FILES['foto_bersama']['error'] === UPLOAD_ERR_OK) {
        $upload = uploadFile($_FILES['foto_bersama'], 'kabinet');
        if ($upload) { if (!empty($foto_bersama)) deleteFile($foto_bersama); $foto_bersama = $upload; }
    }

    $result = dbQuery(
        "UPDATE kabinet SET nama=?, arti=?, tahun_mulai=?, tahun_selesai=?, logo=?, foto_bersama=?, deskripsi=? WHERE id=1",
        [$nama, $arti, $tahun_mulai, $tahun_selesai, $logo, $foto_bersama, $deskripsi],
        "ssiisss"
    );

    if ($result !== false) {
        auditLog('UPDATE', 'kabinet', 1, 'Edit data kabinet: ' . $nama);
    }
    redirect('admin/kabinet.php',
        $result !== false ? 'Data kabinet berhasil diperbarui!' : 'Gagal memperbarui data!',
        $result !== false ? 'success' : 'error'
    );
    exit();
}

// Refresh setelah aksi
$kabinet = dbFetchOne("SELECT * FROM kabinet WHERE id = 1");

$beda_mulai   = $periode_aktif && $periode_aktif['tahun_mulai']   != ($kabinet['tahun_mulai']   ?? '');
$beda_selesai = $periode_aktif && $periode_aktif['tahun_selesai'] != ($kabinet['tahun_selesai'] ?? '');
?>

<!-- ===== PAGE HEADER ===== -->
<div class="page-header">
    <h1><i class="fas fa-crown"></i> Edit Data Kabinet</h1>
    <p>Kelola informasi Kabinet <?php echo htmlspecialchars($kabinet['nama'] ?? 'Astawidya'); ?></p>

    <?php if ($periode_aktif): ?>
    <div class="periode-badge">
        <i class="fas fa-info-circle"></i>
        <span>Periode aktif:</span>
        <strong><?php echo htmlspecialchars($periode_aktif['nama']); ?>
            (<?php echo (int)$periode_aktif['tahun_mulai']; ?>/<?php echo (int)$periode_aktif['tahun_selesai']; ?>)
        </strong>
        <span class="warn-text">⚠️ Data kabinet berlaku untuk semua periode</span>
    </div>
    <?php endif; ?>
</div>

<?php flashMessage(); ?>

<?php /* Form hapus logo — di LUAR form utama agar tidak nested */ ?>
<?php if (!empty($kabinet['logo'])): ?>
<form method="POST" id="formHapusLogo"
      onsubmit="return confirm('Yakin ingin menghapus logo sekarang?')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete_logo">
</form>
<?php endif; ?>

<?php /* Form hapus foto bersama — di LUAR form utama */ ?>
<?php if (!empty($kabinet['foto_bersama'])): ?>
<form method="POST" id="formHapusFoto"
      onsubmit="return confirm('Yakin ingin menghapus foto bersama sekarang?')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete_foto">
</form>
<?php endif; ?>

<!-- ===== FORM UTAMA ===== -->
<form method="POST" enctype="multipart/form-data" class="admin-form" id="kabinetForm">

    <?php echo csrfField(); ?>

    <!-- Informasi Dasar -->
    <div class="form-section">
        <h2><i class="fas fa-info-circle"></i> Informasi Dasar</h2>

        <div class="form-group">
            <label for="nama">Nama Kabinet</label>
            <input type="text" id="nama" name="nama"
                   value="<?php echo htmlspecialchars($kabinet['nama'] ?? ''); ?>"
                   placeholder="Contoh: ASTAWIDYA" required>
            <small>Nama kabinet akan tampil di hero section.</small>
        </div>

        <div class="form-group">
            <label for="arti">Arti Nama</label>
            <input type="text" id="arti" name="arti"
                   value="<?php echo htmlspecialchars($kabinet['arti'] ?? ''); ?>"
                   placeholder="Contoh: Delapan Arah Kejayaan" required>
            <small>Penjelasan singkat tentang makna nama kabinet.</small>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="tahun_mulai">Tahun Mulai</label>
                <input type="number" id="tahun_mulai" name="tahun_mulai"
                       value="<?php echo (int)($kabinet['tahun_mulai'] ?? date('Y')); ?>"
                       min="2000" max="2100" required>
                <?php if ($beda_mulai): ?>
                    <small class="warn">⚠️ Berbeda dengan periode aktif (<?php echo (int)$periode_aktif['tahun_mulai']; ?>)</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="tahun_selesai">Tahun Selesai</label>
                <input type="number" id="tahun_selesai" name="tahun_selesai"
                       value="<?php echo (int)($kabinet['tahun_selesai'] ?? date('Y') + 1); ?>"
                       min="2000" max="2100" required>
                <?php if ($beda_selesai): ?>
                    <small class="warn">⚠️ Berbeda dengan periode aktif (<?php echo (int)$periode_aktif['tahun_selesai']; ?>)</small>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Logo Kabinet -->
    <div class="form-section">
        <h2><i class="fas fa-image"></i> Logo Kabinet</h2>
        <div class="form-group">

            <?php if (!empty($kabinet['logo'])): ?>
            <div class="current-image" id="logo-container">
                <span class="current-image-label">Preview</span>
                <img src="<?php echo uploadUrl($kabinet['logo']); ?>" alt="Logo Kabinet">
                <div class="image-info">
                    <small><?php echo htmlspecialchars(basename($kabinet['logo']), ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <p class="image-label">Logo saat ini</p>
                <div class="image-actions">
                    <?php /* Tombol submit ke formHapusLogo yang ada di luar */ ?>
                    <button type="submit" form="formHapusLogo" class="btn-delete-direct">
                        <i class="fas fa-trash"></i> Hapus Sekarang
                    </button>
                    <button type="button" class="btn-delete-form" onclick="tandaiHapus('logo')">
                        <i class="fas fa-clock"></i> Hapus Saat Simpan
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <input type="hidden" name="hapus_logo" id="hapus_logo" value="">

            <label for="inputLogo">Upload Logo Baru</label>
            <input type="file" id="inputLogo" name="logo"
                   accept="image/jpeg,image/png,image/gif,image/webp"
                   onchange="previewFile(this, 'logo-preview')">
            <small>Format: JPG, PNG, GIF, WebP. Maks 5MB. Kosongkan jika tidak ingin mengubah.</small>

            <div class="new-file-preview" id="logo-preview">
                <img id="logo-preview-img" alt="Preview logo baru">
                <small>Preview logo baru</small>
            </div>
        </div>
    </div>

    <!-- Foto Bersama -->
    <div class="form-section">
        <h2><i class="fas fa-users"></i> Foto Bersama</h2>
        <div class="form-group">

            <?php if (!empty($kabinet['foto_bersama'])): ?>
            <div class="current-image" id="foto-container">
                <span class="current-image-label">Preview</span>
                <img src="<?php echo uploadUrl($kabinet['foto_bersama']); ?>" alt="Foto Bersama">
                <div class="image-info">
                    <small><?php echo htmlspecialchars(basename($kabinet['foto_bersama']), ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
                <p class="image-label">Foto saat ini</p>
                <div class="image-actions">
                    <?php /* Tombol submit ke formHapusFoto yang ada di luar */ ?>
                    <button type="submit" form="formHapusFoto" class="btn-delete-direct">
                        <i class="fas fa-trash"></i> Hapus Sekarang
                    </button>
                    <button type="button" class="btn-delete-form" onclick="tandaiHapus('foto')">
                        <i class="fas fa-clock"></i> Hapus Saat Simpan
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <input type="hidden" name="hapus_foto" id="hapus_foto" value="">

            <label for="inputFoto">Upload Foto Bersama Baru</label>
            <input type="file" id="inputFoto" name="foto_bersama"
                   accept="image/jpeg,image/png,image/gif,image/webp"
                   onchange="previewFile(this, 'foto-preview')">
            <small>Format: JPG, PNG, GIF, WebP. Maks 5MB. Kosongkan jika tidak ingin mengubah.</small>

            <div class="new-file-preview" id="foto-preview">
                <img id="foto-preview-img" alt="Preview foto baru">
                <small>Preview foto baru</small>
            </div>
        </div>
    </div>

    <!-- Deskripsi -->
    <div class="form-section">
        <h2><i class="fas fa-align-left"></i> Deskripsi Kabinet</h2>
        <div class="form-group">
            <label for="deskripsi">Deskripsi</label>
            <textarea id="deskripsi" name="deskripsi" rows="5"
                      placeholder="Tuliskan deskripsi tentang kabinet..."><?php echo htmlspecialchars($kabinet['deskripsi'] ?? ''); ?></textarea>
            <small>Deskripsi singkat tentang visi dan semangat kabinet.</small>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="dashboard.php" class="btn-secondary">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-primary" id="submitBtn">
            <i class="fas fa-save"></i> Simpan Perubahan
        </button>
    </div>

</form>

<!-- JavaScript — tidak diubah -->
<script>
function previewFile(input, wrapId) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        alert('Ukuran file terlalu besar! Maksimal 5MB.');
        input.value = '';
        document.getElementById(wrapId).style.display = 'none';
        return;
    }
    const reader = new FileReader();
    reader.onload = e => {
        const wrap = document.getElementById(wrapId);
        wrap.style.display = 'block';
        wrap.querySelector('img').src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function tandaiHapus(tipe) {
    const label = tipe === 'logo' ? 'logo' : 'foto bersama';
    if (!confirm('Tandai ' + label + ' untuk dihapus saat form disimpan?')) return;
    document.getElementById('hapus_' + tipe).value = '1';
    const container = document.getElementById(tipe + '-container');
    if (container) {
        container.classList.add('marked-delete');
        const warn = document.createElement('p');
        warn.className = 'delete-warn';
        warn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Akan dihapus saat Simpan';
        container.appendChild(warn);
    }
}

document.getElementById('kabinetForm').addEventListener('submit', function (e) {
    const nama         = document.getElementById('nama').value.trim();
    const arti         = document.getElementById('arti').value.trim();
    const tahunMulai   = parseInt(document.getElementById('tahun_mulai').value);
    const tahunSelesai = parseInt(document.getElementById('tahun_selesai').value);
    const btn          = document.getElementById('submitBtn');

    if (!nama || !arti) {
        e.preventDefault();
        alert('Nama kabinet dan arti nama wajib diisi!');
        return;
    }
    if (tahunSelesai <= tahunMulai) {
        e.preventDefault();
        alert('Tahun selesai harus lebih besar dari tahun mulai!');
        return;
    }
    if (btn.classList.contains('loading')) { e.preventDefault(); return; }
    btn.classList.add('loading');
    btn.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
    btn.disabled = true;
    formChanged = false;
});

let formChanged = false;
document.getElementById('kabinetForm').addEventListener('change', () => formChanged = true);
window.addEventListener('beforeunload', e => {
    if (formChanged) { e.preventDefault(); e.returnValue = ''; }
});
</script>

<link rel="stylesheet" href="css/kabinet.css">

<?php require_once __DIR__ . '/footer.php'; ?>