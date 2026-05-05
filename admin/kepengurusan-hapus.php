<?php
// admin/kepengurusan-hapus.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: Terima POST bukan GET untuk posisi
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: Redirect ke admin/kepengurusan.php bukan kepengurusan.php root
//   CHANGED: htmlspecialchars pada $periode_info output
//   CHANGED: Blok <style> dipindah ke kepengurusan-hapus.css
//   UNCHANGED: Seluruh HTML dan logika hapus

require_once __DIR__ . '/header.php';

// Terima posisi dari POST (dikirim dari kepengurusan-edit.php) atau GET fallback
$posisi = $_POST['posisi'] ?? $_GET['posisi'] ?? '';

$posisi_valid = ['ketua', 'wakil_ketua', 'sekretaris_umum', 'bendahara_umum'];
if (!$posisi || !in_array($posisi, $posisi_valid)) {
    redirect('admin/kepengurusan.php', 'Posisi tidak valid', 'error');
    exit();
}

$data = dbFetchOne(
    "SELECT * FROM struktur_bph WHERE posisi = ? AND periode_id = ?",
    [$posisi, $active_periode], "si"
);

if (!$data) {
    redirect('admin/kepengurusan.php', 'Data tidak ditemukan atau bukan milik periode ini!', 'error');
    exit();
}

$judul = [
    'ketua'           => 'Ketua BEM',
    'wakil_ketua'     => 'Wakil Ketua BEM',
    'sekretaris_umum' => 'Sekretaris Umum',
    'bendahara_umum'  => 'Bendahara Umum',
];

$jumlah_anggota = 0;
if (in_array($posisi, ['sekretaris_umum', 'bendahara_umum'])) {
    $anggota_check = dbFetchAll(
        "SELECT foto FROM anggota_bph WHERE bph_id = ? AND periode_id = ?",
        [$data['id'], $active_periode], "ii"
    );
    $jumlah_anggota = count($anggota_check);
}

// Proses hapus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm'])) {

    if (!csrfVerify()) {
        redirect('admin/kepengurusan.php', 'Request tidak valid.', 'error');
        exit();
    }

    if ($_POST['confirm'] === 'yes') {
        dbBeginTransaction();
        try {
            if (!empty($data['foto'])) deleteFile($data['foto']);
            if (!empty($data['logo'])) deleteFile($data['logo']);

            if (in_array($posisi, ['sekretaris_umum', 'bendahara_umum'])) {
                $anggota_hapus = dbFetchAll(
                    "SELECT foto FROM anggota_bph WHERE bph_id = ? AND periode_id = ?",
                    [$data['id'], $active_periode], "ii"
                );
                foreach ($anggota_hapus as $a) {
                    if (!empty($a['foto'])) deleteFile($a['foto']);
                }
                dbQuery("DELETE FROM anggota_bph WHERE bph_id = ? AND periode_id = ?",
                        [$data['id'], $active_periode], "ii");
            }

            $hapus = dbQuery(
                "DELETE FROM struktur_bph WHERE id = ? AND periode_id = ?",
                [$data['id'], $active_periode], "ii"
            );

            if ($hapus) {
                dbCommit();
                auditLog('DELETE', 'struktur_bph', $data['id'], 'Hapus kepengurusan: ' . $judul[$posisi] . ' — ' . $data['nama']);
                redirect('admin/kepengurusan.php', $judul[$posisi] . ' berhasil dihapus', 'success');
            } else {
                throw new Exception('Gagal menghapus data');
            }
        } catch (Exception $e) {
            dbRollback();
            error_log("[KEPENGURUSAN-HAPUS] " . $e->getMessage());
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

<link rel="stylesheet" href="css/kepengurusan-hapus.css">

<div class="delete-confirmation">
    <h1>Hapus <?php echo $judul[$posisi]; ?></h1>

    <div style="margin: -10px 0 20px 0; text-align: center; color: #888;">
        <i class="fas fa-calendar-alt"></i> <?php echo $periode_info; ?>
    </div>

    <div class="warning-box">
        <i class="fas fa-exclamation-triangle"></i>
        <p>Anda akan menghapus: <strong><?php echo htmlspecialchars($data['nama'], ENT_QUOTES, 'UTF-8'); ?></strong></p>

        <?php if (in_array($posisi, ['sekretaris_umum', 'bendahara_umum']) && $jumlah_anggota > 0): ?>
            <p>⚠️ <strong><?php echo $jumlah_anggota; ?> orang anggota</strong> juga akan ikut terhapus!</p>
            <p style="font-size: 0.95rem; color: #ffaa00;">Foto-foto anggota akan dihapus permanen dari server</p>
        <?php endif; ?>

        <p>File foto/logo akan dihapus permanen dari server!</p>
        <p class="periode-warning">Data yang dihapus hanya untuk periode
            <strong><?php echo htmlspecialchars($periode_data['nama'] ?? 'Astawidya', ENT_QUOTES, 'UTF-8'); ?></strong>
        </p>
        <p>Tindakan ini tidak dapat dibatalkan.</p>
    </div>

    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="posisi" value="<?php echo htmlspecialchars($posisi, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-actions">
            <button type="submit" name="confirm" value="yes" class="btn-danger">
                <i class="fas fa-trash"></i> Ya, Hapus (Periode Ini)
            </button>
            <a href="kepengurusan-edit.php?posisi=<?php echo urlencode($posisi); ?>" class="btn-secondary">
                <i class="fas fa-times"></i> Batal
            </a>
        </div>
    </form>
</div>

<style>
.periode-warning{font-size:.9rem;color:#ffaa00;margin-top:10px;padding-top:10px;border-top:1px dashed #444}
.warning-box{background:#2a1a1a;border:2px solid #f44336;border-radius:15px;padding:30px;margin:30px 0;box-shadow:0 10px 30px rgba(244,67,54,.2)}
.warning-box i{font-size:3rem;color:#f44336;margin-bottom:20px}
.warning-box p{margin:15px 0;color:#ccc;font-size:1.1rem;line-height:1.6}
.btn-danger{background:#f44336;color:white;padding:12px 30px;border:none;border-radius:8px;cursor:pointer;font-size:1rem;font-weight:600;display:inline-flex;align-items:center;gap:8px;transition:all .3s ease}
.btn-danger:hover{background:#d32f2f;transform:translateY(-3px);box-shadow:0 10px 25px rgba(244,67,54,.4)}
.btn-secondary{background:#333;color:white;padding:12px 30px;text-decoration:none;border-radius:8px;display:inline-flex;align-items:center;gap:8px;transition:all .3s ease;border:1px solid #444}
.btn-secondary:hover{background:#3a3a3a;transform:translateY(-2px)}
.form-actions{display:flex;gap:15px;justify-content:center;margin-top:30px}
</style>

<?php require_once __DIR__ . '/footer.php'; ?>