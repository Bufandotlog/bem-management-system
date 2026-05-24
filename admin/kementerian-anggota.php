<?php
// admin/kementerian-anggota.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token + validasi di semua POST handler
//   CHANGED: Filter periode_id di query kementerian
//   CHANGED: Validasi kepemilikan delete_ids dengan AND kementerian_id
//   CHANGED: sanitizeText() untuk nama dan jabatan
//   CHANGED: Batasi max 100 anggota
//   CHANGED: Redirect ke admin/kementerian-anggota.php bukan root
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

$kementerian_id = (int) ($_GET['id'] ?? 0);
if (!$kementerian_id) {
    redirect('admin/kepengurusan.php', 'ID kementerian tidak valid', 'error');
    exit();
}

// Ambil data kementerian — filter periode_id
$kementerian = dbFetchOne(
    "SELECT * FROM kementerian WHERE id = ? AND periode_id = ?",
    [$kementerian_id, $active_periode], "ii"
);
if (!$kementerian) {
    redirect('admin/kepengurusan.php', 'Kementerian tidak ditemukan atau akses ditolak', 'error');
    exit();
}

// Ambil anggota
$anggota = dbFetchAll(
    "SELECT * FROM anggota_kementerian WHERE kementerian_id = ? ORDER BY urutan",
    [$kementerian_id], "i"
);

// ============================================
// PROSES SUBMIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify()) {
        redirect('admin/kementerian-anggota.php?id=' . $kementerian_id, 'Request tidak valid.', 'error');
        exit();
    }

    // Hapus anggota tunggal via action=delete
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $anggota_id = (int) $_POST['anggota_id'];
        // Validasi kepemilikan — harus milik kementerian ini
        $anggota_data = dbFetchOne(
            "SELECT foto FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
            [$anggota_id, $kementerian_id], "ii"
        );
        if ($anggota_data) {
            if (!empty($anggota_data['foto'])) deleteFile($anggota_data['foto']);
            dbQuery("DELETE FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
                    [$anggota_id, $kementerian_id], "ii");
        }
        redirect('admin/kementerian-anggota.php?id=' . $kementerian_id, 'Anggota berhasil dihapus', 'success');
        exit();
    }

    // Hapus yang dicentang (delete_ids[])
    $deleted_ids = [];
    if (!empty($_POST['delete_ids'])) {
        foreach ($_POST['delete_ids'] as $delete_id) {
            $delete_id = (int) $delete_id;
            // Validasi kepemilikan sebelum hapus
            $old = dbFetchOne(
                "SELECT foto FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
                [$delete_id, $kementerian_id], "ii"
            );
            if (!$old) continue; // Bukan miliknya — skip
            if (!empty($old['foto'])) deleteFile($old['foto']);
            dbQuery("DELETE FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
                    [$delete_id, $kementerian_id], "ii");
            $deleted_ids[] = $delete_id;
        }
    }

    // Simpan / update setiap baris — batasi max 100 anggota
    $nama_list = array_slice($_POST['nama'] ?? [], 0, 100);

    foreach ($nama_list as $index => $nama) {
        $nama = sanitizeText($nama, 100);
        if (empty($nama)) continue;

        $jabatan    = sanitizeText($_POST['jabatan'][$index] ?? '', 100);
        $anggota_id = (int) ($_POST['anggota_id'][$index] ?? 0);

        if (in_array($anggota_id, $deleted_ids)) continue;

        $foto = '';
        if ($anggota_id > 0) {
            $existing = dbFetchOne(
                "SELECT foto FROM anggota_kementerian WHERE id = ? AND kementerian_id = ?",
                [$anggota_id, $kementerian_id], "ii"
            );
            $foto = $existing['foto'] ?? '';
        }

        $ada_upload = isset($_FILES['foto']['name'][$index])
                   && $_FILES['foto']['error'][$index] === UPLOAD_ERR_OK;

        if ($ada_upload) {
            $file = [
                'name'     => $_FILES['foto']['name'][$index],
                'type'     => $_FILES['foto']['type'][$index],
                'tmp_name' => $_FILES['foto']['tmp_name'][$index],
                'error'    => $_FILES['foto']['error'][$index],
                'size'     => $_FILES['foto']['size'][$index],
            ];
            $upload_result = uploadFile($file, 'struktur');
            if ($upload_result) {
                if (!empty($foto)) deleteFile($foto);
                $foto = $upload_result;
            }
        }

        if ($anggota_id > 0) {
            dbQuery(
                "UPDATE anggota_kementerian SET nama=?, jabatan=?, foto=?, urutan=? WHERE id=? AND kementerian_id=?",
                [$nama, $jabatan, $foto, $index, $anggota_id, $kementerian_id],
                "sssiii"
            );
        } else {
            dbQuery(
                "INSERT INTO anggota_kementerian (periode_id, created_by, kementerian_id, nama, jabatan, foto, urutan)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$kementerian['periode_id'], $_SESSION['admin_id'], $kementerian_id, $nama, $jabatan, $foto, $index],
                "iiisssi"
            );
        }
    }

    auditLog('UPDATE', 'anggota_kementerian', $kementerian_id, 'Edit anggota kementerian: ' . $kementerian['nama']);
    redirect('admin/kementerian-anggota.php?id=' . $kementerian_id, 'Data anggota berhasil disimpan!', 'success');
    exit();
}
?>

<!-- ===== PAGE HEADER ===== -->
<div class="page-header">
    <h1>
        <i class="fas fa-users"></i>
        Anggota: <?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?>
    </h1>
    <p>Kelola daftar anggota kementerian ini</p>
