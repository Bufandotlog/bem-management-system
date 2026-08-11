<?php
// admin/cetak-lampiran.php
require_once __DIR__ . '/../core/header.php';

$kegiatan_id = isset($_GET['kegiatan_id']) ? (int)$_GET['kegiatan_id'] : 0;
if (!$kegiatan_id) redirect('admin/core/dashboard.php', 'ID Kegiatan tidak valid.', 'error');
if (isset($_SESSION['admin_role']) && strtolower($_SESSION['admin_role']) === 'kominfo') {
    redirect('admin/core/dashboard.php', 'Kominfo tidak memiliki akses ke Logistik.', 'error');
}
requireEventAccess($kegiatan_id, ['ketuplat', 'sie_logistik']);
$kegiatan = dbFetchOne("SELECT * FROM kegiatan WHERE id = ?", [$kegiatan_id], "i");

$system_role = $_SESSION['admin_role'] ?? '';
$event_role = function_exists('getEventRole') ? getEventRole($_SESSION['admin_id'] ?? 0, $kegiatan_id) : '';
$is_logistik_user = (strtolower($system_role) === 'logistik' || strtolower((string)$event_role) === 'sie_logistik');

$periode_id = getUserPeriode();
$mode = $_GET['mode'] ?? 'pinjam';

// Fetch daftar kegiatan berstatus 'persiapan' untuk switcher dropdown
$persiapan_kegiatan_list = dbFetchAll(
    "SELECT id, nama_kegiatan, tanggal_mulai, tanggal_selesai FROM kegiatan WHERE periode_id = ? AND status = 'persiapan' ORDER BY nama_kegiatan ASC",
    [$periode_id],
    "i"
);
if (empty($persiapan_kegiatan_list)) {
    $persiapan_kegiatan_list = dbFetchAll(
        "SELECT id, nama_kegiatan, tanggal_mulai, tanggal_selesai FROM kegiatan WHERE periode_id = ? ORDER BY nama_kegiatan ASC",
        [$periode_id],
        "i"
    );
}

// --- INITIALIZE EDIT MODE / MONITORING EVENT ---
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_data = null;
$pre_filled_qty = [];

if ($edit_id > 0) {
    $edit_data = dbFetchOne("SELECT * FROM lampiran_pinjam WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
} else if ($kegiatan_id > 0 && !empty($kegiatan['nama_kegiatan'])) {
    // 1. Cari data lampiran_pinjam yang terhubung lewat arsip_surat staging kegiatan ini
    $surat_stg = dbFetchOne("SELECT konten_surat FROM arsip_surat WHERE kegiatan_id = ? AND status_arsip = 'staging' AND (perihal LIKE '%Peminjaman%' OR perihal LIKE '%Sarpras%') ORDER BY id DESC LIMIT 1", [$kegiatan_id]);
    if ($surat_stg && !empty($surat_stg['konten_surat'])) {
        $stg_k = json_decode((string)$surat_stg['konten_surat'], true);
        if (!empty($stg_k['lampiran_internal_ids'][0])) {
            $l_id = (int)$stg_k['lampiran_internal_ids'][0];
            $edit_data = dbFetchOne("SELECT * FROM lampiran_pinjam WHERE id = ? AND periode_id = ?", [$l_id, $periode_id], "ii");
        }
    }
    // 2. Fallback: Cari lampiran_pinjam berdasarkan nama_acara
    if (!$edit_data) {
        $edit_data = dbFetchOne("SELECT * FROM lampiran_pinjam WHERE (nama_acara = ? OR nama_acara LIKE ?) AND periode_id = ? ORDER BY id DESC LIMIT 1", [
            $kegiatan['nama_kegiatan'],
            '%' . $kegiatan['nama_kegiatan'] . '%',
            $periode_id
        ]);
    }
    if ($edit_data) {
        $edit_id = (int)$edit_data['id'];
    }
}

if ($edit_data) {
    $barang_data = json_decode((string)$edit_data['barang_json'], true) ?: [];
    foreach ($barang_data as $b) {
        $pre_filled_qty[$b['id']] = $b['qty'];
    }
}

// Pre-fill Tanggal Pelaksanaan default dari Kegiatan jika bukan edit mode
$default_tgl_mulai = '';
$default_jml_hari = 1;
if (!$edit_data && !empty($kegiatan['tanggal_mulai'])) {
    $default_tgl_mulai = date('Y-m-d', strtotime($kegiatan['tanggal_mulai']));
    if (!empty($kegiatan['tanggal_selesai'])) {
        $diff = (strtotime($kegiatan['tanggal_selesai']) - strtotime($kegiatan['tanggal_mulai'])) / 86400;
        $default_jml_hari = max(1, (int)$diff + 1);
    }
}

$success_msg = '';
$error_msg = '';

// --- POST HANDLER: MASTER BARANG ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mb_action'])) {
    if (!csrfVerify()) {
        $error_msg = "Token CSRF tidak valid atau telah kedaluwarsa.";
    } else {
        $mb_action = $_POST['mb_action'] ?? '';
        if ($mb_action === 'add') {
            $nama = sanitizeText($_POST['nama_barang'] ?? '');
            $satuan = sanitizeText($_POST['satuan'] ?? 'pcs');
            if (empty($nama)) {
                $error_msg = 'Nama barang tidak boleh kosong.';
            } else {
                try {
                    dbQuery("INSERT INTO barang_master (nama_barang, satuan) VALUES (?, ?)", [$nama, $satuan], "ss");
                    $success_msg = 'Barang berhasil ditambahkan.';
                    auditLog('ADD_BARANG', 'barang_master', null, 'Menambah barang: ' . $nama);
                } catch (Exception $e) {
                    $error_msg = 'Gagal menambah barang: ' . $e->getMessage();
                }
            }
        } elseif ($mb_action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $nama = sanitizeText($_POST['nama_barang'] ?? '');
            $satuan = sanitizeText($_POST['satuan'] ?? 'pcs');
            if (empty($nama) || $id <= 0) {
                $error_msg = 'Data tidak valid.';
            } else {
                try {
                    dbUpdate("UPDATE barang_master SET nama_barang = ?, satuan = ? WHERE id = ?", [$nama, $satuan, $id], "ssi");
                    $success_msg = 'Barang berhasil diperbarui.';
                    auditLog('EDIT_BARANG', 'barang_master', $id, 'Update barang ke: ' . $nama);
                } catch (Exception $e) {
                    $error_msg = 'Gagal memperbarui barang: ' . $e->getMessage();
                }
            }
        } elseif ($mb_action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error_msg = 'ID tidak valid.';
            } else {
                try {
                    dbQuery("DELETE FROM barang_master WHERE id = ?", [$id], "i");
                    $success_msg = 'Barang berhasil dihapus.';
                    auditLog('DELETE_BARANG', 'barang_master', $id, 'Menghapus barang ID: ' . $id);
                } catch (Exception $e) {
                    $error_msg = 'Gagal menghapus barang: ' . $e->getMessage();
                }
            }
        }
    }
}

