<?php
// admin/berita-edit.php
// VERSI: 4.3 - AUDIT LOG
//   CHANGED: auditLog() setelah UPDATE dan INSERT berita — sebelum redirect()
//   UNCHANGED: Semua logika, HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

$id     = (int) ($_GET['id'] ?? 0);
$berita = null;

if ($id) {
    $periode_id = (int) getUserPeriode();
    $berita = dbFetchOne(
        "SELECT * FROM berita WHERE id = ? AND periode_id = ?",
        [$id, $periode_id], "ii"
    );
    if (!$berita) {
        redirect('admin/berita.php', 'Berita tidak ditemukan atau akses ditolak.', 'error');
        exit();
    }
}

// Proses hapus foto — POST + CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_hapus_foto'])) {
    if (!csrfVerify()) {
        redirect('admin/berita-edit.php?id=' . $id, 'Request tidak valid.', 'error');
        exit();
    }
    if ($berita && !empty($berita['gambar'])) {
        deleteFile($berita['gambar']);
        dbQuery("UPDATE berita SET gambar = NULL WHERE id = ?", [$id], "i");
        auditLog('UPDATE', 'berita', $id, 'Hapus foto berita: ' . ($berita['judul'] ?? ''));
    }
    redirect('admin/berita-edit.php?id=' . $id, 'Foto berita berhasil dihapus!', 'success');
    exit();
}

// Proses submit form utama
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action_hapus_foto'])) {

    if (!csrfVerify()) {
        redirect('admin/berita-edit.php' . ($id ? "?id={$id}" : ''), 'Request tidak valid.', 'error');
        exit();
    }

    $judul   = sanitizeText($_POST['judul']   ?? '', 255);
    $penulis = sanitizeText($_POST['penulis'] ?? '', 100);
    $konten  = sanitizeHtml($_POST['konten']  ?? '');

    $tanggal = $_POST['tanggal'] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ||
        !checkdate((int)substr($tanggal,5,2), (int)substr($tanggal,8,2), (int)substr($tanggal,0,4))) {
        $tanggal = date('Y-m-d');
    }

    if (empty($judul) || empty($penulis) || empty($konten)) {
        redirect('admin/berita-edit.php' . ($id ? "?id={$id}" : ''), 'Judul, penulis, dan konten tidak boleh kosong.', 'error');
        exit();
    }

    $slug     = createSlug($judul);
    $baseSlug = $slug;
    $suffix   = 1;
    while (dbFetchOne("SELECT id FROM berita WHERE slug = ? AND id != ?", [$slug, $id ?: 0], "si")) {
        $slug = $baseSlug . '-' . $suffix++;
    }

    $gambar = $berita['gambar'] ?? '';

    if (isset($_POST['hapus_foto_via_form']) && $_POST['hapus_foto_via_form'] === '1') {
        if (!empty($gambar)) { deleteFile($gambar); $gambar = ''; }
    }

    if (isset($_FILES['gambar']) && $_FILES['gambar']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadFile($_FILES['gambar'], 'berita');
        if ($uploadResult) {
            if (!empty($gambar)) deleteFile($gambar);
            $gambar = $uploadResult;
        }
    }

    $periode_id = (int) getUserPeriode();

    if ($id) {
        dbQuery(
            "UPDATE berita SET judul=?, slug=?, tanggal=?, penulis=?, gambar=?, konten=? WHERE id=? AND periode_id=?",
            [$judul, $slug, $tanggal, $penulis, $gambar, $konten, $id, $periode_id],
            "ssssssii"
        );
        auditLog('UPDATE', 'berita', $id, 'Edit berita: ' . $judul);
        redirect('admin/berita.php', 'Berita berhasil diperbarui!', 'success');
    } else {
        dbQuery(
            "INSERT INTO berita (judul, slug, tanggal, penulis, gambar, konten, periode_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$judul, $slug, $tanggal, $penulis, $gambar, $konten, $periode_id],
            "ssssssi"
        );
        $newId = dbLastInsertId();
        auditLog('CREATE', 'berita', $newId, 'Tambah berita: ' . $judul);
        redirect('admin/berita.php', 'Berita berhasil ditambahkan!', 'success');
    }
}
?>

<!-- Page Header -->
<div class="page-header">
    <h1><i class="fas fa-<?php echo $id ? 'edit' : 'plus-circle'; ?>"></i>
        <?php echo $id ? 'Edit' : 'Tambah'; ?> Berita
    </h1>
    <p><?php echo $id ? 'Perbarui' : 'Tulis'; ?> berita untuk website BEM</p>
</div>

<?php flashMessage(); ?>

<?php /* Form hapus foto — di LUAR form utama agar tidak nested */ ?>
<?php if (!empty($berita['gambar'])): ?>
<form method="POST" id="formHapusFoto"
      onsubmit="return confirm('Yakin ingin menghapus foto ini?')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_hapus_foto" value="1">
</form>
<?php endif; ?>

