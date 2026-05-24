<?php
// admin/visi-misi.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: sanitizeText() untuk visi dan array misi
//   CHANGED: Batasi max 20 misi
//   CHANGED: Validasi visi dan minimal 1 misi tidak kosong di PHP
//   CHANGED: Redirect ke admin/visi-misi.php bukan root
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

$visiMisi = dbFetchOne("SELECT * FROM visi_misi WHERE id = 1");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!csrfVerify()) {
        redirect('admin/visi-misi.php', 'Request tidak valid.', 'error');
        exit();
    }

    $visi = sanitizeText($_POST['visi'] ?? '', 1000);

    // Batasi max 20 misi, sanitasi setiap item
    $misi = array_values(array_filter(
        array_map(
            fn($v) => sanitizeText($v, 300),
            array_slice($_POST['misi'] ?? [], 0, 20)
        ),
        fn($v) => !empty($v)
    ));

    // Validasi di PHP — tidak hanya JavaScript
    if (empty($visi)) {
        redirect('admin/visi-misi.php', 'Visi tidak boleh kosong.', 'error');
        exit();
    }
    if (empty($misi)) {
        redirect('admin/visi-misi.php', 'Minimal satu misi harus diisi.', 'error');
        exit();
    }

    $misi_json = json_encode($misi);

    if ($visiMisi) {
        dbQuery("UPDATE visi_misi SET visi = ?, misi = ? WHERE id = 1", [$visi, $misi_json], "ss");
    } else {
        dbQuery("INSERT INTO visi_misi (visi, misi) VALUES (?, ?)", [$visi, $misi_json], "ss");
    }

    auditLog('UPDATE', 'visi_misi', 1, 'Edit visi misi');
    redirect('admin/visi-misi.php', 'Visi misi berhasil diperbarui!', 'success');
    exit();
}

$misiList = [];
if ($visiMisi && !empty($visiMisi['misi'])) {
    $misiList = json_decode($visiMisi['misi'], true) ?: [];
}
?>

<!-- ===== PAGE HEADER ===== -->
<div class="page-header">
    <h1><i class="fas fa-bullseye"></i> Edit Visi & Misi</h1>
    <p>Kelola visi dan misi Kabinet <?php echo htmlspecialchars($kabinet['nama'] ?? 'Astawidya'); ?></p>
</div>

<?php flashMessage(); ?>

<!-- ===== FORM ===== -->
<form method="POST" class="admin-form" id="visiMisiForm">

    <?php echo csrfField(); ?>

    <!-- Visi -->
    <div class="form-section">
        <h2><i class="fas fa-eye"></i> Visi</h2>
        <div class="form-group">
            <label for="visi">Pernyataan Visi</label>
            <textarea id="visi" name="visi" rows="5" required
                      placeholder="Tuliskan visi organisasi..."><?php echo htmlspecialchars($visiMisi['visi'] ?? ''); ?></textarea>
            <small>Visi adalah gambaran masa depan yang ingin dicapai organisasi.</small>
        </div>
    </div>

    <!-- Misi -->
    <div class="form-section">
        <h2><i class="fas fa-list-ul"></i> Misi</h2>
        <div class="form-group">
            <label>Daftar Misi</label>
            <div id="misiContainer">
                <?php foreach (!empty($misiList) ? $misiList : [''] as $i => $item): ?>
                <div class="misi-item">
                    <input type="text" name="misi[]"
                           value="<?php echo htmlspecialchars($item); ?>"
                           placeholder="Misi <?php echo $i + 1; ?>">
                    <button type="button" class="btn-remove" onclick="hapusMisi(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add" onclick="tambahMisi()">
                <i class="fas fa-plus"></i> Tambah Misi
            </button>
            <small>Setiap poin misi akan otomatis diberi nomor urut. Maksimal 20 misi.</small>
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
function tambahMisi() {
    const container = document.getElementById('misiContainer');
    if (container.children.length >= 20) {
        alert('Maksimal 20 misi.');
        return;
    }
    const index = container.children.length + 1;
    const div = document.createElement('div');
    div.className = 'misi-item';
    div.innerHTML =
        `<input type="text" name="misi[]" placeholder="Misi ${index}">` +
        `<button type="button" class="btn-remove" onclick="hapusMisi(this)" title="Hapus">×</button>`;
    container.appendChild(div);
    div.querySelector('input').focus();
}

function hapusMisi(btn) {
    const item = btn.closest('.misi-item');
    const container = document.getElementById('misiContainer');
    if (container.children.length > 1) {
        item.remove();
    } else {
        item.querySelector('input').value = '';
    }
}

document.getElementById('submitBtn').addEventListener('click', function (e) {
    const inputs = document.querySelectorAll('input[name="misi[]"]');
    const adaIsi = Array.from(inputs).some(i => i.value.trim() !== '');
    if (!adaIsi) {
        e.preventDefault();
        alert('Minimal satu misi harus diisi!');
        return;
    }
    if (this.classList.contains('loading')) { e.preventDefault(); return; }
    this.classList.add('loading');
    this.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
});

const visiArea = document.getElementById('visi');
if (visiArea) {
    const resize = () => {
        visiArea.style.height = 'auto';
        visiArea.style.height = visiArea.scrollHeight + 'px';
    };
    visiArea.addEventListener('input', resize);
    resize();
}
</script>

<link rel="stylesheet" href="css/visi-misi.css">

<?php require_once __DIR__ . '/footer.php'; ?>