// --- POST HANDLER: MASTER TEMPAT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mt_action'])) {
    if (!csrfVerify()) {
        $error_msg = "Token CSRF tidak valid atau telah kedaluwarsa.";
    } else {
        $mt_action = $_POST['mt_action'] ?? '';
        if ($mt_action === 'add') {
            $nama = sanitizeText($_POST['nama_tempat'] ?? '');
            if (empty($nama)) {
                $error_msg = 'Nama tempat tidak boleh kosong.';
            } else {
                try {
                    dbQuery("INSERT INTO tempat_master (nama_tempat) VALUES (?)", [$nama], "s");
                    $success_msg = 'Tempat berhasil ditambahkan.';
                    auditLog('ADD_TEMPAT', 'tempat_master', null, 'Menambah tempat: ' . $nama);
                } catch (Exception $e) {
                    $error_msg = 'Gagal menambah tempat: ' . $e->getMessage();
                }
            }
        } elseif ($mt_action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $nama = sanitizeText($_POST['nama_tempat'] ?? '');
            if (empty($nama) || $id <= 0) {
                $error_msg = 'Data tidak valid.';
            } else {
                try {
                    dbUpdate("UPDATE tempat_master SET nama_tempat = ? WHERE id = ?", [$nama, $id], "si");
                    $success_msg = 'Tempat berhasil diperbarui.';
                    auditLog('EDIT_TEMPAT', 'tempat_master', $id, 'Update tempat ke: ' . $nama);
                } catch (Exception $e) {
                    $error_msg = 'Gagal memperbarui tempat: ' . $e->getMessage();
                }
            }
        } elseif ($mt_action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $error_msg = 'ID tidak valid.';
            } else {
                try {
                    dbQuery("DELETE FROM tempat_master WHERE id = ?", [$id], "i");
                    $success_msg = 'Tempat berhasil dihapus.';
                    auditLog('DELETE_TEMPAT', 'tempat_master', $id, 'Menghapus tempat ID: ' . $id);
                } catch (Exception $e) {
                    $error_msg = 'Gagal menghapus tempat: ' . $e->getMessage();
                }
            }
        } elseif ($mt_action === 'copy') {
            $nama = sanitizeText($_POST['nama_tempat'] ?? '');
            if (empty($nama)) {
                $error_msg = 'Data yang disalin tidak valid.';
            } else {
                try {
                    $exists = dbFetchOne("SELECT id FROM tempat_master WHERE nama_tempat = ?", [$nama], "s");
                    if ($exists) {
                        $error_msg = 'Tempat "' . $nama . '" sudah ada di daftar Master Inventaris Tempat.';
                    } else {
                        dbQuery("INSERT INTO tempat_master (nama_tempat) VALUES (?)", [$nama], "s");
                        $success_msg = 'Berhasil menyalin "' . $nama . '" ke Master Inventaris Tempat.';
                        auditLog('COPY_TEMPAT', 'tempat_master', null, 'Menyalin Tempat: ' . $nama);
                    }
                } catch (Exception $e) {
                    $error_msg = 'Gagal menyalin tempat: ' . $e->getMessage();
                }
            }
        } elseif ($mt_action === 'copy_all') {
            try {
                $source_items = dbFetchAll("SELECT nama_tempat FROM rundown_tempat");
                $inserted = 0;
                foreach ($source_items as $src) {
                    $nama = $src['nama_tempat'];
                    $exists = dbFetchOne("SELECT id FROM tempat_master WHERE nama_tempat = ?", [$nama], "s");
                    if (!$exists) {
                        dbQuery("INSERT INTO tempat_master (nama_tempat) VALUES (?)", [$nama], "s");
                        $inserted++;
                    }
                }
                $success_msg = "Berhasil menyalin $inserted data ke Master Inventaris Tempat.";
                auditLog('COPY_ALL_TEMPAT', 'tempat_master', null, "Menyalin $inserted tempat dari Kegiatan");
            } catch (Exception $e) {
                $error_msg = 'Gagal menyalin semua tempat: ' . $e->getMessage();
            }
        }
    }
}

// --- POST HANDLER: SIMPAN DATA PEMINJAMAN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    if (!csrfVerify()) {
        $error_msg = "Token CSRF tidak valid atau telah kedaluwarsa.";
    } else {
        $acara   = $kegiatan['nama_kegiatan'];
        $tanggal = trim($_POST['tanggal'] ?? '');
        $tahun   = trim($_POST['tahun'] ?? '');
        $qtys    = $_POST['qty'] ?? [];
        $target_edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
        
        // Filter barang yang jumlahnya > 0
        $items_to_save = [];
        foreach ($qtys as $item_id => $qty) {
            if ($qty > 0) {
                $items_to_save[] = [
                    'id' => $item_id,
                    'nama' => $_POST['item_name'][$item_id] ?? 'Barang',
                    'qty' => (int)$qty
                ];
            }
        }
        
        if (empty($acara) || empty($tanggal)) {
            $error_msg = "Nama acara dan tanggal wajib diisi.";
        } elseif (empty($items_to_save)) {
            $error_msg = "Minimal pilih 1 barang untuk disimpan.";
        } else {
            $barang_json = json_encode($items_to_save);
            $auto_create  = isset($_POST['auto_create_surat']) ? (int)$_POST['auto_create_surat'] : 1;
            $admin_id     = $_SESSION['admin_id'] ?? null;

            $saved_lampiran_id = saveLogistikPeminjamanAndDraftLetter(
                $kegiatan_id, $periode_id, $acara, $tanggal, $tahun, $barang_json, $target_edit_id, $auto_create, $admin_id
            );

            if ($saved_lampiran_id) {
                $success_msg = ($target_edit_id > 0) ? "Data peminjaman berhasil diperbarui." : "Data peminjaman berhasil disimpan ke arsip.";
                if ($auto_create) {
                    $success_msg .= " 📩 Draft Surat Peminjaman Sarpras otomatis diperbarui/dibuat & masuk ke Staging Index Surat!";
                }
                if ($target_edit_id > 0) {
                    $edit_id = $target_edit_id;
                    $edit_data = dbFetchOne("SELECT * FROM lampiran_pinjam WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
                    $pre_filled_qty = [];
                    if ($edit_data) {
                        $barang_data = json_decode($edit_data['barang_json'], true) ?: [];
                        foreach ($barang_data as $b) {
                            $pre_filled_qty[$b['id']] = $b['qty'];
                        }
                    }
                }
            } else {
                $error_msg = "Gagal menyimpan data peminjaman ke database.";
            }
        }
    }
}

