<?php
// admin/kementerian-edit.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF token di form + validasi di POST handler
//   CHANGED: Hapus logo pindah dari GET ke POST (form di luar form utama)
//   CHANGED: Hapus kementerian pindah dari GET ke POST
//   CHANGED: sanitizeText() untuk nama dan deskripsi
//   CHANGED: Sanitasi array tugas[] dan proker[]
//   CHANGED: Validasi nama tidak boleh kosong di PHP
//   CHANGED: Error message generic (tidak bocorkan detail exception)
//   CHANGED: Redirect ke admin/kepengurusan.php bukan root
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

require_once __DIR__ . '/../core/header.php';

$id = (int) ($_GET['id'] ?? 0);

$kementerian = null;
if ($id) {
    $kementerian = dbFetchOne(
        "SELECT * FROM kementerian WHERE id = ? AND periode_id = ?",
        [$id, $active_periode], "ii"
    );
    if (!$kementerian) {
        redirect('admin/konten/kepengurusan.php', 'Kementerian tidak ditemukan atau bukan milik periode ini!', 'error');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['review_program_kerja'])) {
    if (!csrfVerify()) {
        redirect('admin/konten/kementerian-edit.php?id=' . $id, 'Request tidak valid.', 'error');
        exit();
    }

    $program_kerja_id = (int)($_POST['program_kerja_id'] ?? 0);
    $review_action = $_POST['review_program_kerja'];
    $expected_status = in_array($review_action, ['approve', 'archive'], true)
        ? 'usulan'
        : ($review_action === 'restore' ? 'arsip' : '');
    $new_status = match ($review_action) {
        'approve', 'restore' => 'aktif',
        'archive' => 'arsip',
        default => '',
    };

    if (!$id || $program_kerja_id <= 0 || $expected_status === '' || $new_status === '') {
        redirect('admin/konten/kementerian-edit.php?id=' . $id, 'Usulan Program Kerja tidak valid.', 'error');
        exit();
    }

    try {
        $proposal = dbFetchOne(
            "SELECT id, nama FROM program_kerja
             WHERE id = ? AND kementerian_id = ? AND periode_id = ? AND status = ?",
            [$program_kerja_id, $id, $active_periode, $expected_status],
            "iiis"
        );
        if (!$proposal) {
            redirect('admin/konten/kementerian-edit.php?id=' . $id, 'Program Kerja tidak ditemukan atau sudah diproses.', 'error');
            exit();
        }

        dbBeginTransaction();
        $locked_ministry = dbFetchOne(
            "SELECT id FROM kementerian WHERE id = ? AND periode_id = ? FOR UPDATE",
            [$id, $active_periode],
            "ii"
        );
        if (!$locked_ministry) {
            throw new InvalidArgumentException('Kementerian tidak ditemukan atau bukan milik periode aktif.');
        }

        if ($new_status === 'aktif') {
            $duplicate = dbFetchOne(
                "SELECT id FROM program_kerja
                 WHERE kementerian_id = ? AND periode_id = ? AND status = 'aktif'
                   AND LOWER(TRIM(nama)) = LOWER(TRIM(?)) AND id <> ?",
                [$id, $active_periode, $proposal['nama'], $program_kerja_id],
                "iisi"
            );
            if ($duplicate) {
                dbRollback();
                redirect('admin/konten/kementerian-edit.php?id=' . $id, 'Nama Program Kerja aktif yang sama sudah tersedia.', 'error');
                exit();
            }
        }

        dbQuery(
            "UPDATE program_kerja SET status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND kementerian_id = ? AND periode_id = ? AND status = ?",
            [$new_status, $program_kerja_id, $id, $active_periode, $expected_status],
            "siiis"
        );
        $active_names = dbFetchAll(
            "SELECT nama FROM program_kerja
             WHERE kementerian_id = ? AND periode_id = ? AND status = 'aktif'
             ORDER BY id ASC",
            [$id, $active_periode],
            "ii"
        );
        dbQuery(
            "UPDATE kementerian SET proker = ? WHERE id = ? AND periode_id = ?",
            [
                !empty($active_names) ? json_encode(array_column($active_names, 'nama'), JSON_UNESCAPED_UNICODE) : null,
                $id,
                $active_periode,
            ],
            "sii"
        );
        dbCommit();
        auditLog(
            $review_action === 'approve' ? 'APPROVE' : ($review_action === 'restore' ? 'RESTORE' : 'ARCHIVE'),
            'program_kerja',
            $program_kerja_id,
            ($review_action === 'approve' ? 'Setujui' : ($review_action === 'restore' ? 'Pulihkan' : 'Arsipkan')) . ' Program Kerja kementerian: ' . $kementerian['nama']
        );
        redirect(
            'admin/konten/kementerian-edit.php?id=' . $id,
            $review_action === 'approve'
                ? 'Usulan Program Kerja disetujui.'
                : ($review_action === 'restore' ? 'Program Kerja dipulihkan.' : 'Usulan Program Kerja diarsipkan.'),
            'success'
        );
    } catch (Exception $e) {
        dbRollback();
        error_log('[PROGRAM-KERJA-REVIEW] ' . $e->getMessage());
        redirect('admin/konten/kementerian-edit.php?id=' . $id, 'Gagal memproses usulan Program Kerja.', 'error');
    }
    exit();
}

