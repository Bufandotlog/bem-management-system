<?php
// admin/master-kegiatan.php
require_once __DIR__ . '/../core/header.php';

// Pastikan hanya admin / superadmin yang bisa akses
if (!($isSuperadmin || $admin_role === 'admin')) {
    redirect('admin/core/dashboard.php', 'Akses ditolak: Hanya Admin yang dapat mengelola Kegiatan.', 'error');
}

$periode_id = getUserPeriode();
$error = '';
$success = '';

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

// Proses Update Status Manual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    if (!csrfVerify($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $kegiatan_id = (int)$_POST['kegiatan_id'];
        $new_status = $_POST['status'];
        if (in_array($new_status, ['persiapan', 'berjalan', 'selesai'])) {
            dbQuery("UPDATE kegiatan SET status = ? WHERE id = ? AND periode_id = ?", [$new_status, $kegiatan_id, $periode_id]);
            $success = "Status kegiatan berhasil diperbarui secara manual.";
        }
    }
}

// Proses Update Status Otomatis (Hanya Maju Ke Depan)
// 1. Jika hari ini >= tanggal mulai dan masih persiapan, ubah ke berjalan
dbQuery("UPDATE kegiatan SET status = 'berjalan' WHERE tanggal_mulai IS NOT NULL AND CURRENT_DATE() >= tanggal_mulai AND (tanggal_selesai IS NULL OR CURRENT_DATE() <= tanggal_selesai) AND status = 'persiapan'");
// 2. Jika hari ini > tanggal selesai, ubah ke selesai (meskipun masih persiapan atau berjalan)
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
        $ketuplat_id = !empty($_POST['ketuplat_id']) ? (int)$_POST['ketuplat_id'] : null;

        if (empty($nama)) {
            $error = "Nama kegiatan wajib diisi.";
        } else {
            try {
                dbBeginTransaction();
                
                if ($_POST['action'] === 'add') {
                    dbQuery("INSERT INTO kegiatan (periode_id, nama_kegiatan, kode_kegiatan, deskripsi, tanggal_mulai, tanggal_selesai, waktu_pelaksanaan, tempat_pelaksanaan, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                        $periode_id, $nama, $kode_kegiatan, $deskripsi, $tgl_mulai, $tgl_selesai, $waktu, $tempat, $_SESSION['admin_id']
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
                    dbQuery("UPDATE kegiatan SET nama_kegiatan = ?, kode_kegiatan = ?, deskripsi = ?, tanggal_mulai = ?, tanggal_selesai = ?, waktu_pelaksanaan = ?, tempat_pelaksanaan = ? WHERE id = ? AND periode_id = ?", [
                        $nama, $kode_kegiatan, $deskripsi, $tgl_mulai, $tgl_selesai, $waktu, $tempat, $edit_id, $periode_id
                    ]);
                    
                    // Update ketuplat
                    dbQuery("DELETE FROM kegiatan_panitia WHERE kegiatan_id = ? AND event_role = 'ketuplat'", [$edit_id]);
                    if ($ketuplat_id) {
                        dbQuery("INSERT INTO kegiatan_panitia (kegiatan_id, user_id, event_role, ditunjuk_oleh) VALUES (?, ?, 'ketuplat', ?)", [
                            $edit_id, $ketuplat_id, $_SESSION['admin_id']
                        ]);
                    }
                    $success = "Kegiatan berhasil diperbarui.";
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
    --card-bg: rgba(15, 18, 23, 0.95);
    --border-color: #2a3545;
    --accent-color: #4A90E2;
}
.page-header {
    background: var(--card-bg);
    padding: 25px 30px;
    border-radius: 20px;
    border: 1px solid var(--border-color);
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    backdrop-filter: blur(10px);
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.page-header h1 { margin: 0 0 5px 0; font-size: 1.8rem; color: #fff; }
.page-header p { margin: 0; color: #888; font-size: 0.95rem; }

.table-premium {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 10px;
}
.table-premium th {
    padding: 15px 20px;
    text-transform: uppercase;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 1px;
    color: #888;
    text-align: left;
    border-bottom: 1px solid var(--border-color);
}
.table-premium td {
    padding: 16px 20px;
    background: rgba(255,255,255,0.02);
    border-top: 1px solid var(--border-color);
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}
.table-premium td:first-child {
    border-left: 1px solid var(--border-color);
    border-radius: 12px 0 0 12px;
}
.table-premium td:last-child {
    border-right: 1px solid var(--border-color);
    border-radius: 0 12px 12px 0;
}
.table-premium tr:hover td {
    background: rgba(74, 144, 226, 0.05);
}
.badge-custom {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
    background: rgba(103, 58, 183, 0.15); 
    color: #b388ff; 
    border: 1px solid rgba(103, 58, 183, 0.3);
}
/* Drum Time Picker UI */
.wakpel-card { background: rgba(0,0,0,0.2); border-radius: 20px; padding: 20px; border: 1px solid var(--border-color); margin-bottom: 20px; }
.wakpel-card-label { font-size: 0.75rem; color: #5a8fc4; text-transform: uppercase; font-weight: 700; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
.preview-bar { background: rgba(74,144,226,0.08); border-radius: 12px; padding: 12px 16px; font-size: 0.85rem; margin-top: 15px; color: #8BB9F0; border-left: 4px solid var(--accent-color); }
.drum-col { width: 58px; height: 168px; background: #080808; border-radius: 12px; overflow: hidden; position: relative; cursor: ns-resize; border: 1px solid #222; }
.drum-inner { position: absolute; top: 0; left: 0; width: 100%; transition: transform 0.2s cubic-bezier(0.1, 0.7, 1.0, 0.1); will-change: transform; padding: 4px 0; }
.drum-item { height: 40px; line-height: 40px; text-align: center; font-size: 1.1rem; color: #444; transition: all 0.2s; opacity: 0.3; filter: blur(1px); }
.drum-item.sel { color: #fff; font-weight: 700; opacity: 1; transform: scale(1.1); filter: blur(0); }
.drum-item.near1 { opacity: 0.6; filter: blur(0.5px); }
.drum-item.near2 { opacity: 0.3; filter: blur(1px); }
.drum-highlight { position: absolute; top: 64px; left: 4px; right: 4px; height: 40px; background: rgba(74, 144, 226, 0.15); border-radius: 8px; border: 1px solid rgba(74, 144, 226, 0.3); pointer-events: none; z-index: 5; }
.drum-group { display: flex; align-items: center; gap: 8px; }
.drum-arrow { background: #1a1a1a; border: 1px solid #333; color: #777; font-size: 0.8rem; cursor: pointer; padding: 4px 10px; border-radius: 8px; transition: all 0.2s; display: block; width: 100%; }
.drum-arrow-up { margin-bottom: 5px; }
.drum-arrow-down { margin-top: 5px; }
.drum-arrow:hover { background: #333; color: #fff; }
.drum-time-label { font-size: 0.7rem; color: #555; text-transform: uppercase; margin-bottom: 8px; font-weight: 700; text-align: center; }
.drum-groups-wrap { display: flex; gap: 20px; align-items: flex-start; margin-top: 15px; flex-wrap: wrap; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 12px; }
.drum-colon { color: var(--accent-color); font-weight: 700; font-size: 1.2rem; padding-top: 24px; padding-left: 5px; padding-right: 5px; }
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-calendar-check" style="color: var(--accent-color);"></i> Manajemen Kegiatan</h1>
        <p>Mengelola daftar program kerja dan menunjuk Ketua Pelaksana (Ketuplat).</p>
    </div>
</div>

<?php if ($error) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> $error</div>"; ?>
<?php if ($success) echo "<div class='alert alert-success'><i class='fas fa-check-circle'></i> $success</div>"; ?>

<div class="dashboard-content-grid" style="display: grid; grid-template-columns: 1fr 2fr; gap: 25px; align-items: start;">
    <!-- Form Tambah -->
    <div class="card">
        <div class="card-header">
            <h3><?php echo $edit_data ? 'Edit Kegiatan' : 'Buat Kegiatan Baru'; ?></h3>
        </div>
        <div class="card-body">
            <form action="master-kegiatan.php" method="POST">
                <input type="hidden" name="action" value="<?php echo $edit_data ? 'edit' : 'add'; ?>">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="edit_id" value="<?php echo $edit_data['id']; ?>">
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                
                <div class="form-group">
                    <label>Nama Kegiatan <span style="color:red;">*</span></label>
                    <input type="text" name="nama_kegiatan" class="form-control" required placeholder="Contoh: Pengenalan kehidupan kampus mahasiswa baru" value="<?php echo htmlspecialchars($edit_data['nama_kegiatan'] ?? ''); ?>">
                </div>

                <div class="form-group">
                    <label>Kode Kegiatan <small style="color: #4facfe;">(Singkatan untuk Nomor Surat, misal: PKKMB, MUBES. Jika 2 suku kata maka ditulis biasa, misal STUDIBANDING. Jika 3 suku kata maka diambil yang paling dominan, misal Santunan Anak Yatim jadi SANTUNAN)</small></label>
                    <div class="tpl-picker" id="picker-kode-kegiatan">
                        <i class="fas fa-search tpl-search-icon"></i>
                        <input type="text" id="input_kode_kegiatan" name="kode_kegiatan" class="tpl-search-input form-control" placeholder="Cari atau ketik kode kegiatan..." value="<?php echo htmlspecialchars($edit_data['kode_kegiatan'] ?? ''); ?>" autocomplete="off" onfocus="showTplResults('kode-kegiatan')" onkeyup="filterTpl('kode-kegiatan')" style="text-transform: uppercase; font-weight: 700; letter-spacing: 1px; color: #4facfe;">
                        <div class="tpl-results" id="results-kode-kegiatan">
                            <?php foreach($list_kode_kegiatan as $kk): ?>
                            <div class="tpl-item" onclick='selectKodeKegiatan(<?php echo json_encode(strtoupper($kk["label"])); ?>)'>
                                <div class="tpl-item-label"><?php echo htmlspecialchars(strtoupper($kk['label'])); ?></div>
                                <div class="tpl-item-text" style="color:#4facfe; font-size:0.75rem;">Kode: <?php echo htmlspecialchars($kk['perihal_default']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Tema Kegiatan</label>
                    <textarea name="deskripsi" class="form-control" rows="3" placeholder="Masukkan tema kegiatan..."><?php echo htmlspecialchars($edit_data['deskripsi'] ?? ''); ?></textarea>
                </div>
                
                <div class="wakpel-card">
                    <div class="wakpel-card-label"><i class="fas fa-calendar-alt"></i> Waktu & Tempat Pelaksanaan</div>
                    
                    <div class="form-group" style="margin-bottom:15px;">
                        <label>Hari & Tanggal</label>
                        <div class="date-range-wrap" style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <input type="date" name="tanggal_mulai" id="tgl-mulai" class="form-control" onchange="formatTanggalRange()" style="width: auto;" required value="<?php echo htmlspecialchars($edit_data['tanggal_mulai'] ?? ''); ?>">
                            <span style="color:#888; font-size: 0.8rem;">selama</span>
                            <div style="display:flex; gap:5px; align-items:center;">
                                <select id="durasi-hari" onchange="handleDurasiChange()" class="form-control" style="width:auto; padding: 8px 12px; cursor: pointer;">
                                    <option value="1">1 Hari</option>
                                    <option value="2">2 Hari</option>
                                    <option value="3">3 Hari</option>
                                    <option value="4">4 Hari</option>
                                    <option value="5">5 Hari</option>
                                    <option value="custom">Custom...</option>
                                </select>
                                <input type="number" id="custom-hari" min="1" value="1" oninput="formatTanggalRange()" class="form-control" style="display:none; width: 70px;">
                                <span id="label-hari" style="color:#888; font-size: 0.8rem; display:none;">Hari</span>
                            </div>
                        </div>
                        <input type="hidden" name="tanggal_selesai" id="out-tanggal-selesai" value="<?php echo htmlspecialchars($edit_data['tanggal_selesai'] ?? ''); ?>">
                        <div class="preview-bar" id="preview-tanggal">—belum dipilih—</div>
                    </div>

                    <div class="form-group" style="margin-bottom:15px;">
                        <label>Waktu Pelaksanaan</label>
                        <input type="hidden" id="out-waktu" name="waktu_pelaksanaan" value="<?php echo htmlspecialchars($edit_data['waktu_pelaksanaan'] ?? ''); ?>">
                        <div class="drum-groups-wrap">
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
                            <div style="color:#888; font-size:0.8rem; padding-top: 24px;">s.d</div>
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
                            <div style="padding-top: 24px;">
                                <div class="toggle-switch-wrap" id="toggle-selesai-wrap" onclick="doToggleSelesai()" style="background: rgba(255,255,255,0.05); padding: 10px 14px; border-radius: 12px; border: 1px solid var(--border-color); cursor: pointer; display: flex; align-items: center; gap: 10px;">
                                    <div class="toggle-switch" id="ts-switch" style="position:relative; width:36px; height:20px; background:#222; border-radius:10px; transition: .3s;"><div class="toggle-knob" style="position:absolute; top:2px; left:2px; width:16px; height:16px; background:#fff; border-radius:50%; transition:.3s;"></div></div>
                                    <span class="toggle-label" id="ts-label" style="font-size:0.75rem; color:#888;">Tanpa waktu akhir</span>
                                </div>
                            </div>
                        </div>
                        <div class="preview-bar" id="preview-waktu" style="display:none;"><?php echo htmlspecialchars($edit_data['waktu_pelaksanaan'] ?? ''); ?></div>
                    </div>

                    <div class="form-group">
                        <label>Tempat Pelaksanaan</label>
                        <div class="tpl-picker" id="picker-tempat">
                            <i class="fas fa-search tpl-search-icon"></i>
                            <input type="text" id="input_tempat" name="tempat_pelaksanaan" class="tpl-search-input form-control" placeholder="Cari atau ketik tempat..." value="<?php echo htmlspecialchars($edit_data['tempat_pelaksanaan'] ?? ''); ?>" required autocomplete="off" onfocus="showTplResults('tempat')" onkeyup="filterTpl('tempat')">
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
                
                <div class="form-group">
                    <label>Tunjuk Ketua Pelaksana (Opsional)</label>
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
                        <input type="text" id="input_ketuplat_display" class="tpl-search-input form-control" placeholder="Cari atau pilih anggota..." value="<?php echo htmlspecialchars($ketuplat_nama); ?>" autocomplete="off" onfocus="showTplResults('ketuplat')" onkeyup="filterTpl('ketuplat')">
                        <input type="hidden" id="input_ketuplat_id" name="ketuplat_id" value="<?php echo $edit_data['ketuplat_id'] ?? ''; ?>">
                        <div class="tpl-results" id="results-ketuplat">
                            <div class="tpl-item" onclick='selectTplAnggota("", "")'>
                                <div class="tpl-item-label" style="color:#aaa;">-- Kosongkan (Batal Pilih) --</div>
                            </div>
                            <?php foreach($list_anggota as $anggota): ?>
                            <div class="tpl-item" onclick='selectTplAnggota(<?php echo json_encode($anggota["id"]); ?>, <?php echo json_encode($anggota["nama"]); ?>)'>
                                <div class="tpl-item-label"><?php echo htmlspecialchars($anggota['nama']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <small style="color: #888;">Anggota yang ditunjuk akan otomatis memiliki hak akses Workspace Kegiatan ini.</small>
                </div>
                
                <?php if ($edit_data): ?>
                    <div style="display:flex; gap:10px;">
                        <button type="submit" class="btn btn-primary" style="flex:1;"><i class="fas fa-save"></i> Simpan Perubahan</button>
                        <a href="master-kegiatan.php" class="btn btn-outline" style="flex:1; text-align:center; padding-top:12px;">Batal</a>
                    </div>
                <?php else: ?>
                    <button type="submit" class="btn btn-primary" style="width: 100%;"><i class="fas fa-save"></i> Buat Kegiatan</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
    
    <!-- Tabel Daftar Kegiatan -->
    <div class="card">
        <div class="card-header">
            <h3>Daftar Kegiatan</h3>
        </div>
        <div class="card-body" style="padding: 15px;">
            <div class="table-responsive">
                <table class="table-premium">
                    <thead>
                    <tr>
                        <th>Kegiatan</th>
                        <th>Jadwal</th>
                        <th>Ketua Pelaksana</th>
                        <th width="100">Status</th>
                        <th width="80">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($list_kegiatan)): ?>
                        <tr><td colspan="5" style="text-align:center;">Belum ada kegiatan untuk periode ini.</td></tr>
                    <?php else: ?>
                        <?php foreach($list_kegiatan as $k): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($k['nama_kegiatan']); ?></strong>
                                <?php if (!empty($k['kode_kegiatan'])): ?>
                                    <span style="background: rgba(74, 144, 226, 0.15); color: #4facfe; border: 1px solid rgba(74, 144, 226, 0.3); font-size: 0.7rem; padding: 2px 8px; border-radius: 6px; font-weight: 700; margin-left: 6px;">
                                        <?php echo htmlspecialchars($k['kode_kegiatan']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if($k['deskripsi']): ?>
                                <div style="font-size:0.8rem; color:#888; margin-top:4px;"><?php echo htmlspecialchars($k['deskripsi']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                    if ($k['tanggal_mulai'] && $k['tanggal_selesai']) {
                                        echo date('d/m/Y', strtotime($k['tanggal_mulai'])) . ' - ' . date('d/m/Y', strtotime($k['tanggal_selesai']));
                                    } elseif ($k['tanggal_mulai']) {
                                        echo date('d/m/Y', strtotime($k['tanggal_mulai']));
                                    } else {
                                        echo '-';
                                    }
                                ?>
                            </td>
                            <td>
                                <?php if ($k['nama_ketuplat']): ?>
                                    <span class="badge-custom"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($k['nama_ketuplat']); ?></span>
                                <?php else: ?>
                                    <span style="color:#666; font-size:0.85rem;"><i class="fas fa-exclamation-circle" style="color: #f39c12;"></i> <em>Belum Ditunjuk</em></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $s_bg = $k['status'] === 'selesai' ? '#2ecc71' : ($k['status'] === 'berjalan' ? '#3498db' : '#f1c40f');
                                $s_color = $k['status'] === 'persiapan' ? '#333' : '#fff';
                                ?>
                                <form action="" method="POST" style="margin:0;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                                    <input type="hidden" name="kegiatan_id" value="<?php echo $k['id']; ?>">
                                    <select name="status" onchange="this.form.submit()" style="
                                        background: <?php echo $s_bg; ?>; 
                                        color: <?php echo $s_color; ?>; 
                                        border: none; 
                                        padding: 5px 10px; 
                                        border-radius: 12px; 
                                        font-weight: 600; 
                                        font-size: 0.8rem;
                                        cursor: pointer;
                                        outline: none;
                                        appearance: none;
                                        -webkit-appearance: none;
                                    ">
                                        <option value="persiapan" <?php echo $k['status'] === 'persiapan' ? 'selected' : ''; ?>>Persiapan</option>
                                        <option value="berjalan" <?php echo $k['status'] === 'berjalan' ? 'selected' : ''; ?>>Berjalan</option>
                                        <option value="selesai" <?php echo $k['status'] === 'selesai' ? 'selected' : ''; ?>>Selesai</option>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                    <a href="?edit=<?php echo $k['id']; ?>" class="btn-icon btn-primary" title="Edit"><i class="fas fa-edit"></i></a>
                                    <a href="?del=<?php echo $k['id']; ?>&token=<?php echo htmlspecialchars(csrfToken()); ?>" class="btn-icon btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus kegiatan ini beserta susunan kepanitiaannya?');" title="Hapus"><i class="fas fa-trash"></i></a>
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

<?php require_once __DIR__ . '/../core/footer.php'; ?>

<style>

/* CSS Tambahan untuk tpl-picker */
.tpl-picker { position: relative; }
.tpl-search-input { padding-left: 44px !important; }
.tpl-search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--accent-color); font-size: 1rem; pointer-events: none; z-index: 5; }
.tpl-results { position: absolute; top: calc(100% + 8px); left: 0; right: 0; background: #121822; border: 1px solid var(--border-color); border-radius: 16px; max-height: 250px; overflow-y: auto; z-index: 1000; box-shadow: 0 10px 30px rgba(0,0,0,0.5); display: none; padding: 8px; }
.tpl-item { padding: 12px 16px; border-radius: 10px; cursor: pointer; transition: all 0.2s ease; border: 1px solid transparent; }
.tpl-item:hover { background: rgba(74, 144, 226, 0.1); border-color: rgba(74, 144, 226, 0.3); }
.tpl-item-label { font-weight: 600; color: #fff; margin-bottom: 4px; }
.tpl-empty { padding: 15px; text-align: center; color: #888; font-style: italic; }
</style>

<script>
// ========== Drum Picker Class ==========
class DrumPicker {
    constructor(elId, values, initVal, onChange) {
        this.el       = document.getElementById(elId);
        this.values   = values;
        this.idx      = Math.max(0, values.indexOf(initVal));
        this.onChange = onChange;
        this.ITEM     = 40;
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
    const wrap = document.getElementById('toggle-selesai-wrap');
    const lbl  = document.getElementById('ts-label');
    const end  = document.getElementById('drum-end-wrap');
    const knob = sw.querySelector('.toggle-knob');
    
    sw.style.background = on ? 'var(--accent-color)' : '#222';
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
    
    // Hitung tanggal selesai
    const d2 = new Date(d1);
    d2.setDate(d1.getDate() + (jmlHari - 1));
    
    // Format YYYY-MM-DD untuk input hidden tanggal_selesai
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

// ========== TPL Picker ==========
function _elevatePickerCard(res) {
    const card = res.closest('.card');
    if (card) card.style.zIndex = '999';
}
function _resetPickerCards() {
    document.querySelectorAll('.card').forEach(c => c.style.zIndex = '');
}
function showTplResults(type) {
    document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
    const res = document.getElementById('results-' + type);
    if(res) {
        res.style.display = 'block';
        _elevatePickerCard(res);
    }
}
function filterTpl(type) {
    const input = document.querySelector('#picker-' + type + ' .tpl-search-input');
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
    document.getElementById(targetId).value = value;
    document.getElementById('results-' + type).style.display = 'none';
    _resetPickerCards();
}
function selectTplAnggota(id, name) {
    document.getElementById('input_ketuplat_display').value = name;
    document.getElementById('input_ketuplat_id').value = id;
    document.getElementById('results-ketuplat').style.display = 'none';
    _resetPickerCards();
}
function selectKodeKegiatan(kode) {
    document.getElementById('input_kode_kegiatan').value = kode;
    document.getElementById('results-kode-kegiatan').style.display = 'none';
    _resetPickerCards();
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.tpl-picker')) {
        document.querySelectorAll('.tpl-results').forEach(el => el.style.display = 'none');
        _resetPickerCards();
    }
});
</script>