// Ambil data template kegiatan
$templates = dbFetchAll("SELECT * FROM surat_templates WHERE periode_id = ? AND jenis = 'kegiatan'", [$periode_id], "i");
$list_kegiatan = $templates;

$barang = dbFetchAll("SELECT id, nama_barang as nama, satuan, 'barang' as type FROM barang_master ORDER BY nama_barang ASC");
$tempat = dbFetchAll("SELECT id, nama_tempat as nama, '' as satuan, 'tempat' as type FROM tempat_master ORDER BY nama_tempat ASC");

// UID akan diberikan langsung di bagian rendering UI
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --card-bg: rgba(15, 18, 23, 0.95);
    --input-bg: #0a0c10;
    --border-color: #2a3545;
    --accent-color: #4A90E2;
}

.cetak-lampiran-container {
    max-width: 1000px;
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
    margin-bottom: 30px;
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
    margin-bottom: 30px;
}

@media (min-width: 768px) {
    .info-grid { grid-template-columns: 1.5fr 1fr; }
}

.form-group label {
    display: block;
    font-size: 0.75rem;
    color: #777;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 8px;
    font-weight: 700;
}

.form-group input, .form-group select {
    width: 100%;
    background: #080808;
    border: 1px solid var(--border-color);
    padding: 14px 18px;
    border-radius: 12px;
    color: #fff;
    font-size: 1rem;
    transition: all 0.3s;
}

.form-group input:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 15px rgba(74, 144, 226, 0.2);
    outline: none;
}

