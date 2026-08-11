<?php
// admin/staging-surat.php
$page_css = 'arsip-surat';
require_once __DIR__ . '/../core/header.php';

requireSekretaris();

$periode_id = getUserPeriode();
$error = '';
$success = '';

// Ambil daftar kegiatan berstatus 'persiapan'
$kegiatan_list = dbFetchAll(
    "SELECT id, nama_kegiatan, status FROM kegiatan WHERE periode_id = ? AND status = 'persiapan' ORDER BY id DESC",
    [$periode_id], "i"
);

// Tentukan filter kegiatan aktif
$selected_kegiatan_id = isset($_GET['kegiatan_id']) ? (int)$_GET['kegiatan_id'] : 0;
if ($selected_kegiatan_id === 0 && !empty($kegiatan_list)) {
    // Default ke kegiatan persiapan pertama jika ada
    foreach ($kegiatan_list as $kg) {
        if ($kg['status'] === 'persiapan') {
            $selected_kegiatan_id = (int)$kg['id'];
            break;
        }
    }
}

// HANDLE COMMIT TO ARCHIVE (MASSAL ATAU TUNGGAL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'commit_archive') {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid. Silakan muat ulang halaman.";
    } else {
        $ids = $_POST['ids'] ?? [];
        $tgl_kirim_raw = trim($_POST['tanggal_dikirim'] ?? '');
        
        if (empty($ids) || !is_array($ids)) {
            $error = "Pilih minimal satu surat yang akan dimasukkan ke Arsip Utama.";
        } else {
            // Standardize tanggal dikirim
            $tgl_db = date('Y-m-d');
            if (!empty($tgl_kirim_raw)) {
                if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $tgl_kirim_raw)) {
                    $p = explode('/', $tgl_kirim_raw);
                    $tgl_db = "{$p[2]}-{$p[1]}-{$p[0]}";
                } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_kirim_raw)) {
                    $tgl_db = $tgl_kirim_raw;
                }
            }

            $count_committed = 0;
            foreach ($ids as $id) {
                $id = (int)$id;
                $surat = dbFetchOne("SELECT nomor_surat FROM arsip_surat WHERE id = ? AND periode_id = ? AND status_arsip = 'staging'", [$id, $periode_id], "ii");
                if ($surat) {
                    // Update seluruh grup dengan nomor surat yang sama
                    dbQuery(
                        "UPDATE arsip_surat SET status_arsip = 'archived', tanggal_dikirim = ?, status_humas = 'terkirim' WHERE nomor_surat = ? AND periode_id = ?",
                        [$tgl_db, $surat['nomor_surat'], $periode_id],
                        "ssi"
                    );
                    $count_committed++;
                    auditLog('UPDATE', 'arsip_surat', $id, 'Commit staging surat ke Arsip Utama: ' . $surat['nomor_surat']);
                }
            }

            if ($count_committed > 0) {
                resyncStagingNumbers($periode_id);
                redirect('admin/surat/staging-surat.php?kegiatan_id={$selected_kegiatan_id}', "{$count_committed} surat berhasil di-commit dan resmi dipindahkan ke Arsip Utama!", "success");
            } else {
                $error = "Gagal memproses commit surat.";
            }
        }
    }
}

// HANDLE KIRIM KE HUMAS MASSAL (BATCH)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_to_humas_batch') {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid. Silakan muat ulang halaman.";
    } else {
        $ids = $_POST['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) {
            $error = "Pilih minimal satu surat yang akan diverifikasi dan dikirim ke Humas.";
        } else {
            $count_sent = 0;
            foreach ($ids as $id) {
                $id = (int)$id;
                $surat = dbFetchOne("SELECT nomor_surat FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");
                if ($surat) {
                    dbQuery("UPDATE arsip_surat SET status_humas = 'siap_disebar' WHERE nomor_surat = ? AND periode_id = ?", [$surat['nomor_surat'], $periode_id]);
                    $count_sent++;
                }
            }
            if ($count_sent > 0) {
                // FCM Notification ke Humas & Ketuplat
                $humasUsers = dbFetchAll("
                    SELECT id FROM users WHERE role IN ('humas', 'admin_humas') AND is_active = 1
                    UNION
                    SELECT user_id AS id FROM kegiatan_panitia WHERE event_role IN ('ketuplat', 'sie_humas')
                ");
                $targetUserIds = array_column($humasUsers, 'id');
                if (!empty($targetUserIds)) {
                    $kegNama = dbFetchOne("SELECT nama_kegiatan FROM kegiatan WHERE id = ?", [$selected_kegiatan_id])['nama_kegiatan'] ?? 'Kegiatan BEM';
                    sendFcmNotification(
                        $targetUserIds,
                        "📨 Surat Baru Siap Disebar!",
                        "{$count_sent} surat untuk kegiatan \"{$kegNama}\" telah diverifikasi Sekretaris dan siap Anda sebar.",
                        [
                            'click_action' => "/admin/surat/distribusi-surat.php?kegiatan_id={$selected_kegiatan_id}",
                            'type' => 'humas_dispatch'
                        ]
                    );
                }

                redirect('admin/surat/staging-surat.php?kegiatan_id={$selected_kegiatan_id}', "{$count_sent} surat berhasil diverifikasi & dikirim ke Humas untuk disebar!", "success");
            } else {
                $error = "Gagal memproses pengiriman ke Humas.";
            }
        }
    }
}

