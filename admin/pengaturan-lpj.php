<?php
// admin/pengaturan-lpj.php
$page_css = 'arsip-surat'; // Reuse existing styles
require_once __DIR__ . '/header.php';

requireSekretaris();
$periode_id = getUserPeriode();
$error = '';
$success = '';

// Process update general settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_general') {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $nama_lembaga = sanitizeText($_POST['lpj_nama_lembaga'] ?? 'BEM INSTBUNAS Majalengka');
        $font_name = sanitizeText($_POST['lpj_font_name'] ?? 'Times New Roman');
        $font_size = (int)($_POST['lpj_font_size'] ?? 12);
        $font_size_heading = (int)($_POST['lpj_font_size_heading'] ?? 14);
        $spacing = (float)($_POST['lpj_spacing'] ?? 1.5);
        $margin_top = (float)($_POST['lpj_margin_top'] ?? 3.0);
        $margin_bottom = (float)($_POST['lpj_margin_bottom'] ?? 3.0);
        $margin_left = (float)($_POST['lpj_margin_left'] ?? 4.0);
        $margin_right = (float)($_POST['lpj_margin_right'] ?? 3.0);

        dbUpsertPengaturan('lpj_nama_lembaga', $nama_lembaga);
        dbUpsertPengaturan('lpj_font_name', $font_name);
        dbUpsertPengaturan('lpj_font_size', (string)$font_size);
        dbUpsertPengaturan('lpj_font_size_heading', (string)$font_size_heading);
        dbUpsertPengaturan('lpj_spacing', (string)$spacing);
        dbUpsertPengaturan('lpj_margin_top', (string)$margin_top);
        dbUpsertPengaturan('lpj_margin_bottom', (string)$margin_bottom);
        dbUpsertPengaturan('lpj_margin_left', (string)$margin_left);
        dbUpsertPengaturan('lpj_margin_right', (string)$margin_right);

        // Budget columns
        $kolom = [
            'tanggal' => isset($_POST['kolom_tanggal']) ? '1' : '0',
            'keterangan' => isset($_POST['kolom_keterangan']) ? '1' : '0',
            'uraian' => isset($_POST['kolom_uraian']) ? '1' : '0',
            'debet' => isset($_POST['kolom_debet']) ? '1' : '0',
            'kredit' => isset($_POST['kolom_kredit']) ? '1' : '0',
            'saldo' => isset($_POST['kolom_saldo']) ? '1' : '0'
        ];
        dbUpsertPengaturan('lpj_kolom_anggaran', json_encode($kolom));

        $success = "Pengaturan LPJ berhasil diperbarui.";
    }
}

// Process update ministry visis & order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_kementerian') {
    if (!csrfVerify()) {
        $error = "Token CSRF tidak valid.";
    } else {
        $kementerian_data = $_POST['kementerian'] ?? [];
        dbBeginTransaction();
        try {
            foreach ($kementerian_data as $k_id => $data) {
                $id = (int)$k_id;
                $deskripsi = sanitizeText($data['deskripsi'] ?? '', 1000);
                $urutan = (int)($data['urutan'] ?? 0);
                dbQuery("UPDATE kementerian SET deskripsi = ?, urutan = ? WHERE id = ?", [$deskripsi, $urutan, $id], "sii");
            }
            dbCommit();
            $success = "Pengaturan kementerian berhasil diperbarui.";
        } catch (Exception $e) {
            dbRollback();
            $error = "Gagal memperbarui pengaturan kementerian: " . $e->getMessage();
        }
    }
}

// Fetch current configurations
$db_pengaturan = dbFetchAll("SELECT kunci, nilai FROM pengaturan");
$pengaturan = [];
foreach ($db_pengaturan as $p) {
    $pengaturan[$p['kunci']] = $p['nilai'];
}

$val_nama_lembaga = $pengaturan['lpj_nama_lembaga'] ?? 'BEM INSTBUNAS Majalengka';
$val_font_name = $pengaturan['lpj_font_name'] ?? 'Times New Roman';
$val_font_size = (int)($pengaturan['lpj_font_size'] ?? 12);
$val_font_size_heading = (int)($pengaturan['lpj_font_size_heading'] ?? 14);
$val_spacing = (float)($pengaturan['lpj_spacing'] ?? 1.5);
$val_margin_top = (float)($pengaturan['lpj_margin_top'] ?? 3.0);
$val_margin_bottom = (float)($pengaturan['lpj_margin_bottom'] ?? 3.0);
$val_margin_left = (float)($pengaturan['lpj_margin_left'] ?? 4.0);
$val_margin_right = (float)($pengaturan['lpj_margin_right'] ?? 3.0);

