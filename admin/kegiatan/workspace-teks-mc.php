<?php
// admin/workspace-teks-mc.php
require_once __DIR__ . '/../core/header.php';

$kegiatan_id = isset($_GET['kegiatan_id']) ? (int)$_GET['kegiatan_id'] : 0;
if (!$kegiatan_id) redirect('admin/core/dashboard.php', 'ID Kegiatan tidak valid.', 'error');

requireEventAccess($kegiatan_id, ['ketuplat', 'sie_acara']);
$kegiatan = dbFetchOne("SELECT * FROM kegiatan WHERE id = ?", [$kegiatan_id], "i");
if (!$kegiatan) redirect('admin/core/dashboard.php', 'Kegiatan tidak ditemukan.', 'error');

$periode_id = getUserPeriode();

$current_user_id = $_SESSION['admin_id'] ?? 0;
$user_role_row = dbFetchOne("SELECT event_role FROM kegiatan_panitia WHERE kegiatan_id = ? AND user_id = ?", [$kegiatan_id, $current_user_id], "ii");
$user_event_role = $user_role_row['event_role'] ?? '';
$is_ketuplat = in_array($user_event_role, ['ketuplat', 'ketua_pelaksana']) || (!empty($isSuperadmin)) || isSekretaris();

// Ambil Template Tujuan (Kepada Yth) dari database pengaturan-surat
$templates_tujuan = dbFetchAll("SELECT * FROM surat_templates WHERE jenis = 'tujuan' AND periode_id = ? ORDER BY label ASC", [$periode_id], "i");
if (empty($templates_tujuan)) {
    $templates_tujuan = dbFetchAll("SELECT * FROM surat_templates WHERE jenis = 'tujuan' ORDER BY label ASC");
}

// Check if there is existing Rundown for this kegiatan
$rundown_exist = dbFetchOne("SELECT * FROM arsip_rundown WHERE nama_acara = ? AND periode_id = ? ORDER BY id DESC LIMIT 1", [$kegiatan['nama_kegiatan'], $periode_id], "si");

// --- INITIALIZE EDIT MODE ---
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_data = null;

