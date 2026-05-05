<?php
// admin/kepengurusan-edit.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: 3 aksi hapus foto/logo/foto-anggota pindah dari GET ke POST
//   CHANGED: sanitizeText() untuk semua input teks
//   CHANGED: Batasi max anggota 50
//   CHANGED: Error message generic (tidak bocorkan detail exception)
//   CHANGED: urlencode($posisi) di redirect URL
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/header.php';

$posisi = $_GET['posisi'] ?? '';

$posisi_valid = ['ketua', 'wakil_ketua', 'sekretaris_umum', 'bendahara_umum'];
if (!in_array($posisi, $posisi_valid)) {
    redirect('admin/kepengurusan.php', 'Posisi tidak valid', 'error');
    exit();
}

$data = dbFetchOne(
    "SELECT * FROM struktur_bph WHERE posisi = ? AND periode_id = ?",
    [$posisi, $active_periode], "si"
);

$sambutan_pembuka   = '';
$sambutan_paragraf1 = '';
$sambutan_paragraf2 = '';

if ($data && $posisi === 'ketua' && !empty($data['deskripsi'])) {
    $parts = explode('|', $data['deskripsi']);
    if (count($parts) >= 1) $sambutan_pembuka   = $parts[0];
    if (count($parts) >= 2) $sambutan_paragraf1 = $parts[1];
    if (count($parts) >= 3) $sambutan_paragraf2 = $parts[2];
}

// ============================================
// AKSI POST: Hapus foto / logo / foto anggota
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_hapus'])) {
    if (!csrfVerify()) {
        redirect('admin/kepengurusan-edit.php?posisi=' . urlencode($posisi), 'Request tidak valid.', 'error');
        exit();
    }

    $action = $_POST['action_hapus'];

    if ($action === 'hapus_foto' && $data && !empty($data['foto'])) {
        deleteFile($data['foto']);
        dbQuery("UPDATE struktur_bph SET foto = NULL WHERE id = ? AND periode_id = ?",
                [$data['id'], $active_periode], "ii");
        redirect('admin/kepengurusan-edit.php?posisi=' . urlencode($posisi), 'Foto berhasil dihapus', 'success');
        exit();
    }

    if ($action === 'hapus_logo' && $data && !empty($data['logo'])) {
        deleteFile($data['logo']);
        dbQuery("UPDATE struktur_bph SET logo = NULL WHERE id = ? AND periode_id = ?",
                [$data['id'], $active_periode], "ii");
        redirect('admin/kepengurusan-edit.php?posisi=' . urlencode($posisi), 'Logo berhasil dihapus', 'success');
        exit();
    }

    if ($action === 'hapus_foto_anggota' && isset($_POST['anggota_id'])) {
        $anggota_id_hapus = (int) $_POST['anggota_id'];
        $anggota_hapus = dbFetchOne(
            "SELECT foto FROM anggota_bph WHERE id = ? AND periode_id = ?",
            [$anggota_id_hapus, $active_periode], "ii"
        );
        if ($anggota_hapus && !empty($anggota_hapus['foto'])) {
            deleteFile($anggota_hapus['foto']);
            dbQuery("UPDATE anggota_bph SET foto = NULL WHERE id = ? AND periode_id = ?",
                    [$anggota_id_hapus, $active_periode], "ii");
        }
        redirect('admin/kepengurusan-edit.php?posisi=' . urlencode($posisi), 'Foto anggota berhasil dihapus', 'success');
        exit();
    }
}

