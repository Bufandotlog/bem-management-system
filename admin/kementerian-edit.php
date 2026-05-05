<?php
// admin/kementerian-edit.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: Hapus logo pindah dari GET ke POST (form di luar form utama)
//   CHANGED: Hapus kementerian pindah dari GET ke POST
//   CHANGED: sanitizeText() untuk nama dan deskripsi
//   CHANGED: Sanitasi array tugas[] dan proker[]
//   CHANGED: Validasi nama tidak boleh kosong di PHP
//   CHANGED: Error message generic (tidak bocorkan detail exception)
//   CHANGED: Redirect ke admin/kepengurusan.php bukan root
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

$id = (int) ($_GET['id'] ?? 0);

$kementerian = null;
if ($id) {
    $kementerian = dbFetchOne(
        "SELECT * FROM kementerian WHERE id = ? AND periode_id = ?",
        [$id, $active_periode], "ii"
    );
    if (!$kementerian) {
        redirect('admin/kepengurusan.php', 'Kementerian tidak ditemukan atau bukan milik periode ini!', 'error');
        exit();
    }
}

// ============================================
// AKSI POST: Hapus logo
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_hapus_logo'])) {
    if (!csrfVerify()) {
        redirect('admin/kementerian-edit.php?id=' . $id, 'Request tidak valid.', 'error');
        exit();
    }
    if ($kementerian && !empty($kementerian['logo'])) {
        deleteFile($kementerian['logo']);
        dbQuery("UPDATE kementerian SET logo = NULL WHERE id = ? AND periode_id = ?",
                [$id, $active_periode], "ii");
    }
    redirect('admin/kementerian-edit.php?id=' . $id, 'Logo berhasil dihapus', 'success');
    exit();
}

// ============================================
// PROSES SUBMIT FORM UTAMA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action_hapus_logo'])) {

    if (!csrfVerify()) {
        redirect('admin/kementerian-edit.php' . ($id ? "?id={$id}" : ''), 'Request tidak valid.', 'error');
        exit();
    }

    $nama      = sanitizeText($_POST['nama']      ?? '', 100);
    $deskripsi = sanitizeText($_POST['deskripsi'] ?? '', 1000);

    if (empty($nama)) {
        redirect('admin/kementerian-edit.php' . ($id ? "?id={$id}" : ''),
                 'Nama kementerian tidak boleh kosong.', 'error');
        exit();
    }

    $base_slug = createSlug($nama);
    $slug      = $base_slug . '-' . $active_periode;

    $logo = $kementerian['logo'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadFile($_FILES['logo'], 'struktur');
        if ($uploadResult) {
            if (!empty($logo)) deleteFile($logo);
            $logo = $uploadResult;
        }
    }

    $tugas = array_values(array_filter(
        array_map(fn($v) => sanitizeText($v, 200), $_POST['tugas'] ?? []),
        fn($v) => !empty($v)
    ));
    $proker = array_values(array_filter(
        array_map(fn($v) => sanitizeText($v, 200), $_POST['proker'] ?? []),
        fn($v) => !empty($v)
    ));

    $tugas_json  = !empty($tugas)  ? json_encode($tugas)  : null;
    $proker_json = !empty($proker) ? json_encode($proker) : null;

    dbBeginTransaction();
    try {
        if ($id) {
            dbQuery(
                "UPDATE kementerian SET nama=?, slug=?, logo=?, deskripsi=?, tugas=?, proker=?
                 WHERE id=? AND periode_id=?",
                [$nama, $slug, $logo, $deskripsi, $tugas_json, $proker_json, $id, $active_periode],
                "ssssssii"
            );
            dbCommit();
            auditLog('UPDATE', 'kementerian', $id, 'Edit kementerian: ' . $nama);
            redirect('admin/kementerian-anggota.php?id=' . $id, 'Kementerian berhasil diupdate!', 'success');
        } else {
            dbQuery(
                "INSERT INTO kementerian (periode_id, created_by, nama, slug, logo, deskripsi, tugas, proker)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$active_periode, $_SESSION['admin_id'], $nama, $slug, $logo, $deskripsi, $tugas_json, $proker_json],
                "iissssss"
            );
            $new_id = dbLastId();
            dbCommit();
            auditLog('CREATE', 'kementerian', $new_id, 'Tambah kementerian: ' . $nama);
            redirect('admin/kementerian-anggota.php?id=' . $new_id, 'Kementerian berhasil ditambah!', 'success');
        }
    } catch (Exception $e) {
        dbRollback();
        error_log("[KEMENTERIAN-EDIT] " . $e->getMessage());
        redirect('admin/kementerian-edit.php' . ($id ? "?id={$id}" : ''),
                 'Gagal menyimpan. Silakan coba lagi.', 'error');
    }
    exit();
}