// ============================================
// AKSI POST: Hapus logo
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_hapus_logo'])) {
    if (!csrfVerify()) {
        redirect('admin/konten/kementerian-edit.php?id=' . $id, 'Request tidak valid.', 'error');
        exit();
    }
    if ($kementerian && !empty($kementerian['logo'])) {
        deleteFile($kementerian['logo']);
        dbQuery("UPDATE kementerian SET logo = NULL WHERE id = ? AND periode_id = ?",
                [$id, $active_periode], "ii");
    }
    redirect('admin/konten/kementerian-edit.php?id=' . $id, 'Logo berhasil dihapus', 'success');
    exit();
}

// ============================================
// PROSES SUBMIT FORM UTAMA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action_hapus_logo'])) {

    if (!csrfVerify()) {
        redirect('admin/konten/kementerian-edit.php' . ($id ? "?id={$id}" : ''), 'Request tidak valid.', 'error');
        exit();
    }

    $nama      = sanitizeText($_POST['nama']      ?? '', 100);
    $deskripsi = sanitizeText($_POST['deskripsi'] ?? '', 1000);

    if (empty($nama)) {
        redirect('admin/konten/kementerian-edit.php' . ($id ? "?id={$id}" : ''),
                 'Nama kementerian tidak boleh kosong.', 'error');
        exit();
    }

    $base_slug = createSlug($nama);
    $slug      = $base_slug . '-' . $active_periode;

    $logo = $kementerian['logo'] ?? '';
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $uploadResult = uploadFile($_FILES['logo'], 'struktur');
        if ($uploadResult) {
            if (!empty($logo)) deleteFile($logo);
            $logo = $uploadResult;
        }
    }

    $tugas = array_values(array_filter(
        array_map(fn($v) => sanitizeText($v, 200), $_POST['tugas'] ?? []),
        fn($v) => !empty($v)
    ));
    $proker_inputs = is_array($_POST['proker'] ?? null) ? $_POST['proker'] : [];
    $proker_ids = is_array($_POST['proker_id'] ?? null) ? $_POST['proker_id'] : [];
    $fungsi = array_values(array_filter(
        array_map(fn($v) => sanitizeText($v, 200), $_POST['fungsi'] ?? []),
        fn($v) => !empty($v)
    ));

    $tugas_json  = !empty($tugas)  ? json_encode($tugas)  : null;
    $fungsi_json = !empty($fungsi) ? json_encode($fungsi) : null;

    dbBeginTransaction();
    try {
        if ($id) {
            dbQuery(
                "UPDATE kementerian SET nama=?, slug=?, logo=?, deskripsi=?, tugas=?, fungsi=?
                 WHERE id=? AND periode_id=?",
                [$nama, $slug, $logo, $deskripsi, $tugas_json, $fungsi_json, $id, $active_periode],
                "ssssssii"
            );
            $saved_id = $id;
        } else {
            dbQuery(
                "INSERT INTO kementerian (periode_id, created_by, nama, slug, logo, deskripsi, tugas, proker, fungsi)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$active_periode, $_SESSION['admin_id'], $nama, $slug, $logo, $deskripsi, $tugas_json, null, $fungsi_json],
                "iisssssss"
            );
            $saved_id = dbLastId();
        }

        $active_programs = dbFetchAll(
            "SELECT id, nama FROM program_kerja
             WHERE kementerian_id = ? AND periode_id = ? AND status = 'aktif'",
            [$saved_id, $active_periode],
            "ii"
        );
        $active_by_id = [];
        foreach ($active_programs as $program) {
            $active_by_id[(int)$program['id']] = $program;
        }

        $kept_program_ids = [];
        foreach ($proker_inputs as $index => $raw_name) {
            if (!is_string($raw_name) || !is_scalar($proker_ids[$index] ?? 0)) {
                throw new InvalidArgumentException('Daftar Program Kerja tidak valid.');
            }
            $program_name = sanitizeText($raw_name, 200);
            $program_id = (int)($proker_ids[$index] ?? 0);
            if ($program_name === '') {
                continue;
            }

            if ($program_id > 0 && !isset($active_by_id[$program_id])) {
                throw new InvalidArgumentException('Program Kerja tidak valid atau telah berubah. Muat ulang halaman dan coba lagi.');
            }
            if ($program_id > 0 && in_array($program_id, $kept_program_ids, true)) {
                throw new InvalidArgumentException('Satu Program Kerja tidak boleh dikirim lebih dari satu kali.');
            }

            $duplicate = dbFetchOne(
                "SELECT id FROM program_kerja
                 WHERE kementerian_id = ? AND periode_id = ? AND status = 'aktif'
                   AND LOWER(TRIM(nama)) = LOWER(TRIM(?)) AND id <> ?",
                [$saved_id, $active_periode, $program_name, $program_id],
                "iisi"
            );
            if ($duplicate) {
                throw new InvalidArgumentException('Nama Program Kerja aktif tidak boleh duplikat dalam satu kementerian.');
            }

            if ($program_id > 0) {
                dbQuery(
                    "UPDATE program_kerja SET nama = ?, updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND kementerian_id = ? AND periode_id = ? AND status = 'aktif'",
                    [$program_name, $program_id, $saved_id, $active_periode],
                    "siii"
                );
            } else {
                dbQuery(
                    "INSERT INTO program_kerja (kementerian_id, periode_id, nama, status)
                     VALUES (?, ?, ?, 'aktif')",
                    [$saved_id, $active_periode, $program_name],
                    "iis"
                );
                $program_id = dbLastId();
            }
            $kept_program_ids[] = $program_id;
        }

        foreach (array_keys($active_by_id) as $existing_id) {
            if (!in_array($existing_id, $kept_program_ids, true)) {
                dbQuery(
                    "UPDATE program_kerja SET status = 'arsip', updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND kementerian_id = ? AND periode_id = ? AND status = 'aktif'",
                    [$existing_id, $saved_id, $active_periode],
                    "iii"
                );
            }
        }

        $active_programs = dbFetchAll(
            "SELECT nama FROM program_kerja
             WHERE kementerian_id = ? AND periode_id = ? AND status = 'aktif'
             ORDER BY id ASC",
            [$saved_id, $active_periode],
            "ii"
        );
        $proker_json = !empty($active_programs)
            ? json_encode(array_column($active_programs, 'nama'), JSON_UNESCAPED_UNICODE)
            : null;
        dbQuery(
            "UPDATE kementerian SET proker = ? WHERE id = ? AND periode_id = ?",
            [$proker_json, $saved_id, $active_periode],
            "sii"
        );

        dbCommit();
        auditLog($id ? 'UPDATE' : 'CREATE', 'kementerian', $saved_id, ($id ? 'Edit' : 'Tambah') . ' kementerian: ' . $nama);
        if ($id) {
            redirect('admin/konten/kementerian-anggota.php?id=' . $saved_id, 'Kementerian berhasil diupdate!', 'success');
        } else {
            redirect('admin/konten/kementerian-anggota.php?id=' . $saved_id, 'Kementerian berhasil ditambah!', 'success');
        }
    } catch (InvalidArgumentException $e) {
        dbRollback();
        redirect('admin/konten/kementerian-edit.php' . ($id ? "?id={$id}" : ''), $e->getMessage(), 'error');
    } catch (Exception $e) {
        dbRollback();
        error_log("[KEMENTERIAN-EDIT] " . $e->getMessage());
        redirect('admin/konten/kementerian-edit.php' . ($id ? "?id={$id}" : ''),
                 'Gagal menyimpan. Silakan coba lagi.', 'error');
    }
    exit();
}

