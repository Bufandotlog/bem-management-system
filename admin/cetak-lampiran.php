<?php
// admin/cetak-lampiran.php
require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();

// --- INITIALIZE EDIT MODE ---
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_data = null;
$pre_filled_qty = [];

if ($edit_id > 0) {
    $edit_data = dbFetchOne("SELECT * FROM lampiran_pinjam WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
    if ($edit_data) {
        $barang_data = json_decode($edit_data['barang_json'], true) ?: [];
        foreach ($barang_data as $b) {
            $pre_filled_qty[$b['id']] = $b['qty'];
        }
    }
}

// --- POST HANDLER: SIMPAN DATA PEMINJAMAN ---
$success_msg = '';
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    csrfVerify();
    $acara   = trim($_POST['acara'] ?? '');
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
        
        if ($target_edit_id > 0) {
            // Update existing data
            $res = dbQuery("UPDATE lampiran_pinjam SET nama_acara = ?, tanggal_kegiatan = ?, tahun = ?, barang_json = ? WHERE id = ? AND periode_id = ?", [
                $acara, $tanggal, $tahun, $barang_json, $target_edit_id, $periode_id
            ]);
            if ($res) {
                $success_msg = "Data peminjaman berhasil diperbarui.";
                // Refresh edit data after update
                $edit_id = $target_edit_id;
                $edit_data = dbFetchOne("SELECT * FROM lampiran_pinjam WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
                $pre_filled_qty = [];
                if ($edit_data) {
                    $barang_data = json_decode($edit_data['barang_json'], true) ?: [];
                    foreach ($barang_data as $b) {
                        $pre_filled_qty[$b['id']] = $b['qty'];
                    }
                }
            } else {
                $error_msg = "Gagal memperbarui data ke database.";
            }
        } else {
            // Insert new data
            $res = dbQuery("INSERT INTO lampiran_pinjam (nama_acara, tanggal_kegiatan, tahun, barang_json, periode_id) VALUES (?, ?, ?, ?, ?)", [
                $acara, $tanggal, $tahun, $barang_json, $periode_id
            ]);
            if ($res) {
                $success_msg = "Data peminjaman berhasil disimpan ke arsip.";
            } else {
                $error_msg = "Gagal menyimpan data ke database.";
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
    --accent-color: #E23C3C;
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
    box-shadow: 0 0 15px rgba(226, 60, 60, 0.2);
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
.tpl-item:hover { background: rgba(226, 60, 60, 0.1); }
.tpl-item-label { font-weight: 700; color: #F0D095; font-size: 0.9rem; }

/* Date Range */
.date-range-wrap { display: flex; gap: 10px; align-items: center; }
.date-range-wrap input { flex: 1; }
.preview-bar { background: rgba(226,60,60,0.08); border-radius: 12px; padding: 12px 16px; font-size: 0.85rem; margin-top: 10px; color: #F0D095; border-left: 4px solid var(--accent-color); }

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
    background: rgba(226,60,60,0.2);
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
</style>

<div class="cetak-lampiran-container">
    <form action="cetak-lampiran-pdf.php" method="POST" target="_blank" id="printForm">
        <?php echo csrfField(); ?>
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list-check fa-2x"></i>
                <h2><?php echo $edit_data ? 'Edit Data Lampiran' : 'Form Cetak & Simpan Lampiran'; ?></h2>
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
            
            <div class="info-grid">
                <!-- Kalender Form -->
                <div class="form-group">
                    <label>Tanggal Pelaksanaan</label>
                    <div class="date-range-wrap">
                        <input type="date" id="tgl-mulai" onchange="formatTanggalRange()" required>
                        <span style="color:#777; font-size:0.9rem; margin:0 5px;">Selama</span>
                        <div class="qty-wrapper" style="flex: none; width: auto;">
                            <button type="button" class="qty-btn" onclick="const i=document.getElementById('jml-hari'); if(i.value>1) {i.stepDown(); formatTanggalRange();} ">-</button>
                            <input type="number" id="jml-hari" class="barang-qty" onchange="formatTanggalRange()" onkeyup="formatTanggalRange()" min="1" value="1" style="width: 40px !important;">
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
                    <label>Nama Acara / Kegiatan</label>
                    <div class="tpl-picker" id="picker-acara">
                        <i class="fas fa-search tpl-search-icon"></i>
                        <input type="text" id="input_acara" name="acara" class="tpl-search-input" placeholder="Cari atau ketik nama acara..." value="<?php echo $edit_data ? htmlspecialchars($edit_data['nama_acara']) : ''; ?>" required onfocus="showTplResults()" onkeyup="filterTpl()">
                        <div class="tpl-results" id="results-acara">
                            <?php foreach($list_kegiatan as $k): ?>
                            <div class="tpl-item" onclick="selectTpl('<?php echo htmlspecialchars(addslashes($k['label'])); ?>')">
                                <div class="tpl-item-label"><?php echo htmlspecialchars($k['label']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
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
                                Master barang kosong. Silakan isi di <a href="master-barang.php" style="color:var(--accent-color);">Master Barang</a>.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($barang as $item): $uid = 'b_' . $item['id']; ?>
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
                    Belum ada data tempat. Silakan isi di <a href="master-tempat.php" style="color:var(--accent-color);">Master Tempat</a>.
                </div>
            <?php else: ?>
                <div style="display:flex; flex-direction:column; gap:10px;">
                    <?php foreach ($tempat as $item): $uid = 't_' . $item['id']; ?>
                        <div class="switch-container" onclick="const cb = this.querySelector('input[type=checkbox]'); if(event.target.tagName !== 'INPUT' && !event.target.classList.contains('slider')) { cb.checked = !cb.checked; }">
                            <div class="switch-label">
                                <i class="fas fa-building"></i>
                                <span><?php echo htmlspecialchars($item['nama']); ?></span>
                                <span style="font-size: 0.7rem; color: #888; background: #222; padding: 2px 6px; border-radius: 4px; margin-left: 8px;">Tempat</span>
                            </div>
                            <label class="switch" style="margin:0;" onclick="event.stopPropagation();">
                                <!-- Kita gunakan checkbox, dan JS akan map nilainya ke 1 atau 0 -->
                                <input type="checkbox" name="qty[<?php echo $uid; ?>]" value="1" class="qty-input tempat-toggle" <?php echo isset($pre_filled_qty[$uid]) && $pre_filled_qty[$uid] > 0 ? 'checked' : ''; ?>>
                                <span class="slider"></span>
                            </label>
                            <input type="hidden" name="item_name[<?php echo $uid; ?>]" value="<?php echo htmlspecialchars($item['nama']); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="actions-bar">
            <?php if ($edit_data): ?>
                <a href="arsip-lampiran.php" class="btn-reset" style="text-decoration:none; display:flex; align-items:center; gap:8px;">
                    <i class="fas fa-arrow-left"></i> Kembali ke Arsip
                </a>
            <?php else: ?>
                <button type="button" class="btn-reset" onclick="resetAll()">
                    <i class="fas fa-undo"></i> Reset Semua Jumlah
                </button>
            <?php endif; ?>
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
        form.action = 'cetak-lampiran-pdf.php';
        form.submit();
    } else {
        form.target = '_self';
        form.action = '';
        form.submit();
    }
}
// ========== Template Picker Logic ==========
function showTplResults() {
    document.getElementById('results-acara').style.display = 'block';
}

function filterTpl() {
    const q = document.getElementById('input_acara').value.toLowerCase();
    const items = document.querySelectorAll('#results-acara .tpl-item');
    items.forEach(it => {
        const txt = it.innerText.toLowerCase();
        it.style.display = txt.includes(q) ? 'block' : 'none';
    });
}

function selectTpl(val) {
    document.getElementById('input_acara').value = val;
    document.getElementById('results-acara').style.display = 'none';
}

// Close results when clicking outside
window.addEventListener('click', function(e) {
    if (!document.getElementById('picker-acara').contains(e.target)) {
        document.getElementById('results-acara').style.display = 'none';
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

<?php require_once __DIR__ . '/footer.php'; ?>
