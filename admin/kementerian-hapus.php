<?php
// admin/kementerian-hapus.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: $id cast (int)
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: Redirect ke admin/kepengurusan.php bukan root
//   CHANGED: htmlspecialchars pada $periode_info output
//   CHANGED: Blok <style> diminify
//   UNCHANGED: Seluruh HTML dan logika hapus

require_once __DIR__ . '/header.php';

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    redirect('admin/kepengurusan.php', 'ID kementerian tidak valid', 'error');
    exit();
}

$kementerian = dbFetchOne(
    "SELECT * FROM kementerian WHERE id = ? AND periode_id = ?",
    [$id, $active_periode], "ii"
);
if (!$kementerian) {
    redirect('admin/kepengurusan.php', 'Kementerian tidak ditemukan atau bukan milik periode ini!', 'error');
    exit();
}

$anggota_list = dbFetchAll(
    "SELECT foto FROM anggota_kementerian WHERE kementerian_id = ? AND periode_id = ?",
    [$id, $active_periode], "ii"
);
$jumlah_anggota = count($anggota_list);

// Proses hapus
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        redirect('admin/kepengurusan.php', 'Request tidak valid.', 'error');
        exit();
    }

    if (($_POST['confirm'] ?? '') === 'yes') {
        dbBeginTransaction();
        try {
            if (!empty($kementerian['logo'])) deleteFile($kementerian['logo']);

            foreach ($anggota_list as $a) {
                if (!empty($a['foto'])) deleteFile($a['foto']);
            }

            dbQuery("DELETE FROM anggota_kementerian WHERE kementerian_id = ? AND periode_id = ?",
                    [$id, $active_periode], "ii");

            $hapus = dbQuery("DELETE FROM kementerian WHERE id = ? AND periode_id = ?",
                             [$id, $active_periode], "ii");

            if ($hapus) {
                dbCommit();
                auditLog('DELETE', 'kementerian', $id, 'Hapus kementerian: ' . $kementerian['nama']);
                redirect('admin/kepengurusan.php', 'Kementerian berhasil dihapus', 'success');
            } else {
                throw new Exception('Gagal menghapus kementerian');
            }
        } catch (Exception $e) {
            dbRollback();
            error_log("[KEMENTERIAN-HAPUS] " . $e->getMessage());
            redirect('admin/kepengurusan.php', 'Gagal menghapus data.', 'error');
        }
    } else {
        redirect('admin/kepengurusan.php', 'Penghapusan dibatalkan', 'info');
    }
    exit();
}

$periode_info = htmlspecialchars(
    "Periode: " . ($periode_data['nama'] ?? 'Astawidya')
    . " (" . ($periode_data['tahun_mulai'] ?? '2025')
    . "/" . ($periode_data['tahun_selesai'] ?? '2026') . ")",
    ENT_QUOTES, 'UTF-8'
);
?>

<div class="delete-confirmation">
    <h1>Hapus Kementerian</h1>

    <div style="margin: -10px 0 20px 0; text-align: center; color: #888;">
        <i class="fas fa-calendar-alt"></i> <?php echo $periode_info; ?>
    </div>

    <div class="warning-box">
        <i class="fas fa-exclamation-triangle"></i>
        <p>Anda akan menghapus kementerian: <strong><?php echo htmlspecialchars($kementerian['nama'], ENT_QUOTES, 'UTF-8'); ?></strong></p>

        <?php if ($jumlah_anggota > 0): ?>
            <p>⚠️ <strong><?php echo $jumlah_anggota; ?> orang anggota</strong> juga akan ikut terhapus!</p>
            <p style="font-size: 0.95rem; color: #ffaa00;">Foto-foto anggota akan dihapus permanen dari server</p>
        <?php endif; ?>

        <p class="periode-warning">Data yang dihapus hanya untuk periode
            <strong><?php echo htmlspecialchars($periode_data['nama'] ?? 'Astawidya', ENT_QUOTES, 'UTF-8'); ?></strong>
        </p>
        <p style="color: #f44336;">Tindakan ini tidak dapat dibatalkan.</p>
    </div>

    <form method="POST">
        <?php echo csrfField(); ?>
        <div class="form-actions">
            <button type="submit" name="confirm" value="yes" class="btn-danger">
                <i class="fas fa-trash"></i> Ya, Hapus Kementerian (Periode Ini)
            </button>
            <a href="kepengurusan.php" class="btn-secondary">
                <i class="fas fa-times"></i> Batal
            </a>
        </div>
    </form>
</div>

<style>
.delete-confirmation{max-width:600px;margin:50px auto;text-align:center}
.warning-box{background:#2a1a1a;border:1px solid #f44336;border-radius:10px;padding:30px;margin:30px 0}
.warning-box i{font-size:3rem;color:#f44336;margin-bottom:20px}
.warning-box p{margin:10px 0;color:#ccc;font-size:1.1rem}
.periode-warning{font-size:.95rem;color:#ffaa00;margin-top:15px;padding-top:15px;border-top:1px dashed #444}
.btn-danger{background:#f44336;color:white;padding:12px 30px;border:none;border-radius:5px;cursor:pointer;font-size:1rem;margin-right:10px;display:inline-flex;align-items:center;gap:8px;transition:all .3s ease}
.btn-danger:hover{background:#d32f2f;transform:translateY(-2px);box-shadow:0 5px 15px rgba(244,67,54,.4)}
.btn-secondary{background:#333;color:white;padding:12px 30px;text-decoration:none;border-radius:5px;display:inline-flex;align-items:center;gap:8px;transition:all .3s ease;border:1px solid #444}
.btn-secondary:hover{background:#3a3a3a;transform:translateY(-2px)}
.form-actions{display:flex;gap:15px;justify-content:center;margin-top:30px}
</style>

<?php require_once __DIR__ . '/footer.php'; ?>