if ($edit_id > 0) {
    $edit_data = dbFetchOne("SELECT * FROM arsip_teks_mc WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
} else {
    // Check if there's an existing draft for this kegiatan
    $draft_mc = dbFetchOne("SELECT * FROM arsip_teks_mc WHERE kegiatan_id = ? AND periode_id = ? ORDER BY id DESC LIMIT 1", [$kegiatan_id, $periode_id], "ii");
    if ($draft_mc) {
        $edit_id = (int)$draft_mc['id'];
        $edit_data = $draft_mc;
    }
}

// --- POST HANDLER: SIMPAN NASKAH MC ---
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mc') {
    if (!csrfVerify()) {
        $error_msg = "Token CSRF tidak valid atau telah kedaluwarsa.";
    } else {
        $judul_naskah   = trim($_POST['judul_naskah'] ?? '');
        $tipe_acara     = trim($_POST['tipe_acara'] ?? 'formal');
        $catatan_mc     = trim($_POST['catatan_mc'] ?? '');
        $target_edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
        
        // Update daftar tamu dari Ketuplak jika diisi di workspace ini
        if (isset($_POST['tamu_undangan_ketuplak'])) {
            $tamu_ketuplak_post = trim($_POST['tamu_undangan_ketuplak']);
            dbQuery("UPDATE kegiatan SET tamu_undangan = ? WHERE id = ?", [$tamu_ketuplak_post, $kegiatan_id]);
            $kegiatan['tamu_undangan'] = $tamu_ketuplak_post;
        }

        $waktu_arr      = $_POST['waktu'] ?? [];
        $segmen_arr     = $_POST['segmen'] ?? [];
        $mc_speaker_arr = $_POST['mc_speaker'] ?? [];
        $script_arr     = $_POST['script_teks'] ?? [];
        $stage_cue_arr  = $_POST['stage_cue'] ?? [];
        $pengisi_arr    = $_POST['pengisi'] ?? [];

        $susunan_mc = [];
        $total_rows = count($segmen_arr);

        for ($i = 0; $i < $total_rows; $i++) {
            if (!empty($segmen_arr[$i]) || !empty($script_arr[$i])) {
                $susunan_mc[] = [
                    'waktu'       => trim($waktu_arr[$i] ?? ''),
                    'segmen'      => trim($segmen_arr[$i] ?? ''),
                    'mc_speaker'  => trim($mc_speaker_arr[$i] ?? 'MC'),
                    'script_teks' => trim($script_arr[$i] ?? ''),
                    'stage_cue'   => trim($stage_cue_arr[$i] ?? ''),
                    'pengisi'     => trim($pengisi_arr[$i] ?? ''),
                ];
            }
        }

        if (empty($judul_naskah)) {
            $error_msg = "Judul naskah MC wajib diisi.";
        } elseif (empty($susunan_mc)) {
            $error_msg = "Minimal harus ada 1 segmen naskah MC untuk disimpan.";
        } else {
            $susunan_json = json_encode($susunan_mc, JSON_UNESCAPED_UNICODE);
            $rundown_id = $rundown_exist ? (int)$rundown_exist['id'] : null;

            try {
                if ($target_edit_id > 0) {
                    dbQuery(
                        "UPDATE arsip_teks_mc SET judul_naskah = ?, tipe_acara = ?, susunan_mc = ?, catatan_mc = ?, rundown_id = ? WHERE id = ? AND periode_id = ?",
                        [$judul_naskah, $tipe_acara, $susunan_json, $catatan_mc, $rundown_id, $target_edit_id, $periode_id]
                    );
                    $success_msg = "Naskah Teks MC berhasil diperbarui di arsip.";
                    $edit_id = $target_edit_id;
                    $edit_data = dbFetchOne("SELECT * FROM arsip_teks_mc WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
                } else {
                    $new_id = dbInsert(
                        "INSERT INTO arsip_teks_mc (kegiatan_id, rundown_id, judul_naskah, tipe_acara, susunan_mc, catatan_mc, periode_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        [$kegiatan_id, $rundown_id, $judul_naskah, $tipe_acara, $susunan_json, $catatan_mc, $periode_id]
                    );
                    $success_msg = "Naskah Teks MC berhasil disimpan ke arsip.";
                    $edit_id = $new_id;
                    $edit_data = dbFetchOne("SELECT * FROM arsip_teks_mc WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
                }
            } catch (Exception $e) {
                $error_msg = "Gagal menyimpan Teks MC: " . $e->getMessage();
            }
        }
    }
}

// Default items if new and no edit_data
$items_list = [];
if ($edit_data && !empty($edit_data['susunan_mc'])) {
    $items_list = json_decode($edit_data['susunan_mc'], true) ?: [];
}

// Convert rundown items for JS auto-sync if available
$rundown_sync_data = [];
if ($rundown_exist && !empty($rundown_exist['rundown_json'])) {
    $r_days = json_decode($rundown_exist['rundown_json'], true) ?: [];
    foreach ($r_days as $dIdx => $day) {
        $dayNum = $dIdx + 1;
        if (isset($day['items'])) {
            foreach ($day['items'] as $item) {
                $rundown_sync_data[] = [
                    'waktu' => (count($r_days) > 1 ? "Day $dayNum " : "") . ($item['waktu'] ?? ''),
                    'segmen' => $item['acara'] ?? '',
                    'pengisi' => $item['pj'] ?? '',
                    'keterangan' => $item['keterangan'] ?? ''
                ];
            }
        }
    }
}
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --mc-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    --card-bg: rgba(15, 18, 23, 0.95);
    --input-bg: #0a0c10;
    --border-color: #2a3545;
    --accent-color: #f5576c;
}

.mc-workspace-container {
    max-width: 1400px;
    margin: 0 auto;
    animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    padding: 30px;
    margin-bottom: 24px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.4);
    backdrop-filter: blur(15px);
}

.card-header {
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 15px;
    color: var(--accent-color);
}

.card-header h2 {
    margin: 0;
    font-size: 1.5rem;
    color: #fff;
}

.info-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
    margin-bottom: 25px;
}

@media (min-width: 768px) {
    .info-grid { grid-template-columns: 2fr 1fr; }
}

.form-group label {
    display: block;
    font-size: 0.75rem;
    color: #888;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
    font-weight: 700;
}

.form-group input, .form-group select, .form-group textarea {
    width: 100%;
    background: #080808;
    border: 1px solid var(--border-color);
    padding: 14px 18px;
    border-radius: 12px;
    color: #fff;
    font-size: 1rem;
    transition: all 0.3s;
}