// Persiapan data tampilan
$tugas_list  = [];
$proker_list = [];
$fungsi_list = [];
if ($kementerian) {
    if (!empty($kementerian['tugas']))  $tugas_list  = json_decode($kementerian['tugas'],  true) ?: [];
    if (!empty($kementerian['fungsi'])) $fungsi_list = json_decode($kementerian['fungsi'], true) ?: [];
    $proker_list = dbFetchAll(
        "SELECT id, nama FROM program_kerja
         WHERE kementerian_id = ? AND periode_id = ? AND status = 'aktif'
         ORDER BY id ASC",
        [$id, $active_periode],
        "ii"
    );
}
$proposal_list = $kementerian ? dbFetchAll(
    "SELECT id, nama, created_at FROM program_kerja
     WHERE kementerian_id = ? AND periode_id = ? AND status = 'usulan'
     ORDER BY created_at ASC",
    [$id, $active_periode],
    "ii"
) : [];
$archived_programs = $kementerian ? dbFetchAll(
    "SELECT id, nama FROM program_kerja
     WHERE kementerian_id = ? AND periode_id = ? AND status = 'arsip'
     ORDER BY updated_at DESC",
    [$id, $active_periode],
    "ii"
) : [];

$periode_info = ($periode_data['nama'] ?? 'Astawidya')
              . ' (' . ($periode_data['tahun_mulai'] ?? '2025')
              . '/' . ($periode_data['tahun_selesai'] ?? '2026') . ')';
