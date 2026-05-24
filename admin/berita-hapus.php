<?php
// admin/berita-hapus.php
// VERSI: 4.1 - AUDIT LOG
//   CHANGED: auditLog() setelah DELETE berita — sebelum redirect()
//   UNCHANGED: Semua logika, HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    redirect('admin/berita.php', 'ID berita tidak valid', 'error');
    exit();
}

$periode_id = (int) getUserPeriode();
$berita = dbFetchOne(
    "SELECT * FROM berita WHERE id = ? AND periode_id = ?",
    [$id, $periode_id], "ii"
);
if (!$berita) {
    redirect('admin/berita.php', 'Berita tidak ditemukan atau akses ditolak', 'error');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        redirect('admin/berita.php', 'Request tidak valid.', 'error');
        exit();
    }
    if (($_POST['confirm'] ?? '') === 'yes') {
        if (!empty($berita['gambar'])) deleteFile($berita['gambar']);
        dbQuery("DELETE FROM berita WHERE id = ? AND periode_id = ?",
                [$id, $periode_id], "ii");
        auditLog('DELETE', 'berita', $id, 'Hapus berita: ' . $berita['judul']);
        redirect('admin/berita.php', 'Berita berhasil dihapus!', 'success');
    } else {
        redirect('admin/berita.php', 'Penghapusan dibatalkan', 'info');
    }
    exit();
}
?>

<!-- Page Header -->
<div class="page-header">
    <h1><i class="fas fa-trash-alt"></i> Hapus Berita</h1>
</div>

<div class="delete-confirmation">
    <div class="warning-box">
        <i class="fas fa-exclamation-triangle"></i>
        <p>Anda akan menghapus berita:</p>
        <p><strong><?php echo htmlspecialchars($berita['judul'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
        <p style="color:#f44336;margin-top:1rem;">
            <i class="fas fa-exclamation-circle"></i>
            Tindakan ini tidak dapat dibatalkan!
        </p>
    </div>

    <div class="berita-info">
        <div class="berita-info-item">
            <span class="label">Penulis:</span>
            <span class="value"><?php echo htmlspecialchars($berita['penulis'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <div class="berita-info-item">
            <span class="label">Tanggal:</span>
            <span class="value"><?php echo date('d/m/Y', strtotime($berita['tanggal'])); ?></span>
        </div>
        <?php if (!empty($berita['gambar'])): ?>
        <div class="berita-info-item">
            <span class="label">Gambar:</span>
            <span class="value"><?php echo htmlspecialchars(basename($berita['gambar']), ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php endif; ?>
    </div>

    <form method="POST">
        <?php echo csrfField(); ?>
        <div class="form-actions">
            <button type="submit" name="confirm" value="yes" class="btn-danger" id="confirmBtn">
                <i class="fas fa-trash"></i> Ya, Hapus Berita
            </button>
            <a href="berita.php" class="btn-secondary">
                <i class="fas fa-times"></i> Batal
            </a>
        </div>
    </form>
</div>

<!-- JavaScript — tidak diubah -->
<script>
document.getElementById('confirmBtn').addEventListener('click', function(e) {
    if (this.classList.contains('loading')) {
        e.preventDefault();
        return;
    }
    this.classList.add('loading');
    this.innerHTML = ' Menghapus...';
});
</script>

<link rel="stylesheet" href="css/berita-hapus.css">

<?php require_once __DIR__ . '/footer.php'; ?>