.form-group input:focus, .form-group select:focus, .form-group textarea:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 15px rgba(245, 87, 108, 0.2);
    outline: none;
}

.mc-table-wrapper {
    overflow-x: auto;
    margin-top: 15px;
}

.mc-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 12px;
    min-width: 900px;
}

.mc-table th {
    text-align: left;
    padding: 12px 15px;
    color: #666;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.mc-table td {
    padding: 12px;
    background: rgba(255,255,255,0.02);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
    vertical-align: top;
}

.mc-table tr td:first-child {
    border-left: 1px solid var(--border-color);
    border-radius: 12px 0 0 12px;
    text-align: center;
    width: 45px;
}

.mc-table tr td:last-child {
    border-right: 1px solid var(--border-color);
    border-radius: 0 12px 12px 0;
    width: 60px;
    text-align: center;
}

.mc-table input, .mc-table textarea, .mc-table select {
    width: 100%;
    background: #080808;
    border: 1px solid var(--border-color);
    padding: 10px 12px;
    border-radius: 8px;
    color: #fff;
    font-size: 0.9rem;
}

.mc-table textarea {
    resize: vertical;
    min-height: 70px;
    font-family: inherit;
    line-height: 1.4;
}

.btn-add-row {
    background: rgba(245, 87, 108, 0.1);
    color: var(--accent-color);
    border: 1px dashed var(--accent-color);
    padding: 14px;
    border-radius: 12px;
    width: 100%;
    text-align: center;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s;
    margin-top: 15px;
}

.btn-add-row:hover {
    background: rgba(245, 87, 108, 0.2);
    transform: translateY(-2px);
}

.btn-sync-rundown {
    background: rgba(79, 172, 254, 0.15);
    color: #4facfe;
    border: 1px solid rgba(79, 172, 254, 0.3);
    padding: 10px 18px;
    border-radius: 10px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.btn-sync-rundown:hover {
    background: rgba(79, 172, 254, 0.3);
    transform: translateY(-2px);
}

.btn-remove-row {
    background: rgba(231, 76, 60, 0.1);
    color: #e74c3c;
    border: none;
    border-radius: 8px;
    width: 36px;
    height: 36px;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-remove-row:hover {
    background: rgba(231, 76, 60, 0.25);
}

.actions-bar {
    position: sticky;
    bottom: 20px;
    background: rgba(15, 18, 23, 0.9);
    backdrop-filter: blur(12px);
    padding: 18px 30px;
    border-radius: 20px;
    border: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 -10px 30px rgba(0,0,0,0.4);
    margin-top: 30px;
    z-index: 100;
}

.btn-save {
    background: var(--mc-gradient);
    border: none;
    color: #fff;
    padding: 14px 35px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s;
    box-shadow: 0 10px 20px rgba(245, 87, 108, 0.3);
}

.btn-save:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(245, 87, 108, 0.4);
}

.btn-reader {
    background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    border: none;
    color: #fff;
    padding: 14px 25px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s;
    box-shadow: 0 10px 20px rgba(56, 239, 125, 0.25);
}

.btn-reader:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 25px rgba(56, 239, 125, 0.4);
    color: #fff;
}

.btn-pdf {
    background: rgba(241, 196, 15, 0.15);
    color: #f1c40f;
    border: 1px solid rgba(241, 196, 15, 0.3);
    padding: 14px 25px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s;
}

.btn-pdf:hover {
    background: rgba(241, 196, 15, 0.3);
    color: #f1c40f;
    transform: translateY(-3px);
}

