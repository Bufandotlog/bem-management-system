<?php
// admin/kegiatan/master-kegiatan.php
require_once __DIR__ . '/../core/header.php';

// Pastikan hanya admin / superadmin yang bisa akses
if (!($isSuperadmin || $admin_role === 'admin')) {
    redirect('admin/core/dashboard.php', 'Akses ditolak: Hanya Admin yang dapat mengelola Kegiatan.', 'error');
}

$periode_id = getUserPeriode();
$error = '';
$success = '';

// Check if request is AJAX
$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') 
        || (isset($_POST['ajax']) && $_POST['ajax'] === '1');

// Proses Hapus
if (isset($_GET['del']) && is_numeric($_GET['del']) && isset($_GET['token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_GET['token'])) {
    $del_id = (int)$_GET['del'];
    try {
        dbQuery("DELETE FROM kegiatan WHERE id = ? AND periode_id = ?", [$del_id, $periode_id]);
        $success = "Kegiatan berhasil dihapus beserta data panitia terkait.";
    } catch (Exception $e) {
        $error = "Gagal menghapus kegiatan: " . $e->getMessage();
    }
}

// Proses Update Status (Support AJAX & Standard Form Submit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!csrfVerify($_POST['csrf_token'] ?? '')) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Token keamanan tidak valid.']);
            exit;
        }
        $error = "Token keamanan tidak valid.";
    } else {
        $kegiatan_id = (int)$_POST['kegiatan_id'];
        $new_status = $_POST['status'];
        if (in_array($new_status, ['persiapan', 'berjalan', 'selesai'])) {
            dbQuery("UPDATE kegiatan SET status = ? WHERE id = ? AND periode_id = ?", [$new_status, $kegiatan_id, $periode_id]);
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Status kegiatan berhasil diperbarui.']);
                exit;
            }
            $success = "Status kegiatan berhasil diperbarui secara manual.";
        } else {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Status tidak valid.']);
                exit;
            }
        }
    }
}

// Proses Update Status Otomatis (Hanya Maju Ke Depan)
dbQuery("UPDATE kegiatan SET status = 'berjalan' WHERE tanggal_mulai IS NOT NULL AND CURRENT_DATE() >= tanggal_mulai AND (tanggal_selesai IS NULL OR CURRENT_DATE() <= tanggal_selesai) AND status = 'persiapan'");
dbQuery("UPDATE kegiatan SET status = 'selesai' WHERE tanggal_selesai IS NOT NULL AND CURRENT_DATE() > tanggal_selesai AND status != 'selesai'");

// Proses Simpan (Tambah / Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['add', 'edit'])) {
    if (!csrfVerify($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $nama = trim($_POST['nama_kegiatan']);
        $kode_kegiatan = strtoupper(trim($_POST['kode_kegiatan'] ?? ''));
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $tgl_mulai = !empty($_POST['tanggal_mulai']) ? $_POST['tanggal_mulai'] : null;
        $tgl_selesai = !empty($_POST['tanggal_selesai']) ? $_POST['tanggal_selesai'] : null;
        $waktu = !empty($_POST['waktu_pelaksanaan']) ? $_POST['waktu_pelaksanaan'] : null;
        $tempat = !empty($_POST['tempat_pelaksanaan']) ? $_POST['tempat_pelaksanaan'] : null;
        $pelaksana = trim($_POST['pelaksana'] ?? '');
        $program_kerja = trim($_POST['program_kerja'] ?? '');
        $tujuan = isset($_POST['tujuan']) && is_array($_POST['tujuan']) ? json_encode(array_values(array_filter(array_map('trim', $_POST['tujuan'])))) : null;
        $manfaat = isset($_POST['manfaat']) && is_array($_POST['manfaat']) ? json_encode(array_values(array_filter(array_map('trim', $_POST['manfaat'])))) : null;
        $ketuplat_id = !empty($_POST['ketuplat_id']) ? (int)$_POST['ketuplat_id'] : null;

        if (empty($nama)) {
            $error = "Nama kegiatan wajib diisi.";
        } else {
            try {
                dbBeginTransaction();
                
                if ($_POST['action'] === 'add') {
                    dbQuery("INSERT INTO kegiatan (periode_id, nama_kegiatan, kode_kegiatan, deskripsi, tanggal_mulai, tanggal_selesai, waktu_pelaksanaan, tempat_pelaksanaan, pelaksana, program_kerja, tujuan, manfaat, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                        $periode_id, $nama, $kode_kegiatan, $deskripsi, $tgl_mulai, $tgl_selesai, $waktu, $tempat, $pelaksana, $program_kerja, $tujuan, $manfaat, $_SESSION['admin_id']
                    ]);
                    $kegiatan_id = dbLastId();
                    
                    if ($ketuplat_id) {
                        dbQuery("INSERT INTO kegiatan_panitia (kegiatan_id, user_id, event_role, ditunjuk_oleh) VALUES (?, ?, 'ketuplat', ?)", [
                            $kegiatan_id, $ketuplat_id, $_SESSION['admin_id']
                        ]);
                    }
                    $success = "Kegiatan berhasil ditambahkan.";
                } else {
                    $edit_id = (int)$_POST['edit_id'];
                    dbQuery("UPDATE kegiatan SET nama_kegiatan = ?, kode_kegiatan = ?, deskripsi = ?, tanggal_mulai = ?, tanggal_selesai = ?, waktu_pelaksanaan = ?, tempat_pelaksanaan = ?, pelaksana = ?, program_kerja = ?, tujuan = ?, manfaat = ? WHERE id = ? AND periode_id = ?", [
                        $nama, $kode_kegiatan, $deskripsi, $tgl_mulai, $tgl_selesai, $waktu, $tempat, $pelaksana, $program_kerja, $tujuan, $manfaat, $edit_id, $periode_id
                    ]);
                    
                    // Update ketuplat
                    dbQuery("DELETE FROM kegiatan_panitia WHERE kegiatan_id = ? AND event_role = 'ketuplat'", [$edit_id]);
                    if ($ketuplat_id) {
                        dbQuery("INSERT INTO kegiatan_panitia (kegiatan_id, user_id, event_role, ditunjuk_oleh) VALUES (?, ?, 'ketuplat', ?)", [
                            $edit_id, $ketuplat_id, $_SESSION['admin_id']
                        ]);
                    }
                    $success = "Kegiatan berhasil diperbarui.";

                    // === RETAIN CONSISTENCY: sinkron tanggal rundown terikat saat kegiatan diedit ===
                    if ($tgl_mulai) {
                        $_new_durasi = 1;
                        if ($tgl_selesai) {
                            $_rd1 = new DateTime($tgl_mulai);
                            $_rd2 = new DateTime($tgl_selesai);
                            $_new_durasi = max(1, $_rd1->diff($_rd2)->days + 1);
                        }
                        dbQuery("UPDATE arsip_rundown SET tanggal_mulai = ?, durasi_hari = ? WHERE kegiatan_id = ? AND periode_id = ?", [
                            $tgl_mulai, $_new_durasi, $edit_id, $periode_id
                        ]);
                    }
                }

                // Auto-insert kode_kegiatan baru ke surat_templates jika belum ada
                if (!empty($kode_kegiatan)) {
                    $existing_tpl = dbFetchOne("SELECT id FROM surat_templates WHERE periode_id = ? AND jenis = 'kegiatan' AND UPPER(label) = ?", [$periode_id, $kode_kegiatan]);
                    if (!$existing_tpl) {
                        dbQuery("INSERT INTO surat_templates (periode_id, nama_template, label, jenis, perihal_default, urutan) VALUES (?, ?, ?, 'kegiatan', ?, 0)", [$periode_id, $kode_kegiatan, $kode_kegiatan, $kode_kegiatan]);
                    }
                }
                
                dbCommit();
            } catch (Exception $e) {
                dbRollback();
                $error = "Terjadi kesalahan: " . $e->getMessage();
            }
        }
    }
}