</div>

<!-- ===== HEADER ACTIONS ===== -->
<div class="header-actions">
    <a href="kementerian-edit.php?id=<?php echo $kementerian_id; ?>" class="btn-secondary">
        <i class="fas fa-edit"></i> Edit Kementerian
    </a>
    <a href="kepengurusan.php" class="btn-secondary">
        <i class="fas fa-arrow-left"></i> Kembali
    </a>
</div>

<?php flashMessage(); ?>

<!-- ===== FORM ===== -->
<form method="POST" enctype="multipart/form-data" class="admin-form">

    <?php echo csrfField(); ?>

    <div id="anggotaContainer">
        <?php if (!empty($anggota)): ?>
            <?php foreach ($anggota as $index => $a): ?>
            <div class="anggota-item" data-id="<?php echo (int)$a['id']; ?>">
                <input type="hidden" name="anggota_id[]" value="<?php echo (int)$a['id']; ?>">

                <div class="anggota-photo-preview">
                    <?php if (!empty($a['foto'])): ?>
                        <img src="<?php echo uploadUrl($a['foto']); ?>"
                             class="preview-img"
                             alt="Foto <?php echo htmlspecialchars($a['nama'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php else: ?>
                        <div class="preview-placeholder">
                            <i class="fas fa-user"></i>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="anggota-fields">
                    <input type="text" name="nama[]"
                           value="<?php echo htmlspecialchars($a['nama'], ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="Nama Lengkap" required>
                    <input type="text" name="jabatan[]"
                           value="<?php echo htmlspecialchars($a['jabatan'], ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="Jabatan" required>
                </div>

                <div class="anggota-foto-input">
                    <input type="file" name="foto[]" accept="image/*">
                    <?php if (!empty($a['foto'])): ?>
                        <span class="foto-note">
                            <i class="fas fa-info-circle"></i>
                            Kosongkan jika tidak ingin mengubah foto
                        </span>
                    <?php endif; ?>
                </div>

                <div class="anggota-actions">
                    <label class="checkbox-label" title="Centang untuk menghapus saat disimpan">
                        <input type="checkbox" name="delete_ids[]" value="<?php echo (int)$a['id']; ?>">
                        <span>Hapus</span>
                    </label>
                    <button type="button" class="btn-remove"
                            onclick="hapusAnggotaItem(this)"
                            title="Hilangkan dari form">×</button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="anggota-item">
                <input type="hidden" name="anggota_id[]" value="0">
                <div class="anggota-photo-preview">
                    <div class="preview-placeholder"><i class="fas fa-user"></i></div>
                </div>
                <div class="anggota-fields">
                    <input type="text" name="nama[]" placeholder="Nama Lengkap" required>
                    <input type="text" name="jabatan[]" placeholder="Jabatan" required>
                </div>
                <div class="anggota-foto-input">
                    <input type="file" name="foto[]" accept="image/*">
                </div>
                <div class="anggota-actions">
                    <button type="button" class="btn-remove"
                            onclick="hapusAnggotaItem(this)"
                            title="Hilangkan dari form">×</button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <button type="button" class="btn-add" onclick="tambahAnggota()">
        <i class="fas fa-user-plus"></i> Tambah Anggota
    </button>

    <div class="form-actions">
        <a href="kepengurusan.php" class="btn-secondary">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-primary" id="submitBtn">
            <i class="fas fa-save"></i> Simpan Semua
        </button>
    </div>

</form>

<!-- JavaScript — tidak diubah -->
<script>
function tambahAnggota() {
    const container = document.getElementById('anggotaContainer');
    const div = document.createElement('div');
    div.className = 'anggota-item';
    div.innerHTML =
        `<input type="hidden" name="anggota_id[]" value="0">` +
        `<div class="anggota-photo-preview">` +
            `<div class="preview-placeholder"><i class="fas fa-user"></i></div>` +
        `</div>` +
        `<div class="anggota-fields">` +
            `<input type="text" name="nama[]" placeholder="Nama Lengkap" required>` +
            `<input type="text" name="jabatan[]" placeholder="Jabatan" required>` +
        `</div>` +
        `<div class="anggota-foto-input">` +
            `<input type="file" name="foto[]" accept="image/*">` +
        `</div>` +
        `<div class="anggota-actions">` +
            `<button type="button" class="btn-remove" onclick="hapusAnggotaItem(this)" title="Hilangkan dari form">×</button>` +
        `</div>`;
    container.appendChild(div);
    div.querySelector('input[name="nama[]"]').focus();
}

function hapusAnggotaItem(btn) {
    const item = btn.closest('.anggota-item');
    const container = document.getElementById('anggotaContainer');
    if (container.children.length > 1) {
        item.remove();
    } else {
        alert('Minimal harus ada satu baris anggota.');
    }
}

document.addEventListener('change', function (e) {
    if (e.target.type !== 'file' || e.target.name !== 'foto[]') return;
    const file = e.target.files[0];
    if (!file) return;
    const preview = e.target.closest('.anggota-item').querySelector('.anggota-photo-preview');
    const reader = new FileReader();
    reader.onload = ev => {
        preview.innerHTML = `<img src="${ev.target.result}" class="preview-img" alt="Preview">`;
    };
    reader.readAsDataURL(file);
});

document.getElementById('submitBtn').addEventListener('click', function () {
    if (this.classList.contains('loading')) return;
    this.classList.add('loading');
    this.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
});
</script>

<link rel="stylesheet" href="css/kementerian-anggota.css">

<?php require_once __DIR__ . '/footer.php'; ?>