<!-- Main Form -->
<form method="POST" enctype="multipart/form-data" class="admin-form" id="beritaForm">

    <?php echo csrfField(); ?>

    <!-- Informasi Dasar -->
    <div class="form-section">
        <h2><i class="fas fa-info-circle"></i> Informasi Berita</h2>

        <div class="form-group">
            <label for="judul">Judul Berita</label>
            <input type="text" id="judul" name="judul"
                   value="<?php echo htmlspecialchars($berita['judul'] ?? ''); ?>"
                   required placeholder="Masukkan judul berita">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="tanggal">Tanggal Publikasi</label>
                <input type="date" id="tanggal" name="tanggal"
                       value="<?php echo htmlspecialchars($berita['tanggal'] ?? date('Y-m-d')); ?>" required>
            </div>
            <div class="form-group">
                <label for="penulis">Penulis</label>
                <input type="text" id="penulis" name="penulis"
                       value="<?php echo htmlspecialchars($berita['penulis'] ?? 'Tim Kominfo'); ?>"
                       required placeholder="Nama penulis">
            </div>
        </div>
    </div>

    <!-- Gambar -->
    <div class="form-section">
        <h2><i class="fas fa-image"></i> Gambar Berita</h2>

        <div class="form-group">
            <?php if (!empty($berita['gambar'])): ?>
                <div class="current-image" id="gambar-container">
                    <img src="<?php echo uploadUrl($berita['gambar']); ?>"
                         alt="Preview Gambar">

                    <div class="image-info">
                        <small>File: <?php echo htmlspecialchars(basename($berita['gambar'])); ?></small>
                    </div>

                    <div class="image-actions">
                        <button type="submit" form="formHapusFoto" class="btn-delete-direct">
                            <i class="fas fa-trash"></i> Hapus Langsung
                        </button>
                        <button type="button" class="btn-delete-form" onclick="hapusFotoViaForm()">
                            <i class="fas fa-clock"></i> Hapus via Form
                        </button>
                    </div>

                    <input type="hidden" name="hapus_foto_via_form" id="hapus_foto_via_form" value="0">
                    <p class="image-label">Gambar saat ini</p>
                </div>
            <?php endif; ?>

            <label for="gambar">Upload Gambar Baru</label>
            <input type="file" id="gambar" name="gambar" accept="image/*" onchange="previewGambarBaru(this)">
            <small>
                <i class="fas fa-info-circle"></i>
                Format: JPG, PNG, GIF. Maksimal 5MB.
                <?php if (!empty($berita['gambar'])): ?>
                    Kosongkan jika tidak ingin mengubah gambar.
                <?php endif; ?>
            </small>

            <div id="gambar-preview-container" style="display:none;margin-top:15px;">
                <p>Preview Gambar Baru:</p>
                <img id="gambar-preview" src="#" alt="Preview"
                     style="max-width:200px;max-height:150px;border:2px solid #4A90E2;padding:5px;border-radius:5px;">
            </div>
        </div>
    </div>

    <!-- Konten -->
    <div class="form-section">
        <h2><i class="fas fa-align-left"></i> Konten Berita</h2>

        <div class="form-group">
            <label for="konten">Isi Berita (textarea biasa)</label>
            <textarea name="konten" id="konten" rows="15"
                      style="width:100%;padding:15px;background:#222;color:#fff;border:1px solid #333;border-radius:5px;font-family:inherit;line-height:1.6;"><?php echo htmlspecialchars($berita['konten'] ?? ''); ?></textarea>
            <small>Gunakan textarea biasa. Format teks bisa menggunakan HTML.</small>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="berita.php" class="btn-secondary">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-primary" id="submitBtn">
            <i class="fas fa-save"></i> Simpan Berita
        </button>
    </div>
</form>

<!-- JavaScript — tidak diubah -->
<script>
function hapusFotoViaForm() {
    if (confirm('Tandai foto untuk dihapus? (Penghapusan akan terjadi saat form disimpan)')) {
        document.getElementById('hapus_foto_via_form').value = '1';
        const container = document.getElementById('gambar-container');
        if (container) {
            container.style.opacity = '0.5';
            container.style.pointerEvents = 'none';
        }
        alert('Foto akan dihapus saat Anda menekan tombol Simpan');
    }
}

function previewGambarBaru(input) {
    const previewContainer = document.getElementById('gambar-preview-container');
    const preview = document.getElementById('gambar-preview');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            previewContainer.style.display = 'block';
        }
        reader.readAsDataURL(input.files[0]);
        const fileSize = input.files[0].size / 1024 / 1024;
        if (fileSize > 5) {
            alert('Ukuran file terlalu besar! Maksimal 5MB');
            input.value = '';
            previewContainer.style.display = 'none';
        }
    } else {
        previewContainer.style.display = 'none';
    }
}

document.getElementById('beritaForm').addEventListener('submit', function(e) {
    const judul   = document.getElementById('judul').value.trim();
    const tanggal = document.getElementById('tanggal').value;
    const penulis = document.getElementById('penulis').value.trim();
    const konten  = document.getElementById('konten').value.trim();
    if (!judul || !tanggal || !penulis || !konten) {
        e.preventDefault();
        alert('Semua field harus diisi!');
        return false;
    }
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.classList.add('loading');
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    return true;
});
</script>

<link rel="stylesheet" href="css/berita-edit.css">

<?php require_once __DIR__ . '/footer.php'; ?>