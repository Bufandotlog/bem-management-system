<?php
// admin/distribusi-surat.php - Halaman Staging Distribusi Surat untuk Humas
$page_css = 'arsip-surat';
require_once __DIR__ . '/../core/header.php';

// Check if user is Ketuplat or Sie Humas
$isKetuplat = false;
$isEventHumas = false;
$cek_event = dbFetchOne("SELECT event_role FROM kegiatan_panitia WHERE user_id = ? AND event_role IN ('ketuplat', 'sie_humas') LIMIT 1", [$_SESSION['admin_id'] ?? 0]);
if($cek_event) {
    if ($cek_event['event_role'] === 'ketuplat') {
        $isKetuplat = true;
    } else if ($cek_event['event_role'] === 'sie_humas') {
        $isEventHumas = true;
    }
}

if ($admin_role === 'kominfo' || (!$isHumas && !$isKetuplat && !$isEventHumas)) {
    redirect('admin/core/dashboard.php', 'Akses ditolak: Kominfo tidak diizinkan mengakses halaman Distribusi Surat.', 'error');
}

$periode_id = getUserPeriode();
$success = '';
$error = '';

// Proses Tandai Terkirim
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_sent') {
    if (csrfVerify()) {
        $surat_id = (int)($_POST['surat_id'] ?? 0);
        $tgl_kirim = date('Y-m-d');
        $suratData = dbFetchOne("SELECT nomor_surat, perihal, tujuan, created_by, kegiatan_id FROM arsip_surat WHERE id = ? AND periode_id = ?", [$surat_id, $periode_id], "ii");
        dbQuery("UPDATE arsip_surat SET tanggal_dikirim = ?, status_humas = 'terkirim', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND periode_id = ?", [$tgl_kirim, $surat_id, $periode_id], "sii");
        autoCommitSentStagingLetters($periode_id);

        if ($suratData) {
            $tujuanClean = strip_tags(str_replace(['<p>','</p>','<br>'], ['', ', ', ', '], $suratData['tujuan']));
            if (strlen($tujuanClean) > 30) {
                $tujuanClean = substr($tujuanClean, 0, 30) . '...';
            }

            // Ambil ID Superadmin, Admin, Sekretaris, Ketuplat, & Pembuat Surat
            $recipIds = getTargetUserIdsByRole(
                ['superadmin', 'admin', 'sekretaris'],
                $suratData['kegiatan_id'] ?? 0,
                ['ketuplat']
            );
            if (!empty($suratData['created_by'])) {
                $recipIds[] = (int)$suratData['created_by'];
                $recipIds = array_values(array_unique($recipIds));
            }
            if (!empty($recipIds)) {
                createNotificationAndPush(
                    $recipIds,
                    "🚀 Surat Resmi Dikirim oleh Humas",
                    "Surat " . $suratData['nomor_surat'] . " (" . $suratData['perihal'] . ") telah resmi disebar ke " . $tujuanClean . ".",
                    baseUrl('admin/surat/distribusi-surat.php'),
                    "info"
                );
            }
        }

        $success = "Surat berhasil ditandai sebagai terkirim pada tanggal hari ini.";
    } else {
        $error = "Token CSRF tidak valid.";
    }
}

// Ambil data staging (hanya surat yang sudah diverifikasi & dikirim oleh Sekretaris)
$where_clause = "s.periode_id = ? AND s.status_humas IN ('siap_disebar', 'terkirim')";
$params = [$periode_id];
$types = "i";

if (!$isHumas && ($isKetuplat || $isEventHumas)) {
    // Jika bukan humas pusat, hanya bisa melihat surat dari kegiatan yang dia ikuti sebagai ketuplat/sie humas
    $where_clause .= " AND k.id IN (SELECT kegiatan_id FROM kegiatan_panitia WHERE user_id = ? AND event_role IN ('ketuplat', 'sie_humas'))";
    $params[] = $_SESSION['admin_id'];
    $types .= "i";
}

$get_kegiatan_id = (int)($_GET['kegiatan_id'] ?? 0);
if ($get_kegiatan_id > 0) {
    $where_clause .= " AND k.id = ?";
    $params[] = $get_kegiatan_id;
    $types .= "i";
}