?>

<!-- ===== PAGE HEADER ===== -->
<div class="page-header">
    <h1>
        <i class="fas fa-building"></i>
        <?php echo $id ? 'Edit' : 'Tambah'; ?> Kementerian
    </h1>
    <p><?php echo $id ? 'Edit data kementerian' : 'Tambah kementerian baru untuk periode ini'; ?></p>
    <span class="periode-badge">
        <i class="fas fa-calendar-alt"></i>
        Periode: <?php echo htmlspecialchars($periode_info); ?>
    </span>
</div>

<?php flashMessage(); ?>

<?php if (!empty($proposal_list)): ?>
<div class="form-section">
    <h2><i class="fas fa-inbox"></i> Usulan Program Kerja</h2>
    <p>Usulan baru belum menjadi pilihan kegiatan sampai disetujui. Mengarsipkan usulan tidak menghapus referensi kegiatan yang sudah memakainya.</p>
    <?php foreach ($proposal_list as $proposal): ?>
    <div class="list-item" style="justify-content:space-between;gap:12px;">
        <span><?php echo htmlspecialchars($proposal['nama'], ENT_QUOTES, 'UTF-8'); ?></span>
        <div style="display:flex;gap:8px;">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="review_program_kerja" value="approve">
                <input type="hidden" name="program_kerja_id" value="<?php echo (int)$proposal['id']; ?>">
                <button type="submit" class="btn-primary">Setujui</button>
            </form>
            <form method="POST" onsubmit="return confirm('Arsipkan usulan ini? Data kegiatan yang sudah menggunakannya tetap dipertahankan.')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="review_program_kerja" value="archive">
                <input type="hidden" name="program_kerja_id" value="<?php echo (int)$proposal['id']; ?>">
                <button type="submit" class="btn-delete-small">Arsipkan</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($archived_programs)): ?>