// Persiapan data tampilan
$tugas_list  = [];
$proker_list = [];
if ($kementerian) {
    if (!empty($kementerian['tugas']))  $tugas_list  = json_decode($kementerian['tugas'],  true) ?: [];
    if (!empty($kementerian['proker'])) $proker_list = json_decode($kementerian['proker'], true) ?: [];
}

$periode_info = ($periode_data['nama'] ?? 'Astawidya')
              . ' (' . ($periode_data['tahun_mulai'] ?? '2025')
              . '/' . ($periode_data['tahun_selesai'] ?? '2026') . ')';
?>

<!-- ===== PAGE HEADER ===== -->
<div class="page-header">
    <h1>
        <i class="fas fa-building"></i>
        <?php echo $id ? 'Edit' : 'Tambah'; ?> Kementerian
    </h1>
    <p><?php echo $id ? 'Edit data kementerian' : 'Tambah kementerian baru untuk periode ini'; ?></p>
    <span class="periode-badge">
        <i class="fas fa-calendar-alt"></i>
        Periode: <?php echo htmlspecialchars($periode_info); ?>
    </span>
</div>

<?php flashMessage(); ?>

<?php /* Form hapus logo — di LUAR form utama agar tidak nested */ ?>
<?php if ($id && $kementerian && !empty($kementerian['logo'])): ?>
<form method="POST" id="formHapusLogo"
      onsubmit="return confirm('Yakin ingin menghapus logo ini?')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_hapus_logo" value="1">
</form>
<?php endif; ?>

<?php if ($id && $kementerian): ?>
<div class="delete-row">
    <?php /* Hapus kementerian via POST + CSRF — bukan GET link */ ?>
    <form method="POST" action="kepengurusan.php" style="display:inline"
          onsubmit="return confirm('Yakin ingin menghapus kementerian ini? Semua anggota juga akan ikut terhapus.')">
        <?php echo csrfField(); ?>
        <input type="hidden" name="hapus_kementerian_id" value="<?php echo $id; ?>">
        <button type="submit" class="btn-delete">
            <i class="fas fa-trash"></i> Hapus Kementerian
        </button>
    </form>
</div>
<?php endif; ?>