// Cek jika mode edit
$edit_data = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $e_id = (int)$_GET['edit'];
    $edit_data = dbFetchOne("
        SELECT k.*, 
               (SELECT user_id FROM kegiatan_panitia kp WHERE kp.kegiatan_id = k.id AND kp.event_role = 'ketuplat' LIMIT 1) as ketuplat_id
        FROM kegiatan k 
        WHERE k.id = ? AND k.periode_id = ?
    ", [$e_id, $periode_id]);
}

// Ambil daftar user ber-role 'anggota' untuk dropdown Ketuplat
$list_anggota = dbFetchAll("SELECT id, nama, username FROM users WHERE role = 'anggota' AND is_active = 1 AND (periode_id = ? OR periode_id IS NULL) ORDER BY nama ASC", [$periode_id]);

// Ambil data template tempat & kode kegiatan
$templates = dbFetchAll("SELECT * FROM surat_templates WHERE periode_id = ?", [$periode_id], "i");
$list_tempat = array_filter($templates, fn($t) => $t['jenis'] === 'tempat');
$list_kode_kegiatan = array_filter($templates, fn($t) => $t['jenis'] === 'kegiatan');

// Ambil kementerian untuk proker dropdown
$list_kementerian = dbFetchAll("SELECT nama, proker FROM kementerian WHERE periode_id = ? ORDER BY urutan ASC", [$periode_id], "i");
$proker_map = [];
foreach ($list_kementerian as $kem) {
    $proker_map[$kem['nama']] = !empty($kem['proker']) ? (json_decode($kem['proker'], true) ?: []) : [];
}

// Ambil daftar kegiatan
$list_kegiatan = dbFetchAll("
    SELECT k.*, u.nama as pembuat, 
           (SELECT users.nama FROM kegiatan_panitia kp JOIN users ON kp.user_id = users.id WHERE kp.kegiatan_id = k.id AND kp.event_role = 'ketuplat' LIMIT 1) as nama_ketuplat
    FROM kegiatan k
    LEFT JOIN users u ON k.created_by = u.id
    WHERE k.periode_id = ?
    ORDER BY k.created_at DESC
", [$periode_id]);
?>

<style>
:root {
    --card-bg: #0d1117;
    --border-color: rgba(255, 255, 255, 0.12);
    --accent-blue: #2563eb;
    --text-main: #ffffff;
    --text-muted: #9ea7b4;
}

/* Page Header & View Toggle */
.page-header {
    background: var(--card-bg);
    padding: 20px 25px;
    border-radius: 16px;
    border: 1px solid var(--border-color);
    box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    backdrop-filter: blur(10px);
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}
.page-header-info h1 { margin: 0 0 4px 0; font-size: 1.6rem; color: var(--text-main); display: flex; align-items: center; gap: 10px; }
.page-header-info p { margin: 0; color: var(--text-muted); font-size: 0.88rem; }

.btn-toggle-main {
    background: #ffffff;
    color: #090d16;
    border: none;
    font-weight: 700;
    padding: 10px 20px;
    border-radius: 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(255,255,255,0.15);
}
.btn-toggle-main:hover {
    background: #e2e8f0;
    transform: translateY(-1px);
}

.view-section {
    transition: opacity 0.25s ease-in-out;
}

/* Grid Layout Form Full Width */
.form-grid-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
    align-items: start;
}

/* ============================================
   MODERN FLOATING LABEL INPUT SYSTEM
   ============================================ */
.floating-group {
    position: relative;
    margin-bottom: 22px;
    overflow: visible;
}

.floating-input, .floating-textarea {
    width: 100%;
    padding: 18px 14px 6px 14px;
    background: #080c14;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-main);
    font-size: 0.92rem;
    box-sizing: border-box;
    transition: all 0.2s ease;
}

.floating-textarea {
    padding-top: 22px;
    resize: vertical;
}

.floating-input::placeholder, .floating-textarea::placeholder {
    color: transparent;
}

.floating-label {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 0.88rem;
    color: var(--text-muted);
    pointer-events: none;
    transition: all 0.2s ease;
    background: transparent;
    padding: 0 4px;
    font-weight: 500;
    z-index: 10;
}

.floating-textarea ~ .floating-label {
    top: 20px;
    transform: none;
}

/* TPL Icon floating offset */
.tpl-floating-input {
    padding-left: 40px !important;
}
.tpl-floating-label {
    left: 38px !important;
}

/* Floating Label Animation & Scale */
.floating-input:focus ~ .floating-label,
.floating-input:not(:placeholder-shown) ~ .floating-label,
.floating-textarea:focus ~ .floating-label,
.floating-textarea:not(:placeholder-shown) ~ .floating-label {
    top: 0;
    transform: translateY(-50%) scale(0.82);
    transform-origin: left top;
    background: #080c14;
    color: #ffffff;
    font-weight: 700;
    border-radius: 4px;
}

.floating-input:focus, .floating-textarea:focus {
    outline: none;
    border-color: var(--accent-blue);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
}

/* Popover Button Inside Floating Group Header */
.popover-header-btn {
    position: absolute;
    right: 12px;
    top: -12px;
    z-index: 999;
    overflow: visible;
    pointer-events: auto;
}

