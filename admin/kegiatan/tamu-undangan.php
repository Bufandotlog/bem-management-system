<?php
// admin/tamu-undangan.php
require_once __DIR__ . '/../core/header.php';

$kegiatan_id = isset($_GET['kegiatan_id']) ? (int)$_GET['kegiatan_id'] : 0;
if (!$kegiatan_id) redirect('admin/core/dashboard.php', 'ID Kegiatan tidak valid.', 'error');

// Khusus Ketua Pelaksana & Sekretaris Panitia
requireEventAccess($kegiatan_id, ['ketuplat', 'sekretaris_panitia']);

$kegiatan = dbFetchOne("SELECT * FROM kegiatan WHERE id = ?", [$kegiatan_id], "i");
if (!$kegiatan) redirect('admin/core/dashboard.php', 'Kegiatan tidak ditemukan.', 'error');

$periode_id = getUserPeriode();

// Ambil Template Tujuan (Kepada Yth) dari database pengaturan-surat
$templates_tujuan = dbFetchAll("SELECT * FROM surat_templates WHERE jenis = 'tujuan' AND periode_id = ? ORDER BY label ASC", [$periode_id], "i");
if (empty($templates_tujuan)) {
    $templates_tujuan = dbFetchAll("SELECT * FROM surat_templates WHERE jenis = 'tujuan' ORDER BY label ASC");
}

// Preset dasar yang diizinkan sesuai permintaan (Tamu Undangan, Sambutan, Pemateri)
$allowed_presets = ['Tamu Undangan', 'Sambutan', 'Pemateri'];

// Ambil HANYA template custom yang pernah diinput dari modul Tamu Undangan (perihal_default = 'custom_tamu')
$custom_db_perihal = dbFetchAll("SELECT * FROM surat_templates WHERE jenis = 'perihal' AND perihal_default = 'custom_tamu' AND (periode_id = ? OR periode_id IS NULL) ORDER BY label ASC", [$periode_id], "i");

// Susun daftar template akhir
$templates_perihal = [];
foreach ($allowed_presets as $preset) {
    $templates_perihal[] = [
        'label' => $preset,
        'isi_teks' => $preset
    ];
}

// Sertakan template custom yang pernah disimpan oleh Ketua Pelaksana dari modul ini
if (!empty($custom_db_perihal)) {
    foreach ($custom_db_perihal as $db_tpl) {
        $lbl = trim($db_tpl['label']);
        $in_list = false;
        foreach ($templates_perihal as $existing) {
            if (strtolower($existing['label']) === strtolower($lbl)) {
                $in_list = true;
                break;
            }
        }
        if (!$in_list && !empty($lbl)) {
            $templates_perihal[] = [
                'label' => $lbl,
                'isi_teks' => $db_tpl['isi_teks'] ?: $lbl
            ];
        }
    }
}

$success_msg = '';
$error_msg = '';