/* ============ RESPONSIVE MOBILE ============ */
@media (max-width: 768px) {
    .mc-workspace-container { padding: 0 4px; }

    .card {
        padding: 16px;
        border-radius: 18px;
        margin-bottom: 16px;
    }

    .card-header {
        gap: 10px;
        margin-bottom: 16px;
    }
    .card-header h2 {
        font-size: 1.1rem;
        line-height: 1.3;
    }
    .card-header .fa-2x { font-size: 1.4rem; }

    .info-grid {
        gap: 14px;
        margin-bottom: 16px;
    }

    .form-group input, .form-group select, .form-group textarea {
        padding: 12px 14px;
        font-size: 0.95rem;
    }

    /* Tombol di header kartu tamu: full width stack */
    .card > div[style*="justify-content: space-between"] {
        gap: 10px;
    }

    /* Ubah tabel jadi kartu vertikal */
    .mc-table-wrapper {
        overflow-x: visible;
    }
    .mc-table {
        min-width: 0;
        border-spacing: 0;
        display: block;
    }
    .mc-table thead {
        display: none;
    }
    .mc-table tbody, .mc-table tr, .mc-table td {
        display: block;
        width: 100% !important;
    }
    .mc-table tr {
        background: rgba(255,255,255,0.02);
        border: 1px solid var(--border-color);
        border-radius: 14px;
        margin-bottom: 14px;
        padding: 4px 0;
        overflow: hidden;
    }
    .mc-table tr td:first-child {
        border: none;
        border-radius: 0;
        text-align: left;
        width: auto;
        padding: 10px 14px 0;
        font-size: 0.75rem;
        color: #888;
        font-weight: 700;
        letter-spacing: 1px;
    }
    .mc-table tr td:first-child::before { content: "NO "; }
    .mc-table tr td:last-child {
        border: none;
        border-radius: 0;
        width: auto;
        text-align: right;
        padding: 8px 14px;
    }
    .mc-table td {
        border: none !important;
        border-radius: 0 !important;
        padding: 10px 14px;
        background: transparent;
    }
    .mc-table td:not(:first-child):not(:last-child)::before {
        display: block;
        font-size: 0.7rem;
        color: #888;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 6px;
        font-weight: 700;
    }
    /* Label per kolom (urutan: 2=Waktu, 3=Segmen, 4=Script, 5=Cue) */
    .mc-table td:nth-child(2)::before { content: "Waktu"; }
    .mc-table td:nth-child(3)::before { content: "Segmen & Pengisi (MC)"; }
    .mc-table td:nth-child(4)::before { content: "Naskah Bicara MC (Script)"; }
    .mc-table td:nth-child(5)::before { content: "Catatan Panggung (Cue)"; }
    .mc-table td:nth-child(6)::before { content: "Aksi"; }

    .mc-table input, .mc-table textarea, .mc-table select {
        font-size: 0.95rem;
    }
    .mc-table textarea { min-height: 64px; }

    .btn-add-row {
        padding: 12px;
        font-size: 0.9rem;
    }

    /* Actions bar: stack vertikal, tombol full width */
    .actions-bar {
        flex-direction: column;
        gap: 12px;
        padding: 14px 16px;
        bottom: 10px;
        border-radius: 16px;
        position: sticky;
    }
    .actions-bar > div:first-child { width: 100%; }
    .actions-bar > div:last-child {
        width: 100%;
        flex-direction: column;
        gap: 10px;
    }
    .actions-bar .btn-reader,
    .actions-bar .btn-pdf,
    .actions-bar .btn-save {
        width: 100%;
        justify-content: center;
        padding: 13px;
        font-size: 0.95rem;
    }

    /* Kartu tamu undangan: stack tombol ke bawah */
    .card > div[style*="background: rgba(241, 196, 15"] > div {
        flex-direction: column;
        align-items: stretch !important;
    }
}

@media (max-width: 480px) {
    .card { padding: 14px; }
    .card-header h2 { font-size: 1rem; }
    .actions-bar { margin-left: -4px; margin-right: -4px; }
}
</style>