.btn-info-popover {
    position: relative;
    z-index: 999;
    background: #080c14;
    color: var(--text-muted);
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 0.75rem;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-info-popover:hover {
    background: rgba(255, 255, 255, 0.15);
    color: #ffffff;
}
.popover-box {
    display: none;
    position: absolute;
    right: 0;
    top: 28px;
    width: 280px;
    background: #090d16;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 12px 14px;
    font-size: 0.8rem;
    color: var(--text-muted);
    line-height: 1.5;
    box-shadow: 0 10px 30px rgba(0,0,0,0.85);
    z-index: 100;
}
.popover-box.show { display: block !important; }

/* Symmetric Date Range Inputs */
.date-range-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.date-range-wrap input[type="date"],
.date-range-wrap select {
    min-height: 42px;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid var(--border-color);
    background: #080c14;
    color: var(--text-main);
    font-size: 0.88rem;
}

/* Compact & Aligned Drum Time Picker UI */
.wakpel-card { background: rgba(0,0,0,0.25); border-radius: 16px; padding: 20px; border: 1px solid var(--border-color); margin-bottom: 20px; }
.wakpel-card-label { font-size: 0.8rem; color: #ffffff; text-transform: uppercase; font-weight: 700; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
.preview-bar { background: rgba(255, 255, 255, 0.03); border-radius: 10px; padding: 10px 14px; font-size: 0.85rem; margin-top: 12px; color: var(--text-main); border-left: 3px solid #ffffff; }

.drum-groups-wrap {
    display: flex;
    gap: 15px;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: nowrap;
    overflow-x: auto;
    background: #05080e;
    padding: 15px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
}
.drum-time-container-mobile {
    display: flex;
    align-items: center;
    gap: 12px;
}
.drum-col { width: 50px; height: 136px; background: #0b0f19; border-radius: 10px; overflow: hidden; position: relative; cursor: ns-resize; border: 1px solid rgba(255, 255, 255, 0.08); }
.drum-inner { position: absolute; top: 0; left: 0; width: 100%; transition: transform 0.2s cubic-bezier(0.1, 0.7, 1.0, 0.1); will-change: transform; padding: 4px 0; }
.drum-item { height: 36px; line-height: 36px; text-align: center; font-size: 1rem; color: #475569; transition: all 0.2s; opacity: 0.4; filter: blur(0.5px); }
.drum-item.sel { color: #ffffff; font-weight: 700; opacity: 1; transform: scale(1.08); filter: blur(0); }
.drum-highlight { position: absolute; top: 50px; left: 3px; right: 3px; height: 36px; background: rgba(255, 255, 255, 0.08); border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.2); pointer-events: none; z-index: 5; }
.drum-group { display: flex; align-items: center; gap: 6px; }
.drum-arrow { background: #121824; border: 1px solid var(--border-color); color: var(--text-muted); font-size: 0.75rem; cursor: pointer; padding: 3px 8px; border-radius: 6px; transition: all 0.2s; display: block; width: 100%; }
.drum-arrow:hover { background: #1e293b; color: #ffffff; }
.drum-colon { color: #ffffff; font-weight: 700; font-size: 1.1rem; padding-top: 18px; }
.drum-time-label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; margin-bottom: 6px; font-weight: 700; text-align: center; }

/* Autocomplete TPL Picker */
.tpl-picker { position: relative; }
.tpl-search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.9rem; pointer-events: none; z-index: 12; }
.tpl-results { position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: #090d16; border: 1px solid var(--border-color); border-radius: 12px; max-height: 220px; overflow-y: auto; z-index: 100; box-shadow: 0 12px 30px rgba(0,0,0,0.7); display: none; padding: 6px; }
.tpl-item { padding: 10px 14px; border-radius: 8px; cursor: pointer; transition: all 0.2s ease; }
.tpl-item:hover { background: rgba(255, 255, 255, 0.08); }
.tpl-item-label { font-weight: 600; color: #ffffff; font-size: 0.88rem; }
.tpl-empty { padding: 12px; text-align: center; color: var(--text-muted); font-style: italic; font-size: 0.85rem; }

/* Desktop Premium Table Layout */
.table-premium {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 8px;
}
.table-premium th {
    padding: 14px 18px;
    text-transform: uppercase;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: var(--text-muted);
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}
.table-premium td {
    padding: 14px 18px;
    background: rgba(255, 255, 255, 0.02);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.table-premium td:first-child { border-left: 1px solid var(--border-color); border-radius: 12px 0 0 12px; }
.table-premium td:last-child { border-right: 1px solid var(--border-color); border-radius: 0 12px 12px 0; }
.table-premium tr:hover td { background: rgba(255, 255, 255, 0.05); }

/* Desktop Card Body Padding Defaults */
.card-body-list { padding: 18px; }
.card-body-form { padding: 24px; }

/* Custom Badge Status Select */
.status-select-wrap { position: relative; display: inline-block; width: 100%; max-width: 160px; }
.status-select {
    width: 100%;
    padding: 6px 26px 6px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.78rem;
    cursor: pointer;
    outline: none;
    appearance: none;
    -webkit-appearance: none;
    border: 1px solid transparent;
    transition: all 0.2s ease;
}
.status-select-wrap::after {
    content: "\f0d7";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    font-size: 0.75rem;
    opacity: 0.8;
}
.status-persiapan { background: rgba(245, 158, 11, 0.15); color: #fbbf24; border-color: rgba(245, 158, 11, 0.3); }
.status-berjalan  { background: rgba(37, 99, 235, 0.15); color: #60a5fa; border-color: rgba(37, 99, 235, 0.3); }
.status-selesai   { background: rgba(16, 185, 129, 0.15); color: #34d399; border-color: rgba(16, 185, 129, 0.3); }

.badge-ketuplat {
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255, 255, 255, 0.06); 
    color: #ffffff; 
    border: 1px solid rgba(255, 255, 255, 0.15);
}

/* Touch-friendly Action Buttons */
.btn-action-group {
    display: flex;
    gap: 8px;
    align-items: center;
}
.btn-action {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 600;
    transition: all 0.2s;
    border: 1px solid transparent;
    text-decoration: none !important;
}
.btn-action-primary { background: rgba(255, 255, 255, 0.08); color: #ffffff; border-color: rgba(255, 255, 255, 0.15); }
.btn-action-primary:hover { background: #ffffff; color: #090d16; }
.btn-action-danger { background: rgba(239, 68, 68, 0.12); color: #f87171; border-color: rgba(239, 68, 68, 0.25); }
.btn-action-danger:hover { background: #ef4444; color: #ffffff; }

/* Custom Delete Modal */
.custom-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(4px);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.custom-modal-content {
    background: #0d1117;
    border: 1px solid var(--border-color);
    border-radius: 16px;
    width: 100%;
    max-width: 440px;
    padding: 24px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.8);
    animation: modalSlideIn 0.2s ease-out;
}
@keyframes modalSlideIn {
    from { opacity: 0; transform: translateY(-15px); }
    to { opacity: 1; transform: translateY(0); }
}
.custom-modal-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
.custom-modal-header h3 { margin: 0; font-size: 1.15rem; color: #ffffff; }
.custom-modal-body { color: var(--text-muted); font-size: 0.9rem; line-height: 1.5; margin-bottom: 20px; }
.custom-modal-footer { display: flex; justify-content: flex-end; gap: 10px; }

/* RESPONSIVE MOBILE STYLES (MAX 768px) */
@media (max-width: 768px) {
    .page-header {
        padding: 16px;
        border-radius: 14px;
        margin-bottom: 15px;
    }
    .page-header-info h1 {
        font-size: 1.35rem;
    }
    .btn-toggle-main {
        width: 100%;
        justify-content: center;
        padding: 12px;
    }

    /* Standardize padding layers on mobile to fix horizontal alignment */
    .card-body-list, .card-body-form, .card-body-custom {
        padding: 12px 8px !important;
    }
    .card-header {
        padding: 14px 16px !important;
    }
    
    /* Mobile Form Grid & Controls */
    .form-grid-layout {
        grid-template-columns: 1fr !important;
        gap: 16px !important;
    }
    .wakpel-card {
        padding: 14px;
        border-radius: 14px;
    }
    .date-range-wrap {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
    }
    .date-range-wrap input[type="date"],
    .date-range-wrap select {
        width: 100% !important;
        box-sizing: border-box;
    }

    /* Drum Time Picker Mobile Layout */
    .drum-groups-wrap {
        flex-direction: column;
        align-items: center;
        gap: 12px;
        padding: 14px;
    }
    .drum-time-container-mobile {
        width: 100%;
        justify-content: center;
        gap: 10px;
    }
    .toggle-switch-wrap-mobile {
        width: 100%;
        margin-top: 4px;
    }

    /* Form Action Buttons Mobile (Full Width 100%) */
    .form-submit-bar {
        flex-direction: column-reverse !important;
        gap: 10px !important;
        width: 100%;
    }
    .form-submit-bar button {
        width: 100% !important;
        padding: 12px !important;
        justify-content: center;
        font-size: 0.95rem !important;
        min-height: 44px;
    }

    /* Mobile Responsive Card Rows (Daftar Kegiatan)
       Use .table-premium prefix to win specificity over desktop rules */
    .table-premium.responsive-card-table {
        display: flex !important;
        flex-direction: column;
        gap: 12px;
        border: none !important;
        border-spacing: 0 !important;
        border-collapse: unset !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .table-responsive {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .table-premium.responsive-card-table thead {
        display: none !important;
    }
    .table-premium.responsive-card-table tbody {
        display: flex !important;
        flex-direction: column;
        gap: 12px;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .table-premium.responsive-card-table tr {
        display: flex !important;
        flex-direction: column;
        align-items: stretch;
        background: #080c14 !important;
        border: 1px solid var(--border-color) !important;
        border-radius: 14px !important;
        padding: 0 !important;
        box-shadow: 0 4px 15px rgba(0,0,0,0.4);
        margin: 0 !important;
        width: 100% !important;
        box-sizing: border-box;
    }
    .table-premium.responsive-card-table tr:hover td {
        background: transparent !important;
    }
    .table-premium.responsive-card-table td,
    .table-premium.responsive-card-table td:first-child,
    .table-premium.responsive-card-table td:last-child {
        display: block !important;
        padding: 0 !important;
        border: none !important;
        border-radius: 0 !important;
        background: transparent !important;
        width: 100% !important;
        margin: 0 !important;
        min-width: 0 !important;
        max-width: none !important;
        box-sizing: border-box;
        align-self: stretch;
    }
    .table-premium.responsive-card-table .hide-on-mobile {
        display: none !important;
    }
    .mobile-only-status {
        display: block !important;
    }

    /* Symmetrical Card Interior Sections */
    .mobile-card-header-area {
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
        padding: 12px 12px 10px 12px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        margin-bottom: 0;
    }
    .mobile-card-header-flex {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 6px;
        min-width: 0;
        width: 100%;
    }
    .mobile-card-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        width: 100%;
        max-width: 100%;
        min-width: 0;
        box-sizing: border-box;
    }
    .mobile-card-label {
        font-size: 0.72rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        flex-shrink: 0;
    }
    .mobile-card-value {
        font-size: 0.85rem;
        color: var(--text-main);
        text-align: right;
        display: flex;
        justify-content: flex-end;
        align-items: center;
    }

    .status-select-wrap {
        display: flex !important;
        justify-content: flex-end !important;
        max-width: none !important;
        width: auto !important;
    }
    .status-select {
        text-align: center;
        width: auto !important;
        min-width: 130px;
    }

    .btn-action-group {
        width: 100%;
        display: flex;
        gap: 10px;
        padding: 12px;
        border-top: 1px solid rgba(255,255,255,0.08);
        box-sizing: border-box;
    }
    .btn-action-group .btn-action {
        flex: 1;
        height: 42px;
        border-radius: 10px;
        font-size: 0.88rem;
        justify-content: center;
    }
}

/* Dynamic List specific styles */
.dynamic-list-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}
.dynamic-list-row input {
    flex: 1;
    padding: 12px 14px;
    background: #080c14;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    color: var(--text-main);
    font-size: 0.92rem;
}
.dynamic-list-row input:focus {
    outline: none;
    border-color: var(--accent-blue);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
}
.btn-remove-row {
    background: #ef4444;
    color: #ffffff;
    border: none;
    border-radius: 10px;
    width: 44px;
    height: 44px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.btn-remove-row:hover { background: #dc2626; }
.btn-add-row {
    width: 100%;
    padding: 12px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px dashed rgba(255, 255, 255, 0.2);
    color: var(--text-muted);
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.2s;
    font-weight: 600;
}
.btn-add-row:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #ffffff;
    border-color: rgba(255, 255, 255, 0.4);
}
</style>

<!-- Header Halaman Dengan View Switcher Toggle -->
<div class="page-header">
    <div class="page-header-info">
        <h1><i class="fas fa-calendar-check" style="color: #ffffff;"></i> Manajemen Kegiatan</h1>
        <p>Kelola program kerja dan penunjukan Ketua Pelaksana.</p>
    </div>
    <div>
        <button id="btn-toggle-view" class="btn-toggle-main" onclick="toggleView()">
            <i class="fas fa-plus-circle" id="toggle-icon"></i>
            <span id="toggle-text">Buat Kegiatan Baru</span>
        </button>
    </div>
</div>

<?php if ($error) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> $error</div>"; ?>
<?php if ($success) echo "<div class='alert alert-success'><i class='fas fa-check-circle'></i> $success</div>"; ?>

<!-- VIEW 1: DAFTAR KEGIATAN (DEFAULT FULL WIDTH) -->
<div id="view-list" class="view-section" style="<?php echo $edit_data ? 'display: none;' : 'display: block;'; ?>">
    <div class="card" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px; overflow: hidden;">
        <div class="card-header" style="padding: 18px 22px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.1rem; color: #ffffff;"><i class="fas fa-list-ul" style="color: #ffffff; margin-right: 8px;"></i> Daftar Kegiatan</h3>
            <span class="badge" style="background: rgba(255, 255, 255, 0.06); color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.15); padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 0.8rem;">
                Total: <?php echo count($list_kegiatan); ?> Kegiatan
            </span>
        </div>
        <div class="card-body card-body-custom card-body-list">
            <div class="table-responsive" style="border: none;">
                <table class="table-premium responsive-card-table">
                    <thead>
                        <tr>
                            <th>Kegiatan</th>
                            <th>Jadwal</th>
                            <th>Ketua Pelaksana</th>
                            <th width="140">Status</th>
                            <th width="120" style="text-align: center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($list_kegiatan)): ?>
                            <tr><td colspan="5" style="text-align:center; color: var(--text-muted); padding: 30px;">Belum ada kegiatan untuk periode ini.</td></tr>
                        <?php else: ?>
                            <?php foreach($list_kegiatan as $k): ?>
                            <tr>
                                <!-- Kolom Header Utama (Nama & Kode) -->
                                <td>
                                    <div class="mobile-card-header-area">
                                        <div style="width: 100%;">
                                            <strong style="color: #ffffff; font-size: 0.98rem; display: block; margin-bottom: 4px;"><?php echo htmlspecialchars($k['nama_kegiatan']); ?></strong>
                                            <?php if($k['deskripsi']): ?>
                                            <div style="font-size:0.82rem; color: var(--text-muted); line-height: 1.4;"><?php echo htmlspecialchars($k['deskripsi']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>

                                <!-- Kolom Jadwal -->
                                <td class="hide-on-mobile">
                                    <div class="mobile-card-row">
                                        <span class="mobile-card-label">Jadwal</span>
                                        <span class="mobile-card-value">
                                            <?php 
                                                if ($k['tanggal_mulai'] && $k['tanggal_selesai']) {
                                                    echo date('d/m/Y', strtotime($k['tanggal_mulai'])) . ' – ' . date('d/m/Y', strtotime($k['tanggal_selesai']));
                                                } elseif ($k['tanggal_mulai']) {
                                                    echo date('d/m/Y', strtotime($k['tanggal_mulai']));
                                                } else {
                                                    echo '-';
                                                }
                                            ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Kolom Ketuplat -->
                                <td class="hide-on-mobile">
                                    <div class="mobile-card-row">
                                        <span class="mobile-card-label">Ketua Pelaksana</span>
                                        <span class="mobile-card-value">
                                            <?php if ($k['nama_ketuplat']): ?>
                                                <span class="badge-ketuplat"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($k['nama_ketuplat']); ?></span>
                                            <?php else: ?>
                                                <span style="color: #64748b; font-size:0.83rem;"><i class="fas fa-exclamation-circle" style="color: #f59e0b; margin-right: 4px;"></i> <em>Belum Ditunjuk</em></span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Kolom Status -->
                                <td class="hide-on-mobile">
                                    <div class="mobile-card-row">
                                        <span class="mobile-card-label">Status</span>
                                        <div class="status-select-wrap">
                                            <select class="status-select status-<?php echo $k['status']; ?>" onchange="updateStatusAjax(this, <?php echo $k['id']; ?>)">
                                                <option value="persiapan" <?php echo $k['status'] === 'persiapan' ? 'selected' : ''; ?>>Persiapan</option>
                                                <option value="berjalan" <?php echo $k['status'] === 'berjalan' ? 'selected' : ''; ?>>Berjalan</option>
                                                <option value="selesai" <?php echo $k['status'] === 'selesai' ? 'selected' : ''; ?>>Selesai</option>
                                            </select>
                                        </div>
                                    </div>
                                </td>

                                <!-- Kolom Aksi -->
                                <td class="td-aksi hide-on-mobile">
                                    <div class="btn-action-group">
                                        <a href="?edit=<?php echo $k['id']; ?>" class="btn-action btn-action-primary" title="Edit Kegiatan"><i class="fas fa-edit"></i> Edit</a>
                                        <button type="button" onclick="openDeleteModal('<?php echo baseUrl('admin/kegiatan/master-kegiatan.php?del=' . $k['id'] . '&token=' . csrfToken()); ?>', '<?php echo htmlspecialchars(addslashes($k['nama_kegiatan'])); ?>')" class="btn-action btn-action-danger" title="Hapus Kegiatan"><i class="fas fa-trash-alt"></i> Hapus</button>
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
</div>

<!-- VIEW 2: FORM BUAT / EDIT KEGIATAN (FULL WIDTH GRID 2 KOLOM) -->
<div id="view-form" class="view-section" style="<?php echo $edit_data ? 'display: block;' : 'display: none;'; ?>">
    <div class="card" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 16px;">
        <div class="card-header" style="padding: 18px 22px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.1rem; color: #ffffff;">
                <i class="fas <?php echo $edit_data ? 'fa-edit' : 'fa-plus-circle'; ?>" style="color: #ffffff; margin-right: 8px;"></i>
                <?php echo $edit_data ? 'Edit Kegiatan' : 'Buat Kegiatan Baru'; ?>
            </h3>
            <button type="button" class="btn btn-outline" onclick="switchView('list')" style="padding: 6px 14px; font-size: 0.85rem; border-radius: 8px; border-color: rgba(255,255,255,0.2); color: #ffffff;">
                <i class="fas fa-times"></i> Batal
            </button>
        </div>
        <div class="card-body card-body-custom card-body-form">
            <form action="master-kegiatan.php" method="POST">
                <input type="hidden" name="action" value="<?php echo $edit_data ? 'edit' : 'add'; ?>">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $edit_data['id']; ?>">
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">

                <div class="form-grid-layout">
                    <!-- KOLOM KIRI: Informasi Utama & Ketuplat -->
                    <div>
                        <!-- FLOATING INPUT: Pelaksana (Kementerian) -->
                        <div class="floating-group">
                            <div class="tpl-picker" id="picker-pelaksana">
                                <i class="fas fa-users tpl-search-icon"></i>
                                <input type="text" id="input_pelaksana" name="pelaksana" class="floating-input tpl-floating-input" placeholder=" " value="<?php echo htmlspecialchars($edit_data['pelaksana'] ?? ''); ?>" required autocomplete="off" onfocus="showTplResults('pelaksana')" onkeyup="filterTpl('pelaksana')">
                                <label for="input_pelaksana" class="floating-label tpl-floating-label">Menteri / Pelaksana</label>
                                <div class="tpl-results" id="results-pelaksana">
                                    <div class="tpl-item" onclick='selectPelaksana("Badan Eksekutif Mahasiswa (BEM)")'>
                                        <div class="tpl-item-label">Badan Eksekutif Mahasiswa (BEM)</div>
                                    </div>
                                    <div class="tpl-item" onclick='selectPelaksana("Badan Pengurus Harian (BPH) BEM")'>
                                        <div class="tpl-item-label">Badan Pengurus Harian (BPH) BEM</div>
                                    </div>
                                    <?php foreach($list_kementerian as $kem): ?>
                                    <div class="tpl-item" onclick='selectPelaksana(<?php echo json_encode($kem["nama"]); ?>)'>
                                        <div class="tpl-item-label"><?php echo htmlspecialchars($kem['nama']); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- FLOATING INPUT: Program Kerja -->
                        <div class="floating-group">
                            <div class="tpl-picker" id="picker-proker">
                                <i class="fas fa-tasks tpl-search-icon"></i>
                                <input type="text" id="input_proker" name="program_kerja" class="floating-input tpl-floating-input" placeholder=" " value="<?php echo htmlspecialchars($edit_data['program_kerja'] ?? ''); ?>" autocomplete="off" onfocus="showTplResults('proker')" onkeyup="filterTpl('proker')">
                                <label for="input_proker" class="floating-label tpl-floating-label">Program Kerja</label>
                                <div class="tpl-results" id="results-proker">
                                    <div class="tpl-empty">Pilih Menteri / Pelaksana terlebih dahulu atau ketik manual.</div>
                                </div>
                            </div>
                        </div>

                        <!-- FLOATING INPUT: Nama Kegiatan -->
                        <div class="floating-group">
                            <input type="text" id="nama_kegiatan" name="nama_kegiatan" class="floating-input" placeholder=" " required value="<?php echo htmlspecialchars($edit_data['nama_kegiatan'] ?? ''); ?>">
                            <label for="nama_kegiatan" class="floating-label">Nama Kegiatan <span style="color:#ef4444;">*</span></label>
                        </div>

                        <!-- FLOATING INPUT: Kode Kegiatan -->
                        <div class="floating-group">
                            <div class="tpl-picker" id="picker-kode-kegiatan">
                                <i class="fas fa-search tpl-search-icon"></i>
                                <input type="text" id="input_kode_kegiatan" name="kode_kegiatan" class="floating-input tpl-floating-input" placeholder=" " value="<?php echo htmlspecialchars($edit_data['kode_kegiatan'] ?? ''); ?>" autocomplete="off" onfocus="showTplResults('kode-kegiatan')" onkeyup="filterTpl('kode-kegiatan')" style="text-transform: uppercase; font-weight: 700; letter-spacing: 1px;">
                                <label for="input_kode_kegiatan" class="floating-label tpl-floating-label">Kode Kegiatan</label>
                                <div class="tpl-results" id="results-kode-kegiatan">
                                    <?php foreach($list_kode_kegiatan as $kk): ?>
                                    <div class="tpl-item" onclick='selectKodeKegiatan(<?php echo json_encode(strtoupper($kk["label"])); ?>)'>
                                        <div class="tpl-item-label"><?php echo htmlspecialchars(strtoupper($kk['label'])); ?></div>
                                        <div class="tpl-item-text" style="color:var(--text-muted); font-size:0.75rem;">Kode: <?php echo htmlspecialchars($kk['perihal_default']); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- FLOATING INPUT: Deskripsi / Tema -->
                        <div class="floating-group">
                            <textarea id="deskripsi" name="deskripsi" class="floating-textarea" rows="4" placeholder=" "><?php echo htmlspecialchars($edit_data['deskripsi'] ?? ''); ?></textarea>
                            <label for="deskripsi" class="floating-label">Tema Kegiatan / Deskripsi</label>
                        </div>

                        <!-- FLOATING INPUT: Ketuplat -->
                        <div class="floating-group">
                            <div class="tpl-picker" id="picker-ketuplat">
                                <i class="fas fa-search tpl-search-icon"></i>
                                <?php 
                                    $ketuplat_nama = '';
                                    if ($edit_data && $edit_data['ketuplat_id']) {
                                        foreach($list_anggota as $a) {
                                            if ($a['id'] == $edit_data['ketuplat_id']) {
                                                $ketuplat_nama = $a['nama'];
                                                break;
                                            }
                                        }
                                    }
                                ?>
                                <input type="text" id="input_ketuplat_display" class="floating-input tpl-floating-input" placeholder=" " value="<?php echo htmlspecialchars($ketuplat_nama); ?>" autocomplete="off" onfocus="showTplResults('ketuplat')" onkeyup="filterTpl('ketuplat')">
                                <label for="input_ketuplat_display" class="floating-label tpl-floating-label">Ketua Pelaksana (Opsional)</label>
                                <input type="hidden" id="input_ketuplat_id" name="ketuplat_id" value="<?php echo $edit_data['ketuplat_id'] ?? ''; ?>">
                                <div class="tpl-results" id="results-ketuplat">
                                    <div class="tpl-item" onclick='selectTplAnggota("", "")'>
                                        <div class="tpl-item-label" style="color:var(--text-muted);">-- Kosongkan (Batal Pilih) --</div>
                                    </div>
                                    <?php foreach($list_anggota as $anggota): ?>
                                    <div class="tpl-item" onclick='selectTplAnggota(<?php echo json_encode($anggota["id"]); ?>, <?php echo json_encode($anggota["nama"]); ?>)'>
                                        <div class="tpl-item-label"><?php echo htmlspecialchars($anggota['nama']); ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- KOLOM KANAN: Waktu & Tempat Pelaksanaan -->
                    <div>
                        <div class="wakpel-card">
                            <div class="wakpel-card-label"><i class="fas fa-calendar-alt"></i> Waktu & Tempat Pelaksanaan</div>
                            
                            <div class="form-group" style="margin-bottom:18px;">
                                <label class="form-label-custom" style="font-size:0.8rem; text-transform:uppercase; color:var(--text-muted);">Hari & Tanggal</label>
                                <div class="date-range-wrap">
                                    <input type="date" name="tanggal_mulai" id="tgl-mulai" class="form-control-custom" onchange="formatTanggalRange()" style="flex: 1; min-width: 140px;" required value="<?php echo htmlspecialchars($edit_data['tanggal_mulai'] ?? ''); ?>">
                                    <span style="color:var(--text-muted); font-size: 0.85rem;">selama</span>
                                    <div style="display:flex; gap:6px; align-items:center;">
                                        <select id="durasi-hari" onchange="handleDurasiChange()" class="form-control-custom" style="width:auto; cursor: pointer;">
                                            <option value="1">1 Hari</option>
                                            <option value="2">2 Hari</option>
                                            <option value="3">3 Hari</option>
                                            <option value="4">4 Hari</option>
                                            <option value="5">5 Hari</option>
                                            <option value="custom">Custom...</option>
                                        </select>
                                        <input type="number" id="custom-hari" min="1" value="1" oninput="formatTanggalRange()" class="form-control-custom" style="display:none; width: 75px;">
                                        <span id="label-hari" style="color:var(--text-muted); font-size: 0.85rem; display:none;">Hari</span>
                                    </div>
                                </div>
                                <input type="hidden" name="tanggal_selesai" id="out-tanggal-selesai" value="<?php echo htmlspecialchars($edit_data['tanggal_selesai'] ?? ''); ?>">
                                <div class="preview-bar" id="preview-tanggal">—belum dipilih—</div>
                            </div>

                            <div class="form-group" style="margin-bottom:20px;">
                                <label class="form-label-custom" style="font-size:0.8rem; text-transform:uppercase; color:var(--text-muted);">Waktu Pelaksanaan</label>
                                <input type="hidden" id="out-waktu" name="waktu_pelaksanaan" value="<?php echo htmlspecialchars($edit_data['waktu_pelaksanaan'] ?? ''); ?>">
                                <div class="drum-groups-wrap">
                                    <div class="drum-time-container-mobile">
                                        <div>
                                            <div class="drum-time-label">Mulai</div>
                                            <div class="drum-group">
                                                <div>
                                                    <button type="button" class="drum-arrow drum-arrow-up" onclick="drumHS.scrollBy(-1)">▲</button>
                                                    <div class="drum-col" id="drum-h-start"></div>
                                                    <button type="button" class="drum-arrow drum-arrow-down" onclick="drumHS.scrollBy(1)">▼</button>
                                                </div>
                                                <span class="drum-colon">:</span>
                                                <div>
                                                    <button type="button" class="drum-arrow drum-arrow-up" onclick="drumMS.scrollBy(-1)">▲</button>
                                                    <div class="drum-col" id="drum-m-start"></div>
                                                    <button type="button" class="drum-arrow drum-arrow-down" onclick="drumMS.scrollBy(1)">▼</button>
                                                </div>
                                            </div>
                                        </div>
                                        <div style="color:var(--text-muted); font-size:0.82rem; padding-top: 18px;">s.d</div>
                                        <div id="drum-end-wrap">
                                            <div class="drum-time-label">Selesai</div>
                                            <div class="drum-group">
                                                <div>
                                                    <button type="button" class="drum-arrow drum-arrow-up" onclick="drumHE.scrollBy(-1)">▲</button>
                                                    <div class="drum-col" id="drum-h-end"></div>
                                                    <button type="button" class="drum-arrow drum-arrow-down" onclick="drumHE.scrollBy(1)">▼</button>
                                                </div>
                                                <span class="drum-colon">:</span>
                                                <div>
                                                    <button type="button" class="drum-arrow drum-arrow-up" onclick="drumME.scrollBy(-1)">▲</button>
                                                    <div class="drum-col" id="drum-m-end"></div>
                                                    <button type="button" class="drum-arrow drum-arrow-down" onclick="drumME.scrollBy(1)">▼</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="toggle-switch-wrap-mobile">
                                        <div class="toggle-switch-wrap" id="toggle-selesai-wrap" onclick="doToggleSelesai()" style="background: rgba(255,255,255,0.04); padding: 8px 14px; border-radius: 10px; border: 1px solid var(--border-color); cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;">
                                            <div class="toggle-switch" id="ts-switch" style="position:relative; width:34px; height:18px; background:#121824; border-radius:10px; transition: .3s;"><div class="toggle-knob" style="position:absolute; top:2px; left:2px; width:14px; height:14px; background:#fff; border-radius:50%; transition:.3s;"></div></div>
                                            <span class="toggle-label" id="ts-label" style="font-size:0.75rem; color:var(--text-muted);">Tanpa waktu akhir</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="preview-bar" id="preview-waktu" style="display:none;"><?php echo htmlspecialchars($edit_data['waktu_pelaksanaan'] ?? ''); ?></div>
                            </div>

                            <!-- FLOATING INPUT: Tempat Pelaksanaan -->
                            <div class="floating-group" style="margin-bottom: 0;">
                                <div class="tpl-picker" id="picker-tempat">
                                    <i class="fas fa-search tpl-search-icon"></i>
                                    <input type="text" id="input_tempat" name="tempat_pelaksanaan" class="floating-input tpl-floating-input" placeholder=" " value="<?php echo htmlspecialchars($edit_data['tempat_pelaksanaan'] ?? ''); ?>" required autocomplete="off" onfocus="showTplResults('tempat')" onkeyup="filterTpl('tempat')">
                                    <label for="input_tempat" class="floating-label tpl-floating-label">Tempat Pelaksanaan <span style="color:#ef4444;">*</span></label>
                                    <div class="tpl-results" id="results-tempat">
                                        <?php foreach($list_tempat as $t): ?>
                                        <div class="tpl-item" onclick='selectTpl("input_tempat", <?php echo json_encode($t["label"]); ?>, "tempat")'>
                                            <div class="tpl-item-label"><?php echo htmlspecialchars($t['label']); ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                    $tujuan_list = (!empty($edit_data['tujuan'])) ? json_decode($edit_data['tujuan'], true) : [];
                    $manfaat_list = (!empty($edit_data['manfaat'])) ? json_decode($edit_data['manfaat'], true) : [];
                    if (empty($tujuan_list)) $tujuan_list = [''];
                    if (empty($manfaat_list)) $manfaat_list = [''];
                ?>
                <!-- List Tujuan & Manfaat (Full Width, Bawah Grid) -->
                <div style="margin-top: 20px; padding: 20px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px;">
                    <h4 style="margin: 0 0 15px 0; font-size: 1rem; color: #ffffff;"><i class="fas fa-list-ol"></i> Laporan (Tujuan & Manfaat)</h4>
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <!-- Tujuan List -->
                        <div class="form-group">
                            <label style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 8px;">Tujuan Kegiatan (List A. Tujuan)</label>
                            <div id="tujuan-container">
                                <?php foreach ($tujuan_list as $item): ?>
                                    <div class="dynamic-list-row">
                                        <input type="text" name="tujuan[]" value="<?php echo htmlspecialchars($item); ?>" placeholder="Ketik tujuan kegiatan..." required>
                                        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn-add-row" onclick="addTujuanRow()"><i class="fas fa-plus"></i> Tambah Tujuan</button>
                        </div>

                        <!-- Manfaat List -->
                        <div class="form-group">
                            <label style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; display: block; margin-bottom: 8px;">Manfaat Kegiatan (List B. Manfaat)</label>
                            <div id="manfaat-container">
                                <?php foreach ($manfaat_list as $item): ?>
                                    <div class="dynamic-list-row">
                                        <input type="text" name="manfaat[]" value="<?php echo htmlspecialchars($item); ?>" placeholder="Ketik manfaat kegiatan..." required>
                                        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="btn-add-row" onclick="addManfaatRow()"><i class="fas fa-plus"></i> Tambah Manfaat</button>
                        </div>
                    </div>
                </div>

                <div class="form-submit-bar" style="margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-color); display:flex; gap:12px; justify-content: flex-end;">
                    <button type="button" class="btn btn-outline" onclick="switchView('list')" style="padding: 10px 20px; border-radius: 10px; border-color: rgba(255,255,255,0.2); color: #ffffff;">Batal</button>
                    <button type="submit" class="btn" style="padding: 10px 24px; border-radius: 10px; background: #ffffff; color: #090d16; font-weight: 700; border: none;"><i class="fas fa-save"></i> <?php echo $edit_data ? 'Simpan Perubahan' : 'Buat Kegiatan'; ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CUSTOM DELETE MODAL -->
<div id="custom-delete-modal" class="custom-modal-overlay" style="display:none;">
    <div class="custom-modal-content">
        <div class="custom-modal-header">
            <i class="fas fa-exclamation-triangle" style="color: #ef4444; font-size: 1.6rem;"></i>
            <h3>Konfirmasi Hapus Kegiatan</h3>
        </div>
        <div class="custom-modal-body">
            Apakah Anda yakin ingin menghapus kegiatan <strong id="delete-kegiatan-nama" style="color:#ef4444;"></strong>? Semua data kepanitiaan terkait akan ikut terhapus permanen.
        </div>
        <div class="custom-modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeDeleteModal()" style="border-radius: 8px; border-color: rgba(255,255,255,0.2); color: #ffffff;">Batal</button>
            <a id="delete-confirm-btn" href="#" class="btn btn-danger" style="border-radius: 8px; background: #ef4444; color: #ffffff;"><i class="fas fa-trash-alt"></i> Hapus Sekarang</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../core/footer.php'; ?>

<script>
// ========== VIEW SWITCHER TOGGLE ==========
let currentView = "<?php echo $edit_data ? 'form' : 'list'; ?>";

function switchView(viewName) {
    currentView = viewName;
    const viewList = document.getElementById('view-list');
    const viewForm = document.getElementById('view-form');
    const btnText = document.getElementById('toggle-text');
    const btnIcon = document.getElementById('toggle-icon');

    if (viewName === 'form') {
        viewList.style.display = 'none';
        viewForm.style.display = 'block';
        btnText.textContent = 'Lihat Daftar Kegiatan';
        btnIcon.className = 'fas fa-list-ul';
    } else {
        viewForm.style.display = 'none';
        viewList.style.display = 'block';
        btnText.textContent = 'Buat Kegiatan Baru';
        btnIcon.className = 'fas fa-plus-circle';
        if (window.location.search.includes('edit=')) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }
}

function toggleView() {
    if (currentView === 'list') {
        switchView('form');
    } else {
        switchView('list');
    }
}

function togglePopover(popoverId, event) {
    if (event) event.stopPropagation();
    const pop = document.getElementById(popoverId);
    if (pop) {
        pop.classList.toggle('show');
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.popover-header-btn')) {
        document.querySelectorAll('.popover-box').forEach(el => el.classList.remove('show'));
    }
});

// ========== CUSTOM MODAL DELETE ==========
function openDeleteModal(url, namaKegiatan) {
    document.getElementById('delete-kegiatan-nama').textContent = namaKegiatan;
    document.getElementById('delete-confirm-btn').setAttribute('href', url);
    document.getElementById('custom-delete-modal').style.display = 'flex';
}

function closeDeleteModal() {
    document.getElementById('custom-delete-modal').style.display = 'none';
}

// ========== AJAX STATUS UPDATE ==========
function updateStatusAjax(selectEl, kegiatanId) {
    const newStatus = selectEl.value;
    const csrfToken = "<?php echo htmlspecialchars(csrfToken()); ?>";
    
    selectEl.style.opacity = '0.5';

    const formData = new FormData();
    formData.append('action', 'update_status');
    formData.append('kegiatan_id', kegiatanId);
    formData.append('status', newStatus);
    formData.append('csrf_token', csrfToken);
    formData.append('ajax', '1');

    fetch('master-kegiatan.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        selectEl.style.opacity = '1';
        if (data.success) {
            selectEl.className = 'status-select status-' + newStatus;
        } else {
            alert('Gagal memperbarui status: ' + (data.message || 'Kesalahan sistem'));
        }
    })
    .catch(err => {
        selectEl.style.opacity = '1';
        alert('Terjadi kesalahan jaringan.');
    });
}

// ========== Drum Picker Class ==========
class DrumPicker {
    constructor(elId, values, initVal, onChange) {
        this.el       = document.getElementById(elId);
        this.values   = values;
        this.idx      = Math.max(0, values.indexOf(initVal));
        this.onChange = onChange;
        this.ITEM     = 36;
        this._build();
        this._bind();
        this._render(false);
    }
    _build() {
        const hl = document.createElement('div');
        hl.className = 'drum-highlight';
        this.el.appendChild(hl);
        this.inner = document.createElement('div');
        this.inner.className = 'drum-inner';
        const pad = () => { const d=document.createElement('div'); d.className='drum-item'; return d; };
        [0,1,2].forEach(() => this.inner.appendChild(pad()));
        this.values.forEach((v, i) => {
            const d = document.createElement('div');
            d.className = 'drum-item'; d.dataset.i = i; d.textContent = v;
            this.inner.appendChild(d);
        });
        [0,1,2].forEach(() => this.inner.appendChild(pad()));
        this.el.appendChild(this.inner);
    }
    _render(animate = true) {
        const offset = -56 - this.idx * this.ITEM;
        this.inner.style.transition = animate ? 'transform 0.18s cubic-bezier(0.25,0.46,0.45,0.94)' : 'none';
        this.inner.style.transform  = `translateY(${offset}px)`;
        this.inner.querySelectorAll('[data-i]').forEach(el => {
            const diff = Math.abs(parseInt(el.dataset.i) - this.idx);
            const len = this.values.length;
            const wrapDiff = Math.min(diff, len - diff);
            el.className = 'drum-item' + (wrapDiff===0?' sel':wrapDiff===1?' near1':wrapDiff===2?' near2':'');
        });
        if (this.onChange) setTimeout(() => this.onChange(this.values[this.idx]), 0);
    }
    scrollBy(delta) {
        const oldIdx = this.idx;
        const len = this.values.length;
        this.idx = (this.idx + delta) % len;
        if (this.idx < 0) this.idx += len;
        this._render(Math.abs(this.idx - oldIdx) <= 1);
    }
    _bind() {
        this.el.addEventListener('wheel', e => { e.preventDefault(); this.scrollBy(e.deltaY > 0 ? 1 : -1); }, { passive: false });
        let ty = 0;
        this.el.addEventListener('touchstart', e => { ty = e.touches[0].clientY; }, { passive: true });
        this.el.addEventListener('touchmove', e => {
            const d = ty - e.touches[0].clientY;
            if (Math.abs(d) > 20) { this.scrollBy(d > 0 ? 1 : -1); ty = e.touches[0].clientY; }
        }, { passive: true });
    }
    val() { return this.values[this.idx]; }
}

const hours = Array.from({length:24}, (_,i) => String(i).padStart(2,'0'));
const mins  = Array.from({length:60}, (_,i) => String(i).padStart(2,'0'));

const existingWaktu = "<?php echo addslashes($edit_data['waktu_pelaksanaan'] ?? ''); ?>";
const wParts  = existingWaktu ? existingWaktu.split(' s.d ') : [];
const startT  = wParts[0] ? wParts[0].replace('.', ':').split(':') : ['08', '00'];
const isSelesai = (wParts.length > 1 && wParts[1] === 'Selesai');
const endT    = (!isSelesai && wParts[1]) ? wParts[1].replace('.', ':').split(':') : ['17', '00'];

let drumHS, drumMS, drumHE, drumME, _selesaiMode = isSelesai;

document.addEventListener('DOMContentLoaded', () => {
    drumHS = new DrumPicker('drum-h-start', hours, startT[0]||'08', updateWaktu);
    drumMS = new DrumPicker('drum-m-start', mins,  startT[1]||'00', updateWaktu);
    drumHE = new DrumPicker('drum-h-end',   hours, endT[0]||'17', updateWaktu);
    drumME = new DrumPicker('drum-m-end',   mins,  endT[1]||'00', updateWaktu);
    if (_selesaiMode) applyToggleSelesai(true);
    
    if(document.getElementById('tgl-mulai').value !== '') {
        <?php if (!empty($edit_data['tanggal_mulai']) && !empty($edit_data['tanggal_selesai'])): ?>
            const tM = "<?php echo $edit_data['tanggal_mulai']; ?>";
            const tS = "<?php echo $edit_data['tanggal_selesai']; ?>";
            try {
                const d1 = new Date(tM + 'T00:00:00');
                const d2 = new Date(tS + 'T00:00:00');
                let diffDays = Math.round((d2 - d1) / (1000 * 60 * 60 * 24)) + 1;
                if (diffDays >= 1 && diffDays <= 5) {
                    document.getElementById('durasi-hari').value = diffDays;
                } else if (diffDays > 5) {
                    document.getElementById('durasi-hari').value = 'custom';
                    document.getElementById('custom-hari').style.display = 'block';
                    document.getElementById('custom-hari').value = diffDays;
                    document.getElementById('label-hari').style.display = 'inline';
                }
            } catch(e) {}
        <?php endif; ?>
        formatTanggalRange();
    }
});

function updateWaktu() {
    if (!drumHS || !drumMS || !drumHE || !drumME) return;
    const start  = drumHS.val() + '.' + drumMS.val();
    const end    = _selesaiMode ? 'Selesai' : drumHE.val() + '.' + drumME.val();
    const result = start + ' s.d ' + end;
    document.getElementById('out-waktu').value = result;
}

function doToggleSelesai() {
    _selesaiMode = !_selesaiMode;
    applyToggleSelesai(_selesaiMode);
}

function applyToggleSelesai(on) {
    _selesaiMode = on;
    const sw   = document.getElementById('ts-switch');
    const lbl  = document.getElementById('ts-label');
    const end  = document.getElementById('drum-end-wrap');
    const knob = sw.querySelector('.toggle-knob');
    
    sw.style.background = on ? '#ffffff' : '#121824';
    knob.style.background = on ? '#000000' : '#ffffff';
    knob.style.transform = on ? 'translateX(16px)' : 'translateX(0)';
    lbl.textContent  = on ? 'Tanpa waktu akhir' : 'Dengan waktu akhir';
    end.style.opacity       = on ? '0.2' : '1';
    end.style.pointerEvents = on ? 'none' : '';
    updateWaktu();
}

// ========== Tanggal Range ==========
const HARI_ID  = ['Minggu','Senin','Selasa','Rabu','Kamis',"Jum'at",'Sabtu'];
const BULAN_ID = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

function handleDurasiChange() {
    const sel = document.getElementById('durasi-hari');
    const custom = document.getElementById('custom-hari');
    const label = document.getElementById('label-hari');
    
    if (sel.value === 'custom') {
        custom.style.display = 'block';
        label.style.display = 'inline';
    } else {
        custom.style.display = 'none';
        label.style.display = 'none';
    }
    formatTanggalRange();
}

function formatTanggalRange() {
    const mulai = document.getElementById('tgl-mulai').value;
    const sel = document.getElementById('durasi-hari');
    const custom = document.getElementById('custom-hari');
    
    if (!mulai) { 
        document.getElementById('preview-tanggal').innerText = '—belum dipilih—'; 
        document.getElementById('out-tanggal-selesai').value = '';
        return; 
    }

    let jmlHari = parseInt(sel.value);
    if (sel.value === 'custom') {
        jmlHari = parseInt(custom.value) || 1;
    }

    const d1 = new Date(mulai + 'T00:00:00');
    let result = '';
    
    const d2 = new Date(d1);
    d2.setDate(d1.getDate() + (jmlHari - 1));
    
    const dd = String(d2.getDate()).padStart(2, '0');
    const mm = String(d2.getMonth() + 1).padStart(2, '0');
    const yyyy = d2.getFullYear();
    document.getElementById('out-tanggal-selesai').value = `${yyyy}-${mm}-${dd}`;

    if (jmlHari <= 1) {
        result = HARI_ID[d1.getDay()] + ', ' + d1.getDate() + ' ' + BULAN_ID[d1.getMonth()] + ' ' + d1.getFullYear();
    } else {
        const hari = HARI_ID[d1.getDay()] === HARI_ID[d2.getDay()] ? HARI_ID[d1.getDay()] : HARI_ID[d1.getDay()] + '-' + HARI_ID[d2.getDay()];
        const bln1 = BULAN_ID[d1.getMonth()], bln2 = BULAN_ID[d2.getMonth()];
        const tgl  = bln1 === bln2 && d1.getFullYear() === d2.getFullYear()
            ? d1.getDate() + '-' + d2.getDate() + ' ' + bln1 + ' ' + d1.getFullYear()
            : d1.getDate() + ' ' + bln1 + ' ' + d1.getFullYear() + ' – ' + d2.getDate() + ' ' + bln2 + ' ' + d2.getFullYear();
        result = hari + ', ' + tgl;
    }

    document.getElementById('preview-tanggal').innerText = result;
}

// ========== TPL Autocomplete Picker ==========
function showTplResults(type) {
    document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
    const res = document.getElementById('results-' + type);
    if(res) {
        res.style.display = 'block';
    }
}
function filterTpl(type) {
    const input = document.querySelector('#picker-' + type + ' .floating-input');
    if(!input) return;
    const filter = input.value.toLowerCase();
    const results = document.getElementById('results-' + type);
    const items = results.getElementsByClassName('tpl-item');
    let hasMatch = false;
    for(let i=0;i<items.length;i++) {
        const label = items[i].querySelector('.tpl-item-label').innerText.toLowerCase();
        if(label.includes(filter)) {
            items[i].style.display = "";
            hasMatch = true;
        } else {
            items[i].style.display = "none";
        }
    }
    let emptyMsg = results.querySelector('.tpl-empty');
    if(!hasMatch) {
        if(!emptyMsg) {
            emptyMsg = document.createElement('div');
            emptyMsg.className = 'tpl-empty';
            emptyMsg.innerText = 'Tidak ada hasil...';
            results.appendChild(emptyMsg);
        }
    } else if(emptyMsg) {
        emptyMsg.remove();
    }
}
function selectTpl(targetId, value, type) {
    const el = document.getElementById(targetId);
    el.value = value;
    el.dispatchEvent(new Event('input'));
    document.getElementById('results-' + type).style.display = 'none';
}
function selectTplAnggota(id, name) {
    const el = document.getElementById('input_ketuplat_display');
    el.value = name;
    el.dispatchEvent(new Event('input'));
    document.getElementById('input_ketuplat_id').value = id;
    document.getElementById('results-ketuplat').style.display = 'none';
}
function selectKodeKegiatan(kode) {
    const el = document.getElementById('input_kode_kegiatan');
    el.value = kode;
    el.dispatchEvent(new Event('input'));
    document.getElementById('results-kode-kegiatan').style.display = 'none';
}

const prokerMap = <?php echo json_encode($proker_map ?? []); ?>;

function selectPelaksana(nama) {
    const el = document.getElementById('input_pelaksana');
    el.value = nama;
    el.dispatchEvent(new Event('input'));
    document.getElementById('results-pelaksana').style.display = 'none';
    
    const prokerInput = document.getElementById('input_proker');
    const prokerResults = document.getElementById('results-proker');
    prokerInput.value = '';
    prokerResults.innerHTML = '';
    
    let options = [];
    if (prokerMap[nama]) {
        options = prokerMap[nama];
    } else if (nama === 'Badan Eksekutif Mahasiswa (BEM)' || nama === 'Badan Pengurus Harian (BPH) BEM') {
        options = []; // Usually no specific proker mapped here, but can add if needed
    }
    
    if (options.length > 0) {
        options.forEach(p => {
            const div = document.createElement('div');
            div.className = 'tpl-item';
            div.onclick = function() { selectTpl('input_proker', p, 'proker'); };
            div.innerHTML = `<div class="tpl-item-label">${p}</div>`;
            prokerResults.appendChild(div);
        });
    } else {
        const emptyMsg = document.createElement('div');
        emptyMsg.className = 'tpl-empty';
        emptyMsg.innerText = 'Tidak ada program kerja terdaftar, silakan ketik manual.';
        prokerResults.appendChild(emptyMsg);
    }
} 
document.addEventListener('click', function(e) {
    if (!e.target.closest('.tpl-picker')) {
        document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
    }
});

// ========== Dynamic List Scripts ==========
function addTujuanRow() {
    const container = document.getElementById('tujuan-container');
    const div = document.createElement('div');
    div.className = 'dynamic-list-row';
    div.innerHTML = `
        <input type="text" name="tujuan[]" placeholder="Ketik tujuan kegiatan..." required>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(div);
}

function addManfaatRow() {
    const container = document.getElementById('manfaat-container');
    const div = document.createElement('div');
    div.className = 'dynamic-list-row';
    div.innerHTML = `
        <input type="text" name="manfaat[]" placeholder="Ketik manfaat kegiatan..." required>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fas fa-trash"></i></button>
    `;
    container.appendChild(div);
}

function removeRow(btn) {
    const row = btn.parentElement;
    const container = row.parentElement;
    if (container.children.length > 1) {
        row.remove();
    } else {
        row.querySelector('input').value = '';
    }
}
</script>
