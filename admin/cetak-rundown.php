<?php
// admin/cetak-rundown.php
require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();

$pj_list = dbFetchAll("SELECT * FROM rundown_pj ORDER BY nama_pj ASC");
$ket_list = dbFetchAll("SELECT * FROM rundown_keterangan ORDER BY nama_keterangan ASC");
$tempat_list = dbFetchAll("SELECT * FROM rundown_tempat ORDER BY nama_tempat ASC");

// --- INITIALIZE EDIT MODE ---
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_data = null;

if ($edit_id > 0) {
    $edit_data = dbFetchOne("SELECT * FROM arsip_rundown WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
}

// --- POST HANDLER: SIMPAN KE ARSIP ---
$success_msg = '';
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_arsip') {
    csrfVerify();
    $nama_acara    = trim($_POST['nama_acara'] ?? '');
    $tahun         = trim($_POST['tahun'] ?? '');
    $tanggal_mulai = trim($_POST['tanggal_mulai'] ?? date('Y-m-d'));
    $durasi_hari   = (int)($_POST['durasi_hari'] ?? 1);
    $target_edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;

    $waktu_arr = $_POST['waktu'] ?? [];
    $acara_arr = $_POST['acara'] ?? [];
    $ket_arr   = $_POST['keterangan'] ?? [];
    $pj_arr    = $_POST['penanggung_jawab'] ?? [];
    $is_parallel_arr = $_POST['is_parallel'] ?? [];
    $durasi_jam_arr  = $_POST['durasi_jam'] ?? [];
    $durasi_menit_arr = $_POST['durasi_menit'] ?? [];
    $waktu_mulai_jam_arr  = $_POST['waktu_mulai_jam'] ?? [];
    $waktu_mulai_menit_arr = $_POST['waktu_mulai_menit'] ?? [];
    $tipe_ket_arr = $_POST['tipe_ket'] ?? [];

    // Build rundown JSON
    $rundown_days = [];
    for ($dayId = 1; $dayId <= $durasi_hari; $dayId++) {
        $day_items = [];
        $waktus = $waktu_arr[$dayId] ?? [];
        $acaras = $acara_arr[$dayId] ?? [];
        $kets   = $ket_arr[$dayId] ?? [];
        $pjs    = $pj_arr[$dayId] ?? [];
        $parallels = $is_parallel_arr[$dayId] ?? [];
        $d_jams  = $durasi_jam_arr[$dayId] ?? [];
        $d_menits = $durasi_menit_arr[$dayId] ?? [];

        $total_rows = count($acaras);
        for ($i = 0; $i < $total_rows; $i++) {
            if (!empty($acaras[$i])) {
                $day_items[] = [
                    'waktu'       => $waktus[$i] ?? '',
                    'acara'       => $acaras[$i],
                    'keterangan'  => $kets[$i] ?? '',
                    'pj'          => $pjs[$i] ?? '',
                    'is_parallel' => ($parallels[$i] ?? 0) == 1,
                    'durasi_jam'  => (int)($d_jams[$i] ?? 0),
                    'durasi_menit' => (int)($d_menits[$i] ?? 45),
                ];
            }
        }

        if (!empty($day_items)) {
            $rundown_days[] = [
                'waktu_mulai_jam'   => (int)($waktu_mulai_jam_arr[$dayId] ?? 7),
                'waktu_mulai_menit' => (int)($waktu_mulai_menit_arr[$dayId] ?? 0),
                'tipe_ket'          => $tipe_ket_arr[$dayId] ?? 'ket',
                'items' => $day_items,
            ];
        }
    }

    if (empty($nama_acara)) {
        $error_msg = "Nama acara wajib diisi.";
    } elseif (empty($rundown_days)) {
        $error_msg = "Minimal harus ada 1 baris acara untuk disimpan.";
    } else {
        $rundown_json = json_encode($rundown_days);

        try {
            if ($target_edit_id > 0) {
                dbQuery("UPDATE arsip_rundown SET nama_acara = ?, tahun = ?, tanggal_mulai = ?, durasi_hari = ?, rundown_json = ? WHERE id = ? AND periode_id = ?", [
                    $nama_acara, $tahun, $tanggal_mulai, $durasi_hari, $rundown_json, $target_edit_id, $periode_id
                ]);
                $success_msg = "Data rundown berhasil diperbarui di arsip.";
                $edit_id = $target_edit_id;
                $edit_data = dbFetchOne("SELECT * FROM arsip_rundown WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
            } else {
                $new_id = dbInsert("INSERT INTO arsip_rundown (nama_acara, tahun, tanggal_mulai, durasi_hari, rundown_json, periode_id) VALUES (?, ?, ?, ?, ?, ?)", [
                    $nama_acara, $tahun, $tanggal_mulai, $durasi_hari, $rundown_json, $periode_id
                ]);
                $success_msg = "Data rundown berhasil disimpan ke arsip.";
                // Switch to edit mode so subsequent saves update instead of inserting duplicates
                $edit_id = $new_id;
                $edit_data = dbFetchOne("SELECT * FROM arsip_rundown WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
            }
        } catch (Exception $e) {
            $error_msg = "Gagal menyimpan: " . $e->getMessage();
        }
    }
}
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --card-bg: rgba(15, 18, 23, 0.95);
    --input-bg: #0a0c10;
    --border-color: #2a3545;
    --accent-color: #E23C3C;
}

.cetak-rundown-container {
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
.card:focus-within {
    position: relative;
    z-index: 9999;
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
    .info-grid { grid-template-columns: 1fr 1fr; }
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

.form-group input:focus, .form-group select:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 15px rgba(226, 60, 60, 0.2);
    outline: none;
}

.rundown-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 8px;
}

.rundown-table th {
    text-align: left;
    padding: 12px 15px;
    color: #555;
    font-size: 0.8rem;
    text-transform: uppercase;
}

.rundown-table td {
    padding: 10px;
    background: rgba(255,255,255,0.02);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
}

.rundown-table td:first-child {
    border-left: 1px solid var(--border-color);
    border-radius: 12px 0 0 12px;
    text-align: center;
    width: 50px;
}

.rundown-table td:last-child {
    border-right: 1px solid var(--border-color);
    border-radius: 0 12px 12px 0;
    width: 50px;
    text-align: center;
}

.rundown-table input, .rundown-table select {
    width: 100%;
    background: #080808;
    border: 1px solid var(--border-color);
    padding: 10px;
    border-radius: 8px;
    color: #fff;
    font-size: 0.9rem;
}

.btn-add-row {
    background: rgba(226, 60, 60, 0.1);
    color: var(--accent-color);
    border: 1px dashed var(--accent-color);
    padding: 12px;
    border-radius: 12px;
    width: 100%;
    text-align: center;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.3s;
    margin-top: 10px;
}

.btn-add-row:hover {
    background: rgba(226, 60, 60, 0.2);
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
    background: rgba(231, 76, 60, 0.2);
}

.btn-insert-row {
    background: rgba(46, 204, 113, 0.1);
    color: #2ecc71;
    border: none;
    border-radius: 8px;
    width: 36px;
    height: 36px;
    cursor: pointer;
    transition: all 0.3s;
}
.btn-insert-row:hover {
    background: rgba(46, 204, 113, 0.25);
    transform: scale(1.1);
}

.btn-move-row {
    background: rgba(155, 89, 182, 0.1);
    color: #9b59b6;
    border: none;
    border-radius: 8px;
    width: 28px;
    height: 28px;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
.btn-move-row:hover {
    background: rgba(155, 89, 182, 0.25);
    transform: scale(1.1);
}
.btn-move-row:disabled {
    opacity: 0.3;
    cursor: not-allowed;
    transform: none;
}

@keyframes highlightNew {
    0% { background: rgba(46, 204, 113, 0.25); transform: scale(1.01); }
    100% { background: transparent; transform: scale(1); }
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
    justify-content: flex-end;
    align-items: center;
    box-shadow: 0 -10px 30px rgba(0,0,0,0.3);
    margin-top: 40px;
    z-index: 100;
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

.btn-remove-day {
    position: absolute;
    top: 25px;
    right: 30px;
    background: rgba(231, 76, 60, 0.1);
    color: #e74c3c;
    border: 1px solid rgba(231, 76, 60, 0.2);
    padding: 8px 15px;
    border-radius: 8px;
    font-size: 0.9rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
    font-weight: 600;
}
.btn-remove-day:hover {
    background: rgba(231, 76, 60, 0.2);
}

.qty-wrapper {
    display: inline-flex;
    align-items: center;
    background: rgba(0,0,0,0.3);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    overflow: hidden;
    height: 38px;
    box-shadow: inset 0 2px 4px rgba(0,0,0,0.2);
    width: 100%;
}
.qty-btn {
    background: rgba(255,255,255,0.03);
    border: none;
    color: var(--accent-color);
    width: 32px;
    height: 100%;
    font-size: 1.2rem;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}
.qty-btn:hover {
    background: rgba(226,60,60,0.2);
    color: #fff;
    background: rgba(255,255,255,0.1);
}
.barang-qty {
    flex: 1;
    background: transparent !important;
    border: none !important;
    color: #fff !important;
    text-align: center !important;
    font-size: 0.95rem !important;
    font-weight: 700 !important;
    padding: 0 !important;
    -moz-appearance: textfield;
}
.barang-qty::-webkit-outer-spin-button,
.barang-qty::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

/* RESPONSIVE DESIGN */
@media (max-width: 768px) {
    .container { padding: 10px; }
    .card { padding: 20px 15px; }
    .card-header h2 { font-size: 1.2rem; }
    
    .actions-bar {
        flex-direction: column;
        gap: 12px;
        padding: 15px;
        border-radius: 15px;
        bottom: 10px;
    }
    
    .btn-print {
        width: 100%;
        justify-content: center;
        padding: 12px;
        font-size: 1rem;
    }
    
    .btn-remove-day {
        position: static;
        width: 100%;
        margin-top: 15px;
        justify-content: center;
    }
    
    /* Table Responsive */
    .rundown-table, 
    .rundown-table thead, 
    .rundown-table tbody, 
    .rundown-table th, 
    .rundown-table td, 
    .rundown-table tr { 
        display: block; 
    }
    
    .rundown-table thead { display: none; }
    
    .rundown-table tr {
        margin-bottom: 20px;
        background: rgba(255,255,255,0.02);
        border: 1px solid var(--border-color);
        border-radius: 15px;
        padding: 10px;
        position: relative;
    }
    
    .rundown-table td {
        border: none !important;
        padding: 8px 0 !important;
        width: 100% !important;
        background: transparent !important;
    }
    
    .rundown-table td:first-child {
        font-size: 1.2rem;
        color: var(--accent-color);
        border-bottom: 1px solid var(--border-color) !important;
        padding-bottom: 10px !important;
        margin-bottom: 10px;
        text-align: left !important;
    }
    
    .rundown-table td:first-child::before {
        content: "BARIS KE-";
        font-size: 0.8rem;
        color: #555;
        margin-right: 5px;
    }

    .rundown-table td.time-col {
        margin-bottom: 15px;
    }
    
    .rundown-table td::before {
        content: attr(data-label);
        display: block;
        font-size: 0.7rem;
        color: #555;
        text-transform: uppercase;
        font-weight: 700;
        margin-bottom: 5px;
    }
    
    .btn-remove-row {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 32px;
        height: 32px;
    }
}
.barang-qty:focus {
    outline: none !important;
    box-shadow: none !important;
}

input.barang-qty::-webkit-inner-spin-button, 
input.barang-qty::-webkit-outer-spin-button { 
    -webkit-appearance: none !important; 
    margin: 0 !important; 
    display: none !important;
}

/* Custom Autocomplete */
.tpl-picker {
    position: relative;
    width: 100%;
}
.tpl-picker:focus-within {
    z-index: 9999;
}
.tpl-search-input {
    width: 100%;
    padding: 14px;
    border-radius: 10px;
    background: #080808;
    border: 1px solid var(--border-color);
    color: #fff;
    font-size: 0.95rem;
    transition: all 0.3s;
}
.tpl-search-input:focus {
    border-color: var(--accent-color);
    outline: none;
    box-shadow: 0 0 0 3px rgba(226, 60, 60, 0.1);
}
.tpl-results {
    position: absolute;
    top: calc(100% + 5px);
    left: 0;
    right: 0;
    background: #121822;
    border: 1px solid var(--border-color);
    border-radius: 12px;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    animation: fadeInDown 0.2s ease-out;
}
@keyframes fadeInDown {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}
.tpl-item {
    padding: 12px 15px;
    cursor: pointer;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    transition: background 0.2s;
    color: #eee;
    font-size: 0.9rem;
}
.tpl-item:last-child { border-bottom: none; }
.tpl-item:hover { background: rgba(226, 60, 60, 0.1); color: var(--accent-color); }
</style>

<div class="cetak-rundown-container">

    <?php if ($success_msg): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 12px; border: 1px solid rgba(46, 204, 113, 0.2); margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
            <a href="arsip-rundown.php" style="color: #4facfe; margin-left: 10px; text-decoration: underline;">Lihat Arsip →</a>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; padding: 15px; border-radius: 12px; border: 1px solid rgba(231, 76, 60, 0.2); margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="rundownForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" id="formAction" value="save_arsip">
        <?php if ($edit_id > 0): ?>
            <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-calendar-check fa-2x"></i>
                <h2><?php echo $edit_id > 0 ? 'Edit Rundown' : 'Cetak Rundown / Susunan Acara'; ?></h2>
                <?php if ($edit_id > 0): ?>
                    <span style="background: rgba(243, 156, 18, 0.15); color: #f39c12; padding: 5px 12px; border-radius: 8px; font-size: 0.8rem; font-weight: 600; border: 1px solid rgba(243, 156, 18, 0.3); margin-left: auto;">
                        <i class="fas fa-edit"></i> Mode Edit #<?php echo $edit_id; ?>
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="info-grid">
                <div class="form-group">
                    <label>Nama Acara / Kegiatan</label>
                    <input type="text" name="nama_acara" id="nama_acara" required placeholder="Contoh: BPM CUP" oninput="updateDayNumbers()" value="<?php echo $edit_data ? htmlspecialchars($edit_data['nama_acara']) : ''; ?>">
                </div>
                <div class="form-group">
                    <label>Tahun Periode</label>
                    <input type="text" name="tahun" id="tahun_periode" required placeholder="Contoh: 2025/2026" value="<?php echo $edit_data ? htmlspecialchars($edit_data['tahun']) : date('Y') . '/' . (date('Y')+1); ?>">
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label><i class="far fa-calendar-alt" style="margin-right: 5px;"></i> Hari & Tanggal Acara</label>
                    <div style="display: flex; gap: 15px; align-items: center; background: #080808; padding: 5px 15px; border-radius: 12px; border: 1px solid var(--border-color);">
                        <input type="date" name="tanggal_mulai" id="tanggal_mulai" required style="flex: 2; border: none; padding: 10px 0; background: transparent; outline: none; box-shadow: none;" onchange="generateDays()" value="<?php echo $edit_data ? htmlspecialchars($edit_data['tanggal_mulai']) : ''; ?>">
                        <span style="color: #888; font-size: 0.9rem;">selama</span>
                        <select name="durasi_hari" id="durasi_hari" style="flex: 1; border: none; padding: 10px; background: rgba(255,255,255,0.05); border-radius: 8px; outline: none;" onchange="generateDays()">
                            <?php for ($i = 1; $i <= 7; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($edit_data && (int)$edit_data['durasi_hari'] === $i) ? 'selected' : ''; ?>><?php echo $i; ?> Hari</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div id="daysContainer">
            <!-- Days will be generated here by JS -->
        </div>

        <div class="actions-bar">
            <div style="display: flex; gap: 12px; align-items: center; width: 100%; justify-content: flex-end;">
                <?php if ($edit_id > 0): ?>
                    <a href="cetak-rundown.php" style="color: #888; text-decoration: none; padding: 14px 20px; border-radius: 12px; font-weight: 600; border: 1px solid var(--border-color); transition: 0.3s;">
                        <i class="fas fa-plus"></i> Buat Baru
                    </a>
                <?php endif; ?>
                <button type="button" class="btn-print" onclick="submitPrint()" style="background: rgba(39, 174, 96, 0.15); color: #27ae60; border: 1px solid rgba(39, 174, 96, 0.3);">
                    <i class="fas fa-file-pdf"></i> Cetak PDF
                </button>
                <button type="submit" class="btn-print">
                    <i class="fas fa-save"></i> <?php echo $edit_id > 0 ? 'Perbarui Arsip' : 'Simpan ke Arsip'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
const ketList = <?php echo json_encode(array_column($ket_list, 'nama_keterangan')); ?>;
const tempatList = <?php echo json_encode(array_column($tempat_list, 'nama_tempat')); ?>;
const pjList = <?php echo json_encode(array_column($pj_list, 'nama_pj')); ?>;

let dayCount = 0;

function addDay() {
    dayCount++;
    const container = document.getElementById('daysContainer');
    
    const dayCard = document.createElement('div');
    dayCard.className = 'card day-card';
    dayCard.id = 'day-' + dayCount;
    dayCard.dataset.day = dayCount;
    
    const removeDayBtn = dayCount > 1 ? `<button type="button" class="btn-remove-day" onclick="removeDay(${dayCount})" title="Hapus Hari Ini"><i class="fas fa-times"></i> Hapus Hari</button>` : '';

    dayCard.innerHTML = `
        <div class="card-header" style="position:relative;">
            <i class="fas fa-calendar-day fa-2x"></i>
            <h2>DAY ${dayCount}</h2>
            ${removeDayBtn}
            <input type="hidden" name="tipe_ket[${dayCount}]" class="tipe-ket-hidden" value="ket">
        </div>
        
        <div class="info-grid">
            <div class="form-group" style="margin-bottom: 0;">
                <label>Waktu Mulai Acara (24 Jam)</label>
                <div style="display: flex; gap: 10px; align-items: center; max-width: 100%;">
                    <div style="width: 100px;">
                        <div class="qty-wrapper">
                            <button type="button" class="qty-btn" onclick="const i=this.nextElementSibling; if(i.value>0) {i.value--; recalculateTimes(${dayCount});}">-</button>
                            <input type="number" class="barang-qty waktu-mulai-jam" name="waktu_mulai_jam[${dayCount}]" required value="07" min="0" max="23" onchange="recalculateTimes(${dayCount})" onkeyup="recalculateTimes(${dayCount})">
                            <button type="button" class="qty-btn" onclick="const i=this.previousElementSibling; if(i.value<23) {i.value++; recalculateTimes(${dayCount});}">+</button>
                        </div>
                    </div>
                    <span style="font-size: 1.5rem; color: var(--accent-color); font-weight: bold;">:</span>
                    <div style="width: 100px;">
                        <div class="qty-wrapper">
                            <button type="button" class="qty-btn" onclick="const i=this.nextElementSibling; if(i.value>0) {i.value--; recalculateTimes(${dayCount});}">-</button>
                            <input type="number" class="barang-qty waktu-mulai-menit" name="waktu_mulai_menit[${dayCount}]" required value="00" min="0" max="59" onchange="recalculateTimes(${dayCount})" onkeyup="recalculateTimes(${dayCount})">
                            <button type="button" class="qty-btn" onclick="const i=this.previousElementSibling; if(i.value<59) {i.value++; recalculateTimes(${dayCount});}">+</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="overflow: visible;">
            <table class="rundown-table">
                <thead>
                    <tr>
                        <th style="width: 5%; text-align: center;">NO</th>
                        <th style="width: 20%;">WAKTU / DURASI</th>
                        <th style="width: 25%;">ACARA</th>
                        <th style="width: 30%;" class="th-ket-tempat">KET / TEMPAT</th>
                        <th style="width: 15%;">PENANGGUNG JAWAB</th>
                        <th style="width: 5%; text-align: center;">AKSI</th>
                    </tr>
                </thead>
                <tbody class="rundownBody">
                </tbody>
            </table>
        </div>
        
        <button type="button" class="btn-add-row" onclick="addRow(${dayCount})">
            <i class="fas fa-plus"></i> Tambah Baris Acara
        </button>
    `;
    
    container.appendChild(dayCard);
    addRow(dayCount);
}

function removeDay(id) {
    const day = document.getElementById('day-' + id);
    if (day) {
        day.remove();
        updateDayNumbers();
    }
}

function generateDays() {
    try {
        const durasi = parseInt(document.getElementById('durasi_hari').value) || 1;
        const container = document.getElementById('daysContainer');
        const currentDays = container.querySelectorAll('.day-card').length;

        if (durasi > currentDays) {
            for (let i = currentDays + 1; i <= durasi; i++) {
                addDay();
            }
        } else if (durasi < currentDays) {
            for (let i = currentDays; i > durasi; i--) {
                removeDay(i);
            }
        }
        
        updateDayNumbers();
    } catch (e) {
        console.error("Error in generateDays:", e);
    }
}

function updateDayNumbers() {
    const namaAcara = document.getElementById('nama_acara').value || 'Acara';
    const days = document.querySelectorAll('.day-card');
    
    days.forEach((day, index) => {
        const dCount = index + 1;
        day.id = 'day-' + dCount;
        day.dataset.day = dCount;
        
        let title = days.length === 1 ? namaAcara : `${namaAcara} - Hari Ke-${dCount}`;
        day.querySelector('h2').innerText = title;
        
        const waktuMulaiJam = day.querySelector('.waktu-mulai-jam');
        if (waktuMulaiJam) {
            waktuMulaiJam.name = `waktu_mulai_jam[${dCount}]`;
            waktuMulaiJam.setAttribute('onchange', `recalculateTimes(${dCount})`);
            waktuMulaiJam.setAttribute('onkeyup', `recalculateTimes(${dCount})`);
        }
        const waktuMulaiMenit = day.querySelector('.waktu-mulai-menit');
        if (waktuMulaiMenit) {
            waktuMulaiMenit.name = `waktu_mulai_menit[${dCount}]`;
            waktuMulaiMenit.setAttribute('onchange', `recalculateTimes(${dCount})`);
            waktuMulaiMenit.setAttribute('onkeyup', `recalculateTimes(${dCount})`);
        }
        
        day.querySelector('.btn-add-row').setAttribute('onclick', `addRow(${dCount})`);
        
        let removeBtn = day.querySelector('.card-header .btn-remove-day');
        if (dCount > 1) {
            if (removeBtn) {
                removeBtn.setAttribute('onclick', `removeDay(${dCount})`);
            } else {
                day.querySelector('.card-header').insertAdjacentHTML('beforeend', `<button type="button" class="btn-remove-day" onclick="removeDay(${dCount})" title="Hapus Hari Ini"><i class="fas fa-times"></i> Hapus Hari</button>`);
            }
        } else if (removeBtn) {
            removeBtn.remove();
        }

        const rows = day.querySelectorAll('.rundownBody tr');
        rows.forEach(row => {
            const waktuHidden = row.querySelector('.waktu-hidden');
            if (waktuHidden) waktuHidden.name = `waktu[${dCount}][]`;
            
            const isParallelHidden = row.querySelector('.is-parallel-hidden');
            if (isParallelHidden) isParallelHidden.name = `is_parallel[${dCount}][]`;
            
            const acaraInput = row.querySelector('input[name^="acara"]');
            if (acaraInput) acaraInput.name = `acara[${dCount}][]`;
            
            const ketInput = row.querySelector('input[name^="keterangan"]');
            if (ketInput) ketInput.name = `keterangan[${dCount}][]`;
            
            const pjInput = row.querySelector('input[name^="penanggung_jawab"]');
            if (pjInput) pjInput.name = `penanggung_jawab[${dCount}][]`;
            
            const jamInput = row.querySelector('.durasi-jam');
            if (jamInput) {
                jamInput.name = `durasi_jam[${dCount}][]`;
                jamInput.setAttribute('onchange', `recalculateTimes(${dCount})`);
                jamInput.setAttribute('onkeyup', `recalculateTimes(${dCount})`);
            }
            
            const menitInput = row.querySelector('.durasi-menit');
            if (menitInput) {
                menitInput.name = `durasi_menit[${dCount}][]`;
                menitInput.setAttribute('onchange', `recalculateTimes(${dCount})`);
                menitInput.setAttribute('onkeyup', `recalculateTimes(${dCount})`);
            }
            
            const removeRowBtn = row.querySelector('.btn-remove-row:not(.card-header *)');
            if (removeRowBtn) {
                removeRowBtn.setAttribute('onclick', `removeRow(this, ${dCount})`);
            }
            
            const addParallelBtn = row.querySelector('.btn-add-parallel');
            if (addParallelBtn) {
                addParallelBtn.setAttribute('onclick', `addParallelRow(this, ${dCount})`);
            }
            
            const insertRowBtn = row.querySelector('.btn-insert-row');
            if (insertRowBtn) {
                insertRowBtn.setAttribute('onclick', `insertRowAfter(this, ${dCount})`);
            }
            
            const moveRowBtns = row.querySelectorAll('.btn-move-row');
            moveRowBtns.forEach(moveBtn => {
                const dir = moveBtn.title.includes('Atas') ? 'up' : 'down';
                moveBtn.setAttribute('onclick', `moveRow(this, ${dCount}, '${dir}')`);
            });
            
            const cbSelesai = row.querySelector('.cb-selesai');
            if (cbSelesai) {
                cbSelesai.setAttribute('onchange', `recalculateTimes(${dCount})`);
            }
        });
    });
}

function addRow(dayId, afterRow = null) {
    const dayCard = document.getElementById('day-' + dayId);
    if(!dayCard) return;
    
    const tbody = dayCard.querySelector('.rundownBody');
    const tr = document.createElement('tr');
    tr.className = 'main-row';
    
    tr.innerHTML = `
        <td class="row-num" style="text-align: center; font-weight: bold; font-size: 1.1rem; color: #555;">${tbody.querySelectorAll('tr.main-row').length + 1}</td>
        <td class="time-col" data-label="WAKTU / DURASI" style="width: 200px;">
            <div style="background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.08); border-radius: 12px; padding: 12px 10px; text-align: center; box-shadow: inset 0 2px 10px rgba(0,0,0,0.2);">
                <div style="font-size: 1.1rem; color: var(--accent-color); font-weight: 800; margin-bottom: 12px; letter-spacing: 1px; text-shadow: 0 0 10px rgba(226, 60, 60, 0.3);" class="waktu-display">00.00 - 00.00</div>
                <input type="hidden" name="waktu[${dayId}][]" class="waktu-hidden" value="00.00 - 00.00">
                <input type="hidden" name="is_parallel[${dayId}][]" class="is-parallel-hidden" value="0">
                <div class="durasi-controls" style="display: flex; gap: 8px; justify-content: center;">
                    <div style="flex: 1; display: flex; flex-direction: column;">
                        <span style="font-size: 0.65rem; color: #888; margin-bottom: 4px; font-weight: 700; letter-spacing: 0.5px;">JAM</span>
                        <div class="qty-wrapper" style="height: 32px;">
                            <button type="button" class="qty-btn" style="width: 28px;" onclick="const i=this.nextElementSibling; if(i.value>0) {i.value--; recalculateTimes(${dayId});}">-</button>
                            <input type="number" class="barang-qty durasi-jam" name="durasi_jam[${dayId}][]" placeholder="0" min="0" value="0" onchange="recalculateTimes(${dayId})" onkeyup="recalculateTimes(${dayId})">
                            <button type="button" class="qty-btn" style="width: 28px;" onclick="const i=this.previousElementSibling; i.value++; recalculateTimes(${dayId});">+</button>
                        </div>
                    </div>
                    <div style="flex: 1; display: flex; flex-direction: column;">
                        <span style="font-size: 0.65rem; color: #888; margin-bottom: 4px; font-weight: 700; letter-spacing: 0.5px;">MENIT</span>
                        <div class="qty-wrapper" style="height: 32px;">
                            <button type="button" class="qty-btn" style="width: 28px;" onclick="const i=this.nextElementSibling; if(i.value>0) {i.value--; recalculateTimes(${dayId});}">-</button>
                            <input type="number" class="barang-qty durasi-menit" name="durasi_menit[${dayId}][]" placeholder="0" min="0" value="45" onchange="recalculateTimes(${dayId})" onkeyup="recalculateTimes(${dayId})">
                            <button type="button" class="qty-btn" style="width: 28px;" onclick="const i=this.previousElementSibling; i.value++; recalculateTimes(${dayId});">+</button>
                        </div>
                    </div>
                </div>
                <label class="selesai-toggle" style="display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 8px; cursor: pointer; user-select: none;">
                    <input type="checkbox" class="cb-selesai" onchange="recalculateTimes(${dayId})" style="accent-color: var(--accent-color); width: 14px; height: 14px; cursor: pointer;">
                    <span style="font-size: 0.7rem; color: #888; font-weight: 600;">Sampai Selesai</span>
                </label>
            </div>
        </td>
        <td data-label="ACARA">
            <input type="text" name="acara[${dayId}][]" placeholder="Nama Acara/Materi" required style="padding: 14px; border-radius: 10px; font-size: 0.95rem; width: 100%;">
        </td>
        <td data-label="KET / TEMPAT">
            <div style="display: flex; gap: 5px;">
                <select class="ket-tempat-select" style="width: auto; padding: 10px 5px; border-radius: 8px; background: rgba(0,0,0,0.3); color: #888; border: 1px solid var(--border-color); cursor: pointer; outline: none;" onchange="switchKetTempat(this)">
                    <option value="ket">Ket.</option>
                    <option value="tempat">Tmpt</option>
                </select>
                <div class="tpl-picker" style="flex: 1; min-width: 150px;">
                    <input type="text" name="keterangan[${dayId}][]" class="tpl-search-input" placeholder="Pilih/Ketik..." required style="padding: 14px; border-radius: 10px; font-size: 0.95rem;">
                    <div class="tpl-results">
                        ${ketList.map(v => `<div class="tpl-item" data-val="${v}">${v}</div>`).join('')}
                    </div>
                </div>
            </div>
        </td>
        <td data-label="PENANGGUNG JAWAB">
            <div class="tpl-picker" style="width: 100%;">
                <input type="text" name="penanggung_jawab[${dayId}][]" class="tpl-search-input" placeholder="Pilih/Ketik PJ" required style="padding: 14px; border-radius: 10px; font-size: 0.95rem;">
                <div class="tpl-results">
                    ${pjList.map(v => `<div class="tpl-item" data-val="${v}">${v}</div>`).join('')}
                </div>
            </div>
        </td>
        <td data-label="AKSI" style="text-align: center; white-space: nowrap;">
            <div style="display: flex; flex-direction: column; gap: 4px; align-items: center;">
                <div style="display: flex; gap: 3px; justify-content: center;">
                    <button type="button" class="btn-move-row" onclick="moveRow(this, ${dayId}, 'up')" title="Pindah ke Atas">
                        <i class="fas fa-chevron-up"></i>
                    </button>
                    <button type="button" class="btn-move-row" onclick="moveRow(this, ${dayId}, 'down')" title="Pindah ke Bawah">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
                <div style="display: flex; gap: 3px; justify-content: center;">
                    <button type="button" class="btn-insert-row" onclick="insertRowAfter(this, ${dayId})" title="Sisipkan Baris Baru di Bawah">
                        <i class="fas fa-plus-circle"></i>
                    </button>
                    <button type="button" class="btn-add-parallel" onclick="addParallelRow(this, ${dayId})" title="Tambah Baris Paralel (Kegiatan Bersamaan)" style="background: rgba(226, 60, 60, 0.1); color: var(--accent-color); border: none; border-radius: 8px; width: 36px; height: 36px; cursor: pointer; transition: 0.3s;">
                        <i class="fas fa-layer-group"></i>
                    </button>
                    <button type="button" class="btn-remove-row" onclick="removeRow(this, ${dayId})" title="Hapus Baris Ini">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </td>
    `;
    
    // Support insert-after mode: if afterRow is provided, insert after it instead of appending
    if (afterRow) {
        // Find the last parallel-row after the reference row
        let insertAfterEl = afterRow;
        while (insertAfterEl.nextElementSibling && insertAfterEl.nextElementSibling.classList.contains('parallel-row')) {
            insertAfterEl = insertAfterEl.nextElementSibling;
        }
        insertAfterEl.insertAdjacentElement('afterend', tr);
    } else {
        tbody.appendChild(tr);
    }
    
    updateRowNumbers(dayId);
    recalculateTimes(dayId);
    return tr;
}

function addParallelRow(btn, dayId) {
    const mainTr = btn.closest('tr');
    
    let insertAfter = mainTr;
    while (insertAfter.nextElementSibling && insertAfter.nextElementSibling.classList.contains('parallel-row')) {
        insertAfter = insertAfter.nextElementSibling;
    }
    
    const tr = document.createElement('tr');
    tr.className = 'parallel-row';
    tr.innerHTML = `
        <td data-label="ACARA">
            <input type="hidden" name="waktu[${dayId}][]" class="waktu-hidden" value="00.00 - 00.00">
            <input type="hidden" name="is_parallel[${dayId}][]" class="is-parallel-hidden" value="1">
            <input type="text" name="acara[${dayId}][]" placeholder="Kegiatan Paralel..." required style="padding: 14px; border-radius: 10px; font-size: 0.95rem; width: 100%; border-color: rgba(226, 60, 60, 0.4); box-shadow: inset 0 0 10px rgba(226, 60, 60, 0.05);">
        </td>
        <td data-label="KET / TEMPAT">
            <div style="display: flex; gap: 5px;">
                <select class="ket-tempat-select" style="width: auto; padding: 10px 5px; border-radius: 8px; background: rgba(0,0,0,0.3); color: #888; border: 1px solid var(--border-color); cursor: pointer; outline: none;" onchange="switchKetTempat(this)">
                    <option value="ket">Ket.</option>
                    <option value="tempat">Tmpt</option>
                </select>
                <div class="tpl-picker" style="flex: 1; min-width: 150px;">
                    <input type="text" name="keterangan[${dayId}][]" class="tpl-search-input" placeholder="Pilih/Ketik..." required style="padding: 14px; border-radius: 10px; font-size: 0.95rem;">
                    <div class="tpl-results">
                        ${ketList.map(v => `<div class="tpl-item" data-val="${v}">${v}</div>`).join('')}
                    </div>
                </div>
            </div>
        </td>
        <td data-label="PENANGGUNG JAWAB">
            <div class="tpl-picker" style="width: 100%;">
                <input type="text" name="penanggung_jawab[${dayId}][]" class="tpl-search-input" placeholder="Pilih/Ketik PJ" required style="padding: 14px; border-radius: 10px; font-size: 0.95rem;">
                <div class="tpl-results">
                    ${pjList.map(v => `<div class="tpl-item" data-val="${v}">${v}</div>`).join('')}
                </div>
            </div>
        </td>
        <td data-label="AKSI" style="text-align: center; white-space: nowrap;">
            <button type="button" class="btn-remove-row" onclick="removeRow(this, ${dayId})" title="Hapus Baris Paralel">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    
    insertAfter.insertAdjacentElement('afterend', tr);
    
    const rowNum = mainTr.querySelector('.row-num');
    const timeCol = mainTr.querySelector('.time-col');
    if (rowNum) rowNum.rowSpan = (rowNum.rowSpan || 1) + 1;
    if (timeCol) timeCol.rowSpan = (timeCol.rowSpan || 1) + 1;
    
    recalculateTimes(dayId);
}

function removeRow(btn, dayId) {
    const tr = btn.closest('tr');
    if (tr.classList.contains('main-row')) {
        while (tr.nextElementSibling && tr.nextElementSibling.classList.contains('parallel-row')) {
            tr.nextElementSibling.remove();
        }
        tr.remove();
    } else {
        let prev = tr.previousElementSibling;
        while (prev && !prev.classList.contains('main-row')) {
            prev = prev.previousElementSibling;
        }
        if (prev) {
            const rowNum = prev.querySelector('.row-num');
            const timeCol = prev.querySelector('.time-col');
            if (rowNum) rowNum.rowSpan = Math.max(1, rowNum.rowSpan - 1);
            if (timeCol) timeCol.rowSpan = Math.max(1, timeCol.rowSpan - 1);
        }
        tr.remove();
    }
    updateRowNumbers(dayId);
    recalculateTimes(dayId);
}

// --- INSERT ROW AFTER: Sisipkan baris baru di bawah baris referensi (mirip Google Forms) ---
function insertRowAfter(btn, dayId) {
    const refRow = btn.closest('tr');
    if (!refRow) return;
    
    const newRow = addRow(dayId, refRow);
    
    // Animasi highlight baris baru agar user tahu posisi baris yang baru disisipkan
    if (newRow) {
        newRow.style.animation = 'highlightNew 0.8s ease-out';
        // Scroll ke baris baru
        newRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

// --- MOVE ROW: Pindahkan baris ke atas atau ke bawah ---
function moveRow(btn, dayId, direction) {
    const mainRow = btn.closest('tr.main-row');
    if (!mainRow) return;
    
    const dayCard = document.getElementById('day-' + dayId);
    if (!dayCard) return;
    const tbody = dayCard.querySelector('.rundownBody');
    
    // Kumpulkan baris ini beserta parallel-row-nya sebagai satu grup
    function getRowGroup(row) {
        const group = [row];
        let next = row.nextElementSibling;
        while (next && next.classList.contains('parallel-row')) {
            group.push(next);
            next = next.nextElementSibling;
        }
        return group;
    }
    
    const currentGroup = getRowGroup(mainRow);
    
    if (direction === 'up') {
        // Cari main-row sebelumnya
        let prevEl = mainRow.previousElementSibling;
        // Skip parallel-rows dari grup sebelumnya
        while (prevEl && prevEl.classList.contains('parallel-row')) {
            prevEl = prevEl.previousElementSibling;
        }
        if (!prevEl || !prevEl.classList.contains('main-row')) return; // Sudah paling atas
        
        // Pindahkan grup saat ini ke sebelum prevEl (main-row sebelumnya)
        currentGroup.forEach(row => {
            tbody.insertBefore(row, prevEl);
        });
    } else if (direction === 'down') {
        // Cari main-row setelah grup ini
        const lastInGroup = currentGroup[currentGroup.length - 1];
        let nextMainRow = lastInGroup.nextElementSibling;
        if (!nextMainRow || !nextMainRow.classList.contains('main-row')) return; // Sudah paling bawah
        
        // Kumpulkan grup baris berikutnya
        const nextGroup = getRowGroup(nextMainRow);
        const lastInNextGroup = nextGroup[nextGroup.length - 1];
        
        // Pindahkan grup saat ini ke setelah grup berikutnya
        currentGroup.forEach(row => {
            lastInNextGroup.insertAdjacentElement('afterend', row);
        });
        // Karena forEach menyisipkan satu-satu setelah lastInNextGroup, urutannya jadi terbalik
        // Perbaiki: sisipkan ulang dalam urutan yang benar
        for (let i = 1; i < currentGroup.length; i++) {
            currentGroup[i - 1].insertAdjacentElement('afterend', currentGroup[i]);
        }
    }
    
    updateRowNumbers(dayId);
    recalculateTimes(dayId);
    
    // Animasi singkat untuk feedback visual
    mainRow.style.animation = 'none';
    mainRow.offsetHeight; // trigger reflow
    mainRow.style.animation = 'highlightNew 0.5s ease-out';
}

function updateRowNumbers(dayId) {
    const dayCard = document.getElementById('day-' + dayId);
    if(!dayCard) return;
    const mainRows = dayCard.querySelectorAll('.rundownBody tr.main-row');
    mainRows.forEach((row, index) => {
        const rowNum = row.querySelector('.row-num');
        if (rowNum) rowNum.innerText = index + 1;
    });
}

function recalculateTimes(dayId) {
    const dayCard = document.getElementById('day-' + dayId);
    if (!dayCard) return;
    
    let hours = parseInt(dayCard.querySelector('.waktu-mulai-jam').value) || 0;
    let minutes = parseInt(dayCard.querySelector('.waktu-mulai-menit').value) || 0;
    
    let currentTime = new Date(0, 0, 0, hours, minutes, 0);

    const mainRows = dayCard.querySelectorAll('.rundownBody tr.main-row');
    const lastIndex = mainRows.length - 1;
    
    mainRows.forEach((row, idx) => {
        const jamInput = row.querySelector('.durasi-jam');
        const menitInput = row.querySelector('.durasi-menit');
        const waktuDisplay = row.querySelector('.waktu-display');
        const waktuHidden = row.querySelector('.waktu-hidden');
        const cbSelesai = row.querySelector('.cb-selesai');
        const selesaiToggle = row.querySelector('.selesai-toggle');
        const durasiControls = row.querySelector('.durasi-controls');
        const isLastRow = idx === lastIndex;
        
        // Only the last row shows the "Sampai Selesai" option
        if (selesaiToggle) {
            selesaiToggle.style.display = isLastRow ? 'flex' : 'none';
        }
        // If not the last row, uncheck it
        if (!isLastRow && cbSelesai && cbSelesai.checked) {
            cbSelesai.checked = false;
        }
        
        const isSelesai = isLastRow && cbSelesai && cbSelesai.checked;
        
        // Toggle visibility of duration controls
        if (durasiControls) {
            durasiControls.style.display = isSelesai ? 'none' : 'flex';
        }
        
        let startH = currentTime.getHours().toString().padStart(2, '0');
        let startM = currentTime.getMinutes().toString().padStart(2, '0');
        let startTimeStr = `${startH}.${startM}`;
        
        let displayStr;
        if (isSelesai) {
            displayStr = `${startTimeStr} - Selesai`;
        } else {
            let durasiJam = parseInt(jamInput.value) || 0;
            let durasiMenit = parseInt(menitInput.value) || 0;
            let totalDurasi = (durasiJam * 60) + durasiMenit;
            
            currentTime.setMinutes(currentTime.getMinutes() + totalDurasi);
            
            let endH = currentTime.getHours().toString().padStart(2, '0');
            let endM = currentTime.getMinutes().toString().padStart(2, '0');
            let endTimeStr = `${endH}.${endM}`;
            displayStr = `${startTimeStr} - ${endTimeStr}`;
        }
        
        if (waktuDisplay) waktuDisplay.innerText = displayStr;
        if (waktuHidden) waktuHidden.value = displayStr;
        
        let next = row.nextElementSibling;
        while (next && next.classList.contains('parallel-row')) {
            const nextWaktu = next.querySelector('.waktu-hidden');
            if (nextWaktu) nextWaktu.value = displayStr;
            next = next.nextElementSibling;
        }
    });
}

// Autocomplete Logic
function switchKetTempat(selectElem) {
    const type = selectElem.value;
    const list = type === 'ket' ? ketList : tempatList;
    
    const dayCard = selectElem.closest('.day-card');
    if (!dayCard) return;
    
    // Update hidden input
    const hiddenTipe = dayCard.querySelector('.tipe-ket-hidden');
    if (hiddenTipe) hiddenTipe.value = type;
    
    // Update TH
    const th = dayCard.querySelector('.th-ket-tempat');
    if (th) {
        th.innerText = type === 'ket' ? 'KETERANGAN' : 'TEMPAT';
    }
    
    // Update all selects in this dayCard
    const allSelects = dayCard.querySelectorAll('select.ket-tempat-select');
    allSelects.forEach(sel => {
        sel.value = type;
        const picker = sel.nextElementSibling;
        if (picker && picker.classList.contains('tpl-picker')) {
            const results = picker.querySelector('.tpl-results');
            if (results) {
                results.innerHTML = list.map(v => `<div class="tpl-item" data-val="${v}">${v}</div>`).join('');
            }
        }
    });
    
    // Focus the input of the changed row
    const currentPicker = selectElem.nextElementSibling;
    if (currentPicker) {
        const input = currentPicker.querySelector('input');
        if (input) input.focus();
    }
}

document.addEventListener('focusin', function(e) {
    if (e.target.classList.contains('tpl-search-input')) {
        const results = e.target.nextElementSibling;
        if (results && results.classList.contains('tpl-results')) {
            results.style.display = 'block';
        }
    }
});

document.addEventListener('keyup', function(e) {
    if (e.target.classList.contains('tpl-search-input')) {
        const inputVal = e.target.value.toLowerCase();
        const results = e.target.nextElementSibling;
        if (results && results.classList.contains('tpl-results')) {
            const items = results.querySelectorAll('.tpl-item');
            items.forEach(item => {
                const text = item.innerText.toLowerCase();
                item.style.display = text.includes(inputVal) ? 'block' : 'none';
            });
        }
    }
});

document.addEventListener('click', function(e) {
    if (e.target.closest('.tpl-item')) {
        const item = e.target.closest('.tpl-item');
        const value = item.dataset.val || item.innerText;
        const picker = item.closest('.tpl-picker');
        const input = picker.querySelector('.tpl-search-input');
        input.value = value;
        picker.querySelector('.tpl-results').style.display = 'none';
    } else if (!e.target.closest('.tpl-picker')) {
        document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
    }
});

document.addEventListener('DOMContentLoaded', function() {
    try {
        const dateInput = document.getElementById('tanggal_mulai');
        if (dateInput && !dateInput.value) {
            dateInput.value = new Date().toISOString().split('T')[0];
        }
        
        const container = document.getElementById('daysContainer');
        if (container.querySelectorAll('.day-card').length === 0) {
            generateDays();
        }

        // --- EDIT MODE: Restore data from archive ---
        <?php if ($edit_data): ?>
        const editData = <?php echo $edit_data['rundown_json']; ?>;
        if (editData && editData.length > 0) {
            // Data has been loaded, days are already generated by generateDays() above.
            // Now populate rows for each day.
            editData.forEach((dayData, dayIndex) => {
                const dayId = dayIndex + 1;
                const dayCard = document.getElementById('day-' + dayId);
                if (!dayCard || !dayData.items) return;

                // Restore waktu mulai for this day
                const wmJam = dayCard.querySelector('.waktu-mulai-jam');
                const wmMenit = dayCard.querySelector('.waktu-mulai-menit');
                if (wmJam && dayData.waktu_mulai_jam !== undefined) wmJam.value = dayData.waktu_mulai_jam;
                if (wmMenit && dayData.waktu_mulai_menit !== undefined) wmMenit.value = dayData.waktu_mulai_menit;

                // Remove the default empty row that generateDays adds
                const tbody = dayCard.querySelector('.rundownBody');
                tbody.innerHTML = '';

                dayData.items.forEach((item, itemIdx) => {
                    if (item.is_parallel) {
                        // Find the last main-row to attach this parallel row to
                        const mainRows = tbody.querySelectorAll('tr.main-row');
                        const lastMainRow = mainRows[mainRows.length - 1];
                        if (lastMainRow) {
                            addParallelRow(lastMainRow.querySelector('.btn-add-parallel'), dayId);
                            let lastParallel = lastMainRow.nextElementSibling;
                            while (lastParallel && lastParallel.nextElementSibling && lastParallel.nextElementSibling.classList.contains('parallel-row')) {
                                lastParallel = lastParallel.nextElementSibling;
                            }
                            if (lastParallel && lastParallel.classList.contains('parallel-row')) {
                                const acInput = lastParallel.querySelector('input[name^="acara"]');
                                const ketInput = lastParallel.querySelector('input[name^="keterangan"]');
                                const pjInput = lastParallel.querySelector('input[name^="penanggung_jawab"]');
                                if (acInput) acInput.value = item.acara;
                                if (ketInput) ketInput.value = item.keterangan;
                                if (pjInput) pjInput.value = item.pj;
                            }
                        }
                    } else {
                        addRow(dayId);
                        const mainRows = tbody.querySelectorAll('tr.main-row');
                        const newRow = mainRows[mainRows.length - 1];
                        if (newRow) {
                            const acInput = newRow.querySelector('input[name^="acara"]');
                            const ketInput = newRow.querySelector('input[name^="keterangan"]');
                            const pjInput = newRow.querySelector('input[name^="penanggung_jawab"]');
                            if (acInput) acInput.value = item.acara;
                            if (ketInput) ketInput.value = item.keterangan;
                            if (pjInput) pjInput.value = item.pj;

                            // Restore durasi jam & menit
                            const dJam = newRow.querySelector('.durasi-jam');
                            const dMenit = newRow.querySelector('.durasi-menit');
                            if (dJam && item.durasi_jam !== undefined) dJam.value = item.durasi_jam;
                            if (dMenit && item.durasi_menit !== undefined) dMenit.value = item.durasi_menit;
                        }
                    }
                });

                // Restore tipe_ket for the whole day
                const tipeKetHidden = dayCard.querySelector('.tipe-ket-hidden');
                let dayTipeKet = 'ket'; // default
                if (dayData.tipe_ket !== undefined) {
                    dayTipeKet = dayData.tipe_ket;
                }
                if (tipeKetHidden) tipeKetHidden.value = dayTipeKet;
                const thKetTempat = dayCard.querySelector('.th-ket-tempat');
                if (thKetTempat) thKetTempat.innerText = dayTipeKet === 'ket' ? 'KETERANGAN' : 'TEMPAT';

                // Update all selects to match dayTipeKet
                const allSelects = dayCard.querySelectorAll('select.ket-tempat-select');
                allSelects.forEach(sel => {
                    sel.value = dayTipeKet;
                    const picker = sel.nextElementSibling;
                    if (picker) {
                        const results = picker.querySelector('.tpl-results');
                        const list = dayTipeKet === 'ket' ? ketList : tempatList;
                        if (results) results.innerHTML = list.map(v => `<div class="tpl-item" data-val="${v}">${v}</div>`).join('');
                    }
                });

                recalculateTimes(dayId);
            });
        }
        <?php endif; ?>
    } catch (e) {
        console.error("Error in DOMContentLoaded:", e);
    }
});

// --- Print function: clone form and submit to PDF endpoint ---
function submitPrint() {
    const form = document.getElementById('rundownForm');
    const days = document.querySelectorAll('.day-card');
    if (days.length === 0) {
        alert('Minimal harus ada 1 Hari.');
        return;
    }
    
    let hasRow = false;
    days.forEach(day => {
        if(day.querySelectorAll('.rundownBody tr').length > 0) hasRow = true;
    });
    
    if (!hasRow) {
        alert('Minimal harus ada 1 baris acara di salah satu hari.');
        return;
    }

    // Clone the form, change action and target, then submit
    const clonedForm = form.cloneNode(true);
    clonedForm.action = 'cetak-rundown-pdf.php';
    clonedForm.target = '_blank';
    clonedForm.style.display = 'none';
    // Remove the action hidden field so cetak-rundown-pdf.php doesn't get confused
    const actionField = clonedForm.querySelector('#formAction');
    if (actionField) actionField.remove();
    document.body.appendChild(clonedForm);
    clonedForm.submit();
    setTimeout(() => clonedForm.remove(), 2000);
}

// --- Form validation on save ---
document.getElementById('rundownForm').addEventListener('submit', function(e) {
    const days = document.querySelectorAll('.day-card');
    if (days.length === 0) {
        e.preventDefault();
        alert('Minimal harus ada 1 Hari.');
        return;
    }
    
    let hasRow = false;
    days.forEach(day => {
        if(day.querySelectorAll('.rundownBody tr').length > 0) hasRow = true;
    });
    
    if (!hasRow) {
        e.preventDefault();
        alert('Minimal harus ada 1 baris acara di salah satu hari.');
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