<div class="mc-workspace-container">

    <?php if ($success_msg): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 12px; border: 1px solid rgba(46, 204, 113, 0.2); margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
            <a href="<?php echo baseUrl('admin/rundown/arsip-teks-mc.php'); ?>" style="color: #4facfe; margin-left: 10px; text-decoration: underline;">Lihat Arsip Teks MC →</a>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; padding: 15px; border-radius: 12px; border: 1px solid rgba(231, 76, 60, 0.2); margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="mcForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_mc">
        <?php if ($edit_id > 0): ?>
            <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
        <?php endif; ?>

        <div class="card">
            <div class="card-header" style="justify-content: space-between; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <i class="fas fa-microphone-alt fa-2x"></i>
                    <div>
                        <h2>Workspace: Teks MC (Master of Ceremony)</h2>
                        <small style="color: #888;">Kegiatan: <?php echo htmlspecialchars($kegiatan['nama_kegiatan']); ?></small>
                    </div>
                </div>
                
                <?php if (!empty($rundown_sync_data)): ?>
                    <button type="button" class="btn-sync-rundown" onclick="syncFromRundown()">
                        <i class="fas fa-sync-alt"></i> Impor / Auto-Generate dari Rundown
                    </button>
                <?php else: ?>
                    <a href="workspace-rundown.php?kegiatan_id=<?php echo $kegiatan_id; ?>" style="color: #888; text-decoration: none; font-size: 0.85rem;" target="_blank">
                        <i class="fas fa-info-circle"></i> Buat Rundown Terlebih Dahulu →
                    </a>
                <?php endif; ?>
            </div>

            <div class="info-grid">
                <div class="form-group">
                    <label>Judul Naskah MC</label>
                    <input type="text" name="judul_naskah" required placeholder="Contoh: Naskah MC Opening & Closing Seminar Nasional" value="<?php echo htmlspecialchars($edit_data['judul_naskah'] ?? 'Teks MC - ' . $kegiatan['nama_kegiatan']); ?>">
                </div>

                <div class="form-group">
                    <label>Format / Tipe Acara</label>
                    <select name="tipe_acara" id="tipe_acara">
                        <option value="formal" <?php echo ($edit_data['tipe_acara'] ?? '') === 'formal' ? 'selected' : ''; ?>>Formal (Protokoler)</option>
                        <option value="semi_formal" <?php echo ($edit_data['tipe_acara'] ?? '') === 'semi_formal' ? 'selected' : ''; ?>>Semi Formal</option>
                        <option value="non_formal" <?php echo ($edit_data['tipe_acara'] ?? '') === 'non_formal' ? 'selected' : ''; ?>>Non-Formal (Casual)</option>
                    </select>
                </div>
            </div>

            <!-- TAMU UNDANGAN BOX (KHUSUS INPUT KETUPLAK & AUTO-SYNC SIE ACARA) -->
            <?php 
            $tamu_raw = trim($kegiatan['tamu_undangan'] ?? '');
            $tamu_ketuplak_lines = [];
            $tamu_json_dec = json_decode($tamu_raw, true);

            if (is_array($tamu_json_dec)) {
                foreach ($tamu_json_dec as $titem) {
                    if (is_array($titem) && !empty($titem['nama'])) {
                        $n = trim($titem['nama']);
                        $p = trim($titem['perihal'] ?? '');
                        $tamu_ketuplak_lines[] = "• " . $n . (!empty($p) ? " (Sebagai: {$p})" : "");
                    } elseif (is_string($titem) && trim($titem) !== '') {
                        $tamu_ketuplak_lines[] = "• " . trim($titem);
                    }
                }
                $tamu_ketuplak = implode("\n", $tamu_ketuplak_lines);
            } else {
                $tamu_ketuplak = $tamu_raw;
            }
            ?>
            <div style="background: rgba(241, 196, 15, 0.05); border: 1px solid rgba(241, 196, 15, 0.25); padding: 20px; border-radius: 16px; margin-bottom: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                    <div style="font-weight: 700; color: #f1c40f; font-size: 1rem; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-user-tie"></i> Daftar Tamu Undangan & VVIP <?php echo $is_ketuplat ? '(Form Ketua Pelaksana)' : '(Diisi oleh Ketua Pelaksana)'; ?>
                    </div>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <a href="tamu-undangan.php?kegiatan_id=<?php echo $kegiatan_id; ?>" style="background: rgba(241, 196, 15, 0.1); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.3); padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fas fa-external-link-alt"></i> Halaman Khusus Ketuplak →
                        </a>
                        <?php if (!empty($tamu_ketuplak)): ?>
                            <button type="button" onclick="syncTamuFromKetuplak()" style="background: rgba(241, 196, 15, 0.2); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.4); padding: 8px 16px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: 0.3s;">
                                <i class="fas fa-sync-alt"></i> Impor Data Tamu Ketuplak ke Catatan MC
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <input type="hidden" id="tamuKetuplakText" value="<?php echo htmlspecialchars($tamu_ketuplak); ?>">
                
                <?php if ($is_ketuplat): ?>
                    <?php if (!empty($templates_tujuan)): ?>
                        <div style="margin-bottom: 12px; background: rgba(0,0,0,0.3); padding: 12px; border-radius: 10px;">
                            <small style="color: #4facfe; display: block; margin-bottom: 8px; font-weight: 600;">
                                <i class="fas fa-bookmark"></i> Impor Cepat dari Template "Tujuan" (Pengaturan Surat):
                            </small>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                <?php foreach ($templates_tujuan as $tpl): ?>
                                    <button type="button" 
                                            onclick="appendTamuTemplateMC(<?php echo htmlspecialchars(json_encode($tpl['isi_teks'] ?: $tpl['label']), ENT_QUOTES, 'UTF-8'); ?>)"
                                            style="background: rgba(79, 172, 254, 0.15); color: #4facfe; border: 1px solid rgba(79, 172, 254, 0.3); padding: 5px 12px; border-radius: 20px; font-size: 0.8rem; cursor: pointer; transition: all 0.2s;"
                                            onmouseover="this.style.background='rgba(79, 172, 254, 0.3)'"
                                            onmouseout="this.style.background='rgba(79, 172, 254, 0.15)'">
                                        <i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars($tpl['label']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-group" style="margin-bottom: 0;">
                        <textarea name="tamu_undangan_ketuplak" id="tamu_undangan_ketuplak" rows="3" placeholder="Sebagai Ketua Pelaksana, Anda dapat memilih dari template di atas atau mengetik nama-nama tamu VVIP / Undangan di sini..." style="background: #000; border-color: rgba(241, 196, 15, 0.3); font-size: 0.95rem; line-height: 1.5; color: #fff;"><?php echo htmlspecialchars($tamu_ketuplak); ?></textarea>
                        <small style="color: #aaa; margin-top: 6px; display: block; font-size: 0.8rem;">
                            <i class="fas fa-info-circle" style="color: #f1c40f;"></i> 
                            Daftar tamu ini khusus diisi oleh <b>Ketua Pelaksana</b> dan dapat diimpor langsung ke Naskah MC oleh <b>Sie Acara</b>.
                        </small>
                    </div>
                <?php else: ?>
                    <?php if (!empty($tamu_ketuplak)): ?>
                        <div style="background: rgba(0,0,0,0.4); padding: 12px 16px; border-radius: 10px; color: #fff; font-size: 0.95rem; white-space: pre-wrap; line-height: 1.5; border-left: 3px solid #f1c40f;">
                            <?php echo htmlspecialchars($tamu_ketuplak); ?>
                        </div>
                    <?php else: ?>
                        <div style="color: #888; font-size: 0.85rem; font-style: italic;">
                            <i class="fas fa-info-circle"></i> Ketua Pelaksana belum menginput daftar tamu undangan untuk kegiatan ini di menu Susunan Panitia.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="form-group">
                <label>Catatan Umum MC / Protocoler Note</label>
                <textarea name="catatan_mc" id="catatan_mc" rows="2" placeholder="Catatan pakaian/dresscode MC, daftar tamu VVIP yang hadir, atau arahan khusus panggung..."><?php echo htmlspecialchars($edit_data['catatan_mc'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #fff; font-size: 1.2rem;"><i class="fas fa-list-alt" style="color: var(--accent-color); margin-right: 8px;"></i> Susunan Naskah & Technical Cue</h3>
                <button type="button" onclick="loadDefaultTemplate()" style="background: rgba(255,255,255,0.05); color: #ccc; border: 1px solid var(--border-color); padding: 8px 14px; border-radius: 8px; font-size: 0.85rem; cursor: pointer;">
                    <i class="fas fa-magic"></i> Muat Template Naskah Standar
                </button>
            </div>

            <div class="mc-table-wrapper">
                <table class="mc-table">
                    <thead>
                        <tr>
                            <th>NO</th>
                            <th style="width: 140px;">WAKTU</th>
                            <th style="width: 240px;">SEGMEN & PENGISI (MC)</th>
                            <th>NASKAH BICARA MC (SCRIPT)</th>
                            <th style="width: 220px;">CATATAN PANGGUNG (CUE)</th>
                            <th>AKSI</th>
                        </tr>
                    </thead>
                    <tbody id="mcTableBody">
                        <!-- Dynamic Rows generated by JS -->
                    </tbody>
                </table>
            </div>

            <button type="button" class="btn-add-row" onclick="addMCRow()">
                <i class="fas fa-plus"></i> Tambah Segmen Naskah MC
            </button>
        </div>

        <div class="actions-bar">
            <div>
                <a href="<?php echo baseUrl('admin/rundown/arsip-teks-mc.php'); ?>" style="color: #888; text-decoration: none; font-size: 0.9rem; margin-right: 15px;">
                    <i class="fas fa-arrow-left"></i> Kembali ke Arsip
                </a>
            </div>

            <div style="display: flex; gap: 12px; align-items: center;">
                <?php if ($edit_id > 0): ?>
                    <a href="<?php echo baseUrl('admin/rundown/reader-teks-mc.php?id=<?php echo $edit_id; ?>'); ?>" target="_blank" class="btn-reader" title="Buka Tampilan Teleprompter Layar Penuh">
                        <i class="fas fa-play-circle"></i> Mode Live Reader
                    </a>
                    <a href="<?php echo baseUrl('admin/rundown/cetak-teks-mc-pdf.php?id=<?php echo $edit_id; ?>'); ?>" target="_blank" class="btn-pdf" title="Cetak Format Cue Card PDF">
                        <i class="fas fa-file-pdf"></i> Cetak PDF
                    </a>
                <?php endif; ?>

                <button type="submit" class="btn-save">
                    <i class="fas fa-save"></i> <?php echo $edit_id > 0 ? 'Perbarui Naskah' : 'Simpan Teks MC'; ?>
                </button>
            </div>
        </div>
    </form>

</div>

<script>
const existingItems = <?php echo json_encode($items_list); ?>;
const rundownSyncData = <?php echo json_encode($rundown_sync_data); ?>;

let mcRowCount = 0;

function addMCRow(data = {}) {
    mcRowCount++;
    const tbody = document.getElementById('mcTableBody');
    const tr = document.createElement('tr');
    tr.id = 'mc-row-' + mcRowCount;

    const waktu = data.waktu || '';
    const segmen = data.segmen || '';
    const pengisi = data.pengisi || '';
    const mcSpeaker = data.mc_speaker || 'MC 1 & MC 2';
    const scriptTeks = data.script_teks || '';
    const stageCue = data.stage_cue || '';

    tr.innerHTML = `
        <td class="row-num">${tbody.children.length + 1}</td>
        <td>
            <input type="text" name="waktu[]" placeholder="08:00 - 08:15" value="${escapeHtml(waktu)}">
        </td>
        <td>
            <select name="mc_speaker[]" style="margin-bottom: 6px; font-weight: 600;">
                <option value="MC 1 & MC 2" ${mcSpeaker === 'MC 1 & MC 2' ? 'selected' : ''}>MC 1 & MC 2</option>
                <option value="MC 1" ${mcSpeaker === 'MC 1' ? 'selected' : ''}>MC 1</option>
                <option value="MC 2" ${mcSpeaker === 'MC 2' ? 'selected' : ''}>MC 2</option>
                <option value="Moderator" ${mcSpeaker === 'Moderator' ? 'selected' : ''}>Moderator</option>
            </select>
            <input type="text" name="segmen[]" placeholder="Nama Segmen Acara" value="${escapeHtml(segmen)}" style="font-weight: 600; margin-bottom: 6px;">
            <input type="text" name="pengisi[]" placeholder="Pengisi/Tamu (Opsional)" value="${escapeHtml(pengisi)}" style="font-size: 0.8rem; color: #aaa;">
        </td>
        <td>
            <textarea name="script_teks[]" placeholder="Tuliskan kata-kata / narasi pembukaan & penyampaian MC di sini...">${escapeHtml(scriptTeks)}</textarea>
        </td>
        <td>
            <textarea name="stage_cue[]" placeholder="Catatan aksi/isyarat panggung (misal: Backsound up, Operator slide ready, berdiri)...">${escapeHtml(stageCue)}</textarea>
        </td>
        <td>
            <button type="button" class="btn-remove-row" onclick="removeMCRow(${mcRowCount})" title="Hapus Segmen Ini">
                <i class="fas fa-trash-alt"></i>
            </button>
        </td>
    `;

    tbody.appendChild(tr);
    updateRowNumbers();
}

function removeMCRow(id) {
    const tr = document.getElementById('mc-row-' + id);
    if (tr) {
        tr.remove();
        updateRowNumbers();
    }
}

function updateRowNumbers() {
    const rows = document.querySelectorAll('#mcTableBody tr');
    rows.forEach((row, idx) => {
        row.querySelector('.row-num').innerText = idx + 1;
    });
}

function syncFromRundown() {
    if (rundownSyncData.length === 0) {
        alert("Tidak ada data rundown yang ditemukan untuk kegiatan ini.");
        return;
    }

    if (confirm("Apakah Anda yakin ingin mengimpor data dari Rundown? Segmen dari rundown akan ditambahkan ke susunan naskah MC.")) {
        rundownSyncData.forEach(item => {
            addMCRow({
                waktu: item.waktu,
                segmen: item.segmen,
                pengisi: item.pengisi,
                mc_speaker: 'MC 1 & MC 2',
                script_teks: `Hadirin sekalian, acara selanjutnya yaitu ${item.segmen}` + (item.pengisi ? ` yang akan dipimpin / dibawakan oleh ${item.pengisi}.` : `.`),
                stage_cue: `Operator siapkan materi ${item.segmen}`
            });
        });
    }
}

function loadDefaultTemplate() {
    if (confirm("Muat template standar pembukaan & penutupan acara formal?")) {
        addMCRow({
            waktu: "07:30 - 08:00",
            segmen: "Registrasi & Pre-Show",
            mc_speaker: "MC 1 & MC 2",
            script_teks: "Selamat pagi dan selamat datang kami ucapkan kepada seluruh peserta dan tamu undangan yang telah hadir...",
            stage_cue: "Music background instrumental playing, standing mic check."
        });

        addMCRow({
            waktu: "08:00 - 08:10",
            segmen: "Pembukaan Resmi MC",
            mc_speaker: "MC 1 & MC 2",
            script_teks: "Assalamu'alaikum Warahmatullahi Wabarakatuh. Selamat pagi dan salam sejahtera untuk kita semua. Yang terhormat...",
            stage_cue: "Backsound pelan, MC berdiri di posisi tengah panggung."
        });

        addMCRow({
            waktu: "08:10 - 08:20",
            segmen: "Menyanyikan Lagu Indonesia Raya",
            mc_speaker: "MC 1",
            script_teks: "Mengawali acara pada pagi hari ini, marilah kita menyanyikan lagu kebangsaan Indonesia Raya. Hadirin dimohon berdiri.",
            stage_cue: "Lampu panggung terang, operator siapkan lagu Indonesia Raya."
        });
    }
}

function syncTamuFromKetuplak() {
    const tamuText = document.getElementById('tamuKetuplakText').value.trim();
    if (!tamuText) {
        alert("Ketua Pelaksana belum menginput daftar tamu undangan.");
        return;
    }

    const catatanElem = document.getElementById('catatan_mc');
    if (!catatanElem) return;

    let currentCatatan = catatanElem.value;
    const headerTag = "=== DAFTAR TAMU UNDANGAN (DARI KETUPLAK) ===";

    if (currentCatatan.includes(headerTag)) {
        currentCatatan = currentCatatan.replace(new RegExp(headerTag + "[\\s\\S]*?(?=\\n\\n|$)"), headerTag + "\n" + tamuText);
    } else {
        currentCatatan = (headerTag + "\n" + tamuText + (currentCatatan ? "\n\n" + currentCatatan : "")).trim();
    }

    catatanElem.value = currentCatatan;
    alert("Daftar Tamu Undangan dari Ketuplak berhasil diimpor ke Catatan MC!");
}

function appendTamuTemplateMC(text) {
    const textarea = document.getElementById('tamu_undangan_ketuplak');
    if (!textarea) return;
    let current = textarea.value.trim();
    if (current === '') {
        textarea.value = text;
    } else {
        if (!current.includes(text)) {
            textarea.value = current + "\n" + text;
        }
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Initial render
document.addEventListener('DOMContentLoaded', () => {
    if (existingItems.length > 0) {
        existingItems.forEach(item => addMCRow(item));
    } else if (rundownSyncData.length > 0) {
        // Auto prepopulate from rundown if brand new
        rundownSyncData.forEach(item => {
            addMCRow({
                waktu: item.waktu,
                segmen: item.segmen,
                pengisi: item.pengisi,
                mc_speaker: 'MC 1 & MC 2',
                script_teks: `Acara selanjutnya yaitu ${item.segmen}.`,
                stage_cue: `Cue: ${item.keterangan || 'Persiapan segmen'}`
            });
        });
    } else {
        addMCRow();
    }
});
</script>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