<div class="form-section">
    <h2><i class="fas fa-archive"></i> Program Kerja Diarsipkan</h2>
    <p>Data arsip tidak tampil sebagai rekomendasi kegiatan. Pulihkan bila item terhapus secara tidak sengaja.</p>
    <?php foreach ($archived_programs as $program): ?>
    <div class="list-item" style="justify-content:space-between;gap:12px;">
        <span><?php echo htmlspecialchars($program['nama'], ENT_QUOTES, 'UTF-8'); ?></span>
        <form method="POST" onsubmit="return confirm('Pulihkan Program Kerja ini sebagai pilihan aktif?')">
            <?php echo csrfField(); ?>
            <input type="hidden" name="review_program_kerja" value="restore">
            <input type="hidden" name="program_kerja_id" value="<?php echo (int)$program['id']; ?>">
            <button type="submit" class="btn-primary">Pulihkan</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* Form hapus logo — di LUAR form utama agar tidak nested */ ?>
<?php if ($id && $kementerian && !empty($kementerian['logo'])): ?>
<form method="POST" id="formHapusLogo"
      onsubmit="return confirm('Yakin ingin menghapus logo ini?')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_hapus_logo" value="1">
</form>
<?php endif; ?>

<?php if ($id && $kementerian): ?>
<div class="delete-row">
    <?php /* Hapus kementerian via POST + CSRF — bukan GET link */ ?>
    <form method="POST" action="kepengurusan.php" style="display:inline"
          onsubmit="return confirm('Yakin ingin menghapus kementerian ini? Semua anggota juga akan ikut terhapus.')">
        <?php echo csrfField(); ?>
        <input type="hidden" name="hapus_kementerian_id" value="<?php echo $id; ?>">
        <button type="submit" class="btn-delete">
            <i class="fas fa-trash"></i> Hapus Kementerian
        </button>
    </form>
</div>
<?php endif; ?>