/* Template Picker */
.tpl-picker { position: relative; }
.tpl-search-input { padding-left: 44px !important; }
.tpl-search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--accent-color); font-size: 1rem; pointer-events: none; z-index: 5; }
.tpl-results {
    position: absolute; top: calc(100% + 8px); left: 0; right: 0;
    background: #121822; border: 1px solid var(--border-color); border-radius: 16px;
    max-height: 250px; overflow-y: auto; z-index: 1000; display: none;
    box-shadow: 0 10px 40px rgba(0,0,0,0.5);
}
.tpl-item { padding: 12px 18px; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.05); }
.tpl-item:hover { background: rgba(74, 144, 226, 0.1); }
.tpl-item-label { font-weight: 700; color: #8BB9F0; font-size: 0.9rem; }

/* Date Range */
.date-range-wrap { display: flex; gap: 10px; align-items: center; }
.date-range-wrap input { flex: 1; }
.preview-bar { background: rgba(74,144,226,0.08); border-radius: 12px; padding: 12px 16px; font-size: 0.85rem; margin-top: 10px; color: #8BB9F0; border-left: 4px solid var(--accent-color); }

.items-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 8px;
}

.items-table th {
    text-align: left;
    padding: 12px 15px;
    color: #555;
    font-size: 0.8rem;
    text-transform: uppercase;
}

.items-table td {
    padding: 15px;
    background: rgba(255,255,255,0.02);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
}

.items-table td:first-child {
    border-left: 1px solid var(--border-color);
    border-radius: 12px 0 0 12px;
    font-weight: 600;
    color: #eee;
}

.items-table td:last-child {
    border-right: 1px solid var(--border-color);
    border-radius: 0 12px 12px 0;
    width: 150px;
}

.qty-wrapper {
    display: inline-flex;
    align-items: center;
    background: rgba(0,0,0,0.3);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    overflow: hidden;
    height: 36px;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
}
.qty-btn {
    background: rgba(255,255,255,0.03);
    border: none;
    color: var(--accent-color);
    width: 32px;
    height: 100%;
    font-size: 1.1rem;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qty-btn:hover {
    background: rgba(74,144,226,0.2);
    color: #fff;
}
.barang-qty {
    width: 40px !important;
    text-align: center;
    padding: 0 !important;
    background: transparent !important;
    border: none !important;
    color: #fff;
    font-size: 1rem;
    font-weight: 600;
    -moz-appearance: textfield;
}
.barang-qty::-webkit-outer-spin-button,
.barang-qty::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.barang-qty:focus {
    outline: none;
    box-shadow: none !important;
}

.notice-bar {
    background: rgba(255, 193, 7, 0.05);
    border: 1px dashed rgba(255, 193, 7, 0.3);
    color: #ffc107;
    padding: 12px 20px;
    border-radius: 12px;
    font-size: 0.85rem;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.actions-bar {
    position: sticky;
    bottom: 20px;
    background: rgba(15, 18, 23, 0.9);
    backdrop-filter: blur(10px);
    padding: 20px 30px;
    border-radius: 20px;
    border: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: 0 -10px 30px rgba(0,0,0,0.3);
    margin-top: 40px;
    z-index: 100;
}

.btn-reset {
    background: rgba(231, 76, 60, 0.1);
    border: 1px solid rgba(231, 76, 60, 0.3);
    color: #e74c3c;
    padding: 12px 24px;
    border-radius: 12px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s;
}

.btn-reset:hover {
    background: rgba(231, 76, 60, 0.2);
    transform: translateY(-2px);
}

.btn-print {
    background: var(--primary-gradient);
    border: none;
    color: #fff;
    padding: 14px 40px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1.1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.3s;
    box-shadow: 0 10px 20px rgba(79, 172, 254, 0.3);
}

.btn-print:hover {
    transform: translateY(-4px) scale(1.02);
    box-shadow: 0 15px 30px rgba(79, 172, 254, 0.4);
}

.tab-container {
    display: flex;
    gap: 15px;
    margin-bottom: 25px;
}

.btn-tab {
    flex: 1;
    text-align: center;
    padding: 15px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    color: #888;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
}

.btn-tab:hover {
    background: rgba(255,255,255,0.1);
    color: #fff;
}

.btn-tab.active {
    background: rgba(74, 144, 226, 0.15);
    border-color: var(--accent-color);
    color: #fff;
    box-shadow: inset 0 0 10px rgba(74, 144, 226, 0.1);
}

.table-beli {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}
.table-beli th, .table-beli td {
    padding: 12px;
    border: 1px solid var(--border-color);
    text-align: left;
}
.table-beli th {
    background: rgba(255,255,255,0.05);
    color: #aaa;
}
.table-beli input {
    width: 100%;
    background: transparent;
    border: none;
    color: #fff;
    outline: none;
    font-size: 1rem;
}
</style>

<div class="cetak-lampiran-container">
    <div class="tab-container">
        <a href="workspace-logistik.php?kegiatan_id=<?php echo $kegiatan_id; ?>&mode=pinjam" class="btn-tab <?php echo $mode === 'pinjam' ? 'active' : ''; ?>">
            <i class="fas fa-boxes"></i> Peminjaman Barang
        </a>
        <a href="workspace-logistik.php?kegiatan_id=<?php echo $kegiatan_id; ?>&mode=beli" class="btn-tab <?php echo $mode === 'beli' ? 'active' : ''; ?>">
            <i class="fas fa-shopping-cart"></i> Pembelian Barang
        </a>
        <a href="workspace-logistik.php?kegiatan_id=<?php echo $kegiatan_id; ?>&mode=master_barang" class="btn-tab <?php echo $mode === 'master_barang' ? 'active' : ''; ?>">
            <i class="fas fa-cubes"></i> Master Barang
        </a>
        <a href="workspace-logistik.php?kegiatan_id=<?php echo $kegiatan_id; ?>&mode=master_tempat" class="btn-tab <?php echo $mode === 'master_tempat' ? 'active' : ''; ?>">
            <i class="fas fa-map-marker-alt"></i> Master Tempat
        </a>
    </div>

    <?php if ($mode === 'pinjam'): ?>
    <form action="" method="POST" target="_self" id="printForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="kegiatan_id" value="<?php echo $kegiatan_id; ?>">
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list-check fa-2x"></i>
                <h2><?php echo $edit_data ? 'Edit Data Lampiran' : 'Workspace: Logistik & Peminjaman (Sie Logistik)'; ?></h2>
            </div>

            <?php if ($success_msg): ?>
                <div class="preview-bar" style="background: rgba(39, 174, 96, 0.1); color: #2ecc71; border: 1px solid rgba(39, 174, 96, 0.3); margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="preview-bar" style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>
            <?php if ($edit_data && !$success_msg && !$error_msg && !$is_logistik_user): ?>
                <div class="preview-bar" style="background: rgba(74, 144, 226, 0.1); color: #8BB9F0; border: 1px solid rgba(74, 144, 226, 0.3); margin-bottom: 20px;">
                    <i class="fas fa-chart-line"></i> <strong>Monitoring Logistik Kegiatan:</strong> Data peminjaman barang & tempat aktif untuk kegiatan ini otomatis dimuat dari arsip logistik (#<?php echo $edit_data['id']; ?>).
                </div>
            <?php endif; ?>
            
            <div class="info-grid">
                <!-- Kalender Form -->
                <div class="form-group">
                    <label>Tanggal Pelaksanaan</label>
                    <div class="date-range-wrap">
                        <input type="date" id="tgl-mulai" onchange="formatTanggalRange()" value="<?php echo htmlspecialchars($default_tgl_mulai); ?>" required>
                        <span style="color:#777; font-size:0.9rem; margin:0 5px;">Selama</span>
                        <div class="qty-wrapper" style="flex: none; width: auto;">
                            <button type="button" class="qty-btn" onclick="const i=document.getElementById('jml-hari'); if(i.value>1) {i.stepDown(); formatTanggalRange();} ">-</button>
                            <input type="number" id="jml-hari" class="barang-qty" onchange="formatTanggalRange()" onkeyup="formatTanggalRange()" min="1" value="<?php echo $default_jml_hari; ?>" style="width: 40px !important;">
                            <button type="button" class="qty-btn" onclick="const i=document.getElementById('jml-hari'); i.stepUp(); formatTanggalRange();">+</button>
                        </div>
                        <span style="color:#777; font-size:0.9rem; margin-left: 5px;">Hari</span>
                    </div>
                    <div class="preview-bar" id="preview-tanggal"><?php echo $edit_data ? htmlspecialchars($edit_data['tanggal_kegiatan']) : 'Pilih tanggal di atas...'; ?></div>
                    <!-- Input hidden untuk dikirim ke PHP -->
                    <input type="hidden" name="tanggal" id="out-tanggal" value="<?php echo $edit_data ? htmlspecialchars($edit_data['tanggal_kegiatan']) : ''; ?>" required>
                    <input type="hidden" name="tahun" id="out-tahun" value="<?php echo $edit_data ? htmlspecialchars($edit_data['tahun']) : date('Y'); ?>">
                </div>

                <!-- Template Picker Acara -->
                <div class="form-group">
                    <label>
                        Nama Acara / Kegiatan
                        <?php if(count($persiapan_kegiatan_list) > 1): ?>
                            <span style="font-size:0.75rem; color:var(--accent-color); float:right; font-weight:normal; cursor:pointer;" onclick="toggleAcaraDropdown()">
                                <i class="fas fa-caret-down"></i> Ganti Kegiatan (<?php echo count($persiapan_kegiatan_list); ?> Persiapan)
                            </span>
                        <?php endif; ?>
                    </label>
                    <div class="tpl-picker" id="picker-acara" style="position: relative;">
                        <input type="text" name="acara" id="input_acara" class="form-control" value="<?php echo htmlspecialchars($kegiatan['nama_kegiatan']); ?>" readonly style="background: rgba(255,255,255,0.05); color: #fff; cursor: pointer; padding-right: 35px;" onclick="toggleAcaraDropdown()">
                        <i class="fas fa-chevron-down" onclick="toggleAcaraDropdown()" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: var(--accent-color); cursor: pointer;"></i>
                        
                        <?php if (!empty($persiapan_kegiatan_list)): ?>
                        <div class="tpl-results" id="results-acara" style="display:none; position: absolute; top: 100%; left: 0; right: 0; z-index: 100; background: #0f1217; border: 1px solid var(--border-color); border-radius: 12px; max-height: 250px; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-top: 5px;">
                            <div style="padding: 10px 14px; font-size: 0.75rem; color: #888; border-bottom: 1px solid var(--border-color); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">
                                <i class="fas fa-clock" style="color: #f1c40f;"></i> Program Kegiatan (Status: Persiapan)
                            </div>
                            <?php foreach($persiapan_kegiatan_list as $pk): ?>
                                <div class="tpl-item" 
                                     style="padding: 12px 14px; cursor: pointer; border-bottom: 1px solid rgba(255,255,255,0.03); transition: 0.2s; <?php echo $pk['id'] == $kegiatan_id ? 'background: rgba(74, 144, 226, 0.15); border-left: 4px solid var(--accent-color);' : ''; ?>"
                                     onmouseover="if(<?php echo $pk['id']; ?> != <?php echo $kegiatan_id; ?>) this.style.background='rgba(255,255,255,0.05)'"
                                     onmouseout="if(<?php echo $pk['id']; ?> != <?php echo $kegiatan_id; ?>) this.style.background='transparent'"
                                     onclick="switchKegiatan(<?php echo $pk['id']; ?>)">
                                    <div style="display:flex; justify-content:space-between; align-items:center;">
                                        <strong style="color: <?php echo $pk['id'] == $kegiatan_id ? 'var(--accent-color)' : '#eee'; ?>; font-size: 0.95rem;">
                                            <?php echo htmlspecialchars($pk['nama_kegiatan']); ?>
                                        </strong>
                                        <?php if($pk['id'] == $kegiatan_id): ?>
                                            <span style="font-size:0.7rem; background:var(--accent-color); color:#fff; padding:2px 8px; border-radius:10px; font-weight:bold;">Aktif</span>
                                        <?php else: ?>
                                            <span style="font-size:0.75rem; color:#777;"><i class="fas fa-sign-in-alt"></i> Pilih</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($pk['tanggal_mulai']): ?>
                                        <div style="font-size: 0.75rem; color: #777; margin-top: 3px;">
                                            <i class="fas fa-calendar-day"></i> <?php echo date('d M Y', strtotime($pk['tanggal_mulai'])); ?>
                                            <?php if ($pk['tanggal_selesai']) echo ' - ' . date('d M Y', strtotime($pk['tanggal_selesai'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- CARD PILIH BARANG -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <i class="fas fa-boxes"></i>
                <h2>Pilih Barang & Jumlah</h2>
            </div>

            <div class="notice-bar">
                <i class="fas fa-info-circle"></i>
                <span>Barang dengan jumlah <strong>0</strong> tidak akan masuk dalam daftar cetak.</span>
            </div>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Nama Barang</th>
                        <th style="text-align:center;">Jumlah Pinjam</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($barang)): ?>
                        <tr>
                            <td colspan="2" style="text-align:center; padding: 40px; color:#555;">
                                Master barang kosong. Silakan isi di <a href="<?php echo baseUrl('admin/logistik/master-barang.php'); ?>" style="color:var(--accent-color);">Master Barang</a>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($barang as $item): ?>
                            <?php $uid = 'b_' . $item['id']; ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($item['nama']); ?>
                                </td>
                                <td>
                                    <div style="display:flex; justify-content:flex-end; align-items:center; gap:12px;">
                                        <!-- Class qty-input digunakan oleh JS untuk validasi 'total > 0' -->
                                        <div class="qty-wrapper">
                                            <button type="button" class="qty-btn" onclick="const i=this.nextElementSibling; if(i.value>0) i.stepDown();">-</button>
                                            <input type="number" name="qty[<?php echo $uid; ?>]" class="qty-input barang-qty" min="0" value="<?php echo (int)($pre_filled_qty[$uid] ?? 0); ?>" onfocus="this.select()">
                                            <button type="button" class="qty-btn" onclick="this.previousElementSibling.stepUp();">+</button>
                                        </div>
                                        <span style="font-size: 0.85rem; color: #aaa; min-width: 40px; text-align: left;"><?php echo htmlspecialchars($item['satuan'] ?? 'pcs'); ?></span>
                                    </div>
                                    <input type="hidden" name="item_name[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars($item['nama'] . ' (' . ($item['satuan'] ?? 'pcs') . ')'); ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- CARD PILIH TEMPAT -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-map-marker-alt"></i>
                <h2>Pilih Tempat Kegiatan</h2>
            </div>
            
            <div class="notice-bar">
                <i class="fas fa-info-circle"></i>
                <span>Aktifkan toggle untuk memilih tempat yang akan dipinjam.</span>
            </div>

            <?php if (empty($tempat)): ?>
                <div style="text-align:center; padding: 20px; color:#555; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid var(--border-color);">
                    Belum ada data tempat. Silakan isi di <a href="<?php echo baseUrl('admin/logistik/master-tempat.php'); ?>" style="color:var(--accent-color);">Master Tempat</a>.
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($tempat as $item): ?>
                        <?php 
                            $uid = 't_' . $item['id']; 
                            $is_checked = (isset($pre_filled_qty[$uid]) && $pre_filled_qty[$uid] > 0) ? 'checked' : '';
                        ?>
                        <div class="switch-container" onclick="const cb = this.querySelector('input[type=checkbox]'); if(event.target.tagName !== 'INPUT' && !event.target.classList.contains('slider')) { cb.checked = !cb.checked; }">
                            <div class="switch-label">
                                <i class="fas fa-building"></i>
                                <span><?php echo htmlspecialchars($item['nama']); ?></span>
                                <span style="font-size: 0.7rem; color: #888; background: #222; padding: 2px 6px; border-radius: 4px; margin-left: 8px;">Tempat</span>
                            </div>
                            <label class="switch" style="margin:0;" onclick="event.stopPropagation();">
                                <!-- Kita gunakan checkbox, dan JS akan map nilainya ke 1 atau 0 -->
                                <input type="checkbox" name="qty[<?php echo $uid; ?>]" value="1" class="qty-input tempat-toggle" <?php echo $is_checked; ?>>
                                <span class="slider"></span>
                            </label>
                            <input type="hidden" name="item_name[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars($item['nama']); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="actions-bar" style="flex-wrap: wrap; gap: 15px;">
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <?php if ($edit_data): ?>
                    <a href="workspace-logistik.php?kegiatan_id=<?php echo $kegiatan_id; ?>" class="btn-reset" style="text-decoration:none; display:flex; align-items:center; gap:8px;">
                        <i class="fas fa-plus"></i> Buat Baru
                    </a>
                <?php endif; ?>
                <button type="button" class="btn-reset" onclick="resetAll()">
                    <i class="fas fa-undo"></i> Reset Semua Jumlah
                </button>
                <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; color: #8BB9F0; font-size: 0.88rem; font-weight: 600; background: rgba(74, 144, 226, 0.1); padding: 10px 16px; border-radius: 12px; border: 1px solid rgba(74, 144, 226, 0.3); margin: 0;">
                    <input type="checkbox" name="auto_create_surat" value="1" checked style="width: 16px; height: 16px; accent-color: #4A90E2; cursor: pointer;">
                    <span><i class="fas fa-paper-plane" style="color: #4A90E2;"></i> Auto-Draft Surat ke Staging Index</span>
                </label>
            </div>
            <div style="display: flex; gap: 12px;">
                <button type="button" class="btn-print" style="background: var(--card-bg); border: 1px solid var(--border-color); color: #eee; box-shadow: none;" onclick="submitAction('save')">
                    <i class="fas fa-save"></i> <?php echo $edit_data ? 'Update Arsip' : 'Simpan ke Arsip'; ?>
                </button>
                <button type="button" class="btn-print" onclick="submitAction('print')">
                    <i class="fas fa-file-pdf"></i> Cetak Lampiran PDF
                </button>
            </div>
        </div>
        
        <input type="hidden" name="action" id="form-action" value="print">
        <?php if ($edit_data): ?>
            <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
        <?php endif; ?>
    </form>
</div>

<script>
function submitAction(type) {
    const form = document.getElementById('printForm');
    const actionInput = document.getElementById('form-action');
    
    // Validasi barang/tempat
    const inputs = document.querySelectorAll('.qty-input');
    let total = 0;
    inputs.forEach(input => {
        if(input.type === 'checkbox') {
            total += input.checked ? 1 : 0;
        } else {
            total += parseInt(input.value || 0);
        }
    });
    
    if (total <= 0) {
        alert('Minimal pilih 1 tempat atau 1 barang.');
        return;
    }

    // Ubah status checkbox agar hanya mengirim value jika diceklis,
    // dan pastikan input number mengirim nilai default form (browser native)
    
    actionInput.value = type;
    
    if (type === 'print') {
        form.target = '_blank';
        form.action = '<?php echo baseUrl("admin/logistik/cetak-lampiran-pdf.php"); ?>';
        form.submit();
    } else {
        form.target = '_self';
        form.action = '';
        form.submit();
    }
}
// ========== Template Picker & Switcher Logic ==========
function toggleAcaraDropdown() {
    const res = document.getElementById('results-acara');
    if (res) {
        res.style.display = (res.style.display === 'none' || res.style.display === '') ? 'block' : 'none';
    }
}

function switchKegiatan(newId) {
    if (newId != <?php echo $kegiatan_id; ?>) {
        window.location.href = 'workspace-logistik.php?kegiatan_id=' + newId + '&mode=pinjam';
    } else {
        toggleAcaraDropdown();
    }
}

// Close results when clicking outside
window.addEventListener('click', function(e) {
    const picker = document.getElementById('picker-acara');
    if (picker && !picker.contains(e.target)) {
        const res = document.getElementById('results-acara');
        if (res) res.style.display = 'none';
    }
});

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('tgl-mulai') && document.getElementById('tgl-mulai').value) {
        formatTanggalRange();
    }
});

// ========== Date Range Logic ==========
const HARI_ID  = ['Minggu','Senin','Selasa','Rabu','Kamis',"Jum'at",'Sabtu'];
const BULAN_ID = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

function formatTanggalRange() {
    const mulai   = document.getElementById('tgl-mulai').value;
    const jmlHari = parseInt(document.getElementById('jml-hari').value) || 1;
    
    if (!mulai) { 
        document.getElementById('preview-tanggal').innerText = 'Pilih tanggal di atas...'; 
        return; 
    }
    
    const d1 = new Date(mulai + 'T00:00:00');
    document.getElementById('out-tahun').value = d1.getFullYear(); // Update tahun otomatis
    
    let result = '';
    if (jmlHari <= 1) {
        result = HARI_ID[d1.getDay()] + ', ' + d1.getDate() + ' ' + BULAN_ID[d1.getMonth()] + ' ' + d1.getFullYear();
    } else {
        const d2 = new Date(d1);
        d2.setDate(d2.getDate() + (jmlHari - 1));
        
        const hari = HARI_ID[d1.getDay()] === HARI_ID[d2.getDay()] ? HARI_ID[d1.getDay()] : HARI_ID[d1.getDay()] + '-' + HARI_ID[d2.getDay()];
        const bln1 = BULAN_ID[d1.getMonth()], bln2 = BULAN_ID[d2.getMonth()];
        const tgl  = bln1 === bln2 && d1.getFullYear() === d2.getFullYear()
            ? d1.getDate() + '-' + d2.getDate() + ' ' + bln1 + ' ' + d1.getFullYear()
            : d1.getDate() + ' ' + bln1 + ' ' + d1.getFullYear() + ' – ' + d2.getDate() + ' ' + bln2 + ' ' + d2.getFullYear();
        result = hari + ', ' + tgl;
    }
    
    document.getElementById('out-tanggal').value = result;
    document.getElementById('preview-tanggal').innerText = result;
}

function resetAll() {
    if (confirm('Kosongkan semua pilihan dan jumlah input?')) {
        document.querySelectorAll('.qty-input').forEach(input => {
            if (input.type === 'checkbox') {
                input.checked = false;
            } else {
                input.value = 0;
            }
        });
    }
}

// Validasi minimal ada 1 barang yang dipinjam - dipindahkan ke submitAction
</script>
    <?php elseif ($mode === 'beli'): ?>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-shopping-cart fa-2x"></i>
                <h2>Catatan Pembelian Barang (Logistik)</h2>
            </div>
            
            <div class="notice-bar" style="background: rgba(74, 144, 226, 0.1); border-color: rgba(74, 144, 226, 0.3); color: #8BB9F0;">
                <i class="fas fa-info-circle"></i>
                <span>Fitur ini berfungsi sebagai catatan sementara bagi seksi logistik untuk mendata estimasi atau list barang yang harus dibeli.</span>
            </div>
            
            <table class="table-beli" id="tableBeli">
                <thead>
                    <tr>
                        <th width="50">No</th>
                        <th>Nama Barang</th>
                        <th width="100">Jumlah</th>
                        <th width="150">Harga Satuan (Rp)</th>
                        <th width="150">Total Harga (Rp)</th>
                        <th width="50">Aksi</th>
                    </tr>
                </thead>
                <tbody id="beliBody">
                    <tr>
                        <td class="row-num" style="text-align:center;">1</td>
                        <td><input type="text" placeholder="Contoh: Lakban Hitam"></td>
                        <td><input type="number" min="1" value="1" oninput="kalkulasiTotal(this)"></td>
                        <td><input type="number" min="0" placeholder="0" oninput="kalkulasiTotal(this)"></td>
                        <td><input type="text" class="row-total" value="0" readonly style="color:var(--accent-color); font-weight:bold;"></td>
                        <td style="text-align:center;">
                            <button type="button" class="qty-btn" style="color:#e74c3c;" onclick="hapusBarisBeli(this)"><i class="fas fa-times"></i></button>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="4" style="text-align:right;">Total Keseluruhan :</th>
                        <th id="grandTotal" style="color:var(--accent-color); font-size:1.1rem;">Rp 0</th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
            
            <button type="button" class="btn-reset" style="background: rgba(74, 144, 226, 0.1); border-color: rgba(74, 144, 226, 0.3); color: var(--accent-color);" onclick="tambahBarisBeli()">
                <i class="fas fa-plus"></i> Tambah Barang
            </button>
        </div>
        
        <script>
        function kalkulasiTotal(el) {
            const tr = el.closest('tr');
            const qty = parseFloat(tr.querySelector('td:nth-child(3) input').value) || 0;
            const hrg = parseFloat(tr.querySelector('td:nth-child(4) input').value) || 0;
            const total = qty * hrg;
            tr.querySelector('.row-total').value = new Intl.NumberFormat('id-ID').format(total);
            updateGrandTotal();
        }
        
        function updateGrandTotal() {
            let gt = 0;
            document.querySelectorAll('.row-total').forEach(inp => {
                gt += parseFloat(inp.value.replace(/[^0-9,-]/g, '')) || 0;
            });
            document.getElementById('grandTotal').innerText = 'Rp ' + new Intl.NumberFormat('id-ID').format(gt);
        }
        
        function updateRowNumbers() {
            document.querySelectorAll('.row-num').forEach((td, idx) => {
                td.innerText = idx + 1;
            });
        }
        
        function tambahBarisBeli() {
            const tbody = document.getElementById('beliBody');
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="row-num" style="text-align:center;"></td>
                <td><input type="text" placeholder="Nama barang..."></td>
                <td><input type="number" min="1" value="1" oninput="kalkulasiTotal(this)"></td>
                <td><input type="number" min="0" placeholder="0" oninput="kalkulasiTotal(this)"></td>
                <td><input type="text" class="row-total" value="0" readonly style="color:var(--accent-color); font-weight:bold;"></td>
                <td style="text-align:center;">
                    <button type="button" class="qty-btn" style="color:#e74c3c;" onclick="hapusBarisBeli(this)"><i class="fas fa-times"></i></button>
                </td>
            `;
            tbody.appendChild(tr);
            updateRowNumbers();
        }
        
        function hapusBarisBeli(btn) {
            const tbody = document.getElementById('beliBody');
            if (tbody.children.length > 1) {
                btn.closest('tr').remove();
                updateRowNumbers();
                updateGrandTotal();
            } else {
                alert('Minimal harus ada 1 baris.');
            }
        }
        
        updateRowNumbers();
        </script>
    <?php elseif ($mode === 'master_barang'): ?>
        <?php $items_mb = dbFetchAll("SELECT * FROM barang_master ORDER BY nama_barang ASC"); ?>
        <div class="card">
            <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <div style="display:flex; align-items:center; gap:15px;">
                    <i class="fas fa-boxes fa-2x" style="color:var(--accent-color);"></i>
                    <h2 style="margin:0;">Master Inventaris Barang</h2>
                </div>
                <button type="button" class="btn-print" style="padding: 10px 20px; font-size: 0.9rem;" onclick="openModalMb('add')">
                    <i class="fas fa-plus"></i> Tambah Barang
                </button>
            </div>

            <?php if ($success_msg): ?>
                <div class="preview-bar" style="background: rgba(39, 174, 96, 0.1); color: #2ecc71; border-color: #2ecc71; margin-bottom: 20px;">
                    <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="preview-bar" style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; border-color: #e74c3c; margin-bottom: 20px;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
                </div>
            <?php endif; ?>
            
            <div class="card-body">
                <table class="items-table" style="width: 100%;">
                    <thead>
                        <tr>
                            <th width="50">No</th>
                            <th>Nama Barang</th>
                            <th width="150">Satuan</th>
                            <th width="120" style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items_mb)): ?>
                            <tr>
                                <td colspan="4" style="text-align:center; color:#555; padding: 40px;">
                                    <i class="fas fa-boxes" style="font-size:2rem; display:block; margin-bottom:10px;"></i>
                                    Belum ada data barang.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items_mb as $idx => $item): ?>
                                <tr>
                                    <td><?php echo $idx + 1; ?></td>
                                    <td style="font-weight:600; color:#eee;"><?php echo htmlspecialchars($item['nama_barang']); ?></td>
                                    <td><span style="background:rgba(255,255,255,0.05); padding:4px 10px; border-radius:6px; font-size:0.85rem; color:#aaa;"><?php echo htmlspecialchars($item['satuan'] ?? 'pcs'); ?></span></td>
                                    <td style="text-align:right;">
                                        <div style="display:inline-flex; gap:8px;">
                                            <button type="button" class="qty-btn" style="width:36px; height:36px; border-radius:8px;" onclick="openModalMb('edit', <?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['nama_barang'])); ?>', '<?php echo htmlspecialchars(addslashes($item['satuan'])); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus barang ini?')">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="mb_action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="qty-btn" style="width:36px; height:36px; border-radius:8px; color:#e74c3c;">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal Add/Edit Barang -->
        <div id="modalMb" class="header-custom-modal" style="z-index: 2000;">
            <div class="header-modal-content" style="max-width: 450px;">
                <div class="header-modal-header" style="justify-content:space-between;">
                    <h4 id="modalMbTitle" style="margin:0; color:var(--accent-color);">Tambah Barang</h4>
                    <button type="button" style="background:none; border:none; color:#777; font-size:1.5rem; cursor:pointer;" onclick="closeModalMb()">&times;</button>
                </div>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="mb_action" id="modalMbAction" value="add">
                    <input type="hidden" name="id" id="modalMbId" value="">
                    <div style="padding: 15px 0;">
                        <label style="color:#777; font-size:0.8rem; margin-bottom:8px; display:block;">NAMA BARANG</label>
                        <input type="text" name="nama_barang" id="modalMbInputNama" required placeholder="Contoh: Sound System Portable" style="width:100%; background:#050505; border:1px solid var(--border-color); padding:12px 16px; border-radius:10px; color:#fff; margin-bottom:15px;">
                        
                        <label style="color:#777; font-size:0.8rem; margin-bottom:8px; display:block;">SATUAN</label>
                        <input type="text" name="satuan" id="modalMbInputSatuan" required placeholder="Contoh: unit, pcs, set" value="pcs" style="width:100%; background:#050505; border:1px solid var(--border-color); padding:12px 16px; border-radius:10px; color:#fff;">
                    </div>
                    <div style="text-align:right; margin-top:15px; display:flex; gap:10px; justify-content:flex-end;">
                        <button type="button" class="header-btn-cancel" onclick="closeModalMb()">Batal</button>
                        <button type="submit" class="btn-print" style="padding: 10px 20px; font-size: 0.9rem;">Simpan Data</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function openModalMb(mode, id = null, name = '', satuan = 'pcs') {
            const modal = document.getElementById('modalMb');
            const title = document.getElementById('modalMbTitle');
            const action = document.getElementById('modalMbAction');
            const inputNama = document.getElementById('modalMbInputNama');
            const inputSatuan = document.getElementById('modalMbInputSatuan');
            const idField = document.getElementById('modalMbId');
            
            if (mode === 'add') {
                title.innerText = 'Tambah Barang Baru';
                action.value = 'add';
                inputNama.value = '';
                inputSatuan.value = 'pcs';
                idField.value = '';
            } else {
                title.innerText = 'Edit Barang';
                action.value = 'edit';
                inputNama.value = name;
                inputSatuan.value = satuan;
                idField.value = id;
            }
            
            modal.style.display = 'flex';
            inputNama.focus();
        }
        function closeModalMb() { document.getElementById('modalMb').style.display = 'none'; }
        </script>

    <?php elseif ($mode === 'master_tempat'): ?>
        <?php 
        $items_peminjaman = dbFetchAll("SELECT * FROM tempat_master ORDER BY nama_tempat ASC");
        $items_kegiatan = dbFetchAll("SELECT * FROM rundown_tempat ORDER BY nama_tempat ASC");
        ?>
        <div style="display: grid; grid-template-columns: 1fr; gap: 24px;">
            <!-- Card 1: Inventaris Tempat -->
            <div class="card">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <div style="display:flex; align-items:center; gap:15px;">
                        <i class="fas fa-map-marker-alt fa-2x" style="color:var(--accent-color);"></i>
                        <h2 style="margin:0;">Master Inventaris Tempat</h2>
                    </div>
                    <button type="button" class="btn-print" style="padding: 10px 20px; font-size: 0.9rem;" onclick="openModalMt('add')">
                        <i class="fas fa-plus"></i> Tambah Tempat
                    </button>
                </div>

                <?php if ($success_msg): ?>
                    <div class="preview-bar" style="background: rgba(39, 174, 96, 0.1); color: #2ecc71; border-color: #2ecc71; margin-bottom: 20px;">
                        <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
                    </div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div class="preview-bar" style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; border-color: #e74c3c; margin-bottom: 20px;">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
                    </div>
                <?php endif; ?>

                <div class="card-body">
                    <table class="items-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th width="50">No</th>
                                <th>Nama Tempat</th>
                                <th width="120" style="text-align:right;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items_peminjaman)): ?>
                                <tr>
                                    <td colspan="3" style="text-align:center; color:#555; padding: 40px;">
                                        <i class="fas fa-building" style="font-size:2rem; display:block; margin-bottom:10px;"></i>
                                        Belum ada data tempat peminjaman.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items_peminjaman as $idx => $item): ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td style="font-weight:600; color:#eee;"><?php echo htmlspecialchars($item['nama_tempat']); ?></td>
                                        <td style="text-align:right;">
                                            <div style="display:inline-flex; gap:8px;">
                                                <button type="button" class="qty-btn" style="width:36px; height:36px; border-radius:8px;" onclick="openModalMt('edit', <?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['nama_tempat'])); ?>')">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus tempat ini?')">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="mt_action" value="delete">
                                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                    <button type="submit" class="qty-btn" style="width:36px; height:36px; border-radius:8px; color:#e74c3c;">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Card 2: Referensi dari Tempat Kegiatan -->
            <div class="card" style="border-top: 4px solid #f39c12; background: rgba(243, 156, 18, 0.02);">
                <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                    <div style="display:flex; align-items:center; gap:15px;">
                        <i class="fas fa-map-marked-alt fa-2x" style="color: #f39c12;"></i>
                        <h2 style="margin:0;">Referensi: Master Tempat Kegiatan</h2>
                    </div>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Salin semua data kegiatan yang belum ada ke Inventaris?')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="mt_action" value="copy_all">
                        <button type="submit" class="btn-reset" style="border-color: #f39c12; color: #f39c12; background: transparent; padding: 8px 16px; font-size: 0.85rem;">
                            <i class="fas fa-copy"></i> Salin Semua
                        </button>
                    </form>
                </div>
                
                <div class="card-body">
                    <table class="items-table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th width="50">No</th>
                                <th>Nama Tempat</th>
                                <th width="120" style="text-align:right;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($items_kegiatan)): ?>
                                <tr>
                                    <td colspan="3" style="text-align:center; color:#555; padding: 40px;">
                                        Belum ada data di kegiatan.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($items_kegiatan as $idx => $item): ?>
                                    <tr>
                                        <td><?php echo $idx + 1; ?></td>
                                        <td style="color: #ccc;"><?php echo htmlspecialchars($item['nama_tempat']); ?></td>
                                        <td style="text-align:right;">
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="mt_action" value="copy">
                                                <input type="hidden" name="nama_tempat" value="<?php echo htmlspecialchars($item['nama_tempat']); ?>">
                                                <button type="submit" class="qty-btn" style="width:36px; height:36px; border-radius:8px; color:#2ecc71;" title="Salin ke Inventaris Tempat">
                                                    <i class="fas fa-share"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal Add/Edit Tempat -->
        <div id="modalMt" class="header-custom-modal" style="z-index: 2000;">
            <div class="header-modal-content" style="max-width: 450px;">
                <div class="header-modal-header" style="justify-content:space-between;">
                    <h4 id="modalMtTitle" style="margin:0; color:var(--accent-color);">Tambah Tempat</h4>
                    <button type="button" style="background:none; border:none; color:#777; font-size:1.5rem; cursor:pointer;" onclick="closeModalMt()">&times;</button>
                </div>
                <form method="POST">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="mt_action" id="modalMtAction" value="add">
                    <input type="hidden" name="id" id="modalMtId" value="">
                    <div style="padding: 15px 0;">
                        <label style="color:#777; font-size:0.8rem; margin-bottom:8px; display:block;">NAMA TEMPAT</label>
                        <input type="text" name="nama_tempat" id="modalMtInputNama" required placeholder="Contoh: Aula Wisata Intelektual" style="width:100%; background:#050505; border:1px solid var(--border-color); padding:12px 16px; border-radius:10px; color:#fff;">
                    </div>
                    <div style="text-align:right; margin-top:15px; display:flex; gap:10px; justify-content:flex-end;">
                        <button type="button" class="header-btn-cancel" onclick="closeModalMt()">Batal</button>
                        <button type="submit" class="btn-print" style="padding: 10px 20px; font-size: 0.9rem;">Simpan Data</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        function openModalMt(mode, id = null, name = '') {
            const modal = document.getElementById('modalMt');
            const title = document.getElementById('modalMtTitle');
            const action = document.getElementById('modalMtAction');
            const inputNama = document.getElementById('modalMtInputNama');
            const idField = document.getElementById('modalMtId');
            
            if (mode === 'add') {
                title.innerText = 'Tambah Tempat Baru';
                action.value = 'add';
                inputNama.value = '';
                idField.value = '';
            } else {
                title.innerText = 'Edit Tempat';
                action.value = 'edit';
                inputNama.value = name;
                idField.value = id;
            }
            
            modal.style.display = 'flex';
            inputNama.focus();
        }
        function closeModalMt() { document.getElementById('modalMt').style.display = 'none'; }
        </script>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../core/footer.php'; ?>