// Process Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_tamu') {
    if (!csrfVerify()) {
        $error_msg = "Token CSRF tidak valid atau telah kedaluwarsa.";
    } else {
        $raw_nama = $_POST['tamu_nama'] ?? [];
        $raw_perihal = $_POST['tamu_perihal'] ?? [];
        $raw_perihal_custom = $_POST['tamu_perihal_custom'] ?? [];
        $raw_kategori = $_POST['tamu_kategori'] ?? [];
        
        $clean_items = [];
        for ($i = 0; $i < count($raw_nama); $i++) {
            $nama_trimmed = trim($raw_nama[$i] ?? '');
            if ($nama_trimmed !== '') {
                $p_sel = trim($raw_perihal[$i] ?? '');
                $p_cust = trim($raw_perihal_custom[$i] ?? '');
                $kat_val = strtoupper(trim($raw_kategori[$i] ?? 'D'));
                if ($kat_val !== 'L') {
                    $kat_val = 'D';
                }
                
                if ($p_sel === '__custom__' && $p_cust !== '') {
                    $perihal_final = $p_cust;
                    // OTOMATIS SIMPAN KE TABEL surat_templates DENGAN TAG 'custom_tamu'
                    try {
                        $exist_check = dbFetchOne("SELECT id FROM surat_templates WHERE jenis = 'perihal' AND LOWER(TRIM(label)) = LOWER(TRIM(?)) AND (periode_id = ? OR periode_id IS NULL)", [$p_cust, $periode_id]);
                        if (!$exist_check) {
                            dbQuery("INSERT INTO surat_templates (periode_id, jenis, label, isi_teks, perihal_default) VALUES (?, 'perihal', ?, ?, 'custom_tamu')", [$periode_id, $p_cust, $p_cust]);
                        }
                    } catch (Exception $ex) {
                        // Abaikan jika sudah ada
                    }
                } elseif ($p_sel !== '__custom__' && $p_sel !== '') {
                    $perihal_final = $p_sel;
                } else {
                    $perihal_final = 'Tamu Undangan';
                }
                
                $clean_items[] = [
                    'nama' => $nama_trimmed,
                    'perihal' => $perihal_final,
                    'kategori' => $kat_val
                ];
            }
        }
        
        $tamu_undangan_json = json_encode($clean_items, JSON_UNESCAPED_UNICODE);
        
        try {
            dbQuery("UPDATE kegiatan SET tamu_undangan = ? WHERE id = ?", [$tamu_undangan_json, $kegiatan_id], "si");
            $kegiatan['tamu_undangan'] = $tamu_undangan_json;

            // --- OTOMATISASI SINKRONISASI SURAT UNDANGAN KE STAGING INDEX SURAT ---
            $ketuplat_row = dbFetchOne(
                "SELECT u.nama FROM kegiatan_panitia kp JOIN users u ON kp.user_id = u.id WHERE kp.kegiatan_id = ? AND kp.event_role = 'ketuplat' LIMIT 1",
                [$kegiatan_id]
            );
            $ketuplat_nama = $ketuplat_row['nama'] ?? 'Ketua Pelaksana';

            $sekretaris_row = dbFetchOne(
                "SELECT u.nama FROM kegiatan_panitia kp JOIN users u ON kp.user_id = u.id WHERE kp.kegiatan_id = ? AND kp.event_role = 'sekretaris_panitia' LIMIT 1",
                [$kegiatan_id]
            );
            $sekretaris_nama = $sekretaris_row['nama'] ?? 'Sekretaris Panitia';

            $rundown = dbFetchOne("SELECT id FROM arsip_rundown WHERE kegiatan_id = ? AND periode_id = ? LIMIT 1", [$kegiatan_id, $periode_id]);
            
            $has_rundown_dependent = false;
            foreach ($clean_items as $ci) {
                $p_check = strtolower(trim($ci['perihal'] ?? ''));
                if (strpos($p_check, 'pemateri') !== false || strpos($p_check, 'narasumber') !== false || strpos($p_check, 'undangan') !== false || strpos($p_check, 'sambutan') !== false || strpos($p_check, 'tamu') !== false) {
                    $has_rundown_dependent = true;
                    break;
                }
            }

            syncTamuUndanganLetters($kegiatan_id, $periode_id);

            if (!$has_rundown_dependent) {
                $success_msg = "Daftar Tamu Undangan & VVIP berhasil disimpan! 📩 Surat otomatis disinkronkan ke Staging Index Surat.";
            } else {
                if ($rundown) {
                    $success_msg = "Daftar Tamu Undangan & VVIP berhasil disimpan! 📩 Surat otomatis disinkronkan (termasuk Surat Undangan & Pemateri dengan Lampiran Rundown) ke Staging Index Surat.";
                } else {
                    $success_msg = "Daftar Tamu Undangan & VVIP berhasil disimpan! 📩 Surat rapat & pemberitahuan otomatis disinkronkan. ⚠️ Surat Undangan Kegiatan & Pemateri ditahan sementara hingga Sie Acara membuat Rundown.";
                }
            }
        } catch (Exception $e) {
            $error_msg = "Gagal menyimpan daftar tamu: " . $e->getMessage();
        }
    }
}

$tamu_val = $kegiatan['tamu_undangan'] ?? '';
$guest_list_array = [];

$decoded = json_decode($tamu_val, true);
if (is_array($decoded)) {
    foreach ($decoded as $item) {
        if (is_array($item) && !empty($item['nama'])) {
            $guest_list_array[] = [
                'nama' => trim($item['nama']),
                'perihal' => trim($item['perihal'] ?? 'Tamu Undangan')
            ];
        } elseif (is_string($item) && trim($item) !== '') {
            $guest_list_array[] = [
                'nama' => trim($item),
                'perihal' => 'Tamu Undangan'
            ];
        }
    }
} else {
    $raw_guests = explode("\n", $tamu_val);
    foreach ($raw_guests as $g) {
        $g_clean = trim($g);
        if ($g_clean !== '') {
            if (preg_match('/^(.*?)\s*\(Perihal:\s*(.*?)\)$/i', $g_clean, $matches)) {
                $guest_list_array[] = [
                    'nama' => trim($matches[1]),
                    'perihal' => trim($matches[2])
                ];
            } else {
                $guest_list_array[] = [
                    'nama' => ltrim($g_clean, "• \t\n\r\0\x0B"),
                    'perihal' => 'Tamu Undangan'
                ];
            }
        }
    }
}
?>

<style>
.tamu-container {
    max-width: 1150px;
    margin: 0 auto;
    animation: fadeIn 0.4s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.tamu-card {
    background: rgba(15, 18, 23, 0.95);
    border: 1px solid #2a3545;
    border-radius: 24px;
    padding: 30px;
    margin-bottom: 24px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.4);
    backdrop-filter: blur(15px);
}

.tamu-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 25px;
    padding-bottom: 20px;
    border-bottom: 1px solid #2a3545;
    flex-wrap: wrap;
    gap: 15px;
}

.tamu-header-title {
    display: flex;
    align-items: center;
    gap: 15px;
}

.tamu-header-title h2 {
    margin: 0;
    font-size: 1.6rem;
    color: #fff;
    font-weight: 700;
}