<!-- ===== FORM UTAMA ===== -->
<form method="POST" enctype="multipart/form-data" class="admin-form">

    <?php echo csrfField(); ?>

    <!-- Informasi Dasar -->
    <div class="form-section">
        <h2><i class="fas fa-info-circle"></i> Informasi Dasar</h2>
        <div class="form-group">
            <label>Nama Kementerian</label>
            <input type="text" name="nama"
                   value="<?php echo htmlspecialchars($kementerian['nama'] ?? ''); ?>"
                   placeholder="Masukkan nama kementerian..." required>
        </div>
        <div class="form-group">
            <label>Slug (URL)</label>
            <input type="text"
                   value="<?php echo $kementerian ? htmlspecialchars($kementerian['slug'] ?? '') : '(akan dibuat otomatis)'; ?>"
                   disabled readonly>
            <small>Dibuat otomatis dari nama + periode agar unik antar periode.</small>
        </div>
    </div>

    <!-- Logo -->
    <div class="form-section">
        <h2><i class="fas fa-image"></i> Logo</h2>
        <div class="form-group">
            <?php if (!empty($kementerian['logo'])): ?>
                <div class="current-image">
                    <span class="current-image-label">Preview</span>
                    <img src="<?php echo uploadUrl($kementerian['logo']); ?>"
                         alt="Logo <?php echo htmlspecialchars($kementerian['nama']); ?>">
                    <p>Logo saat ini</p>
                    <?php /* Tombol submit ke formHapusLogo yang ada di luar */ ?>
                    <button type="submit" form="formHapusLogo" class="btn-delete-small">
                        <i class="fas fa-trash"></i> Hapus Logo
                    </button>
                </div>
            <?php endif; ?>
            <div class="upload-section">
                <label for="inputLogo">Upload Logo Baru</label>
                <input type="file" name="logo" id="inputLogo" accept="image/*">
                <small>Format: JPG, PNG, WebP. Maks 2MB. Kosongkan jika tidak ingin mengubah.</small>
            </div>
        </div>
    </div>

    <!-- Deskripsi -->
    <div class="form-section">
        <h2><i class="fas fa-align-left"></i> Deskripsi</h2>
        <div class="form-group">
            <textarea name="deskripsi" rows="4"
                      placeholder="Deskripsi kementerian..."><?php echo htmlspecialchars($kementerian['deskripsi'] ?? ''); ?></textarea>
        </div>
    </div>

    <!-- Fungsi -->
    <div class="form-section">
        <h2><i class="fas fa-bullseye"></i> Fungsi</h2>
        <div class="form-group">
            <div id="fungsiContainer">
                <?php foreach (!empty($fungsi_list) ? $fungsi_list : [''] as $i => $item): ?>
                <div class="list-item">
                    <input type="text" name="fungsi[]"
                           value="<?php echo htmlspecialchars($item); ?>"
                           placeholder="Fungsi <?php echo $i + 1; ?>">
                    <button type="button" class="btn-remove" onclick="hapusItem(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add"
                    onclick="tambahItem('fungsiContainer', 'fungsi[]', 'Fungsi')">
                <i class="fas fa-plus"></i> Tambah Fungsi
            </button>
        </div>
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
                    <button type="button" class="btn-remove" onclick="hapusItem(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add"
                    onclick="tambahItem('tugasContainer', 'tugas[]', 'Tugas')">
                <i class="fas fa-plus"></i> Tambah Tugas
            </button>
        </div>
    </div>

    <!-- Program Kerja -->
    <div class="form-section">
        <h2><i class="fas fa-calendar-alt"></i> Program Kerja</h2>
        <div class="form-group">
            <div id="prokerContainer">
                <?php foreach (!empty($proker_list) ? $proker_list : [['id' => 0, 'nama' => '']] as $i => $item): ?>
                <div class="list-item">
                    <input type="hidden" name="proker_id[]"
                           value="<?php echo (int)$item['id']; ?>">
                    <input type="text" name="proker[]"
                           value="<?php echo htmlspecialchars($item['nama'], ENT_QUOTES, 'UTF-8'); ?>"
                           placeholder="Program Kerja <?php echo $i + 1; ?>">
                    <button type="button" class="btn-remove" onclick="hapusItem(this)" title="Hapus">×</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add"
                    onclick="tambahItem('prokerContainer', 'proker[]', 'Program Kerja')">
                <i class="fas fa-plus"></i> Tambah Program Kerja
            </button>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <a href="kepengurusan.php" class="btn-secondary">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-primary" id="submitBtn">
            <i class="fas fa-save"></i>
            <?php echo $id ? 'Simpan Perubahan' : 'Simpan & Lanjut ke Anggota'; ?>
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
    const idField = fieldName === 'proker[]'
        ? '<input type="hidden" name="proker_id[]" value="0">'
        : '';
    div.innerHTML =
        idField +
        `<input type="text" name="${fieldName}" placeholder="${label} ${index}">` +
        `<button type="button" class="btn-remove" onclick="hapusItem(this)" title="Hapus">×</button>`;
    container.appendChild(div);
    div.querySelector('input').focus();
}

function hapusItem(btn) {
    const item = btn.closest('.list-item');
    const container = item.parentElement;
    if (container.children.length > 1) {
        item.remove();
    } else {
        item.querySelector('input').value = '';
    }
}

document.getElementById('submitBtn')?.addEventListener('click', function () {
    if (this.classList.contains('loading')) return;
    this.classList.add('loading');
    this.innerHTML = '<i class="fas fa-spinner"></i> Menyimpan...';
});
</script>

<link rel="stylesheet" href="<?php echo baseUrl('admin/css/kementerian-edit.css'); ?>">

<?php require_once __DIR__ . '/../core/footer.php'; ?>