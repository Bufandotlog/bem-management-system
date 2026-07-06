<?php
// admin/buat-panitia.php
require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();

// Ambil tahun periode untuk judul
$tahun_mulai = $periode_data['tahun_mulai'] ?? date('Y');
$tahun_selesai = $periode_data['tahun_selesai'] ?? (date('Y') + 1);
$tahun_periode_str = $tahun_mulai . '/' . $tahun_selesai;

// Ambil Nama Warek III dari pengaturan
$warek_name_row = dbFetchOne("SELECT nilai FROM pengaturan WHERE kunci = 'ttd_warek_name'");
$default_warek = $warek_name_row['nilai'] ?? 'Ii Muhamad Misbah, S.Pd.I., SE., MM.';

// Ambil Ketua & Wakil Ketua BEM (BPH Inti) untuk Steering Committee (SC)
$presma_row = dbFetchOne("SELECT nama FROM struktur_bph WHERE posisi = 'ketua' AND periode_id = ?", [$periode_id], "i");
$wapresma_row = dbFetchOne("SELECT nama FROM struktur_bph WHERE posisi = 'wakil_ketua' AND periode_id = ?", [$periode_id], "i");
$presma_name = $presma_row['nama'] ?? 'Dede Anggi Muhyidin';
$wapresma_name = $wapresma_row['nama'] ?? 'Salma Sabila Rahmah';

// Ambil data anggota BPH & Kementerian untuk dropdown
$bph_inti_members = dbFetchAll("SELECT nama, jabatan, posisi FROM struktur_bph WHERE periode_id = ? AND posisi IN ('ketua', 'wakil_ketua')", [$periode_id], "i");
$bph_anggota_members = dbFetchAll("SELECT a.nama, a.jabatan, s.posisi FROM anggota_bph a JOIN struktur_bph s ON a.bph_id = s.id WHERE a.periode_id = ?", [$periode_id], "i");
$kementerian_members = dbFetchAll("SELECT a.nama, a.jabatan, k.nama as nama_kementerian FROM anggota_kementerian a JOIN kementerian k ON a.kementerian_id = k.id WHERE a.periode_id = ?", [$periode_id], "i");

// 1. Gabungkan semua anggota untuk dropdown pilihan umum (Ketua Pelaksana & Seksi)
$all_members = [];
foreach ($bph_inti_members as $m) {
    $all_members[] = ['nama' => $m['nama'], 'jabatan' => $m['jabatan'], 'group' => 'BPH Inti'];
}
foreach ($bph_anggota_members as $m) {
    $all_members[] = ['nama' => $m['nama'], 'jabatan' => $m['jabatan'], 'group' => ($m['posisi'] === 'sekretaris_umum' ? 'Sekretaris Umum' : 'Bendahara Umum')];
}
foreach ($kementerian_members as $m) {
    $all_members[] = ['nama' => $m['nama'], 'jabatan' => $m['jabatan'], 'group' => $m['nama_kementerian']];
}

// Urutkan semua anggota berdasarkan nama
usort($all_members, function($a, $b) {
    return strcmp($a['nama'], $b['nama']);
});

// 2. Filter sekretaris umum (dari BPH anggota)
$sekre_umum_candidates = [];
foreach ($bph_anggota_members as $m) {
    if ($m['posisi'] === 'sekretaris_umum') {
        $sekre_umum_candidates[] = $m['nama'];
    }
}

// 3. Filter sekretaris menteri (dari kementerian anggota, yang jabatannya mengandung kata "sekretaris" atau "sekertaris")
$sekre_menteri_candidates = [];
foreach ($kementerian_members as $m) {
    $jab_lower = strtolower($m['jabatan']);
    if (strpos($jab_lower, 'sekretaris') !== false || strpos($jab_lower, 'sekertaris') !== false) {
        $sekre_menteri_candidates[] = $m['nama'];
    }
}

// 4. Filter bendahara umum (dari BPH anggota)
$bendum_candidates = [];
foreach ($bph_anggota_members as $m) {
    if ($m['posisi'] === 'bendahara_umum') {
        $bendum_candidates[] = $m['nama'];
    }
}

// 5. Filter bendahara menteri (dari kementerian anggota, yang jabatannya mengandung kata "bendahara")
$bend_menteri_candidates = [];
foreach ($kementerian_members as $m) {
    $jab_lower = strtolower($m['jabatan']);
    if (strpos($jab_lower, 'bendahara') !== false) {
        $bend_menteri_candidates[] = $m['nama'];
    }
}

// --- INITIALIZE EDIT MODE ---
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$edit_data = null;
$panitia_json = [];

