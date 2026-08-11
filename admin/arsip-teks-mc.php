<?php
// admin/arsip-teks-mc.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/header.php';

$periode_id = getUserPeriode();

$success = '';
$error = '';

// --- ACTION HANDLER: DELETE ---
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    csrfVerify();
    $del_id = (int)$_GET['delete'];
    try {
        $res = dbQuery("DELETE FROM arsip_teks_mc WHERE id = ? AND periode_id = ?", [$del_id, $periode_id]);
        if ($res) {
            $success = "Data Naskah Teks MC berhasil dihapus dari arsip.";
        } else {
            $error = "Gagal menghapus data.";
        }
    } catch (Exception $e) {
        $error = "Gagal menghapus data: " . $e->getMessage();
    }
}

// Ambil list Teks MC terdaftar
$list_mc = dbFetchAll(
    "SELECT mc.*, k.nama_kegiatan 
     FROM arsip_teks_mc mc
     LEFT JOIN kegiatan k ON mc.kegiatan_id = k.id
     WHERE mc.periode_id = ? 
     ORDER BY mc.updated_at DESC", 
    [$periode_id], 
    "i"
);
?>

<style>
@media (max-width: 768px) {
    .page-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .arsip-table, .arsip-table thead, .arsip-table tbody, .arsip-table th, .arsip-table td, .arsip-table tr { 
        display: block; 
    }
    
    .arsip-table thead { display: none; }
    
    .arsip-table tr {
        margin-bottom: 20px;
        background: rgba(255,255,255,0.02);
        border: 1px solid var(--border-color);
        border-radius: 15px;
        padding: 15px;
    }
    
    .arsip-table td {
        border: none !important;
        padding: 8px 0 !important;
        width: 100% !important;
    }
}
</style>

<div class="arsip-surat-container" style="max-width: 1400px; margin: 0 auto;">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <div class="header-content">
            <h1 style="color: #fff; font-size: 1.8rem; margin: 0; display: flex; align-items: center; gap: 12px;">
                <i class="fas fa-microphone-alt" style="color: #f5576c;"></i> Arsip Naskah Teks MC
            </h1>
            <p style="color: #888; margin: 5px 0 0 0;">Daftar naskah Master of Ceremony yang telah dibuat oleh Sie Acara.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 12px; border: 1px solid rgba(46, 204, 113, 0.2); margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo $success; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; padding: 15px; border-radius: 12px; border: 1px solid rgba(231, 76, 60, 0.2); margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="card" style="background: rgba(15, 18, 23, 0.95); border: 1px solid #2a3545; border-radius: 20px; overflow: hidden; backdrop-filter: blur(10px);">
        <div class="table-responsive">
            <table class="arsip-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: rgba(255,255,255,0.03);">
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid #2a3545;" width="50">No</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid #2a3545;">Judul Naskah & Kegiatan</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid #2a3545;">Format Acara</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid #2a3545;">Jumlah Segmen</th>
                        <th style="padding: 15px; text-align: left; color: #888; font-weight: 600; border-bottom: 1px solid #2a3545;">Terakhir Diperbarui</th>
                        <th style="padding: 15px; text-align: center; color: #888; font-weight: 600; border-bottom: 1px solid #2a3545;" width="220">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($list_mc)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; padding: 50px; color: #555;">
                                <i class="fas fa-microphone-slash" style="font-size: 3rem; margin-bottom: 15px; display:block; color: #333;"></i>
                                Belum ada naskah Teks MC yang disimpan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($list_mc as $idx => $item):
                            $susunan = json_decode($item['susunan_mc'], true) ?: [];
                            $total_segmen = count($susunan);
                            $tipe_label = [
                                'formal' => 'Formal (Protokoler)',
                                'semi_formal' => 'Semi Formal',
                                'non_formal' => 'Non-Formal (Casual)'
                            ][$item['tipe_acara']] ?? 'Formal';
                        ?>
                        <tr style="border-bottom: 1px solid #2a3545; transition: 0.3s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                            <td style="padding: 15px;" data-label="No"><?php echo $idx + 1; ?></td>
                            <td style="padding: 15px;" data-label="Judul Naskah & Kegiatan">
                                <div style="font-weight:600; color:#f5576c; font-size: 1rem;"><?php echo htmlspecialchars($item['judul_naskah']); ?></div>
                                <div style="font-size:0.8rem; color:#888; margin-top: 3px;">
                                    <i class="fas fa-calendar-alt" style="margin-right: 5px;"></i> <?php echo htmlspecialchars($item['nama_kegiatan'] ?? 'Kegiatan General'); ?>
                                </div>
                            </td>
                            <td style="padding: 15px;" data-label="Format Acara">
                                <span style="background: rgba(245, 87, 108, 0.1); color: #f5576c; border: 1px solid rgba(245, 87, 108, 0.3); padding: 4px 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;">
                                    <?php echo $tipe_label; ?>
                                </span>
                            </td>
                            <td style="padding: 15px;" data-label="Jumlah Segmen">
                                <span style="background: rgba(79, 172, 254, 0.1); color: #4facfe; border: 1px solid rgba(79, 172, 254, 0.3); padding: 4px 10px; border-radius: 8px; font-size: 0.8rem; font-weight: 600;">
                                    <?php echo $total_segmen; ?> Segmen
                                </span>
                            </td>
                            <td style="padding: 15px; color: #aaa; font-size: 0.85rem;" data-label="Terakhir Diperbarui">
                                <?php echo date('d M Y, H:i', strtotime($item['updated_at'])); ?>
                            </td>
                            <td style="padding: 15px; text-align:center;" data-label="AKSI">
                                <div style="display:flex; gap:8px; justify-content:center;">
                                    <!-- Reader Mode -->
                                    <a href="reader-teks-mc.php?id=<?php echo $item['id']; ?>" target="_blank" style="width: 36px; height: 36px; border-radius: 10px; background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); display: flex; align-items: center; justify-content: center; text-decoration: none;" title="Mode Live Reader (Teleprompter)">
                                        <i class="fas fa-play"></i>
                                    </a>
                                    <!-- Cetak PDF -->
                                    <a href="cetak-teks-mc-pdf.php?id=<?php echo $item['id']; ?>" target="_blank" style="width: 36px; height: 36px; border-radius: 10px; background: rgba(241, 196, 15, 0.15); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.3); display: flex; align-items: center; justify-content: center; text-decoration: none;" title="Cetak Cue Card PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                    <!-- Edit -->
                                    <a href="workspace-teks-mc.php?kegiatan_id=<?php echo $item['kegiatan_id']; ?>&edit_id=<?php echo $item['id']; ?>" style="width: 36px; height: 36px; border-radius: 10px; background: rgba(79, 172, 254, 0.15); color: #4facfe; border: 1px solid rgba(79, 172, 254, 0.3); display: flex; align-items: center; justify-content: center; text-decoration: none;" title="Edit Naskah">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <!-- Hapus -->
                                    <a href="?delete=<?php echo $item['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                       style="width: 36px; height: 36px; border-radius: 10px; background: rgba(231, 76, 60, 0.15); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); display: flex; align-items: center; justify-content: center; text-decoration: none;"
                                       onclick="return confirm('Hapus naskah Teks MC ini dari arsip?')" 
                                       title="Hapus">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