// HANDLE KIRIM KE HUMAS (INDIVIDUAL VIA GET)
if (isset($_GET['kirim_humas']) && is_numeric($_GET['kirim_humas'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_target = (int)$_GET['kirim_humas'];
        $surat_target = dbFetchOne("SELECT nomor_surat, perihal, kegiatan_id FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id_target, $periode_id], "ii");
        if ($surat_target) {
            dbQuery("UPDATE arsip_surat SET status_humas = 'siap_disebar' WHERE nomor_surat = ? AND periode_id = ?", [$surat_target['nomor_surat'], $periode_id]);
            auditLog('UPDATE', 'arsip_surat', $id_target, 'Verifikasi & kirim surat ke Humas: ' . $surat_target['nomor_surat']);
            
            // FCM Notification ke Humas & Ketuplat
            $humasUsers = dbFetchAll("
                SELECT id FROM users WHERE role IN ('humas', 'admin_humas') AND is_active = 1
                UNION
                SELECT user_id AS id FROM kegiatan_panitia WHERE event_role IN ('ketuplat', 'sie_humas')
            ");
            $targetUserIds = array_column($humasUsers, 'id');
            if (!empty($targetUserIds)) {
                sendFcmNotification(
                    $targetUserIds,
                    "📨 Surat Baru Siap Disebar!",
                    "Surat " . $surat_target['nomor_surat'] . " (" . $surat_target['perihal'] . ") telah diverifikasi Sekretaris.",
                    [
                        'click_action' => "/admin/surat/distribusi-surat.php?kegiatan_id=" . ($surat_target['kegiatan_id'] ?? 0),
                        'type' => 'humas_dispatch'
                    ]
                );
            }

            redirect('admin/surat/staging-surat.php?kegiatan_id={$selected_kegiatan_id}', "Surat ({$surat_target['nomor_surat']}) telah diverifikasi & berhasil dikirim ke Humas!", "success");
        }
    }
}

// HANDLE KEMBALIKAN KE DRAFT (INDIVIDUAL VIA GET)
if (isset($_GET['batal_humas']) && is_numeric($_GET['batal_humas'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_target = (int)$_GET['batal_humas'];
        $surat_target = dbFetchOne("SELECT nomor_surat FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id_target, $periode_id], "ii");
        if ($surat_target) {
            dbQuery("UPDATE arsip_surat SET status_humas = 'draft' WHERE nomor_surat = ? AND periode_id = ?", [$surat_target['nomor_surat'], $periode_id]);
            redirect('admin/surat/staging-surat.php?kegiatan_id={$selected_kegiatan_id}', "Status surat dikembalikan ke Draft.", "success");
        }
    }
}

// HANDLE HAPUS STAGING
if (isset($_GET['hapus']) && is_numeric($_GET['hapus'])) {
    if (hash_equals($_SESSION['csrf_token'] ?? '', $_GET['csrf_token'] ?? '')) {
        $id_hapus = (int)$_GET['hapus'];
        $surat_target = dbFetchOne("SELECT * FROM arsip_surat WHERE id = ? AND periode_id = ? AND status_arsip = 'staging'", [$id_hapus, $periode_id], "ii");
        if ($surat_target) {
            // Hapus file lampiran jika ada
            if (!empty($surat_target['konten_surat'])) {
                $konten = json_decode((string)$surat_target['konten_surat'], true);
                if (isset($konten['lampiran_files']) && is_array($konten['lampiran_files'])) {
                    foreach ($konten['lampiran_files'] as $rel_path) {
                        deleteFile($rel_path);
                    }
                }
            }
            dbQuery("DELETE FROM arsip_surat WHERE id = ?", [$id_hapus], "i");
            resyncStagingNumbers($periode_id);
            redirect('admin/surat/staging-surat.php?kegiatan_id={$selected_kegiatan_id}', "Surat di area staging berhasil dihapus.", "success");
        } else {
            $error = "Surat staging tidak ditemukan.";
        }
    } else {
        $error = "Token keamanan tidak valid.";
    }
}

// Ambil data surat di staging area
$where_kegiatan = "";
$params = [$periode_id];
$types = "i";

if ($selected_kegiatan_id > 0) {
    $where_kegiatan = " AND s.kegiatan_id = ?";
    $params[] = $selected_kegiatan_id;
    $types .= "i";
}

$query = "SELECT s.*, k.nama_kegiatan as nama_event, k.status as status_event 
          FROM arsip_surat s 
          LEFT JOIN kegiatan k ON s.kegiatan_id = k.id 
          WHERE s.periode_id = ? AND s.status_arsip = 'staging' {$where_kegiatan}
          ORDER BY s.id ASC";

$staging_list_raw = dbFetchAll($query, $params, $types);

$rapat_perihals = ['Undangan Rapat Persiapan', 'Undangan Rapat Pemantapan', 'Undangan Rapat Final'];
$rapat_letters = [];
$regular_staging = [];

foreach ($staging_list_raw as $s) {
    if (in_array(trim($s['perihal']), $rapat_perihals)) {
        $rapat_letters[] = $s;
    } else {
        $regular_staging[] = $s;
    }
}

// Grouping regular staging by nomor_surat (untuk Desktop)
$grouped_staging = [];
foreach ($regular_staging as $s) {
    $grouped_staging[$s['nomor_surat']][] = $s;
}

// Grouping by perihal (untuk Mobile)
$grouped_mobile = [];
foreach ($grouped_staging as $nomor_surat => $items) {
    $parent = $items[0];
    $perihal = trim($parent['perihal']);
    $ns_parts = explode('/', $parent['nomor_surat']);
    $dl = $ns_parts[1] ?? 'D';
    $label_dl = ($dl === 'D') ? 'Dalam' : 'Luar';
    
    $group_key = $perihal . '|' . $label_dl;
    if (!isset($grouped_mobile[$group_key])) {
        $grouped_mobile[$group_key] = [
            'perihal' => $perihal,
            'label_dl' => $label_dl,
            'items' => []
        ];
    }
    $grouped_mobile[$group_key]['items'][] = $items;
}

// Hitung total staging keseluruhan periode dan breakdown per status
$total_staging_count = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND status_arsip = 'staging'", [$periode_id], "i")['total'] ?? 0;
$where_count_keg = ($selected_kegiatan_id > 0) ? " AND kegiatan_id = {$selected_kegiatan_id}" : "";
$cnt_sekretaris = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND status_arsip = 'staging' AND (status_humas IS NULL OR status_humas = '' OR status_humas = 'draft'){$where_count_keg}", [$periode_id], "i")['total'] ?? 0;
$cnt_humas = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND status_arsip = 'staging' AND status_humas = 'siap_disebar' AND (tanggal_dikirim IS NULL OR tanggal_dikirim = '0000-00-00'){$where_count_keg}", [$periode_id], "i")['total'] ?? 0;
$cnt_terkirim = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND status_arsip = 'staging' AND (status_humas = 'terkirim' OR (tanggal_dikirim IS NOT NULL AND tanggal_dikirim != '0000-00-00')){$where_count_keg}", [$periode_id], "i")['total'] ?? 0;
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --card-bg: rgba(15, 18, 23, 0.95);
    --border-color: #2a3545;
    --accent-color: #4A90E2;
}

.staging-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.staging-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.staging-header h1 {
    font-weight: 700;
    letter-spacing: -0.5px;
    background: var(--primary-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
}

.badge-staging {
    background: rgba(241, 196, 15, 0.15);
    color: #f1c40f;
    border: 1px solid rgba(241, 196, 15, 0.3);
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.kegiatan-switcher {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 16px;
    padding: 16px 20px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
}

.kegiatan-switcher select {
    background: #0a0c10;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: #fff;
    padding: 10px 14px;
    font-size: 0.9rem;
    font-weight: 600;
    min-width: 280px;
}

.staging-actions-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(74, 144, 226, 0.08);
    border: 1px solid rgba(74, 144, 226, 0.3);
    border-radius: 16px;
    padding: 15px 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.btn-commit {
    background: linear-gradient(135deg, #27ae60 0%, #2ecc71 100%);
    color: white;
    border: none;
    padding: 10px 22px;
    border-radius: 30px;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(46, 204, 113, 0.3);
}

.btn-commit:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(46, 204, 113, 0.4);
}

.table-responsive {
    overflow-x: auto;
}

.staging-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--card-bg);
    border-radius: 16px;
    border: 1px solid var(--border-color);
    overflow: hidden;
}

.staging-table th, .staging-table td {
    padding: 14px 18px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.staging-table th {
    background: rgba(255, 255, 255, 0.03);
    color: #8BB9F0;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.staging-table tr:last-child td {
    border-bottom: none;
}

/* Mobile Adjustments */
.show-mobile-only {
    display: none;
}
.action-menu {
    position: relative;
    display: inline-block;
}
.hamburger-btn {
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    color: #fff;
    padding: 6px 12px;
    border-radius: 6px;
    cursor: pointer;
}
.action-dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: 100%;
    background: #0f1217;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    min-width: 170px;
    z-index: 999;
    box-shadow: 0 10px 25px rgba(0,0,0,0.8);
    flex-direction: column;
    padding: 5px;
    gap: 5px;
    margin-top: 5px;
}
.action-dropdown.show {
    display: flex;
}
.action-dropdown a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    color: #fff;
    text-decoration: none;
    font-size: 0.85rem;
    border-radius: 6px;
    white-space: nowrap;
}
.action-dropdown a:hover {
    background: rgba(255,255,255,0.05);
}
.desktop-actions {
    display: flex;
    gap: 6px;
    justify-content: center;
    align-items: center;
}
.mobile-actions {
    display: none;
}

@media (max-width: 768px) {
    .hide-mobile {
        display: none !important;
    }
    .show-mobile-only {
        display: table-cell !important;
    }
    .staging-table th, .staging-table td {
        padding: 12px 10px;
    }
    .desktop-actions {
        display: none !important;
    }
    .mobile-actions {
        display: flex !important;
        justify-content: center;
    }
    
    .rapat-header {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 15px;
    }
    .rapat-item {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 12px;
    }
    .rapat-item > div:first-child {
        min-width: 0 !important;
        width: 100%;
    }
    .rapat-item-actions {
        width: 100%;
        justify-content: flex-start;
    }
    
    .mobile-cards-container {
        display: flex !important;
        flex-direction: column;
        gap: 20px;
    }
    .mobile-group-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        overflow: hidden;
    }
    .mgc-header {
        background: rgba(255,255,255,0.03);
        padding: 12px 15px;
        border-bottom: 1px solid var(--border-color);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }
    .mgc-header h4 {
        margin: 0;
        color: #fff;
        font-size: 1rem;
        word-break: break-word;
    }
    .badge-dl {
        background: rgba(74, 144, 226, 0.15); 
        color: #4A90E2; 
        border: 1px solid rgba(74, 144, 226, 0.3);
        font-size: 0.75rem; padding: 4px 8px; border-radius: 6px;
    }
    .mgc-item {
        display: flex;
        align-items: center;
        padding: 12px 15px;
        border-bottom: 1px solid rgba(255,255,255,0.05);
        gap: 10px;
        transition: background 0.2s;
        position: relative;
    }
    .mgc-item:last-child {
        border-bottom: none;
    }
    .mgc-item-no {
        font-weight: bold;
        color: #4facfe;
        font-size: 1.1rem;
        min-width: 35px;
    }
    .mgc-item-tujuan {
        flex: 1;
        color: #ccc;
        font-size: 0.85rem;
        line-height: 1.3;
    }
    
    .mobile-checkbox-col {
        display: none;
        margin-right: 5px;
    }
    body.selection-mode-active .mobile-checkbox-col {
        display: block;
    }
    body.selection-mode-active .mgc-item {
        cursor: pointer;
    }
}
</style>

<div class="staging-container">
    <div class="staging-header">
        <div>
            <h1><i class="fas fa-layer-group"></i> Staging Index Surat</h1>
            <p style="color: #aaa; font-size: 0.9rem; margin-top: 5px;">
                Arsip surat kegiatan yang sedang dalam proses penerbitan dan penyebaran oleh Humas.
            </p>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <span class="badge-staging" style="background: rgba(243, 156, 18, 0.15); color: #f39c12; border-color: rgba(243, 156, 18, 0.3);" title="1. Surat masih di Sekretaris (Belum dikirim ke Humas)">
                <i class="fas fa-clock"></i> <b><?php echo $cnt_sekretaris; ?></b> Draft
            </span>
            <span class="badge-staging" style="background: rgba(52, 152, 219, 0.15); color: #3498db; border-color: rgba(52, 152, 219, 0.3);" title="2. Surat sudah di Humas (Sedang diproses / belum disebar ke tujuan)">
                <i class="fas fa-spinner"></i> <b><?php echo $cnt_humas; ?></b> Diproses
            </span>
            <span class="badge-staging" style="background: rgba(46, 204, 113, 0.15); color: #2ecc71; border-color: rgba(46, 204, 113, 0.3);" title="3. Surat telah dikirim oleh Humas ke tujuan">
                <i class="fas fa-check-circle"></i> <b><?php echo $cnt_terkirim; ?></b> Terkirim
            </span>
            <a href="buat-surat.php" class="btn-buat" style="background:#4A90E2; color:white; padding:8px 16px; border-radius:8px; text-decoration:none; font-weight:bold; font-size:0.85rem; display:inline-flex; align-items:center; gap:6px;">
                <i class="fas fa-plus"></i> Buat Surat Baru
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" style="background: rgba(231, 76, 60, 0.1); border: 1px solid #e74c3c; color: #ff6b6b; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success" style="background: rgba(46, 204, 113, 0.1); border: 1px solid #2ecc71; color: #2ecc71; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- KEGIATAN SWITCHER -->
    <div class="kegiatan-switcher">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-filter" style="color: #4A90E2; font-size: 1.2rem;"></i>
            <span style="font-weight: 600; color: #fff;">Filter Kegiatan Staging:</span>
        </div>
        <form method="GET" action="staging-surat.php" style="display: flex; align-items: center; gap: 10px; margin: 0;">
            <select name="kegiatan_id" onchange="this.form.submit()">
                <option value="0">-- Semua Surat Staging Kegiatan --</option>
                <?php foreach ($kegiatan_list as $kg): ?>
                    <option value="<?php echo $kg['id']; ?>" <?php echo ((int)$selected_kegiatan_id === (int)$kg['id']) ? 'selected' : ''; ?>>
                        [<?php echo strtoupper($kg['status']); ?>] <?php echo htmlspecialchars($kg['nama_kegiatan']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <!-- FORM COMMIT STAGING -->
    <form method="POST" id="commitForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" id="formActionInput" value="commit_archive">

        <div class="staging-actions-bar" style="margin-bottom: 25px;">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; color: #fff; font-weight: 600; margin: 0;">
                    <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)" style="width: 18px; height: 18px;">
                    <span>Pilih Semua Surat</span>
                </label>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="color: #aaa; font-size: 0.85rem;">Tanggal Dikirim Resmi:</span>
                    <input type="date" name="tanggal_dikirim" value="<?php echo date('Y-m-d'); ?>" style="background: #0a0c10; border: 1px solid var(--border-color); border-radius: 8px; color: #fff; padding: 6px 12px; font-size: 0.85rem;">
                </div>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="btn-commit" style="background: linear-gradient(135deg, #2980b9 0%, #3498db 100%); box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);" onclick="submitBatchAction('send_to_humas_batch', 'Verifikasi & kirim surat terpilih ke Humas untuk disebar?')">
                    <i class="fas fa-paper-plane"></i> Kirim Terpilih ke Humas
                </button>
                <button type="button" class="btn-commit" onclick="submitBatchAction('commit_archive', 'Pindahkan surat terpilih dari Staging Area ke Arsip Utama?')">
                    <i class="fas fa-archive"></i> Arsip Surat Terpilih (Commit)
                </button>
            </div>
        </div>

    <!-- CARD SURAT RAPAT INTERNAL BPM -->
    <?php if (!empty($rapat_letters) || $selected_kegiatan_id > 0): ?>
    <div style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; padding: 20px; margin-bottom: 25px;">
        <div class="rapat-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px;">
            <div>
                <h3 style="margin: 0; font-size: 1.1rem; color: #fff; display: flex; align-items: center; gap: 10px; font-weight: 700; word-break: break-word; line-height: 1.3;">
                    <i class="fas fa-users-cog" style="color: #4A90E2; flex-shrink: 0;"></i> Surat Rapat Internal & Pemantapan BPM
                </h3>
                <p style="color: #8A99AD; font-size: 0.85rem; margin: 6px 0 0 0; line-height: 1.4;">
                    Surat rapat memiliki nomor surat paling awal. Tanggal, waktu, dan tempat belum fiks. Hapus jika kegiatan tidak memerlukan rapat.
                </p>
            </div>
            <span class="badge" style="background: rgba(74, 144, 226, 0.15); color: #4A90E2; border: 1px solid rgba(74, 144, 226, 0.3); padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; white-space: nowrap;">
                <?php echo count($rapat_letters); ?> Surat Rapat
            </span>
        </div>

        <?php if (empty($rapat_letters)): ?>
            <div style="text-align: center; padding: 15px; color: #777; font-size: 0.9rem;">
                <i class="fas fa-info-circle"></i> Tidak ada surat rapat internal aktif untuk kegiatan ini (Telah dihapus manual / tidak digunakan).
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 10px;">
                <?php foreach ($rapat_letters as $r_item): 
                    $r_konten = json_decode($r_item['konten_surat'], true) ?: [];
                    $r_is_edited = !empty($r_konten['is_edited']);
                    $r_status_h = $r_item['status_humas'] ?? 'draft';
                    $r_is_sent = (!empty($r_item['tanggal_dikirim']) && $r_item['tanggal_dikirim'] !== '0000-00-00') || $r_status_h === 'terkirim';
                ?>
                    <div class="rapat-item" style="display: flex; justify-content: space-between; align-items: center; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); border-radius: 10px; padding: 14px 18px; flex-wrap: wrap; gap: 10px;">
                        <div style="flex: 1; display: flex; align-items: center; gap: 15px; min-width: 280px; word-break: break-word;">
                            <?php if ($r_is_edited): ?>
                                <input type="checkbox" name="ids[]" value="<?php echo $r_item['id']; ?>" class="item-checkbox" style="width: 18px; height: 18px; cursor: pointer;">
                            <?php else: ?>
                                <span style="width: 18px; height: 18px; display: inline-block; opacity: 0.3;" title="Wajib di-edit terlebih dahulu sebelum dikirim/diarsipkan">
                                    <i class="fas fa-lock" style="font-size: 0.8rem; color: #777;"></i>
                                </span>
                            <?php endif; ?>
                            <div>
                                <strong style="color: #fff; font-size: 1rem; display: block; margin-bottom: 2px;">
                                    <?php echo htmlspecialchars($r_item['perihal']); ?>
                                </strong>
                                <span style="color: #4A90E2; font-size: 0.85rem; font-weight: 600;">
                                    <?php echo htmlspecialchars($r_item['nomor_surat']); ?>
                                </span>
                            </div>
                        </div>
                        <div class="rapat-item-actions" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <?php if ($r_is_edited): ?>
                                <a href="cetak-surat.php?id=<?php echo $r_item['id']; ?>" target="_blank" class="btn-buat" style="background: rgba(74, 144, 226, 0.15); color: #4A90E2; border: 1px solid rgba(74, 144, 226, 0.3); padding: 7px 12px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none;" title="Preview / Cetak Surat">
                                    <i class="fas fa-eye"></i> Preview
                                </a>
                            <?php endif; ?>
                            <a href="buat-surat.php?edit=<?php echo $r_item['id']; ?>" class="btn-edit" style="background: rgba(241, 196, 15, 0.15); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.3); padding: 7px 12px; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 600;" title="Edit Surat">
                                <i class="fas fa-edit"></i> Edit
                            </a>
                            <?php if ($r_is_edited): ?>
                                <?php if (!$r_is_sent): ?>
                                    <?php if ($r_status_h !== 'siap_disebar'): ?>
                                        <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&kirim_humas=<?php echo $r_item['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                           onclick="return confirm('Kirim surat rapat ini ke Humas untuk disebar?')" 
                                           class="btn-buat" style="background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); padding: 7px 12px; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 600;" title="Kirim Surat ke Humas">
                                            <i class="fas fa-paper-plane"></i> Kirim Humas
                                        </a>
                                    <?php else: ?>
                                        <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&batal_humas=<?php echo $r_item['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                           onclick="return confirm('Kembalikan status surat ke Sekretaris (Draft)?')" 
                                           class="btn-buat" style="background: rgba(149, 165, 166, 0.15); color: #95a5a6; border: 1px solid rgba(149, 165, 166, 0.3); padding: 7px 12px; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 600;" title="Kembalikan ke Sekretaris">
                                            <i class="fas fa-undo"></i> Batal Kirim
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); font-size: 0.8rem; padding: 6px 12px; border-radius: 8px;">
                                        <i class="fas fa-check-circle"></i> Terkirim
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&hapus=<?php echo $r_item['id']; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?? ''; ?>" 
                               onclick="return confirm('Apakah Anda yakin ingin menghapus <?php echo htmlspecialchars($r_item['perihal']); ?>?');" 
                               class="btn-hapus" style="background: rgba(231, 76, 60, 0.15); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); padding: 7px 12px; border-radius: 8px; text-decoration: none; font-size: 0.85rem; font-weight: 600;" title="Hapus Staging">
                                <i class="fas fa-trash"></i> Hapus
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

        <!-- MOBILE VIEW CARDS -->
        <div class="mobile-cards-container show-mobile-only">
            <?php if (empty($grouped_mobile)): ?>
                <div style="padding: 40px; text-align: center; color: #777;">
                    <i class="fas fa-inbox" style="font-size: 2.5rem; color: #444; display: block; margin-bottom: 10px;"></i>
                    Tidak ada surat di area staging untuk kegiatan ini.
                </div>
            <?php else: ?>
                <?php foreach ($grouped_mobile as $g): ?>
                    <div class="mobile-group-card">
                        <div class="mgc-header">
                            <h4><?php echo htmlspecialchars($g['perihal']); ?></h4>
                            <span class="badge badge-dl"><?php echo $g['label_dl']; ?></span>
                        </div>
                        <div class="mgc-body">
                            <?php foreach ($g['items'] as $items): 
                                $parent = $items[0];
                                $ns_parts = explode('/', $parent['nomor_surat']);
                                $no_surat_pendek = $ns_parts[0] ?? '-';
                                $status_h = $parent['status_humas'] ?? 'draft';
                                $is_sent = (!empty($parent['tanggal_dikirim']) && $parent['tanggal_dikirim'] !== '0000-00-00') || $status_h === 'terkirim';
                            ?>
                                <div class="mgc-item" ontouchstart="handleTouchStart(event, this)" ontouchend="handleTouchEnd(event, this)" ontouchmove="handleTouchMove(event, this)" oncontextmenu="return false;" onclick="handleItemClick(event, this)">
                                    <div class="mobile-checkbox-col">
                                        <input type="checkbox" name="ids[]" value="<?php echo $parent['id']; ?>" class="item-checkbox mobile-checkbox" onclick="event.stopPropagation();">
                                    </div>
                                    <div class="mgc-item-no"><?php echo htmlspecialchars($no_surat_pendek); ?></div>
                                    <div class="mgc-item-tujuan"><?php echo nl2br(htmlspecialchars($parent['tujuan'])); ?></div>
                                    <div class="mgc-item-status" style="margin-right: 5px;">
                                        <?php if ($is_sent): ?>
                                            <i class="fas fa-check-circle" style="color: #2ecc71; font-size: 1.2rem;" title="Terkirim"></i>
                                        <?php elseif ($status_h === 'siap_disebar'): ?>
                                            <i class="fas fa-spinner" style="color: #3498db; font-size: 1.2rem;" title="Diproses"></i>
                                        <?php else: ?>
                                            <i class="fas fa-clock" style="color: #f39c12; font-size: 1.2rem;" title="Draft"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="action-menu" onclick="event.stopPropagation();">
                                        <button type="button" class="hamburger-btn" onclick="toggleDropdown(this)" style="padding: 4px 8px;"><i class="fas fa-ellipsis-v"></i></button>
                                        <div class="action-dropdown" style="text-align: left;">
                                            <a href="cetak-surat.php?id=<?php echo $parent['id']; ?>" target="_blank" style="color: #4A90E2;"><i class="fas fa-eye" style="width: 20px; text-align: center;"></i> Preview/Cetak</a>
                                            <a href="buat-surat.php?edit=<?php echo $parent['id']; ?>" style="color: #f1c40f;"><i class="fas fa-edit" style="width: 20px; text-align: center;"></i> Edit Surat</a>
                                            <?php if (!$is_sent): ?>
                                                <?php if ($status_h !== 'siap_disebar'): ?>
                                                    <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&kirim_humas=<?php echo $parent['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" onclick="return confirm('Kirim surat ini ke Humas untuk disebar?')" style="color: #2ecc71;"><i class="fas fa-paper-plane" style="width: 20px; text-align: center;"></i> Kirim ke Humas</a>
                                                <?php else: ?>
                                                    <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&batal_humas=<?php echo $parent['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" onclick="return confirm('Kembalikan status surat ke Sekretaris (Draft)?')" style="color: #95a5a6;"><i class="fas fa-undo" style="width: 20px; text-align: center;"></i> Batal Kirim</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&hapus=<?php echo $parent['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" onclick="return confirm('Hapus surat staging ini?')" style="color: #e74c3c;"><i class="fas fa-trash" style="width: 20px; text-align: center;"></i> Hapus Staging</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- DESKTOP VIEW TABLE -->
        <div class="table-responsive hide-mobile">
            <table class="staging-table">
                <thead>
                    <tr>
                        <th width="4%" style="text-align:center;">Pilih</th>
                        <th width="5%" style="text-align:center;">No</th>
                        <th width="24%">Nomor Surat</th>
                        <th width="22%">Perihal</th>
                        <th width="22%">Dituju Kepada</th>
                        <th width="13%" style="text-align:center;">Status Surat</th>
                        <th width="10%" style="text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grouped_staging)): ?>
                        <tr>
                            <td colspan="7" style="padding: 40px; text-align: center; color: #777;">
                                <i class="fas fa-inbox" style="font-size: 2.5rem; color: #444; display: block; margin-bottom: 10px;"></i>
                                Tidak ada surat di area staging untuk kegiatan ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php $no = 1; foreach ($grouped_staging as $nomor_surat => $items): 
                            $parent = $items[0];
                            $has_children = count($items) > 1;
                        ?>
                        <tr>
                            <td style="text-align:center;">
                                <input type="checkbox" name="ids[]" value="<?php echo $parent['id']; ?>" class="item-checkbox" style="width: 18px; height: 18px;">
                            </td>
                            <td style="text-align:center; font-weight: bold; color: #fff;"><?php echo $no++; ?></td>
                            <td>
                                <strong style="color: #4A90E2; font-size: 0.95rem;"><?php echo htmlspecialchars($parent['nomor_surat']); ?></strong>
                                <?php if ($has_children): ?>
                                    <span class="badge" style="background: rgba(156, 39, 176, 0.2); color: #9C27B0; border: 1px solid rgba(156, 39, 176, 0.3); font-size: 0.7rem; padding: 2px 6px; border-radius: 6px; margin-left: 6px;">
                                        <?php echo count($items); ?> Recipient Group
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="color: #ddd; font-size: 0.9rem;"><?php echo htmlspecialchars($parent['perihal']); ?></td>
                            <td style="color: #ccc; font-size: 0.85rem;"><?php echo nl2br(htmlspecialchars($parent['tujuan'])); ?></td>
                            <td style="text-align:center;">
                                <?php 
                                $status_h = $parent['status_humas'] ?? 'draft';
                                $is_sent = (!empty($parent['tanggal_dikirim']) && $parent['tanggal_dikirim'] !== '0000-00-00') || $status_h === 'terkirim';
                                if ($is_sent): 
                                ?>
                                    <span class="badge" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); font-size: 0.75rem; padding: 5px 10px; border-radius: 8px; display: inline-block;" title="Surat sudah dikirim oleh Humas ke tujuan">
                                        <i class="fas fa-check-circle"></i> Terkirim
                                    </span>
                                <?php elseif ($status_h === 'siap_disebar'): ?>
                                    <span class="badge" style="background: rgba(52, 152, 219, 0.2); color: #3498db; border: 1px solid rgba(52, 152, 219, 0.3); font-size: 0.75rem; padding: 5px 10px; border-radius: 8px; display: inline-block;" title="Surat sudah di Humas, sedang diproses / belum dikirim ke tujuan">
                                        <i class="fas fa-spinner"></i> Diproses
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(243, 156, 18, 0.2); color: #f39c12; border: 1px solid rgba(243, 156, 18, 0.3); font-size: 0.75rem; padding: 5px 10px; border-radius: 8px; display: inline-block;" title="Surat masih di Sekretaris (Belum dikirim ke Humas)">
                                        <i class="fas fa-clock"></i> Draft
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:center;">
                                <div style="display: flex; gap: 6px; justify-content: center; align-items: center;">
                                    <a href="cetak-surat.php?id=<?php echo $parent['id']; ?>" target="_blank" class="btn-buat" style="background: rgba(74, 144, 226, 0.15); color: #4A90E2; padding: 6px 10px; border-radius: 6px; font-size: 0.8rem;" title="Preview/Cetak">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="buat-surat.php?edit=<?php echo $parent['id']; ?>" class="btn-buat" style="background: rgba(241, 196, 15, 0.15); color: #f1c40f; padding: 6px 10px; border-radius: 6px; font-size: 0.8rem;" title="Edit Surat">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if (!$is_sent): ?>
                                        <?php if ($status_h !== 'siap_disebar'): ?>
                                            <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&kirim_humas=<?php echo $parent['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                               onclick="return confirm('Kirim surat ini ke Humas untuk disebar?')" 
                                               class="btn-buat" style="background: rgba(46, 204, 113, 0.15); color: #2ecc71; padding: 6px 10px; border-radius: 6px; font-size: 0.8rem;" title="Kirim Surat ke Humas">
                                                <i class="fas fa-paper-plane"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&batal_humas=<?php echo $parent['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                               onclick="return confirm('Kembalikan status surat ke Sekretaris (Draft)?')" 
                                               class="btn-buat" style="background: rgba(149, 165, 166, 0.15); color: #95a5a6; padding: 6px 10px; border-radius: 6px; font-size: 0.8rem;" title="Kembalikan ke Sekretaris">
                                                <i class="fas fa-undo"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <a href="staging-surat.php?kegiatan_id=<?php echo $selected_kegiatan_id; ?>&hapus=<?php echo $parent['id']; ?>&csrf_token=<?php echo csrfToken(); ?>" 
                                       onclick="return confirm('Hapus surat staging ini?')" 
                                       class="btn-buat" style="background: rgba(231, 76, 60, 0.15); color: #e74c3c; padding: 6px 10px; border-radius: 6px; font-size: 0.8rem;" title="Hapus Staging">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
let touchTimer;
let isSelectionMode = false;

function handleTouchStart(e, elem) {
    if (isSelectionMode) return;
    touchTimer = setTimeout(() => {
        activateSelectionMode();
        const cb = elem.querySelector('.mobile-checkbox');
        if (cb) cb.checked = true;
    }, 600); // 600ms long press
}

function handleTouchEnd(e, elem) {
    if (touchTimer) clearTimeout(touchTimer);
}

function handleTouchMove(e, elem) {
    if (touchTimer) clearTimeout(touchTimer);
}

function handleItemClick(e, elem) {
    if (isSelectionMode) {
        // Toggle checkbox on tap
        if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'BUTTON' && !e.target.closest('.action-menu')) {
            const cb = elem.querySelector('.mobile-checkbox');
            if (cb) {
                cb.checked = !cb.checked;
            }
        }
    }
}

function activateSelectionMode() {
    isSelectionMode = true;
    document.body.classList.add('selection-mode-active');
    if ('vibrate' in navigator) navigator.vibrate(50);
}

function toggleDropdown(btn) {
    const dropdown = btn.nextElementSibling;
    document.querySelectorAll('.action-dropdown.show').forEach(d => {
        if (d !== dropdown) d.classList.remove('show');
    });
    dropdown.classList.toggle('show');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.action-menu')) {
        document.querySelectorAll('.action-dropdown.show').forEach(d => d.classList.remove('show'));
    }
});

function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.item-checkbox');
    checkboxes.forEach(cb => cb.checked = master.checked);
}

function submitBatchAction(actionName, confirmMsg) {
    const checked = document.querySelectorAll('.item-checkbox:checked');
    if (checked.length === 0) {
        alert('Silakan pilih minimal satu surat terlebih dahulu.');
        return;
    }
    if (confirm(confirmMsg)) {
        document.getElementById('formActionInput').value = actionName;
        document.getElementById('commitForm').submit();
    }
}
</script>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