// ============================================
// PROSES SUBMIT FORM UTAMA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action_hapus'])) {

    if (!csrfVerify()) {
        redirect('admin/kepengurusan-edit.php?posisi=' . urlencode($posisi), 'Request tidak valid.', 'error');
        exit();
    }

    $nama    = sanitizeText($_POST['nama']    ?? '', 100);
    $jabatan = sanitizeText($_POST['jabatan'] ?? '', 100);
    $foto    = $data['foto'] ?? '';
    $logo    = $data['logo'] ?? '';

    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $upload_foto = uploadFile($_FILES['foto'], 'struktur');
        if ($upload_foto) {
            if (!empty($foto)) deleteFile($foto);
            $foto = $upload_foto;
        }
    }

    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $upload_logo = uploadFile($_FILES['logo'], 'struktur');
        if ($upload_logo) {
            if (!empty($logo)) deleteFile($logo);
            $logo = $upload_logo;
        }
    }

    if ($posisi === 'ketua') {
        $sambutan_pembuka   = sanitizeText($_POST['sambutan_pembuka']   ?? '', 200);
        $sambutan_paragraf1 = sanitizeText($_POST['sambutan_paragraf1'] ?? '', 2000);
        $sambutan_paragraf2 = sanitizeText($_POST['sambutan_paragraf2'] ?? '', 2000);
        $deskripsi = $sambutan_pembuka . '|' . $sambutan_paragraf1 . '|' . $sambutan_paragraf2;
    } else {
        $deskripsi = sanitizeText($_POST['deskripsi'] ?? '', 1000);
    }

    $tugas = array_values(array_filter(
        array_map(fn($v) => sanitizeText($v, 200), $_POST['tugas'] ?? []),
        fn($v) => !empty($v)
    ));
    $proker = array_values(array_filter(
        array_map(fn($v) => sanitizeText($v, 200), $_POST['proker'] ?? []),
        fn($v) => !empty($v)
    ));

    $tugas_json  = !empty($tugas)  ? json_encode($tugas)  : null;
    $proker_json = !empty($proker) ? json_encode($proker) : null;

    dbBeginTransaction();

    try {
        if ($data) {
            dbQuery(
                "UPDATE struktur_bph SET nama=?, jabatan=?, foto=?, logo=?, deskripsi=?, tugas=?, proker=?
                 WHERE posisi=? AND periode_id=?",
                [$nama, $jabatan, $foto, $logo, $deskripsi, $tugas_json, $proker_json, $posisi, $active_periode],
                "ssssssssi"
            );
            $bph_id = $data['id'];
        } else {
            dbQuery(
                "INSERT INTO struktur_bph (periode_id, created_by, posisi, nama, jabatan, foto, logo, deskripsi, tugas, proker)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$active_periode, $_SESSION['admin_id'], $posisi, $nama, $jabatan, $foto, $logo, $deskripsi, $tugas_json, $proker_json],
                "iissssssss"
            );
            $bph_id = dbLastId();
        }

        if (in_array($posisi, ['sekretaris_umum', 'bendahara_umum']) && isset($_POST['anggota_nama'])) {
            $existing_raw   = dbFetchAll("SELECT id, foto FROM anggota_bph WHERE bph_id=? AND periode_id=?",
                                         [$bph_id, $active_periode], "ii");
            $existing_fotos = array_column($existing_raw, 'foto', 'id');
            $existing_ids   = array_keys($existing_fotos);
            $processed_ids  = [];

            // Batasi max 50 anggota
            $anggota_names = array_slice($_POST['anggota_nama'] ?? [], 0, 50);

            foreach ($anggota_names as $index => $nama_anggota) {
                $nama_anggota = sanitizeText($nama_anggota, 100);
                if (empty($nama_anggota)) continue;

                $jabatan_anggota = sanitizeText($_POST['anggota_jabatan'][$index] ?? '', 100);
                $anggota_id      = (int) ($_POST['anggota_id'][$index] ?? 0);
                $foto_anggota    = ($anggota_id > 0 && isset($existing_fotos[$anggota_id]))
                                   ? $existing_fotos[$anggota_id] : '';

                $ada_upload = isset($_FILES['anggota_foto']['name'][$index])
                           && $_FILES['anggota_foto']['error'][$index] === UPLOAD_ERR_OK;

                if ($ada_upload) {
                    $file = [
                        'name'     => $_FILES['anggota_foto']['name'][$index],
                        'type'     => $_FILES['anggota_foto']['type'][$index],
                        'tmp_name' => $_FILES['anggota_foto']['tmp_name'][$index],
                        'error'    => $_FILES['anggota_foto']['error'][$index],
                        'size'     => $_FILES['anggota_foto']['size'][$index],
                    ];
                    $upload_result = uploadFile($file, 'struktur');
                    if ($upload_result) {
                        if (!empty($foto_anggota)) deleteFile($foto_anggota);
                        $foto_anggota = $upload_result;
                    }
                }

                if ($anggota_id > 0) {
                    dbQuery("UPDATE anggota_bph SET nama=?, jabatan=?, foto=?, urutan=? WHERE id=? AND periode_id=?",
                            [$nama_anggota, $jabatan_anggota, $foto_anggota, $index, $anggota_id, $active_periode],
                            "sssiii");
                    $processed_ids[] = $anggota_id;
                } else {
                    dbQuery("INSERT INTO anggota_bph (periode_id, created_by, bph_id, nama, jabatan, foto, urutan) VALUES (?,?,?,?,?,?,?)",
                            [$active_periode, $_SESSION['admin_id'], $bph_id, $nama_anggota, $jabatan_anggota, $foto_anggota, $index],
                            "iiisssi");
                    $processed_ids[] = dbLastId();
                }
            }

            foreach (array_diff($existing_ids, $processed_ids) as $delete_id) {
                if (!empty($existing_fotos[$delete_id])) deleteFile($existing_fotos[$delete_id]);
                dbQuery("DELETE FROM anggota_bph WHERE id=? AND periode_id=?",
                        [$delete_id, $active_periode], "ii");
            }
        }

        dbCommit();
        auditLog('UPDATE', 'struktur_bph', $bph_id ?? null, 'Edit kepengurusan: ' . $judul[$posisi]);
        redirect('admin/kepengurusan.php', 'Data berhasil disimpan!', 'success');

    } catch (Exception $e) {
        dbRollback();
        error_log("[KEPENGURUSAN-EDIT] " . $e->getMessage());
        redirect('admin/kepengurusan-edit.php?posisi=' . urlencode($posisi),
                 'Gagal menyimpan data. Silakan coba lagi.', 'error');
    }
}