$kolom_def = ['tanggal' => '1', 'keterangan' => '1', 'uraian' => '1', 'debet' => '1', 'kredit' => '1', 'saldo' => '1'];
$val_kolom = json_decode($pengaturan['lpj_kolom_anggaran'] ?? '', true) ?: $kolom_def;

// Fetch ministries
$kementerian_list = dbFetchAll("SELECT * FROM kementerian WHERE periode_id = ? ORDER BY urutan ASC", [$periode_id], "i");
?>

<div class="page-header">
    <h1><i class="fas fa-sliders-h"></i> Pengaturan Master LPJ</h1>
    <p>Konfigurasi identitas organisasi, margin dokumen, font, kolom anggaran, dan deskripsi statis kementerian.</p>
</div>

<?php if ($error): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="upload-grid">
    <!-- Form Pengaturan Umum -->
    <div class="upload-card" style="grid-column: span 1;">
        <div class="upload-card-header"><i class="fas fa-file-alt"></i> Format & Margin Dokumen LPJ</div>
        <div class="upload-card-body">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_general">

                <div class="form-group">
                    <label>Nama Lembaga (Cover & KOP)</label>
                    <input type="text" name="lpj_nama_lembaga" class="form-control" value="<?php echo htmlspecialchars($val_nama_lembaga); ?>" required>
                </div>

                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Font Utama</label>
                        <select name="lpj_font_name" class="form-control">
                            <option value="Times New Roman" <?php echo $val_font_name === 'Times New Roman' ? 'selected' : ''; ?>>Times New Roman</option>
                            <option value="Arial" <?php echo $val_font_name === 'Arial' ? 'selected' : ''; ?>>Arial</option>
                            <option value="Calibri" <?php echo $val_font_name === 'Calibri' ? 'selected' : ''; ?>>Calibri</option>
                            <option value="Helvetica" <?php echo $val_font_name === 'Helvetica' ? 'selected' : ''; ?>>Helvetica</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Spasi Baris</label>
                        <select name="lpj_spacing" class="form-control">
                            <option value="1.0" <?php echo $val_spacing == 1.0 ? 'selected' : ''; ?>>1.0 (Single)</option>
                            <option value="1.15" <?php echo $val_spacing == 1.15 ? 'selected' : ''; ?>>1.15</option>
                            <option value="1.5" <?php echo $val_spacing == 1.5 ? 'selected' : ''; ?>>1.5 (Default)</option>
                            <option value="2.0" <?php echo $val_spacing == 2.0 ? 'selected' : ''; ?>>2.0 (Double)</option>
                        </select>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Ukuran Font (Isi)</label>
                        <input type="number" name="lpj_font_size" class="form-control" value="<?php echo $val_font_size; ?>" min="8" max="24" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Ukuran Font (Judul)</label>
                        <input type="number" name="lpj_font_size_heading" class="form-control" value="<?php echo $val_font_size_heading; ?>" min="10" max="36" required>
                    </div>
                </div>

                <h4 style="margin: 15px 0 10px 0; color: #8BB9F0; font-size: 0.95rem;"><i class="fas fa-border-all"></i> Margin Halaman (dalam CM)</h4>
                <div style="display: flex; gap: 10px;">
                    <div class="form-group" style="flex: 1;">
                        <label>Atas (Top)</label>
                        <input type="number" step="0.1" name="lpj_margin_top" class="form-control" value="<?php echo $val_margin_top; ?>" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Bawah (Bottom)</label>
                        <input type="number" step="0.1" name="lpj_margin_bottom" class="form-control" value="<?php echo $val_margin_bottom; ?>" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Kiri (Left)</label>
                        <input type="number" step="0.1" name="lpj_margin_left" class="form-control" value="<?php echo $val_margin_left; ?>" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label>Kanan (Right)</label>
                        <input type="number" step="0.1" name="lpj_margin_right" class="form-control" value="<?php echo $val_margin_right; ?>" required>
                    </div>
                </div>

                <h4 style="margin: 15px 0 10px 0; color: #8BB9F0; font-size: 0.95rem;"><i class="fas fa-table"></i> Kolom Tabel Anggaran Aktif</h4>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px;">
                    <label class="switch-container" style="padding: 10px 14px; margin-bottom: 0;">
                        <span class="switch-label">Tanggal</span>
                        <span class="switch">
                            <input type="checkbox" name="kolom_tanggal" value="1" <?php echo $val_kolom['tanggal'] === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </span>
                    </label>
                    <label class="switch-container" style="padding: 10px 14px; margin-bottom: 0;">
                        <span class="switch-label">Keterangan</span>
                        <span class="switch">
                            <input type="checkbox" name="kolom_keterangan" value="1" <?php echo $val_kolom['keterangan'] === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </span>
                    </label>
                    <label class="switch-container" style="padding: 10px 14px; margin-bottom: 0;">
                        <span class="switch-label">Uraian</span>
                        <span class="switch">
                            <input type="checkbox" name="kolom_uraian" value="1" <?php echo $val_kolom['uraian'] === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </span>
                    </label>
                    <label class="switch-container" style="padding: 10px 14px; margin-bottom: 0;">
                        <span class="switch-label">Debet</span>
                        <span class="switch">
                            <input type="checkbox" name="kolom_debet" value="1" <?php echo $val_kolom['debet'] === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </span>
                    </label>
                    <label class="switch-container" style="padding: 10px 14px; margin-bottom: 0;">
                        <span class="switch-label">Kredit</span>
                        <span class="switch">
                            <input type="checkbox" name="kolom_kredit" value="1" <?php echo $val_kolom['kredit'] === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </span>
                    </label>
                    <label class="switch-container" style="padding: 10px 14px; margin-bottom: 0;">
                        <span class="switch-label">Saldo</span>
                        <span class="switch">
                            <input type="checkbox" name="kolom_saldo" value="1" <?php echo $val_kolom['saldo'] === '1' ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </span>
                    </label>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%;"><i class="fas fa-save"></i> Simpan Format Dokumen</button>
            </form>
        </div>
    </div>

    <!-- Form Pengaturan Kementerian -->
    <div class="upload-card" style="grid-column: span 1;">
        <div class="upload-card-header"><i class="fas fa-university"></i> Deskripsi & Urutan Konsolidasi Kementerian</div>
        <div class="upload-card-body">
            <p style="font-size: 0.85rem; color: #aaa; margin-bottom: 15px;">Konfigurasi deskripsi visi/fungsi serta urutan bab kementerian saat melakukan konsolidasi LPJ Triwulan.</p>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="update_kementerian">

                <div style="max-height: 520px; overflow-y: auto; padding-right: 5px;">
                    <?php if (empty($kementerian_list)): ?>
                        <p class="text-muted" style="text-align: center; padding: 20px;">Belum ada kementerian yang terdaftar.</p>
                    <?php else: foreach ($kementerian_list as $k): ?>
                        <div style="background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.05); padding: 15px; border-radius: 10px; margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <span style="font-weight: bold; color: #fff; font-size: 0.95rem;"><?php echo htmlspecialchars($k['nama']); ?></span>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <label style="font-size: 0.75rem; color: #8BB9F0; margin-bottom: 0;">Urutan Bab:</label>
                                    <input type="number" name="kementerian[<?php echo $k['id']; ?>][urutan]" class="form-control" style="width: 65px; padding: 4px 8px; text-align: center;" value="<?php echo (int)$k['urutan']; ?>" required>
                                </div>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label style="font-size: 0.8rem; color: #aaa;">Deskripsi Visi & Fungsi (Statis/Default LPJ):</label>
                                <textarea name="kementerian[<?php echo $k['id']; ?>][deskripsi]" rows="3" class="form-control" style="font-size: 0.85rem;" placeholder="Visi, misi, dan fungsi kementerian untuk dicetak di LPJ..."><?php echo htmlspecialchars($k['deskripsi'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%; margin-top: 15px;"><i class="fas fa-save"></i> Simpan Urutan & Deskripsi</button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