<!-- ===== FORM UTAMA ===== -->
<form method="POST" enctype="multipart/form-data" class="admin-form">

    <?php echo csrfField(); ?>

    <!-- Informasi Dasar -->
    <div class="form-section">
        <h2><i class="fas fa-info-circle"></i> Informasi Dasar</h2>
        <div class="form-group">
            <label>Nama Kementerian</label>
            <input type="text" name="nama"
                   value="<?php echo htmlspecialchars($kementerian['nama'] ?? ''); ?>"
                   placeholder="Masukkan nama kementerian..." required>
        </div>
        <div class="form-group">
            <label>Slug (URL)</label>
            <input type="text"
                   value="<?php echo $kementerian ? htmlspecialchars($kementerian['slug'] ?? '') : '(akan dibuat otomatis)'; ?>"
                   disabled readonly>
            <small>Dibuat otomatis dari nama + periode agar unik antar periode.</small>
        </div>
    </div>

    <!-- Logo -->
    <div class="form-section">
        <h2><i class="fas fa-image"></i> Logo</h2>
        <div class="form-group">
            <?php if (!empty($kementerian['logo'])): ?>
                <div class="current-image">
                    <span class="current-image-label">Preview</span>
                    <img src="<?php echo uploadUrl($kementerian['logo']); ?>"
                         alt="Logo <?php echo htmlspecialchars($kementerian['nama']); ?>">
                    <p>Logo saat ini</p>
                    <?php /* Tombol submit ke formHapusLogo yang ada di luar */ ?>
                    <button type="submit" form="formHapusLogo" class="btn-delete-small">
                        <i class="fas fa-trash"></i> Hapus Logo
                    </button>
                </div>
            <?php endif; ?>
            <div class="upload-section">
                <label for="inputLogo">Upload Logo Baru</label>
                <input type="file" name="logo" id="inputLogo" accept="image/*">
                <small>Format: JPG, PNG, WebP. Maks 2MB. Kosongkan jika tidak ingin mengubah.</small>
            </div>
        </div>
    </div>

    <!-- Deskripsi -->
    <div class="form-section">
        <h2><i class="fas fa-align-left"></i> Deskripsi</h2>
        <div class="form-group">
            <textarea name="deskripsi" rows="4"
                      placeholder="Deskripsi kementerian..."><?php echo htmlspecialchars($kementerian['deskripsi'] ?? ''); ?></textarea>
        </div>
    </div>

    <!-- Tugas Pokok -->
    <div class="form-section">
        <h2><i class="fas fa-tasks"></i> Tugas Pokok</h2>
        <div class="form-group">
            <div id="tugasContainer">
                <?php foreach (!empty($tugas_list) ? $tugas_list : [''] as $i => $item): ?>
                <div class="list-item">
                    <input type="text" name="tugas[]"
                           value="<?php echo htmlspecialchars($item); ?>"
                           placeholder="Tugas <?php echo $i + 1; ?>">
                    <button type="button" class="btn-remove" onclick="hapusItem(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add"
                    onclick="tambahItem('tugasContainer', 'tugas[]', 'Tugas')">
                <i class="fas fa-plus"></i> Tambah Tugas
            </button>
        </div>
    </div>

    <!-- Program Kerja -->
    <div class="form-section">
        <h2><i class="fas fa-calendar-alt"></i> Program Kerja</h2>
        <div class="form-group">
            <div id="prokerContainer">
                <?php foreach (!empty($proker_list) ? $proker_list : [''] as $i => $item): ?>
                <div class="list-item">
                    <input type="text" name="proker[]"
                           value="<?php echo htmlspecialchars($item); ?>"
                           placeholder="Program Kerja <?php echo $i + 1; ?>">
                    <button type="button" class="btn-remove" onclick="hapusItem(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add"
                    onclick="tambahItem('prokerContainer', 'proker[]', 'Program Kerja')">
                <i class="fas fa-plus"></i> Tambah Program Kerja
            </button>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="kepengurusan.php" class="btn-secondary">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-primary" id="submitBtn">
            <i class="fas fa-save"></i>
            <?php echo $id ? 'Simpan Perubahan' : 'Simpan & Lanjut ke Anggota'; ?>
        </button>
    </div>

</form>

<!-- JavaScript — tidak diubah -->
<script>
function tambahItem(containerId, fieldName, label) {
    const container = document.getElementById(containerId);
    const index = container.children.length + 1;
    const div = document.createElement('div');
    div.className = 'list-item';
    div.innerHTML =
        `<input type="text" name="${fieldName}" placeholder="${label} ${index}">` +
        `<button type="button" class="btn-remove" onclick="hapusItem(this)" title="Hapus">×</button>`;
    container.appendChild(div);
    div.querySelector('input').focus();
}

function hapusItem(btn) {
    const item = btn.closest('.list-item');
    const container = item.parentElement;
    if (container.children.length > 1) {
        item.remove();
    } else {
        item.querySelector('input').value = '';
    }
}

document.getElementById('submitBtn')?.addEventListener('click', function () {
    if (this.classList.contains('loading')) return;
    this.classList.add('loading');
    this.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
});
</script>

<link rel="stylesheet" href="css/kementerian-edit.css">

<?php require_once __DIR__ . '/footer.php'; ?>