// Persiapan data tampilan
$tugas_list  = [];
$proker_list = [];
if ($data) {
    if (!empty($data['tugas']))  $tugas_list  = json_decode($data['tugas'],  true) ?: [];
    if (!empty($data['proker'])) $proker_list = json_decode($data['proker'], true) ?: [];
}

$anggota_list = [];
if (in_array($posisi, ['sekretaris_umum', 'bendahara_umum']) && $data) {
    $anggota_list = dbFetchAll("SELECT * FROM anggota_bph WHERE bph_id=? AND periode_id=? ORDER BY urutan",
                               [$data['id'], $active_periode], "ii");
}

$icon_map = ['ketua'=>'crown','wakil_ketua'=>'user-tie','sekretaris_umum'=>'file-alt','bendahara_umum'=>'coins'];
$judul    = ['ketua'=>'Ketua BEM','wakil_ketua'=>'Wakil Ketua BEM','sekretaris_umum'=>'Sekretaris Umum','bendahara_umum'=>'Bendahara Umum'];
$icon         = $icon_map[$posisi];
$periode_info = ($periode_data['nama'] ?? 'Astawidya')
              . ' (' . ($periode_data['tahun_mulai'] ?? '2025')
              . '/' . ($periode_data['tahun_selesai'] ?? '2026') . ')';

// Form hapus foto/logo — di luar form utama agar tidak nested
$posisiEncoded = urlencode($posisi);
?>

<!-- ===== PAGE HEADER ===== -->
<div class="page-header">
    <h1><i class="fas fa-<?php echo $icon; ?>"></i> Edit <?php echo $judul[$posisi]; ?></h1>
    <p>Kelola data <?php echo $judul[$posisi]; ?></p>
    <span class="periode-badge">
        <i class="fas fa-calendar-alt"></i>
        Periode: <?php echo htmlspecialchars($periode_info); ?>
    </span>
</div>

<?php flashMessage(); ?>

<?php /* Form hapus foto/logo — di LUAR form utama agar tidak nested */ ?>
<?php if ($data && !empty($data['foto'])): ?>
<form method="POST" id="formHapusFoto"
      onsubmit="return confirm('Yakin ingin menghapus foto ini?')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_hapus" value="hapus_foto">
</form>
<?php endif; ?>

<?php if ($data && !empty($data['logo'])): ?>
<form method="POST" id="formHapusLogo"
      onsubmit="return confirm('Yakin ingin menghapus logo ini?')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_hapus" value="hapus_logo">
</form>
<?php endif; ?>

<?php if (!$data): ?>
    <div class="info-bar">
        <i class="fas fa-info-circle"></i>
        Mode: Menambahkan data baru untuk <?php echo $judul[$posisi]; ?>
    </div>
<?php else: ?>
    <div class="delete-row">
        <?php /* Hapus BPH via POST + CSRF — bukan GET link */ ?>
        <form method="POST" action="kepengurusan-hapus.php" style="display:inline"
              onsubmit="return confirm('Yakin ingin menghapus <?php echo htmlspecialchars(addslashes($judul[$posisi])); ?>? Semua data terkait juga akan dihapus.')">
            <?php echo csrfField(); ?>
            <input type="hidden" name="posisi" value="<?php echo htmlspecialchars($posisi); ?>">
            <button type="submit" class="btn-delete">
                <i class="fas fa-trash"></i> Hapus <?php echo $judul[$posisi]; ?>
            </button>
        </form>
    </div>