.template-box {
    background: rgba(79, 172, 254, 0.05);
    border: 1px solid rgba(79, 172, 254, 0.25);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 25px;
}

.template-badge {
    background: rgba(79, 172, 254, 0.15);
    color: #4facfe;
    border: 1px solid rgba(79, 172, 254, 0.3);
    padding: 8px 16px;
    border-radius: 25px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.25s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.template-badge:hover {
    background: rgba(79, 172, 254, 0.35);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(79, 172, 254, 0.3);
}

.guest-card-row {
    background: #080a0e;
    border: 1px solid #2a3545;
    border-radius: 16px;
    padding: 18px 20px;
    margin-bottom: 16px;
    display: flex;
    align-items: flex-start;
    gap: 15px;
    transition: all 0.3s;
    position: relative;
    flex-wrap: wrap;
}

.guest-card-row:focus-within {
    border-color: #f1c40f;
    box-shadow: 0 0 15px rgba(241, 196, 15, 0.15);
}

.guest-inputs-wrapper {
    display: flex;
    flex: 1;
    gap: 15px;
    align-items: flex-start;
    flex-wrap: wrap;
}

.guest-field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.guest-field-group.nama-group {
    flex: 1.8;
    min-width: 250px;
}

.guest-field-group.perihal-group {
    flex: 1.2;
    min-width: 220px;
    position: relative;
}

.guest-field-group.kategori-group {
    flex: 0.8;
    min-width: 150px;
}

@media (max-width: 768px) {
    .guest-card-row {
        flex-direction: column;
        padding: 30px 15px 15px 15px;
        margin-top: 15px;
    }
    
    .guest-card-row .guest-number-badge {
        position: absolute;
        top: 0;
        left: 0;
        border-radius: 16px 0 16px 0 !important;
        z-index: 10;
        box-shadow: 2px 2px 8px rgba(0,0,0,0.2);
    }
    
    .guest-inputs-wrapper {
        width: 100%;
        flex-direction: column;
    }
    
    .guest-field-group.nama-group,
    .guest-field-group.perihal-group,
    .guest-field-group.kategori-group {
        min-width: 100%;
        width: 100%;
    }
    
    .guest-card-row > button {
        align-self: flex-end;
        width: 100%;
        margin-top: 5px;
    }
    
    .tamu-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .btn-submit-tamu {
        width: 100%;
        justify-content: center;
    }
}

.guest-field-label {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.guest-input-box {
    background: #0f1217;
    border: 1px solid #2a3545;
    border-radius: 12px;
    color: #fff;
    padding: 11px 15px;
    font-size: 0.95rem;
    width: 100%;
    outline: none;
    transition: all 0.25s ease;
}

.guest-input-box:focus {
    border-color: #f1c40f;
    box-shadow: 0 0 10px rgba(241, 196, 15, 0.2);
}

/* CUSTOM STYLIZED SELECT DROPDOWN */
.custom-select-container {
    position: relative;
    width: 100%;
}

.custom-select-trigger {
    background: #0f1217;
    border: 1px solid #2a3545;
    border-radius: 12px;
    color: #fff;
    padding: 11px 15px;
    font-size: 0.95rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    user-select: none;
    transition: all 0.25s ease;
}

.custom-select-trigger:hover {
    border-color: #f1c40f;
    background: #141922;
}

.custom-select-container.open .custom-select-trigger {
    border-color: #f1c40f;
    box-shadow: 0 0 12px rgba(241, 196, 15, 0.25);
    background: #151b26;
}

.custom-select-trigger .trigger-label {
    display: flex;
    align-items: center;
    gap: 8px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 90%;
}

.custom-select-trigger .arrow-icon {
    color: #f1c40f;
    font-size: 0.8rem;
    transition: transform 0.3s ease;
}

.custom-select-container.open .arrow-icon {
    transform: rotate(180deg);
}

.custom-select-dropdown {
    position: absolute;
    top: calc(100% + 6px);
    left: 0;
    right: 0;
    background: #0f131a;
    border: 1px solid #2a3545;
    border-radius: 14px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.85), 0 0 15px rgba(241, 196, 15, 0.15);
    z-index: 9999;
    max-height: 250px;
    overflow-y: auto;
    display: none;
    opacity: 0;
    transform: translateY(-8px);
    transition: opacity 0.2s ease, transform 0.2s ease;
    scrollbar-width: thin;
    scrollbar-color: #f1c40f #0f131a;
}

.custom-select-container.open .custom-select-dropdown {
    display: block;
    opacity: 1;
    transform: translateY(0);
}

.custom-option-item {
    padding: 11px 16px;
    font-size: 0.9rem;
    color: #ccc;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all 0.15s ease;
    border-bottom: 1px solid rgba(255,255,255,0.03);
}

.custom-option-item:last-child {
    border-bottom: none;
}

.custom-option-item:hover, .custom-option-item.active {
    background: rgba(241, 196, 15, 0.15);
    color: #f1c40f;
    font-weight: 600;
    padding-left: 20px;
}

.custom-option-item i {
    font-size: 0.85rem;
    color: #888;
    transition: color 0.15s ease;
}

.custom-option-item.active i, .custom-option-item:hover i {
    color: #f1c40f;
}

.custom-option-item.custom-add-opt {
    border-top: 1px dashed rgba(241, 196, 15, 0.3);
    color: #f1c40f;
    font-weight: 700;
    background: rgba(241, 196, 15, 0.05);
}

.custom-option-item.custom-add-opt:hover {
    background: rgba(241, 196, 15, 0.25);
    color: #fff;
}

.btn-add-guest-card {
    width: 100%;
    background: rgba(241, 196, 15, 0.05);
    color: #f1c40f;
    border: 2px dashed rgba(241, 196, 15, 0.3);
    padding: 14px;
    border-radius: 14px;
    font-weight: 700;
    font-size: 0.95rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.25s;
    margin-top: 10px;
}

.btn-add-guest-card:hover {
    background: rgba(241, 196, 15, 0.15);
    border-color: #f1c40f;
    transform: translateY(-2px);
}

.btn-submit-tamu {
    background: linear-gradient(135deg, #f1c40f 0%, #f39c12 100%);
    color: #000;
    border: none;
    padding: 14px 35px;
    border-radius: 12px;
    font-weight: 800;
    font-size: 1rem;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    transition: all 0.3s;
    box-shadow: 0 10px 20px rgba(241, 196, 15, 0.3);
}

.btn-submit-tamu:hover {
    transform: translateY(-3px);
    box-shadow: 0 15px 30px rgba(241, 196, 15, 0.45);
}

.info-note {
    background: rgba(255, 255, 255, 0.03);
    border-left: 4px solid #f1c40f;
    padding: 15px 20px;
    border-radius: 8px 12px 12px 8px;
    color: #bbb;
    font-size: 0.9rem;
    margin-top: 25px;
    line-height: 1.5;
}
</style>

<div class="tamu-container">

    <?php if ($success_msg): ?>
        <div style="background: rgba(46, 204, 113, 0.12); color: #2ecc71; padding: 16px 20px; border-radius: 14px; border: 1px solid rgba(46, 204, 113, 0.3); margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
            <div>
                <i class="fas fa-check-circle fa-lg" style="margin-right: 8px;"></i> <?php echo htmlspecialchars($success_msg); ?>
            </div>
            <div style="display: flex; gap: 15px;">
                <a href="<?php echo baseUrl('admin/surat/staging-surat.php?kegiatan_id=<?php echo $kegiatan_id; ?>'); ?>" style="color: #f1c40f; text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                    📩 Staging Index Surat →
                </a>
                <a href="workspace-teks-mc.php?kegiatan_id=<?php echo $kegiatan_id; ?>" style="color: #4facfe; text-decoration: none; font-weight: 600; font-size: 0.9rem;">
                    🎙️ Lihat Teks MC →
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div style="background: rgba(231, 76, 60, 0.12); color: #e74c3c; padding: 16px 20px; border-radius: 14px; border: 1px solid rgba(231, 76, 60, 0.3); margin-bottom: 24px;">
            <i class="fas fa-exclamation-triangle fa-lg" style="margin-right: 8px;"></i> <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_tamu">

        <div class="tamu-card">
            <div class="tamu-header">
                <div class="tamu-header-title">
                    <div style="background: rgba(241, 196, 15, 0.15); width: 50px; height: 50px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #f1c40f;">
                        <i class="fas fa-user-tie fa-2x"></i>
                    </div>
                    <div>
                        <h2>Daftar Tamu Undangan & VVIP</h2>
                        <small style="color: #888; font-size: 0.9rem;">Kegiatan: <strong style="color: #4facfe;"><?php echo htmlspecialchars($kegiatan['nama_kegiatan']); ?></strong></small>
                    </div>
                </div>

                <div>
                    <span style="background: rgba(241, 196, 15, 0.15); color: #f1c40f; padding: 6px 14px; border-radius: 20px; font-size: 0.8rem; font-weight: 700;">
                        <i class="fas fa-crown"></i> Modul Ketua Pelaksana
                    </span>
                </div>
            </div>

            <!-- TEMPLATE SELECTOR FROM PENGATURAN SURAT -->
            <?php if (!empty($templates_tujuan)): ?>
                <div class="template-box">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                        <div style="font-weight: 700; color: #4facfe; font-size: 0.95rem;">
                            <i class="fas fa-bookmark"></i> Impor Cepat dari Template "Tujuan" (Pengaturan Surat):
                        </div>
                        <a href="<?php echo baseUrl('admin/surat/pengaturan-surat.php'); ?>" target="_blank" style="color: #888; text-decoration: none; font-size: 0.8rem;" title="Kelola Master Template di Pengaturan Surat">
                            <i class="fas fa-cog"></i> Kelola Template →
                        </a>
                    </div>

                    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <?php foreach ($templates_tujuan as $tpl): ?>
                            <button type="button" 
                                    class="template-badge"
                                    onclick="addGuestFromTemplate(<?php echo htmlspecialchars(json_encode($tpl['isi_teks'] ?: $tpl['label']), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-plus"></i> <?php echo htmlspecialchars($tpl['label']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label style="font-weight: 700; color: #ccc; font-size: 0.95rem; margin-bottom: 14px; display: flex; align-items: flex-start; justify-content: space-between; flex-direction: column; gap: 8px;">
                    <span><i class="fas fa-list-ol" style="color: #f1c40f; margin-right: 8px;"></i> Susunan Daftar Tamu Undangan (Satu Card Per Nama & Sebagai)</span>
                </label>

                <!-- CONTAINER FOR GUEST CARDS -->
                <div id="guestCardsContainer">
                    <?php if (!empty($guest_list_array)): ?>
                        <?php foreach ($guest_list_array as $idx => $guest): 
                            $g_nama = $guest['nama'];
                            $g_perihal = $guest['perihal'];
                            $g_kategori = $guest['kategori'] ?? 'D';
                            
                            // Check if perihal is in templates_perihal
                            $is_custom_perihal = true;
                            $matched_label = 'Tamu Undangan';
                            foreach ($templates_perihal as $tp) {
                                $val_tp = $tp['isi_teks'] ?: $tp['label'];
                                if ($val_tp === $g_perihal || $tp['label'] === $g_perihal) {
                                    $is_custom_perihal = false;
                                    $matched_label = $tp['label'];
                                    break;
                                }
                            }
                            if ($is_custom_perihal) {
                                $display_trigger_label = '➕ Ketik Perihal Custom...';
                            } else {
                                $display_trigger_label = $matched_label;
                            }
                        ?>
                            <div class="guest-card-row">
                                <div class="guest-number-badge" style="background: #4A90E2; color: #fff; border: none; font-weight: 700; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0;">
                                    #<?php echo ($idx + 1); ?>
                                </div>
                                <div class="guest-inputs-wrapper">
                                    <div class="guest-field-group nama-group">
                                        <label class="guest-field-label" style="color: #8BB9F0;"><i class="fas fa-user"></i> Nama / Gelar / Instansi Tamu</label>
                                        <input type="text" name="tamu_nama[]" class="guest-input-box" value="<?php echo htmlspecialchars($g_nama); ?>" placeholder="Ketik nama / instansi tamu undangan..." oninput="autoDetectKategori(this)">
                                    </div>
                                    <div class="guest-field-group perihal-group">
                                        <label class="guest-field-label" style="color: #f1c40f;"><i class="fas fa-user-tag"></i> Sebagai</label>
                                        
                                        <!-- Hidden Select for POST form handling -->
                                        <select name="tamu_perihal[]" class="guest-select-hidden" style="display:none;">
                                            <?php foreach ($templates_perihal as $tp): 
                                                $tp_val = $tp['isi_teks'] ?: $tp['label'];
                                                $selected = (!$is_custom_perihal && ($g_perihal === $tp_val || $g_perihal === $tp['label'])) ? 'selected' : '';
                                            ?>
                                                <option value="<?php echo htmlspecialchars($tp_val); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($tp['label']); ?></option>
                                            <?php endforeach; ?>
                                            <option value="__custom__" <?php echo $is_custom_perihal ? 'selected' : ''; ?>>➕ Ketik Perihal Custom...</option>
                                        </select>

                                        <!-- Custom Stylized Select Dropdown UI -->
                                        <div class="custom-select-container">
                                            <div class="custom-select-trigger" onclick="toggleCustomDropdown(this)">
                                                <span class="trigger-label">
                                                    <i class="fas fa-bookmark" style="color: #f1c40f;"></i>
                                                    <span class="trigger-text"><?php echo htmlspecialchars($display_trigger_label); ?></span>
                                                </span>
                                                <i class="fas fa-chevron-down arrow-icon"></i>
                                            </div>
                                            <div class="custom-select-dropdown">
                                                <?php foreach ($templates_perihal as $tp): 
                                                    $tp_val = $tp['isi_teks'] ?: $tp['label'];
                                                    $is_act = (!$is_custom_perihal && ($g_perihal === $tp_val || $g_perihal === $tp['label']));
                                                ?>
                                                    <div class="custom-option-item <?php echo $is_act ? 'active' : ''; ?>" data-value="<?php echo htmlspecialchars($tp_val); ?>" onclick="selectCustomDropdownOption(this)">
                                                        <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($tp['label']); ?>
                                                    </div>
                                                <?php endforeach; ?>
                                                <div class="custom-option-item custom-add-opt <?php echo $is_custom_perihal ? 'active' : ''; ?>" data-value="__custom__" onclick="selectCustomDropdownOption(this)">
                                                    <i class="fas fa-pen-fancy"></i> ➕ Ketik Perihal Custom...
                                                </div>
                                            </div>
                                        </div>

                                        <input type="text" name="tamu_perihal_custom[]" class="guest-input-box custom-perihal-field" value="<?php echo $is_custom_perihal ? htmlspecialchars($g_perihal) : ''; ?>" placeholder="Ketik 'Sebagai' custom..." style="display: <?php echo $is_custom_perihal ? 'block' : 'none'; ?>; margin-top: 8px;">
                                    </div>
                                    <div class="guest-field-group kategori-group">
                                        <label class="guest-field-label" style="color: #2ecc71;"><i class="fas fa-building"></i> Asal Tamu</label>
                                        <select name="tamu_kategori[]" class="guest-input-box guest-kategori-select" style="border-color: <?php echo ($g_kategori==='L')?'rgba(231, 76, 60, 0.5)':'rgba(46, 204, 113, 0.5)'; ?>; color: <?php echo ($g_kategori==='L')?'#ff7675':'#2ecc71'; ?>; font-weight: 700; cursor: pointer;" onchange="this.setAttribute('data-user-modified', 'true'); styleKategoriSelect(this);">
                                            <option value="D" <?php echo ($g_kategori === 'D') ? 'selected' : ''; ?>>🏢 Dalam (D)</option>
                                            <option value="L" <?php echo ($g_kategori === 'L') ? 'selected' : ''; ?>>🌐 Luar (L)</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="button" onclick="deleteGuestCard(this)" title="Hapus Tamu Ini" style="background: rgba(231, 76, 60, 0.12); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); width: 38px; height: 38px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.2s;" onmouseover="this.style.background='rgba(231, 76, 60, 0.3)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(231, 76, 60, 0.12)'; this.style.color='#e74c3c';">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div id="emptyGuestMsg" style="display: <?php echo empty($guest_list_array) ? 'block' : 'none'; ?>; text-align: center; padding: 30px; border: 2px dashed #2a3545; border-radius: 16px; color: #888; margin-bottom: 12px;">
                    <i class="fas fa-users-slash fa-2x" style="margin-bottom: 10px; display: block; color: #555;"></i>
                    Belum ada tamu undangan. Klik tombol template di atas atau <strong>+ Tambah Baris Tamu Baru</strong> di bawah.
                </div>

                <button type="button" class="btn-add-guest-card" onclick="addGuestCard('', '')">
                    <i class="fas fa-plus-circle"></i> Tambah Baris Tamu Baru
                </button>
            </div>

            <div class="info-note">
                <p style="margin: 0 0 8px 0;"><i class="fas fa-info-circle" style="color: #f1c40f; margin-right: 6px;"></i> Data tamu dan perihal/posisi (Sebagai) yang diisi oleh <b>Ketua Pelaksana</b> di halaman ini akan diambil secara otomatis oleh <b>Sie Acara</b> untuk disinkronkan ke dalam <b>Catatan Protokoler Teks MC</b>.</p>
                <p style="margin: 0; color: #4facfe;"><i class="fas fa-shield-alt" style="margin-right: 6px;"></i> <b>Ketentuan Khusus Otomatisasi:</b> Jika <b>BPM</b>, <b>Warek I</b>, <b>Warek II</b>, atau <b>Warek III</b> tidak ditambahkan dalam daftar tamu undangan di atas (sebagai tamu, sambutan, atau pemateri), sistem akan secara otomatis membuatkan <b>Surat Pemberitahuan Kegiatan</b> untuk masing-masing pihak tersebut di Staging Surat.</p>
            </div>

            <div style="margin-top: 30px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                <a href="buat-panitia.php?kegiatan_id=<?php echo $kegiatan_id; ?>" style="color: #888; text-decoration: none; font-size: 0.9rem;">
                    <i class="fas fa-arrow-left"></i> Kembali ke Susunan Panitia
                </a>

                <button type="submit" class="btn-submit-tamu">
                    <i class="fas fa-save"></i> Simpan Daftar Tamu Undangan
                </button>
            </div>
        </div>
    </form>
</div>

<script>
const templatesPerihalData = <?php 
    $arr_data = [];
    foreach ($templates_perihal as $tp) {
        $arr_data[] = [
            'val' => $tp['isi_teks'] ?: $tp['label'],
            'label' => $tp['label']
        ];
    }
    echo json_encode($arr_data, JSON_UNESCAPED_UNICODE);
?>;

function toggleCustomDropdown(triggerEl) {
    const container = triggerEl.closest('.custom-select-container');
    const isOpen = container.classList.contains('open');
    
    document.querySelectorAll('.custom-select-container.open').forEach(c => {
        if (c !== container) c.classList.remove('open');
    });
    
    if (isOpen) {
        container.classList.remove('open');
    } else {
        container.classList.add('open');
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-select-container')) {
        document.querySelectorAll('.custom-select-container.open').forEach(c => {
            c.classList.remove('open');
        });
    }
});

function selectCustomDropdownOption(optEl) {
    const container = optEl.closest('.custom-select-container');
    const group = container.closest('.perihal-group');
    const hiddenSelect = group.querySelector('.guest-select-hidden');
    const triggerText = container.querySelector('.trigger-text');
    const customInput = group.querySelector('.custom-perihal-field');
    
    const val = optEl.getAttribute('data-value');
    const textLabel = optEl.innerText.trim();
    
    container.querySelectorAll('.custom-option-item').forEach(item => item.classList.remove('active'));
    optEl.classList.add('active');
    
    if (hiddenSelect) {
        hiddenSelect.value = val;
    }
    
    if (triggerText) {
        triggerText.textContent = textLabel;
    }
    
    container.classList.remove('open');
    
    if (val === '__custom__') {
        if (customInput) {
            customInput.style.display = 'block';
            customInput.focus();
        }
    } else {
        if (customInput) {
            customInput.style.display = 'none';
        }
    }
}

function updateCardNumbers() {
    const cards = document.querySelectorAll('#guestCardsContainer .guest-card-row');
    cards.forEach((card, index) => {
        const badge = card.querySelector('.guest-number-badge');
        if (badge) badge.textContent = '#' + (index + 1);
    });
    const emptyMsg = document.getElementById('emptyGuestMsg');
    if (emptyMsg) {
        emptyMsg.style.display = (cards.length === 0) ? 'block' : 'none';
    }
}

function autoDetectKategori(inputNama) {
    const card = inputNama.closest('.guest-card-row');
    if (!card) return;
    const katSelect = card.querySelector('.guest-kategori-select');
    if (!katSelect) return;

    if (katSelect.getAttribute('data-user-modified') === 'true') return;

    const val = inputNama.value.toLowerCase();
    const internalKeywords = ['warek', 'rektor', 'dosen', 'kemahasiswaan', 'dekan', 'kaprodi', 'prodi', 'bem', 'bpm', 'hima', 'ukm', 'kabag', 'kasubag', 'civitas', 'mahasiswa', 'pembina', 'instbunas', 'panitia', 'jurusan', 'akademik'];
    const externalKeywords = ['dinas', 'polres', 'kodim', 'bupati', 'pt ', 'cv ', 'instansi', 'komunitas', 'swasta', 'luar', 'kabupaten', 'kecamatan', 'desa', 'polda', 'gubernur', 'camat', 'lurah', 'media', 'pt.', 'cv.'];

    let isExt = false;
    let isInt = false;

    for (let kw of externalKeywords) {
        if (val.includes(kw)) { isExt = true; break; }
    }
    if (!isExt) {
        for (let kw of internalKeywords) {
            if (val.includes(kw)) { isInt = true; break; }
        }
    }

    if (isExt) {
        katSelect.value = 'L';
        styleKategoriSelect(katSelect);
    } else if (isInt) {
        katSelect.value = 'D';
        styleKategoriSelect(katSelect);
    }
}

function styleKategoriSelect(selectEl) {
    if (selectEl.value === 'L') {
        selectEl.style.borderColor = 'rgba(231, 76, 60, 0.5)';
        selectEl.style.color = '#ff7675';
    } else {
        selectEl.style.borderColor = 'rgba(46, 204, 113, 0.5)';
        selectEl.style.color = '#2ecc71';
    }
}

function addGuestCard(nama = '', perihal = '', kategori = 'D') {
    const container = document.getElementById('guestCardsContainer');
    const card = document.createElement('div');
    card.className = 'guest-card-row';
    card.style.cssText = 'animation: slideDown 0.3s ease-out;';
    
    let isCustom = false;
    let selectedVal = perihal;
    let triggerLabelText = 'Tamu Undangan';
    
    if (perihal !== '') {
        let matched = false;
        templatesPerihalData.forEach(item => {
            if (item.val === perihal || item.label === perihal) {
                matched = true;
                selectedVal = item.val;
                triggerLabelText = item.label;
            }
        });
        if (!matched && perihal !== '') {
            isCustom = true;
            selectedVal = '__custom__';
            triggerLabelText = '➕ Ketik Perihal Custom...';
        }
    } else {
        if (templatesPerihalData.length > 0) {
            selectedVal = templatesPerihalData[0].val;
            triggerLabelText = templatesPerihalData[0].label;
        }
    }
    
    const divN = document.createElement('div');
    divN.textContent = nama;
    const escapedNama = divN.innerHTML.replace(/"/g, '&quot;');
    
    const divP = document.createElement('div');
    divP.textContent = perihal;
    const escapedPerihalCustom = isCustom ? divP.innerHTML.replace(/"/g, '&quot;') : '';

    // Build options for hidden select & custom dropdown
    let hiddenSelectOpts = '';
    let customItemsHtml = '';
    
    templatesPerihalData.forEach(item => {
        const valEsc = item.val.replace(/"/g, '&quot;');
        const lblEsc = item.label.replace(/</g, '&lt;').replace(/>/g, '&gt;');
        const isSel = (!isCustom && item.val === selectedVal) ? 'selected' : '';
        const isAct = (!isCustom && item.val === selectedVal) ? 'active' : '';
        
        hiddenSelectOpts += `<option value="${valEsc}" ${isSel}>${lblEsc}</option>`;
        customItemsHtml += `<div class="custom-option-item ${isAct}" data-value="${valEsc}" onclick="selectCustomDropdownOption(this)"><i class="fas fa-file-alt"></i> ${lblEsc}</div>`;
    });
    
    hiddenSelectOpts += `<option value="__custom__" ${isCustom ? 'selected' : ''}>➕ Ketik Perihal Custom...</option>`;
    customItemsHtml += `<div class="custom-option-item custom-add-opt ${isCustom ? 'active' : ''}" data-value="__custom__" onclick="selectCustomDropdownOption(this)"><i class="fas fa-pen-fancy"></i> ➕ Ketik Perihal Custom...</div>`;

    const katD = (kategori !== 'L') ? 'selected' : '';
    const katL = (kategori === 'L') ? 'selected' : '';
    const katBorder = (kategori === 'L') ? 'rgba(231, 76, 60, 0.5)' : 'rgba(46, 204, 113, 0.5)';
    const katColor = (kategori === 'L') ? '#ff7675' : '#2ecc71';

    card.innerHTML = `
        <div class="guest-number-badge" style="background: #4A90E2; color: #fff; border: none; font-weight: 700; width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; flex-shrink: 0;">
            #1
        </div>
        <div class="guest-inputs-wrapper">
            <div class="guest-field-group nama-group">
                <label class="guest-field-label" style="color: #8BB9F0;"><i class="fas fa-user"></i> Nama / Gelar / Instansi Tamu</label>
                <input type="text" name="tamu_nama[]" class="guest-input-box" value="${escapedNama}" placeholder="Ketik nama / instansi tamu undangan..." oninput="autoDetectKategori(this)">
            </div>
            <div class="guest-field-group perihal-group">
                <label class="guest-field-label" style="color: #f1c40f;"><i class="fas fa-user-tag"></i> Sebagai</label>
                
                <select name="tamu_perihal[]" class="guest-select-hidden" style="display:none;">
                    ${hiddenSelectOpts}
                </select>

                <div class="custom-select-container">
                    <div class="custom-select-trigger" onclick="toggleCustomDropdown(this)">
                        <span class="trigger-label">
                            <i class="fas fa-bookmark" style="color: #f1c40f;"></i>
                            <span class="trigger-text">${triggerLabelText}</span>
                        </span>
                        <i class="fas fa-chevron-down arrow-icon"></i>
                    </div>
                    <div class="custom-select-dropdown">
                        ${customItemsHtml}
                    </div>
                </div>

                <input type="text" name="tamu_perihal_custom[]" class="guest-input-box custom-perihal-field" value="${escapedPerihalCustom}" placeholder="Ketik 'Sebagai' custom..." style="display: ${isCustom ? 'block' : 'none'}; margin-top: 8px;">
            </div>
            <div class="guest-field-group kategori-group">
                <label class="guest-field-label" style="color: #2ecc71;"><i class="fas fa-building"></i> Asal Tamu</label>
                <select name="tamu_kategori[]" class="guest-input-box guest-kategori-select" style="border-color: ${katBorder}; color: ${katColor}; font-weight: 700; cursor: pointer;" onchange="this.setAttribute('data-user-modified', 'true'); styleKategoriSelect(this);">
                    <option value="D" ${katD}>🏢 Dalam (D)</option>
                    <option value="L" ${katL}>🌐 Luar (L)</option>
                </select>
            </div>
        </div>
        <button type="button" onclick="deleteGuestCard(this)" title="Hapus Tamu Ini" style="background: rgba(231, 76, 60, 0.12); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); width: 38px; height: 38px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all 0.2s;" onmouseover="this.style.background='rgba(231, 76, 60, 0.3)'; this.style.color='#fff';" onmouseout="this.style.background='rgba(231, 76, 60, 0.12)'; this.style.color='#e74c3c';">
            <i class="fas fa-trash-alt"></i>
        </button>
    `;

    container.appendChild(card);
    updateCardNumbers();
    
    const input = card.querySelector('input[name="tamu_nama[]"]');
    if (input && nama === '') input.focus();
}

function deleteGuestCard(btn) {
    const card = btn.closest('.guest-card-row');
    if (card) {
        card.style.opacity = '0';
        card.style.transform = 'scale(0.95)';
        setTimeout(() => {
            card.remove();
            updateCardNumbers();
        }, 200);
    }
}

function addGuestFromTemplate(text) {
    const inputs = document.querySelectorAll('#guestCardsContainer input[name="tamu_nama[]"]');
    let exists = false;
    inputs.forEach(inp => {
        if (inp.value.trim().toLowerCase() === text.trim().toLowerCase()) {
            exists = true;
        }
    });
    
    if (exists) {
        alert("Tamu ini sudah ada di dalam daftar.");
        return;
    }
    
    addGuestCard(text, '');
}

// Auto initialize 1 card if empty on load
document.addEventListener('DOMContentLoaded', function() {
    const cards = document.querySelectorAll('#guestCardsContainer .guest-card-row');
    if (cards.length === 0) {
        addGuestCard('', '');
    }
});
</script>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
