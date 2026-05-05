<?php
// admin/upload-struktur-hapus.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: Filter periode_id — hapus hanya gambar periode aktif
//   CHANGED: dbQuery() bukan dbUpdate() (konsisten dengan fungsi lain)
//   CHANGED: Redirect ke admin/upload-struktur.php bukan root
//   CHANGED: Blok <style> diminify (InfinityFree output buffer)
//   CHANGED: Semua echo pakai htmlspecialchars()
//   UNCHANGED: Seluruh HTML dan logika

require_once __DIR__ . '/header.php';

// Filter periode_id — admin hanya bisa hapus gambar periode sendiri
$struktur = dbFetchOne(
    "SELECT * FROM struktur_organisasi WHERE periode_id = ?",
    [$active_periode], "i"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        redirect('admin/upload-struktur.php', 'Request tidak valid.', 'error');
        exit();
    }

    if (($_POST['confirm'] ?? '') === 'yes') {
        if ($struktur && !empty($struktur['gambar'])) {
            if (deleteFile($struktur['gambar'])) {
                dbQuery(
                    "UPDATE struktur_organisasi SET gambar = NULL, updated_by = ? WHERE periode_id = ?",
                    [(int)($_SESSION['admin_id'] ?? 1), $active_periode], "ii"
                );
                auditLog('DELETE', 'struktur_organisasi', $active_periode, 'Hapus gambar struktur organisasi periode ' . $active_periode);
                redirect('admin/upload-struktur.php', 'Gambar struktur organisasi berhasil dihapus!', 'success');
            } else {
                redirect('admin/upload-struktur.php', 'Gagal menghapus file gambar.', 'error');
            }
        } else {
            redirect('admin/upload-struktur.php', 'Tidak ada gambar yang dihapus.', 'info');
        }
    } else {
        redirect('admin/upload-struktur.php', 'Penghapusan dibatalkan', 'info');
    }
    exit();
}
?>

<div class="delete-confirmation">
    <h1><i class="bi bi-image"></i> Hapus Gambar Struktur</h1>

    <div class="warning-box">
        <i class="bi bi-exclamation-triangle-fill"></i>

        <?php if ($struktur && !empty($struktur['gambar'])): ?>
            <p>Anda akan menghapus gambar struktur organisasi:</p>

            <div class="image-preview">
                <img src="<?php echo uploadUrl($struktur['gambar']); ?>"
                     alt="<?php echo htmlspecialchars($struktur['judul'], ENT_QUOTES, 'UTF-8'); ?>"
                     class="preview-img">
            </div>

            <p><strong><?php echo htmlspecialchars($struktur['judul'] ?? 'Struktur Organisasi', ENT_QUOTES, 'UTF-8'); ?></strong></p>

            <?php if (!empty($struktur['deskripsi'])): ?>
                <p class="deskripsi"><?php echo htmlspecialchars($struktur['deskripsi'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <p style="color: #f44336; margin-top: 20px;">
                <i class="bi bi-exclamation-triangle"></i>
                Tindakan ini akan menghapus file dari server dan tidak dapat dibatalkan!
            </p>
        <?php else: ?>
            <p>Tidak ada gambar yang dapat dihapus.</p>
            <p><a href="upload-struktur.php" class="btn-secondary">Kembali</a></p>
        <?php endif; ?>
    </div>

    <?php if ($struktur && !empty($struktur['gambar'])): ?>
        <form method="POST">
            <?php echo csrfField(); ?>
            <div class="form-actions">
                <button type="submit" name="confirm" value="yes" class="btn-danger">
                    <i class="bi bi-trash"></i> Ya, Hapus Gambar
                </button>
                <a href="upload-struktur.php" class="btn-secondary">
                    <i class="bi bi-x-circle"></i> Batal
                </a>
            </div>
        </form>
    <?php endif; ?>
</div>

<style>
.delete-confirmation{max-width:600px;margin:50px auto;text-align:center;padding:20px}
.delete-confirmation h1{color:var(--text-light,#fff);font-size:2rem;margin-bottom:30px;display:flex;align-items:center;justify-content:center;gap:10px}
.delete-confirmation h1 i{color:var(--primary,#4A90E2)}
.warning-box{background:#2a1a1a;border:2px solid #f44336;border-radius:15px;padding:30px;margin:30px 0;box-shadow:0 10px 30px rgba(244,67,54,.2)}
.warning-box>i{font-size:3rem;color:#f44336;margin-bottom:20px}
.warning-box p{margin:15px 0;color:#ccc;font-size:1.1rem;line-height:1.6}
.image-preview{margin:20px 0;padding:15px;background:#1a1a1a;border-radius:10px;border:1px solid #333}
.preview-img{max-width:100%;max-height:200px;border-radius:8px;border:3px solid var(--primary,#4A90E2)}
.deskripsi{color:#888;font-size:.95rem;font-style:italic}
.form-actions{display:flex;gap:15px;justify-content:center;margin-top:30px}
.btn-danger{background:#f44336;color:white;padding:12px 30px;border:none;border-radius:8px;cursor:pointer;font-size:1rem;font-weight:600;display:inline-flex;align-items:center;gap:8px;transition:all .3s ease}
.btn-danger:hover{background:#d32f2f;transform:translateY(-3px);box-shadow:0 10px 25px rgba(244,67,54,.4)}
.btn-secondary{background:#333;color:white;padding:12px 30px;text-decoration:none;border-radius:8px;display:inline-flex;align-items:center;gap:8px;transition:all .3s ease;border:1px solid #444}
.btn-secondary:hover{background:#3a3a3a;transform:translateY(-2px)}
@media(max-width:768px){.delete-confirmation{margin:30px auto;padding:15px}.delete-confirmation h1{font-size:1.5rem}.warning-box{padding:20px}.warning-box>i{font-size:2.5rem}.warning-box p{font-size:1rem}.form-actions{flex-direction:column-reverse}.btn-danger,.btn-secondary{width:100%;justify-content:center}}
</style>

<?php require_once __DIR__ . '/footer.php'; ?>