<?php endif; ?>

<!-- ===== FORM UTAMA ===== -->
<form method="POST" enctype="multipart/form-data" class="admin-form" id="bphForm">

    <?php echo csrfField(); ?>

    <!-- Informasi Dasar -->
    <div class="form-section">
        <h2><i class="fas fa-info-circle"></i> Informasi Dasar</h2>
        <div class="form-group">
            <label><?php echo in_array($posisi, ['sekretaris_umum', 'bendahara_umum']) ? 'Nama Departemen' : 'Nama Lengkap'; ?></label>
            <input type="text" name="nama"
                   value="<?php echo htmlspecialchars($data['nama'] ?? ''); ?>"
                   placeholder="Masukkan nama..." required>
        </div>
        <div class="form-group">
            <label>Jabatan</label>
            <input type="text" name="jabatan"
                   value="<?php echo htmlspecialchars($data['jabatan'] ?? $judul[$posisi]); ?>"
                   placeholder="Masukkan jabatan..." required>
        </div>
        <?php if (in_array($posisi, ['sekretaris_umum', 'bendahara_umum'])): ?>
        <div class="form-group">
            <label>Slug (URL)</label>
            <input type="text" name="slug"
                   value="<?php echo htmlspecialchars($data['slug'] ?? $posisi); ?>"
                   placeholder="contoh: sekretaris-umum" required>
            <small>Identifikasi unik untuk URL (huruf kecil, pisah dengan tanda hubung)</small>
        </div>
        <?php endif; ?>
    </div>

    <!-- Foto (ketua / wakil ketua) -->
    <?php if (in_array($posisi, ['ketua', 'wakil_ketua'])): ?>
    <div class="form-section">
        <h2><i class="fas fa-camera"></i> Foto</h2>
        <div class="form-group">
            <?php if (!empty($data['foto'])): ?>
                <div class="current-image">
                    <span class="current-image-label">Preview</span>
                    <img src="<?php echo uploadUrl($data['foto']); ?>"
                         alt="Foto <?php echo htmlspecialchars($data['nama'] ?? ''); ?>">
                    <p>Foto saat ini</p>
                    <?php /* Tombol submit ke formHapusFoto yang ada di luar */ ?>
                    <button type="submit" form="formHapusFoto" class="btn-delete-small">
                        <i class="fas fa-trash"></i> Hapus Foto
                    </button>
                </div>
            <?php endif; ?>
            <label>Upload Foto Baru</label>
            <input type="file" name="foto" accept="image/*" id="inputFoto">
            <small>Format: JPG, PNG, WebP. Maks 5MB. Kosongkan jika tidak ingin mengubah.</small>
            <div class="new-foto-preview" id="previewFotoWrap">
                <img id="previewFotoImg" alt="Preview">
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Logo (sekretaris / bendahara) -->
    <?php if (in_array($posisi, ['sekretaris_umum', 'bendahara_umum'])): ?>
    <div class="form-section">
        <h2><i class="fas fa-image"></i> Logo</h2>
        <div class="form-group">
            <?php if (!empty($data['logo'])): ?>
                <div class="current-image">
                    <span class="current-image-label">Preview</span>
                    <img src="<?php echo uploadUrl($data['logo']); ?>" alt="Logo">
                    <p>Logo saat ini</p>
                    <?php /* Tombol submit ke formHapusLogo yang ada di luar */ ?>
                    <button type="submit" form="formHapusLogo" class="btn-delete-small">
                        <i class="fas fa-trash"></i> Hapus Logo
                    </button>
                </div>
            <?php endif; ?>
            <label>Upload Logo Baru</label>
            <input type="file" name="logo" accept="image/*" id="inputLogo">
            <small>Format: JPG, PNG, WebP. Maks 5MB. Kosongkan jika tidak ingin mengubah.</small>
            <div class="new-foto-preview" id="previewLogoWrap">
                <img id="previewLogoImg" alt="Preview">
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Deskripsi / Sambutan -->
    <div class="form-section">
        <h2><i class="fas fa-align-left"></i>
            <?php echo $posisi === 'ketua' ? 'Kata Sambutan' : 'Deskripsi'; ?>
        </h2>
        <?php if ($posisi === 'ketua'): ?>
            <div class="form-group">
                <label>Pembuka</label>
                <input type="text" name="sambutan_pembuka"
                       value="<?php echo htmlspecialchars($sambutan_pembuka ?: "Assalamu'alaikum warahmatullahi wabarakatuh,"); ?>">
            </div>
            <div class="form-group">
                <label>Paragraf 1</label>
                <textarea name="sambutan_paragraf1" rows="4"><?php echo htmlspecialchars($sambutan_paragraf1 ?: 'Selamat datang di website resmi BEM Kabinet Astawidya.'); ?></textarea>
            </div>
            <div class="form-group">
                <label>Paragraf 2</label>
                <textarea name="sambutan_paragraf2" rows="4"><?php echo htmlspecialchars($sambutan_paragraf2 ?: 'Website ini adalah wadah informasi dan komunikasi kita semua.'); ?></textarea>
            </div>
        <?php else: ?>
            <div class="form-group">
                <label>Deskripsi</label>
                <textarea name="deskripsi" rows="4"><?php echo htmlspecialchars($data['deskripsi'] ?? ''); ?></textarea>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tugas Pokok -->
    <div class="form-section">
        <h2><i class="fas fa-tasks"></i> Tugas Pokok</h2>
        <div class="form-group">
            <div id="tugasContainer">
                <?php foreach (!empty($tugas_list) ? $tugas_list : [''] as $i => $item): ?>
                <div class="list-item">
                    <input type="text" name="tugas[]"
                           value="<?php echo htmlspecialchars($item); ?>"
                           placeholder="Tugas <?php echo $i + 1; ?>">
                    <button type="button" class="btn-remove" onclick="hapusListItem(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add" onclick="tambahItem('tugasContainer', 'tugas[]', 'Tugas')">
                <i class="fas fa-plus"></i> Tambah Tugas
            </button>
        </div>
    </div>

    <!-- Program Kerja -->
    <div class="form-section">
        <h2><i class="fas fa-calendar-alt"></i> Program Kerja</h2>
        <div class="form-group">
            <div id="prokerContainer">
                <?php foreach (!empty($proker_list) ? $proker_list : [''] as $i => $item): ?>
                <div class="list-item">
                    <input type="text" name="proker[]"
                           value="<?php echo htmlspecialchars($item); ?>"
                           placeholder="Program Kerja <?php echo $i + 1; ?>">
                    <button type="button" class="btn-remove" onclick="hapusListItem(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add" onclick="tambahItem('prokerContainer', 'proker[]', 'Program Kerja')">
                <i class="fas fa-plus"></i> Tambah Program Kerja
            </button>
        </div>
    </div>

    <!-- Anggota (sekretaris / bendahara) -->
    <?php if (in_array($posisi, ['sekretaris_umum', 'bendahara_umum'])): ?>
    <div class="form-section">
        <h2><i class="fas fa-users"></i> Anggota</h2>
        <div class="form-group">
            <div id="anggotaContainer">
                <?php
                $render_anggota = !empty($anggota_list) ? $anggota_list : [null];
                foreach ($render_anggota as $index => $anggota):
                    $is_new = ($anggota === null);
                ?>
                <div class="anggota-item">
                    <input type="hidden" name="anggota_id[]"
                           value="<?php echo $is_new ? '0' : (int)$anggota['id']; ?>">
                    <input type="text" name="anggota_nama[]"
                           value="<?php echo $is_new ? '' : htmlspecialchars($anggota['nama']); ?>"
                           placeholder="Nama Anggota" required>
                    <input type="text" name="anggota_jabatan[]"
                           value="<?php echo $is_new ? '' : htmlspecialchars($anggota['jabatan']); ?>"
                           placeholder="Jabatan" required>

                    <div class="anggota-foto-container">
                        <?php if (!$is_new && !empty($anggota['foto'])): ?>
                        <div class="foto-preview">
                            <img src="<?php echo uploadUrl($anggota['foto']); ?>"
                                 alt="Foto <?php echo htmlspecialchars($anggota['nama']); ?>">
                            <small><?php echo htmlspecialchars(basename($anggota['foto'])); ?></small>
                            <?php /* Hapus foto anggota via form POST tersendiri */ ?>
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirm('Yakin hapus foto anggota ini?')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action_hapus" value="hapus_foto_anggota">
                                <input type="hidden" name="anggota_id" value="<?php echo (int)$anggota['id']; ?>">
                                <button type="submit" class="btn-delete-very-small" title="Hapus Foto">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="anggota_foto[]" accept="image/*"
                               onchange="previewAnggotaFoto(this)">
                        <span class="foto-note">
                            <?php echo (!$is_new && !empty($anggota['foto'])) ? 'Kosongkan jika tidak ingin mengubah foto' : 'Upload foto anggota (opsional)'; ?>
                        </span>
                    </div>

                    <button type="button" class="btn-remove" onclick="hapusAnggotaItem(this)" title="Hapus Anggota">×</button>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn-add" onclick="tambahAnggota()">
                <i class="fas fa-user-plus"></i> Tambah Anggota
            </button>
            <small>Foto lama tetap tersimpan jika tidak diupload ulang. Maksimal 50 anggota.</small>
        </div>
    </div>
    <?php endif; ?>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="kepengurusan.php" class="btn-secondary">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-primary" id="submitBtn">
            <i class="fas fa-save"></i> Simpan
        </button>
    </div>

