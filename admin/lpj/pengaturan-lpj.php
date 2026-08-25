<?php
// admin/pengaturan-lpj.php
$page_css = 'arsip-surat'; // Reuse existing styles
require_once __DIR__ . '/../core/header.php';

requireSekretaris();
$periode_id = getUserPeriode();
$error = '';
$success = '';

// Process update general settings removed as requested

// Process update ministry visis & order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_kementerian') {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $kementerian_data = $_POST['kementerian'] ?? [];
        dbBeginTransaction();
        try {
            foreach ($kementerian_data as $k_id => $data) {
                $id = (int)$k_id;
                $deskripsi = sanitizeText($data['deskripsi'] ?? '', 1000);
                $urutan = (int)($data['urutan'] ?? 0);
                dbQuery("UPDATE kementerian SET deskripsi = ?, urutan = ? WHERE id = ?", [$deskripsi, $urutan, $id], "sii");
            }
            dbCommit();
            $success = "Pengaturan kementerian berhasil diperbarui.";
        } catch (Exception $e) {
            dbRollback();
            $error = "Gagal memperbarui pengaturan kementerian: " . $e->getMessage();
        }
    }
}

// General configurations fetching removed as requested

// Fetch ministries
$kementerian_list = dbFetchAll("SELECT * FROM kementerian WHERE periode_id = ? ORDER BY urutan ASC", [$periode_id], "i");
?>

<div class="page-header">
    <h1><i class="fas fa-sliders-h"></i> Pengaturan Master LPJ</h1>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="container-centered" style="max-width: 800px; margin: 0 auto; padding-bottom: 40px;">
    <!-- Form Pengaturan Kementerian -->
    <div class="card" style="background: #121620; border: 1px solid #2a3545; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
        <div class="card-header" style="background: #1a222f; padding: 15px 20px; font-weight: bold; color: #fff; border-bottom: 1px solid #2a3545;">
            <i class="fas fa-university"></i> Deskripsi & Urutan Konsolidasi Kementerian
        </div>
        <div class="card-body" style="padding: 20px;">
            <p style="font-size: 0.85rem; color: #aaa; margin-bottom: 20px;">Konfigurasi deskripsi visi/fungsi serta urutan bab kementerian saat melakukan konsolidasi LPJ Triwulan.</p>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_kementerian">

                <div style="padding-right: 5px;">
                    <?php if (empty($kementerian_list)): ?>
                        <p class="text-muted" style="text-align: center; padding: 20px;">Belum ada kementerian yang terdaftar.</p>
                    <?php else: foreach ($kementerian_list as $k): ?>
                        <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); padding: 15px; border-radius: 10px; margin-bottom: 15px; display: flex; flex-direction: column; gap: 10px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
                                <span style="font-weight: bold; color: #fff; font-size: 0.95rem; margin-top: 5px;"><?php echo htmlspecialchars($k['nama']); ?></span>
                                <div style="display: flex; align-items: center; gap: 8px; background: rgba(0,0,0,0.2); padding: 5px 10px; border-radius: 6px;">
                                    <label style="font-size: 0.75rem; color: #8BB9F0; margin-bottom: 0;">Urutan Bab:</label>
                                    <input type="number" name="kementerian[<?php echo $k['id']; ?>][urutan]" class="form-control" style="width: 65px; padding: 4px 8px; text-align: center; margin: 0;" value="<?php echo (int)$k['urutan']; ?>" required>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 0.8rem; color: #aaa; margin-bottom: 5px; display: block;">Deskripsi Visi & Fungsi (Statis/Default LPJ):</label>
                                <textarea name="kementerian[<?php echo $k['id']; ?>][deskripsi]" rows="3" class="form-control" style="font-size: 0.85rem;" placeholder="Visi, misi, dan fungsi kementerian untuk dicetak di LPJ..."><?php echo htmlspecialchars($k['deskripsi'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%; margin-top: 20px; padding: 12px; border-radius: 8px; font-weight: bold;"><i class="fas fa-save"></i> Simpan Urutan & Deskripsi</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