$staging_list = dbFetchAll("
    SELECT s.*, k.nama_kegiatan 
    FROM arsip_surat s
    JOIN kegiatan k ON s.kegiatan_id = k.id
    WHERE $where_clause 
    ORDER BY s.id DESC
", $params, $types);

?>

<style>
.staging-card {
    background: #0f1217;
    border: 1px solid #2a3545;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}
.table-staging {
    width: 100%;
    border-collapse: collapse;
}
.table-staging th, .table-staging td {
    padding: 12px 15px;
    border-bottom: 1px solid #2a3545;
    text-align: left;
}
.table-staging th {
    background: rgba(74, 144, 226, 0.1);
    color: #4A90E2;
    font-weight: bold;
}
.badge-status {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: bold;
}
.badge-pending { background: rgba(243, 156, 18, 0.2); color: #f39c12; }
.badge-sent { background: rgba(46, 204, 113, 0.2); color: #2ecc71; }
.action-btns {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.btn-sm {
    padding: 6px 12px;
    font-size: 0.85rem;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.btn-mark { background: #28a745; color: white; }
.btn-mark:hover { background: #218838; }
.btn-copy { background: #17a2b8; color: white; }
.btn-copy:hover { background: #138496; }
.btn-download { background: #4A90E2; color: white; }
.btn-download:hover { background: #357ABD; }
</style>

<div class="page-header">
    <h1><i class="fas fa-paper-plane"></i> Staging & Distribusi Surat</h1>
    <p>Halaman khusus Humas untuk mendistribusikan surat-surat kegiatan yang sudah dibuat oleh Sekretaris.</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="staging-card">
    <div class="table-responsive">
        <table class="table-staging responsive-card-table">
            <thead>
                <tr>
                    <th>Kegiatan / Acara</th>
                    <th>Perihal Surat</th>
                    <th>Tujuan (Kepada Yth)</th>
                    <th>Status Distribusi</th>
                    <th style="min-width: 250px;">Aksi Eksekusi</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($staging_list)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; color: #888;">Belum ada surat staging kegiatan saat ini.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($staging_list as $s): 
                        $is_sent = !empty($s['tanggal_dikirim']) && $s['tanggal_dikirim'] !== '0000-00-00';
                        $tujuan = strip_tags(str_replace(['<p>','</p>','<br>'], [ '', ', ', ', '], $s['tujuan']));
                        $perihal = strip_tags($s['perihal']);
                        $konteks = strip_tags($s['konteks'] ?? '');
                        
                        // Buat teks redaksi untuk di-copy (format WhatsApp)
                        $redaksi = "*SURAT " . strtoupper(htmlspecialchars($perihal)) . "*\n\n";
                        $redaksi .= "Yth. " . htmlspecialchars($tujuan) . "\n\n";
                        $redaksi .= "Sehubungan dengan akan diadakannya kegiatan *" . htmlspecialchars($s['nama_kegiatan']) . "*, berikut kami lampirkan surat " . htmlspecialchars(strtolower($perihal)) . ". ";
                        if (!empty($konteks)) {
                            $redaksi .= htmlspecialchars($konteks) . "\n\n";
                        }
                        $redaksi .= "Atas perhatian dan kerjasamanya kami ucapkan terima kasih.\n\n";
                        $redaksi .= "_Humas Panitia " . htmlspecialchars($s['nama_kegiatan']) . "_";
                    ?>
                        <tr>
                            <td data-label="Kegiatan"><span style="color: #8BB9F0; font-weight: bold;"><?php echo htmlspecialchars($s['nama_kegiatan']); ?></span></td>
                            <td data-label="Perihal">
                                <div>
                                    <strong><?php echo htmlspecialchars($perihal); ?></strong><br>
                                    <small style="color: #aaa;"><?php echo htmlspecialchars($s['nomor_surat']); ?></small>
                                </div>
                            </td>
                            <td data-label="Tujuan">
                                <span><?php echo htmlspecialchars(substr($tujuan, 0, 50)) . (strlen($tujuan) > 50 ? '...' : ''); ?></span>
                            </td>
                            <td data-label="Status">
                                <?php 
                                $status_h = $s['status_humas'] ?? 'draft';
                                if($is_sent || $status_h === 'terkirim'): 
                                ?>
                                    <span class="badge-status badge-sent" title="Surat sudah dikirim oleh Humas ke tujuan"><i class="fas fa-check-circle"></i> Terkirim (<?php echo date('d M Y', strtotime($s['tanggal_dikirim'] ?: date('Y-m-d'))); ?>)</span>
                                <?php elseif($status_h === 'siap_disebar'): ?>
                                    <span class="badge-status" style="background: rgba(52, 152, 219, 0.2); color: #3498db; border: 1px solid rgba(52, 152, 219, 0.3);" title="Surat sudah di Humas, sedang diproses / belum dikirim ke tujuan"><i class="fas fa-spinner"></i> Diproses</span>
                                <?php else: ?>
                                    <span class="badge-status badge-pending" title="Surat masih di Sekretaris (Belum dikirim ke Humas)"><i class="fas fa-clock"></i> Draft</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi">
                                <div class="action-btns">
                                    <a href="<?php echo (!empty($s['file_surat']) ? uploadUrl($s['file_surat']) : baseUrl('admin/surat/cetak-surat.php?id=' . $s['id'])); ?>" target="_blank" class="btn-sm btn-download" title="Download PDF"><i class="fas fa-file-pdf"></i> Download</a>
                                    
                                    <button type="button" class="btn-sm btn-copy" onclick="copyRedaksi(this)" data-text="<?php echo htmlspecialchars($redaksi, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-copy"></i> Salin Redaksi</button>
                                    
                                    <?php if(!$is_sent): ?>
                                        <form method="POST" style="margin: 0;" onsubmit="return confirm('Tandai surat ini sebagai sudah disebar hari ini?');">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="mark_sent">
                                            <input type="hidden" name="surat_id" value="<?php echo $s['id']; ?>">
                                            <button type="submit" class="btn-sm btn-mark"><i class="fas fa-paper-plane"></i> Tandai Terkirim</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function copyRedaksi(btn) {
    var text = btn.getAttribute('data-text');
    navigator.clipboard.writeText(text).then(function() {
        var oriText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Tersalin!';
        btn.style.background = '#28a745';
        setTimeout(function() {
            btn.innerHTML = oriText;
            btn.style.background = '';
        }, 2000);
    });
}
</script>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