</form>

<!-- JavaScript — tidak diubah -->
<script>
function tambahItem(containerId, fieldName, label) {
    const container = document.getElementById(containerId);
    const index = container.children.length + 1;
    const div = document.createElement('div');
    div.className = 'list-item';
    div.innerHTML =
        `<input type="text" name="${fieldName}" placeholder="${label} ${index}">` +
        `<button type="button" class="btn-remove" onclick="hapusListItem(this)" title="Hapus">×</button>`;
    container.appendChild(div);
    div.querySelector('input').focus();
}

function hapusListItem(btn) {
    const item = btn.closest('.list-item');
    const container = item.parentElement;
    if (container.children.length > 1) {
        item.remove();
    } else {
        item.querySelector('input').value = '';
    }
}

function tambahAnggota() {
    const container = document.getElementById('anggotaContainer');
    const div = document.createElement('div');
    div.className = 'anggota-item';
    div.innerHTML =
        `<input type="hidden" name="anggota_id[]" value="0">` +
        `<input type="text" name="anggota_nama[]" placeholder="Nama Anggota" required>` +
        `<input type="text" name="anggota_jabatan[]" placeholder="Jabatan" required>` +
        `<div class="anggota-foto-container">` +
            `<input type="file" name="anggota_foto[]" accept="image/*" onchange="previewAnggotaFoto(this)">` +
            `<span class="foto-note">Upload foto anggota (opsional)</span>` +
        `</div>` +
        `<button type="button" class="btn-remove" onclick="hapusAnggotaItem(this)" title="Hapus">×</button>`;
    container.appendChild(div);
    div.querySelector('input[name="anggota_nama[]"]').focus();
}