if ($edit_id > 0) {
    $edit_data = dbFetchOne("SELECT * FROM arsip_panitia WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
    if ($edit_data) {
        $panitia_json = json_decode($edit_data['panitia_json'], true) ?: [];
    }
}

// --- POST HANDLER: SAVE/UPDATE ---
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $nama_kegiatan    = trim($_POST['nama_kegiatan'] ?? '');
    $penanggung_jawab = trim($_POST['penanggung_jawab'] ?? '');
    $target_edit_id   = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;

    $sc_1 = trim($_POST['sc_1'] ?? '');
    $sc_2 = trim($_POST['sc_2'] ?? '');

    $ketua_pelaksana = trim($_POST['ketua_pelaksana'] ?? '');

    $sekretaris_1 = trim($_POST['sekretaris_1'] ?? '');
    $sekretaris_2 = trim($_POST['sekretaris_2'] ?? '');
    $sekretaris_3 = trim($_POST['sekretaris_3'] ?? '');

    $bendahara_1 = trim($_POST['bendahara_1'] ?? '');
    $bendahara_2 = trim($_POST['bendahara_2'] ?? '');
    $bendahara_3 = trim($_POST['bendahara_3'] ?? '');

    // Ambil Data Seksi-Seksi
    $seksi_nama_arr = $_POST['seksi_nama'] ?? [];
    $seksi_anggota_arr = $_POST['seksi_anggota'] ?? [];

    $seksi_seksi = [];
    foreach ($seksi_nama_arr as $sec_idx => $sec_name) {
        $sec_name = trim($sec_name);
        if ($sec_name !== '') {
            $members = [];
            if (isset($seksi_anggota_arr[$sec_idx])) {
                foreach ($seksi_anggota_arr[$sec_idx] as $member_name) {
                    $member_name = trim($member_name);
                    if ($member_name !== '') {
                        $members[] = $member_name;
                    }
                }
            }
            $seksi_seksi[] = [
                'nama_seksi' => $sec_name,
                'anggota' => $members
            ];
        }
    }

    if (empty($nama_kegiatan)) {
        $error_msg = "Nama kegiatan wajib diisi.";
    } else {
        $panitia_data = [
            'nama_kegiatan' => $nama_kegiatan,
            'penanggung_jawab' => $penanggung_jawab,
            'sc' => array_filter([$sc_1, $sc_2]),
            'ketua_pelaksana' => $ketua_pelaksana,
            'sekretaris' => array_filter([$sekretaris_1, $sekretaris_2, $sekretaris_3]),
            'bendahara' => array_filter([$bendahara_1, $bendahara_2, $bendahara_3]),
            'seksi_seksi' => $seksi_seksi
        ];

        $json_str = json_encode($panitia_data);

        try {
            if ($target_edit_id > 0) {
                dbQuery("UPDATE arsip_panitia SET nama_kegiatan = ?, panitia_json = ? WHERE id = ? AND periode_id = ?", [
                    $nama_kegiatan, $json_str, $target_edit_id, $periode_id
                ]);
                $success_msg = "Susunan panitia berhasil diperbarui.";
                $edit_id = $target_edit_id;
                $edit_data = dbFetchOne("SELECT * FROM arsip_panitia WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
                $panitia_json = $panitia_data;
            } else {
                $new_id = dbInsert("INSERT INTO arsip_panitia (nama_kegiatan, periode_id, panitia_json) VALUES (?, ?, ?)", [
                    $nama_kegiatan, $periode_id, $json_str
                ]);
                $success_msg = "Susunan panitia berhasil disimpan ke arsip.";
                $edit_id = $new_id;
                $edit_data = dbFetchOne("SELECT * FROM arsip_panitia WHERE id = ? AND periode_id = ?", [$edit_id, $periode_id], "ii");
                $panitia_json = $panitia_data;
            }
        } catch (Exception $e) {
            $error_msg = "Gagal menyimpan data: " . $e->getMessage();
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
    --accent-color: #4A90E2;
}

.panitia-creator-container {
    max-width: 1400px;
    margin: 0 auto;
    animation: fadeIn 0.5s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.panitia-grid-layout {
    display: grid;
    grid-template-columns: 1fr;
    gap: 30px;
}

@media (min-width: 1024px) {
    .panitia-grid-layout {
        grid-template-columns: 1.2fr 1fr;
    }
}

.card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    padding: 25px;
    margin-bottom: 24px;
    box-shadow: 0 15px 35px rgba(0,0,0,0.4);
    backdrop-filter: blur(15px);
}

.card-header-title {
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    color: var(--accent-color);
}

.card-header-title h2 {
    margin: 0;
    font-size: 1.3rem;
    color: #fff;
}

.form-group {
    margin-bottom: 20px;
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

.form-group input, .form-group select {
    width: 100%;
    background: var(--input-bg);
    border: 1px solid var(--border-color);
    padding: 12px 16px;
    border-radius: 12px;
    color: #fff;
    font-size: 0.95rem;
    transition: all 0.3s;
}

.form-group input:focus, .form-group select:focus {
    border-color: var(--accent-color);
    box-shadow: 0 0 15px rgba(74, 144, 226, 0.2);
    outline: none;
}

.form-row-three {
    display: grid;
    grid-template-columns: 1fr;
    gap: 15px;
}

@media (min-width: 768px) {
    .form-row-three {
        grid-template-columns: 1fr 1fr 1fr;
    }
}

/* Seksi-Seksi dynamic card */
.seksi-block {
    background: rgba(255,255,255,0.02);
    border: 1px solid rgba(255,255,255,0.05);
    padding: 20px;
    border-radius: 16px;
    margin-bottom: 20px;
    position: relative;
}

.btn-remove-seksi {
    position: absolute;
    top: 15px;
    right: 15px;
    background: rgba(231, 76, 60, 0.1);
    color: #e74c3c;
    border: 1px solid rgba(231, 76, 60, 0.2);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: bold;
    cursor: pointer;
    transition: 0.3s;
}

.btn-remove-seksi:hover {
    background: rgba(231, 76, 60, 0.2);
}

.seksi-anggota-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: center;
}

.btn-remove-anggota-seksi {
    background: rgba(231, 76, 60, 0.1);
    color: #e74c3c;
    border: none;
    border-radius: 8px;
    width: 38px;
    height: 38px;
    cursor: pointer;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: 0.3s;
}

.btn-remove-anggota-seksi:hover {
    background: rgba(231, 76, 60, 0.25);
}

.btn-add-item {
    background: rgba(74, 144, 226, 0.1);
    color: var(--accent-color);
    border: 1px dashed var(--accent-color);
    padding: 10px;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
    font-size: 0.85rem;
    transition: 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 5px;
}

.btn-add-item:hover {
    background: rgba(74, 144, 226, 0.2);
}

/* Live Preview Sheet styling */
.preview-sticky-wrapper {
    position: sticky;
    top: 30px;
}

.preview-sheet {
    background: #ffffff;
    color: #000000;
    width: 100%;
    min-height: 297mm;
    padding: 20mm 15mm;
    box-shadow: 0 10px 40px rgba(0,0,0,0.5);
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    line-height: 1.4;
    box-sizing: border-box;
    border-radius: 4px;
    overflow-x: auto;
}

.preview-header {
    text-align: center;
    margin-bottom: 25px;
    text-transform: uppercase;
}

.preview-header h1 {
    font-size: 14pt;
    font-weight: bold;
    margin: 0;
}

.preview-header h2 {
    font-size: 14pt;
    font-weight: bold;
    margin: 5px 0;
}

.preview-header h3 {
    font-size: 14pt;
    font-weight: bold;
    margin: 0;
}

.table-panitia {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

.table-panitia th, .table-panitia td {
    border: 1px solid #000000;
    padding: 8px 12px;
    vertical-align: top;
    text-align: left;
}

.table-panitia td.role-title {
    width: 35%;
    font-weight: normal;
}

.table-panitia td.role-title.italic-title {
    font-style: italic;
}

.table-panitia td.names-list {
    width: 65%;
}

.table-panitia td.names-list ol {
    margin: 0;
    padding-left: 15px;
}

.table-panitia td.names-list ol li {
    margin-bottom: 3px;
}

.table-panitia .section-heading {
    text-align: center;
    font-weight: bold;
    background-color: transparent;
    padding: 8px 12px;
}

/* Pool status widget */
.pool-card {
    background: rgba(255,255,255,0.02);
    border: 1px solid var(--border-color);
}

.pool-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
    max-height: 350px;
    overflow-y: auto;
    padding-right: 5px;
}

@media (min-width: 640px) {
    .pool-grid {
        grid-template-columns: 1fr 1fr;
    }
}

.pool-item {
    background: rgba(0,0,0,0.2);
    border: 1px solid rgba(255,255,255,0.05);
    border-radius: 10px;
    padding: 10px 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.pool-name {
    font-size: 0.85rem;
    font-weight: 600;
    color: #eee;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 150px;
}

.pool-badge {
    font-size: 0.7rem;
    font-weight: 700;
    padding: 3px 8px;
    border-radius: 6px;
}

.pool-badge.available {
    background: rgba(46, 204, 113, 0.15);
    color: #2ecc71;
    border: 1px solid rgba(46, 204, 113, 0.3);
}

.pool-badge.assigned {
    background: rgba(241, 196, 15, 0.15);
    color: #f1c40f;
    border: 1px solid rgba(241, 196, 15, 0.3);
}

.actions-sticky-bar {
    position: sticky;
    bottom: 20px;
    background: rgba(15, 18, 23, 0.9);
    backdrop-filter: blur(10px);
    padding: 20px;
    border-radius: 20px;
    border: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    align-items: center;
    box-shadow: 0 -10px 30px rgba(0,0,0,0.3);
    margin-top: 30px;
    z-index: 100;
}

.btn-gradient {
    background: var(--primary-gradient);
    border: none;
    color: #fff;
    padding: 12px 30px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
    box-shadow: 0 10px 20px rgba(79, 172, 254, 0.3);
}

.btn-gradient:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 25px rgba(79, 172, 254, 0.4);
}
</style>

<div class="panitia-creator-container">

    <?php if ($success_msg): ?>
        <div style="background: rgba(46, 204, 113, 0.1); color: #2ecc71; padding: 15px; border-radius: 12px; border: 1px solid rgba(46, 204, 113, 0.2); margin-bottom: 20px;">
            <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
            <a href="arsip-panitia.php" style="color: #00f2fe; margin-left: 10px; text-decoration: underline;">Lihat Arsip →</a>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div style="background: rgba(231, 76, 60, 0.1); color: #e74c3c; padding: 15px; border-radius: 12px; border: 1px solid rgba(231, 76, 60, 0.2); margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i> <?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <div class="page-header" style="margin-bottom: 30px;">
        <h1><i class="fas fa-users-cog"></i> Auto Generate Susunan Panitia</h1>
        <p>Generate, pratinjau, dan arsipkan susunan kepanitiaan kegiatan secara otomatis.</p>
    </div>

    <form method="POST" id="panitiaForm">
        <?php echo csrfField(); ?>
        <?php if ($edit_id > 0): ?>
            <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
        <?php endif; ?>

        <div class="panitia-grid-layout">
            <!-- LEFT PANEL: FORM INPUTS -->
            <div class="form-panel">
                
                <!-- CARD 1: INFORMASI UMUM -->
                <div class="card">
                    <div class="card-header-title">
                        <i class="fas fa-info-circle fa-lg"></i>
                        <h2>Informasi Kegiatan & Pelindung</h2>
                    </div>
                    
                    <div class="form-group">
                        <label>Nama Kegiatan / Acara</label>
                        <input type="text" name="nama_kegiatan" id="nama_kegiatan" required placeholder="Contoh: BEM CUP" value="<?php echo $edit_data ? htmlspecialchars($edit_data['nama_kegiatan']) : ''; ?>" oninput="updateLivePreview()">
                    </div>

                    <div class="form-group">
                        <label>Tahun Periode Aktif</label>
                        <input type="text" value="<?php echo $tahun_periode_str; ?>" disabled style="opacity: 0.7; background: #111;">
                    </div>

                    <div class="form-group">
                        <label>Penanggung Jawab (Warek III)</label>
                        <input type="text" name="penanggung_jawab" id="penanggung_jawab" required value="<?php echo $edit_id > 0 ? htmlspecialchars($panitia_json['penanggung_jawab'] ?? $default_warek) : $default_warek; ?>" oninput="updateLivePreview()">
                        <small style="color: #666; margin-top: 5px; display: block;">Default diambil dari Pengaturan TTD Warek III.</small>
                    </div>

                    <div class="form-row-three">
                        <div class="form-group">
                            <label>Steering Committee 1 (Ketua BEM)</label>
                            <input type="text" name="sc_1" id="sc_1" readonly value="<?php echo $presma_name; ?>" style="opacity: 0.8; background: #111;">
                        </div>
                        <div class="form-group">
                            <label>Steering Committee 2 (Wakil Ketua BEM)</label>
                            <input type="text" name="sc_2" id="sc_2" readonly value="<?php echo $wapresma_name; ?>" style="opacity: 0.8; background: #111;">
                        </div>
                    </div>
                </div>

                <!-- CARD 2: PENGURUS INTI PANITIA -->
                <div class="card">
                    <div class="card-header-title">
                        <i class="fas fa-crown fa-lg"></i>
                        <h2>Pengurus Inti Panitia</h2>
                    </div>

                    <div class="form-group">
                        <label>Ketua Pelaksana</label>
                        <select name="ketua_pelaksana" id="ketua_pelaksana" required onchange="updateLivePreview()">
                            <option value="">-- Pilih Ketua Pelaksana (dari Seluruh Anggota) --</option>
                            <?php foreach ($all_members as $m): ?>
                                <?php 
                                $selected = '';
                                if ($edit_id > 0 && ($panitia_json['ketua_pelaksana'] ?? '') === $m['nama']) {
                                    $selected = 'selected';
                                }
                                ?>
                                <option value="<?php echo htmlspecialchars($m['nama']); ?>" <?php echo $selected; ?>>
                                    <?php echo htmlspecialchars($m['nama']); ?> (<?php echo htmlspecialchars($m['jabatan']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row-three">
                        <div class="form-group">
                            <label>Sekretaris 1 (Sekum BEM 1)</label>
                            <select name="sekretaris_1" id="sekretaris_1" required onchange="updateLivePreview()">
                                <option value="">-- Pilih Sekum 1 --</option>
                                <?php foreach ($sekre_umum_candidates as $name): ?>
                                    <?php 
                                    $selected = '';
                                    if ($edit_id > 0 && isset($panitia_json['sekretaris'][0]) && $panitia_json['sekretaris'][0] === $name) {
                                        $selected = 'selected';
                                    } elseif ($edit_id == 0 && count($sekre_umum_candidates) > 0 && $sekre_umum_candidates[0] === $name) {
                                        // Auto-select first Sekum
                                        $selected = 'selected';
                                    }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Sekretaris 2 (Sekum BEM 2)</label>
                            <select name="sekretaris_2" id="sekretaris_2" required onchange="updateLivePreview()">
                                <option value="">-- Pilih Sekum 2 --</option>
                                <?php foreach ($sekre_umum_candidates as $name): ?>
                                    <?php 
                                    $selected = '';
                                    if ($edit_id > 0 && isset($panitia_json['sekretaris'][1]) && $panitia_json['sekretaris'][1] === $name) {
                                        $selected = 'selected';
                                    } elseif ($edit_id == 0 && count($sekre_umum_candidates) > 1 && $sekre_umum_candidates[1] === $name) {
                                        // Auto-select second Sekum
                                        $selected = 'selected';
                                    }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Sekretaris 3 (Sekre Menteri)</label>
                            <select name="sekretaris_3" id="sekretaris_3" required onchange="updateLivePreview()">
                                <option value="">-- Pilih Sekre Menteri --</option>
                                <?php foreach ($sekre_menteri_candidates as $name): ?>
                                    <?php 
                                    $selected = '';
                                    if ($edit_id > 0 && isset($panitia_json['sekretaris'][2]) && $panitia_json['sekretaris'][2] === $name) {
                                        $selected = 'selected';
                                    }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row-three">
                        <div class="form-group">
                            <label>Bendahara 1 (Bendum BEM 1)</label>
                            <select name="bendahara_1" id="bendahara_1" required onchange="updateLivePreview()">
                                <option value="">-- Pilih Bendum 1 --</option>
                                <?php foreach ($bendum_candidates as $name): ?>
                                    <?php 
                                    $selected = '';
                                    if ($edit_id > 0 && isset($panitia_json['bendahara'][0]) && $panitia_json['bendahara'][0] === $name) {
                                        $selected = 'selected';
                                    } elseif ($edit_id == 0 && count($bendum_candidates) > 0 && $bendum_candidates[0] === $name) {
                                        $selected = 'selected';
                                    }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Bendahara 2 (Bendum BEM 2)</label>
                            <select name="bendahara_2" id="bendahara_2" required onchange="updateLivePreview()">
                                <option value="">-- Pilih Bendum 2 --</option>
                                <?php foreach ($bendum_candidates as $name): ?>
                                    <?php 
                                    $selected = '';
                                    if ($edit_id > 0 && isset($panitia_json['bendahara'][1]) && $panitia_json['bendahara'][1] === $name) {
                                        $selected = 'selected';
                                    } elseif ($edit_id == 0 && count($bendum_candidates) > 1 && $bendum_candidates[1] === $name) {
                                        $selected = 'selected';
                                    }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Bendahara 3 (Bendahara Menteri)</label>
                            <select name="bendahara_3" id="bendahara_3" required onchange="updateLivePreview()">
                                <option value="">-- Pilih Bendahara Menteri --</option>
                                <?php foreach ($bend_menteri_candidates as $name): ?>
                                    <?php 
                                    $selected = '';
                                    if ($edit_id > 0 && isset($panitia_json['bendahara'][2]) && $panitia_json['bendahara'][2] === $name) {
                                        $selected = 'selected';
                                    }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($name); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- CARD 3: SEKSI-SEKSI -->
                <div class="card">
                    <div class="card-header-title">
                        <i class="fas fa-list-ol fa-lg"></i>
                        <h2>Seksi - Seksi Kepanitiaan</h2>
                    </div>

                    <div id="seksiContainer">
                        <!-- Dynamic Seksi blocks will be generated here -->
                    </div>

                    <button type="button" class="btn-add-item" onclick="addSeksiBlock()" style="width: 100%; justify-content: center; padding: 12px; margin-top: 10px;">
                        <i class="fas fa-plus-circle"></i> Tambah Seksi / Divisi Baru
                    </button>
                </div>
            </div>

            <!-- RIGHT PANEL: LIVE PREVIEW & POOL STATUS -->
            <div class="preview-panel">
                <div class="preview-sticky-wrapper">
                    
                    <!-- LIVE PREVIEW SHEET -->
                    <div class="card" style="padding: 10px; background: #222; border-color: #444; overflow: hidden; margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px 15px; border-bottom: 1px solid #333;">
                            <span style="font-weight: bold; color: var(--accent-color); font-size: 0.9rem;"><i class="fas fa-eye"></i> LIVE PREVIEW DOKUMEN</span>
                            <span style="font-size: 0.75rem; color: #888;">Formal A4 Portrait</span>
                        </div>
                        
                        <div style="background: #e0e0e0; padding: 15px; overflow-x: auto; display: flex; justify-content: center;">
                            <div class="preview-sheet" id="previewSheet">
                                <div class="preview-header">
                                    <h1>SUSUNAN PANITIA</h1>
                                    <h2 id="preview_kegiatan_title">NAMA KEGIATAN</h2>
                                    <h3 id="preview_periode_title">PERIODE <?php echo $tahun_periode_str; ?></h3>
                                </div>

                                <table class="table-panitia">
                                    <tbody>
                                        <tr>
                                            <td class="role-title">Penanggung Jawab</td>
                                            <td class="names-list" id="preview_pj">Ii Muhamad Misbah, S.Pd.I,SE,MM</td>
                                        </tr>
                                        <tr>
                                            <td class="role-title italic-title">Steering Committee (SC)</td>
                                            <td class="names-list">
                                                <ol id="preview_sc">
                                                    <li>Dede Anggi Muhyidin</li>
                                                    <li>Salma Sabila Rahmah</li>
                                                </ol>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="role-title">Ketua Pelaksana</td>
                                            <td class="names-list">
                                                <ol id="preview_ketua_pelaksana">
                                                    <li>-</li>
                                                </ol>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="role-title">Sekretaris</td>
                                            <td class="names-list">
                                                <ol id="preview_sekretaris">
                                                    <li>-</li>
                                                    <li>-</li>
                                                    <li>-</li>
                                                </ol>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="role-title">Bendahara</td>
                                            <td class="names-list">
                                                <ol id="preview_bendahara">
                                                    <li>-</li>
                                                    <li>-</li>
                                                    <li>-</li>
                                                </ol>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td colspan="2" class="section-heading">Seksi - Seksi</td>
                                        </tr>
                                    </tbody>
                                    <tbody id="preview_seksi_body">
                                        <!-- Dynamic seksi rows here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- MEMBER ASSIGNMENT POOL -->
                    <div class="card pool-card">
                        <div class="card-header-title" style="margin-bottom: 15px;">
                            <i class="fas fa-users-cog fa-lg"></i>
                            <h2>Pool Status & Sisa Anggota</h2>
                        </div>
                        <p style="font-size: 0.8rem; color: #888; margin-bottom: 15px; line-height: 1.3;">
                            Membantu memantau anggota yang sudah/belum ditugaskan agar tidak terjadi duplikasi tugas.
                        </p>
                        
                        <div class="pool-grid" id="poolGrid">
                            <!-- Members pool populated by JS -->
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- BOTTOM STICKY BAR ACTIONS -->
        <div class="actions-sticky-bar">
            <div style="display: flex; gap: 12px; align-items: center;">
                <a href="arsip-panitia.php" style="color: #ccc; text-decoration: none; padding: 12px 20px; border-radius: 12px; font-weight: 600; border: 1px solid var(--border-color); transition: 0.3s;">
                    <i class="fas fa-arrow-left"></i> Kembali ke Arsip
                </a>
                <button type="submit" class="btn-gradient" id="btnSubmit">
                    <i class="fas fa-save"></i> <?php echo $edit_id > 0 ? 'Perbarui Susunan' : 'Simpan ke Arsip'; ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
// Data Anggota dari PHP ke JS
const listAnggotaBEM = <?php echo json_encode($all_members); ?>;
const defaultSeksiData = <?php echo $edit_id > 0 ? json_encode($panitia_json['seksi_seksi'] ?? []) : '[]'; ?>;

let seksiCounter = 0;

// Menambahkan Blok Seksi Baru
function addSeksiBlock(seksiName = '', anggotaList = []) {
    seksiCounter++;
    const container = document.getElementById('seksiContainer');
    
    const block = document.createElement('div');
    block.className = 'seksi-block';
    block.id = 'seksi-block-' + seksiCounter;
    block.dataset.index = seksiCounter;
    
    block.innerHTML = `
        <button type="button" class="btn-remove-seksi" onclick="removeSeksiBlock(${seksiCounter})">
            <i class="fas fa-trash-alt"></i> Hapus Seksi
        </button>
        
        <div class="form-group" style="padding-right: 120px;">
            <label>Nama Seksi / Divisi</label>
            <input type="text" name="seksi_nama[${seksiCounter}]" class="seksi-name-input" required placeholder="Contoh: Seksi Acara" value="${seksiName}" oninput="updateLivePreview()">
        </div>
        
        <div class="form-group" style="margin-bottom: 5px;">
            <label>Anggota Seksi</label>
            <div class="seksi-members-container" id="seksi-members-${seksiCounter}">
                <!-- Member rows will go here -->
            </div>
        </div>
        
        <button type="button" class="btn-add-item" onclick="addAnggotaToSeksi(${seksiCounter})">
            <i class="fas fa-user-plus"></i> Tambah Anggota
        </button>
    `;
    
    container.appendChild(block);
    
    // Jika ada data anggota yang diload (edit mode)
    if (anggotaList.length > 0) {
        anggotaList.forEach(name => {
            addAnggotaToSeksi(seksiCounter, name);
        });
    } else {
        // Baris anggota pertama default
        addAnggotaToSeksi(seksiCounter);
    }
    
    updateLivePreview();
}

// Menghapus Blok Seksi
function removeSeksiBlock(index) {
    const block = document.getElementById('seksi-block-' + index);
    if (block) {
        block.remove();
        updateLivePreview();
    }
}

// Menambahkan Input Anggota ke Seksi tertentu
function addAnggotaToSeksi(seksiIndex, selectedName = '') {
    const container = document.getElementById('seksi-members-' + seksiIndex);
    
    const row = document.createElement('div');
    row.className = 'seksi-anggota-row';
    
    let optionsHtml = '<option value="">-- Pilih Anggota --</option>';
    listAnggotaBEM.forEach(m => {
        const sel = m.nama === selectedName ? 'selected' : '';
        optionsHtml += `<option value="${escapeHtml(m.nama)}" ${sel}>${escapeHtml(m.nama)} (${escapeHtml(m.group)})</option>`;
    });
    
    row.innerHTML = `
        <select name="seksi_anggota[${seksiIndex}][]" class="seksi-member-select" required onchange="updateLivePreview()" style="flex: 1;">
            ${optionsHtml}
        </select>
        <button type="button" class="btn-remove-anggota-seksi" onclick="removeAnggotaRow(this)">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    container.appendChild(row);
    updateLivePreview();
}

// Menghapus baris anggota seksi
function removeAnggotaRow(btn) {
    const row = btn.closest('.seksi-anggota-row');
    if (row) {
        row.remove();
        updateLivePreview();
    }
}

// Escape HTML Helper
function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Update Live Preview & Member Pool
function updateLivePreview() {
    // 1. Nama Kegiatan & Periode
    const namaKegiatan = document.getElementById('nama_kegiatan').value || 'NAMA KEGIATAN';
    document.getElementById('preview_kegiatan_title').innerText = namaKegiatan;
    
    // 2. Penanggung Jawab
    const pjName = document.getElementById('penanggung_jawab').value || '-';
    document.getElementById('preview_pj').innerText = pjName;
    
    // 3. Steering Committee
    const sc1 = document.getElementById('sc_1').value;
    const sc2 = document.getElementById('sc_2').value;
    const scList = [];
    if (sc1) scList.push(sc1);
    if (sc2) scList.push(sc2);
    
    const previewSc = document.getElementById('preview_sc');
    previewSc.innerHTML = '';
    scList.forEach(name => {
        const li = document.createElement('li');
        li.innerText = name;
        previewSc.appendChild(li);
    });
    
    // 4. Ketua Pelaksana
    const ketuaPelaksana = document.getElementById('ketua_pelaksana').value;
    const previewKetua = document.getElementById('preview_ketua_pelaksana');
    previewKetua.innerHTML = '';
    if (ketuaPelaksana) {
        const li = document.createElement('li');
        li.innerText = ketuaPelaksana;
        previewKetua.appendChild(li);
    } else {
        const li = document.createElement('li');
        li.innerText = '-';
        previewKetua.appendChild(li);
    }
    
    // 5. Sekretaris (3 Orang)
    const sek1 = document.getElementById('sekretaris_1').value;
    const sek2 = document.getElementById('sekretaris_2').value;
    const sek3 = document.getElementById('sekretaris_3').value;
    const sekList = [sek1, sek2, sek3].filter(n => n !== '');
    
    const previewSek = document.getElementById('preview_sekretaris');
    previewSek.innerHTML = '';
    if (sekList.length > 0) {
        sekList.forEach(name => {
            const li = document.createElement('li');
            li.innerText = name;
            previewSek.appendChild(li);
        });
    } else {
        previewSek.innerHTML = '<li>-</li><li>-</li><li>-</li>';
    }
    
    // 6. Bendahara (3 Orang)
    const ben1 = document.getElementById('bendahara_1').value;
    const ben2 = document.getElementById('bendahara_2').value;
    const ben3 = document.getElementById('bendahara_3').value;
    const benList = [ben1, ben2, ben3].filter(n => n !== '');
    
    const previewBen = document.getElementById('preview_bendahara');
    previewBen.innerHTML = '';
    if (benList.length > 0) {
        benList.forEach(name => {
            const li = document.createElement('li');
            li.innerText = name;
            previewBen.appendChild(li);
        });
    } else {
        previewBen.innerHTML = '<li>-</li><li>-</li><li>-</li>';
    }
    
    // 7. Seksi-Seksi
    const previewSeksiBody = document.getElementById('preview_seksi_body');
    previewSeksiBody.innerHTML = '';
    
    // Kumpulkan penugasan untuk Widget Pool Status
    const assignedMembers = {};
    if (sc1) assignedMembers[sc1] = 'Steering Committee';
    if (sc2) assignedMembers[sc2] = 'Steering Committee';
    if (ketuaPelaksana) assignedMembers[ketuaPelaksana] = 'Ketua Pelaksana';
    if (sek1) assignedMembers[sek1] = 'Sekretaris 1';
    if (sek2) assignedMembers[sek2] = 'Sekretaris 2';
    if (sek3) assignedMembers[sek3] = 'Sekretaris 3';
    if (ben1) assignedMembers[ben1] = 'Bendahara 1';
    if (ben2) assignedMembers[ben2] = 'Bendahara 2';
    if (ben3) assignedMembers[ben3] = 'Bendahara 3';

    // Loop blocks seksi
    const seksiBlocks = document.querySelectorAll('.seksi-block');
    seksiBlocks.forEach(block => {
        const secName = block.querySelector('.seksi-name-input').value || 'Nama Seksi';
        const memberSelects = block.querySelectorAll('.seksi-member-select');
        
        const members = [];
        memberSelects.forEach(select => {
            const val = select.value;
            if (val) {
                members.push(val);
                assignedMembers[val] = secName;
            }
        });
        
        // Render baris seksi ke preview table
        const tr = document.createElement('tr');
        
        const tdTitle = document.createElement('td');
        tdTitle.className = 'role-title';
        tdTitle.innerText = secName;
        
        const tdNames = document.createElement('td');
        tdNames.className = 'names-list';
        
        if (members.length > 0) {
            const ol = document.createElement('ol');
            members.forEach(name => {
                const li = document.createElement('li');
                li.innerText = name;
                ol.appendChild(li);
            });
            tdNames.appendChild(ol);
        } else {
            tdNames.innerHTML = '<i>(Belum ada anggota)</i>';
        }
        
        tr.appendChild(tdTitle);
        tr.appendChild(tdNames);
        previewSeksiBody.appendChild(tr);
    });
    
    // 8. Update Widget Pool
    const poolGrid = document.getElementById('poolGrid');
    poolGrid.innerHTML = '';
    
    listAnggotaBEM.forEach(m => {
        const role = assignedMembers[m.nama];
        const isAssigned = !!role;
        
        const item = document.createElement('div');
        item.className = 'pool-item';
        
        item.innerHTML = `
            <div class="pool-name" title="${escapeHtml(m.nama)}">${escapeHtml(m.nama)}</div>
            <div class="pool-badge ${isAssigned ? 'assigned' : 'available'}">
                ${isAssigned ? escapeHtml(role) : 'Tersedia'}
            </div>
        `;
        
        poolGrid.appendChild(item);
    });
}

// Inisialisasi awal saat halaman diload
document.addEventListener('DOMContentLoaded', () => {
    // Load data seksi dari database jika ada
    if (defaultSeksiData.length > 0) {
        defaultSeksiData.forEach(sec => {
            addSeksiBlock(sec.nama_seksi, sec.anggota);
        });
    } else {
        // Template Seksi Default untuk kepanitiaan baru (Acara, Humas, Konsumsi)
        addSeksiBlock('Seksi Acara');
        addSeksiBlock('Seksi Humas');
        addSeksiBlock('Seksi Konsumsi');
    }
    
    updateLivePreview();
});

document.getElementById('panitiaForm').addEventListener('submit', function() {
    const btn = document.getElementById('btnSubmit');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Menyimpan...';
    btn.disabled = true;
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