function hapusAnggotaItem(btn) {
    const container = document.getElementById('anggotaContainer');
    if (container.children.length > 1) {
        btn.closest('.anggota-item').remove();
    } else {
        alert('Minimal harus ada satu baris anggota.');
    }
}

function previewAnggotaFoto(input) {
    if (!input.files[0]) return;
    const container = input.closest('.anggota-foto-container');
    let preview = container.querySelector('.foto-preview');
    if (!preview) {
        preview = document.createElement('div');
        preview.className = 'foto-preview';
        container.insertBefore(preview, input);
    }
    const reader = new FileReader();
    reader.onload = e => {
        preview.innerHTML =
            `<img src="${e.target.result}" alt="Preview">` +
            `<small>Preview foto baru</small>`;
    };
    reader.readAsDataURL(input.files[0]);
}

function setupPreview(inputId, wrapId, imgId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('change', function () {
        if (!this.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => {
            const wrap = document.getElementById(wrapId);
            const img  = document.getElementById(imgId);
            wrap.style.display = 'block';
            img.src = e.target.result;
        };
        reader.readAsDataURL(this.files[0]);
    });
}
setupPreview('inputFoto', 'previewFotoWrap', 'previewFotoImg');
setupPreview('inputLogo', 'previewLogoWrap', 'previewLogoImg');

document.getElementById('submitBtn').addEventListener('click', function () {
    if (this.classList.contains('loading')) return;
    this.classList.add('loading');
    this.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
});
</script>

<link rel="stylesheet" href="css/kepengurusan-edit.css">

<?php require_once __DIR__ . '/footer.php'; ?>