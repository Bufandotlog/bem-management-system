<?php
// admin/periode-kepengurusan.php
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: CSRF di semua form POST (tambah, aktifkan, hapus, edit)
//   CHANGED: sanitizeText() untuk nama dan deskripsi
//   CHANGED: Validasi range tahun di PHP
//   CHANGED: Error message generic
//   CHANGED: Redirect ke admin/periode-kepengurusan.php
//   CHANGED: Cast (int) pada semua output ID
//   UNCHANGED: Seluruh HTML, CSS, JavaScript

$page_css = 'periode-kepengurusan';
require_once __DIR__ . '/header.php';

if ($_SESSION['admin_role'] !== 'superadmin' && !$user_can_access_all) {
    redirect('admin/dashboard.php', 'Akses ditolak!', 'error');
    exit();
}

$periode_list  = dbFetchAll("SELECT * FROM periode_kepengurusan ORDER BY tahun_mulai DESC");
$periode_aktif = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE is_active = 1");

$error   = '';
$success = '';

// ============================================
// PROSES TAMBAH
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tambah') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $nama          = sanitizeText($_POST['nama']        ?? '', 100);
        $deskripsi     = sanitizeText($_POST['deskripsi']   ?? '', 500);
        $tahun_mulai   = (int) ($_POST['tahun_mulai']       ?? 0);
        $tahun_selesai = (int) ($_POST['tahun_selesai']     ?? 0);

        if (empty($nama) || !$tahun_mulai || !$tahun_selesai) {
            $error = 'Nama, tahun mulai, dan tahun selesai wajib diisi!';
        } elseif ($tahun_mulai < 2000 || $tahun_mulai > 2100 || $tahun_selesai < 2000 || $tahun_selesai > 2100) {
            $error = 'Tahun harus antara 2000 dan 2100.';
        } elseif ($tahun_selesai <= $tahun_mulai) {
            $error = 'Tahun selesai harus lebih besar dari tahun mulai!';
        } else {
            $cek = dbFetchOne(
                "SELECT id FROM periode_kepengurusan WHERE nama = ? OR (tahun_mulai = ? AND tahun_selesai = ?)",
                [$nama, $tahun_mulai, $tahun_selesai], "sii"
            );
            if ($cek) {
                $error = 'Periode dengan nama atau tahun yang sama sudah ada!';
            } else {
                dbQuery(
                    "INSERT INTO periode_kepengurusan (nama, tahun_mulai, tahun_selesai, deskripsi, is_active) VALUES (?, ?, ?, ?, 0)",
                    [$nama, $tahun_mulai, $tahun_selesai, $deskripsi], "siis"
                );
                auditLog('CREATE', 'periode_kepengurusan', dbLastId(), 'Tambah periode: ' . $nama);
                redirect('admin/periode-kepengurusan.php', 'Periode berhasil ditambahkan!', 'success');
                exit();
            }
        }
    }
}

// ============================================
// PROSES AKTIFKAN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'aktifkan') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            dbBeginTransaction();
            try {
                dbQuery("UPDATE periode_kepengurusan SET is_active = 0");
                dbQuery("UPDATE periode_kepengurusan SET is_active = 1 WHERE id = ?", [$id], "i");
                dbCommit();
                auditLog('UPDATE', 'periode_kepengurusan', $id, 'Aktifkan periode ID: ' . $id);
                redirect('admin/periode-kepengurusan.php', 'Periode berhasil diaktifkan!', 'success');
                exit();
            } catch (Exception $e) {
                dbRollback();
                error_log("[PERIODE] Aktifkan: " . $e->getMessage());
                $error = 'Gagal mengaktifkan periode.';
            }
        }
    }
}

// ============================================
// PROSES HAPUS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        if ($periode_aktif && (int)$periode_aktif['id'] === $id) {
            $error = 'Tidak dapat menghapus periode yang sedang aktif!';
        } elseif ($id > 0) {
            $periode = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE id = ?", [$id], "i");
            if ($periode) {
                dbBeginTransaction();
                try {
                    $bph_list = dbFetchAll("SELECT foto, logo FROM struktur_bph WHERE periode_id = ?", [$id], "i");
                    foreach ($bph_list as $b) {
                        if (!empty($b['foto'])) deleteFile($b['foto']);
                        if (!empty($b['logo'])) deleteFile($b['logo']);
                    }
                    $anggota_bph = dbFetchAll("SELECT foto FROM anggota_bph WHERE periode_id = ?", [$id], "i");
                    foreach ($anggota_bph as $a) { if (!empty($a['foto'])) deleteFile($a['foto']); }

                    $kementerian = dbFetchAll("SELECT logo FROM kementerian WHERE periode_id = ?", [$id], "i");
                    foreach ($kementerian as $k) { if (!empty($k['logo'])) deleteFile($k['logo']); }

                    $anggota_kem = dbFetchAll("SELECT foto FROM anggota_kementerian WHERE periode_id = ?", [$id], "i");
                    foreach ($anggota_kem as $a) { if (!empty($a['foto'])) deleteFile($a['foto']); }

                    $struktur = dbFetchAll("SELECT gambar FROM struktur_organisasi WHERE periode_id = ?", [$id], "i");
                    foreach ($struktur as $s) { if (!empty($s['gambar'])) deleteFile($s['gambar']); }

                    dbQuery("UPDATE users SET periode_id = NULL WHERE periode_id = ?", [$id], "i");
                    dbQuery("DELETE FROM periode_kepengurusan WHERE id = ?", [$id], "i");
                    dbCommit();
                    auditLog('DELETE', 'periode_kepengurusan', $id, 'Hapus periode: ' . $periode['nama']);
                    redirect('admin/periode-kepengurusan.php', 'Periode berhasil dihapus!', 'success');
                    exit();
                } catch (Exception $e) {
                    dbRollback();
                    error_log("[PERIODE] Hapus: " . $e->getMessage());
                    $error = 'Gagal menghapus periode.';
                }
            }
        }
    }
}

// ============================================
// PROSES EDIT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $id            = (int) ($_POST['id']            ?? 0);
        $nama          = sanitizeText($_POST['nama']        ?? '', 100);
        $deskripsi     = sanitizeText($_POST['deskripsi']   ?? '', 500);
        $tahun_mulai   = (int) ($_POST['tahun_mulai']       ?? 0);
        $tahun_selesai = (int) ($_POST['tahun_selesai']     ?? 0);

        if (empty($nama) || !$tahun_mulai || !$tahun_selesai) {
            $error = 'Nama, tahun mulai, dan tahun selesai wajib diisi!';
        } elseif ($tahun_mulai < 2000 || $tahun_mulai > 2100 || $tahun_selesai < 2000 || $tahun_selesai > 2100) {
            $error = 'Tahun harus antara 2000 dan 2100.';
        } elseif ($tahun_selesai <= $tahun_mulai) {
            $error = 'Tahun selesai harus lebih besar dari tahun mulai!';
        } elseif ($id > 0) {
            dbQuery(
                "UPDATE periode_kepengurusan SET nama=?, tahun_mulai=?, tahun_selesai=?, deskripsi=? WHERE id=?",
                [$nama, $tahun_mulai, $tahun_selesai, $deskripsi, $id], "siisi"
            );
            auditLog('UPDATE', 'periode_kepengurusan', $id, 'Edit periode: ' . $nama);
            redirect('admin/periode-kepengurusan.php', 'Periode berhasil diupdate!', 'success');
            exit();
        }
    }
}

if (isset($_SESSION['flash'])) {
    $success = $_SESSION['flash']['message'];
    unset($_SESSION['flash']);
}

// Refresh setelah proses
$periode_list  = dbFetchAll("SELECT * FROM periode_kepengurusan ORDER BY tahun_mulai DESC");
$periode_aktif = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE is_active = 1");
?>

<!-- Page Header -->
<div class="page-header">
    <h1><i class="fas fa-calendar-alt"></i> Periode Kepengurusan</h1>
    <p>Kelola periode kepengurusan BEM dari tahun ke tahun</p>
</div>

<?php flashMessage(); ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
    </div>
<?php endif; ?>

<!-- Info Periode Aktif -->
<?php if ($periode_aktif): ?>
    <div style="margin-top:30px;" class="info-box">
        <div style="margin-top:5px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div class="info-icon"><i class="fas fa-check"></i></div>
            <div style="flex:1;">
                <h3 class="info-title">Periode Aktif Saat Ini</h3>
                <p class="info-text">
                    <strong><?php echo htmlspecialchars($periode_aktif['nama']); ?></strong>
                    (<?php echo (int)$periode_aktif['tahun_mulai']; ?>/<?php echo (int)$periode_aktif['tahun_selesai']; ?>)
                </p>
                <?php if (!empty($periode_aktif['deskripsi'])): ?>
                    <p class="info-description"><?php echo htmlspecialchars($periode_aktif['deskripsi']); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Tombol Tambah -->
<div style="margin-bottom:20px;">
    <button class="btn-primary" onclick="toggleForm()" style="padding:12px 25px;">
        <i class="fas fa-plus-circle"></i> Tambah Periode Baru
    </button>
</div>

<!-- Form Tambah Periode -->
<div id="formTambah" style="display:none;margin-bottom:30px;">
    <div class="card">
        <div class="card-header">
            <i class="fas fa-plus-circle"></i> Form Tambah Periode
            <button class="btn-close" onclick="toggleForm()">&times;</button>
        </div>
        <div class="card-body">
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="tambah">

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Nama Kabinet:</label>
                        <input type="text" name="nama" class="form-control" placeholder="Contoh: ASTAWIDYA 2" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Tahun Mulai:</label>
                        <input type="number" name="tahun_mulai" class="form-control" value="<?php echo (int)date('Y'); ?>" min="2000" max="2100" required>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-calendar"></i> Tahun Selesai:</label>
                        <input type="number" name="tahun_selesai" class="form-control" value="<?php echo (int)date('Y') + 1; ?>" min="2000" max="2100" required>
                    </div>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Deskripsi (Opsional):</label>
                    <textarea name="deskripsi" class="form-control" rows="3" placeholder="Deskripsi singkat..."></textarea>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Simpan Periode</button>
                    <button type="button" class="btn-secondary" onclick="toggleForm()"><i class="fas fa-times"></i> Batal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Daftar Periode -->
<div class="card">
    <div class="card-header"><i class="fas fa-list"></i> Daftar Periode Kepengurusan</div>
    <div class="card-body">
        <?php if (empty($periode_list)): ?>
            <div style="text-align:center;padding:40px;">
                <i class="fas fa-calendar-times" style="font-size:3rem;color:#444;margin-bottom:15px;"></i>
                <p>Belum ada periode kepengurusan.</p>
                <button class="btn-primary" onclick="toggleForm()">Tambah Periode Sekarang</button>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="periode-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nama Kabinet</th>
                            <th>Tahun</th>
                            <th>Status</th>
                            <th>Deskripsi</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($periode_list as $p):
                            $pid = (int)$p['id'];
                        ?>
                        <tr class="periode-row" data-id="<?php echo $pid; ?>">
                            <td>#<?php echo $pid; ?></td>
                            <td><strong><?php echo htmlspecialchars($p['nama']); ?></strong></td>
                            <td><?php echo (int)$p['tahun_mulai']; ?>/<?php echo (int)$p['tahun_selesai']; ?></td>
                            <td>
                                <?php if ($p['is_active']): ?>
                                    <span class="status-badge active"><i class="fas fa-check-circle"></i> Aktif</span>
                                <?php else: ?>
                                    <span class="status-badge archived"><i class="fas fa-archive"></i> Arsip</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars(mb_substr($p['deskripsi'] ?? '', 0, 50)); ?>
                                <?php echo mb_strlen($p['deskripsi'] ?? '') > 50 ? '...' : ''; ?>
                            </td>
                            <td>
                                <div class="action-group">
                                    <?php if (!$p['is_active']): ?>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('Aktifkan periode <?php echo htmlspecialchars(addslashes($p['nama'])); ?>?')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="aktifkan">
                                            <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                            <button type="submit" class="btn-action btn-action-success" title="Aktifkan">
                                                <i class="fas fa-check-circle"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <button class="btn-action btn-action-edit"
                                            onclick="editPeriode(<?php echo $pid; ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <?php if (!$p['is_active']): ?>
                                        <form method="POST" style="display:inline;"
                                              onsubmit="return confirm('HAPUS PERMANEN periode <?php echo htmlspecialchars(addslashes($p['nama'])); ?>? Semua data akan ikut terhapus!')">
                                            <?php echo csrfField(); ?>
                                            <input type="hidden" name="action" value="hapus">
                                            <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                            <button type="submit" class="btn-action btn-action-delete" title="Hapus">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- Form Edit (hidden row) -->
                        <tr id="edit-<?php echo $pid; ?>" class="edit-row" style="display:none;">
                            <td colspan="6">
                                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">
                                    <h3 style="margin:0;color:white;">Edit Periode: <?php echo htmlspecialchars($p['nama']); ?></h3>
                                    <button class="btn-close" onclick="cancelEdit(<?php echo $pid; ?>)"
                                            style="background:none;border:none;color:white;font-size:1.5rem;">&times;</button>
                                </div>
                                <form method="POST">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="id" value="<?php echo $pid; ?>">

                                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:15px;margin-bottom:15px;">
                                        <div>
                                            <label>Nama Kabinet:</label>
                                            <input type="text" name="nama" class="form-control"
                                                   value="<?php echo htmlspecialchars($p['nama']); ?>" required>
                                        </div>
                                        <div>
                                            <label>Tahun Mulai:</label>
                                            <input type="number" name="tahun_mulai" class="form-control"
                                                   value="<?php echo (int)$p['tahun_mulai']; ?>" required>
                                        </div>
                                        <div>
                                            <label>Tahun Selesai:</label>
                                            <input type="number" name="tahun_selesai" class="form-control"
                                                   value="<?php echo (int)$p['tahun_selesai']; ?>" required>
                                        </div>
                                    </div>

                                    <div style="margin-bottom:15px;">
                                        <label>Deskripsi:</label>
                                        <textarea name="deskripsi" class="form-control" rows="3"><?php echo htmlspecialchars($p['deskripsi'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="form-actions">
                                        <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Simpan Perubahan</button>
                                        <button type="button" class="btn-secondary" onclick="cancelEdit(<?php echo $pid; ?>)">
                                            <i class="fas fa-times"></i> Batal
                                        </button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Informasi Tambahan -->
<div class="card" style="margin-top:30px;">
    <div class="card-header"><i class="fas fa-info-circle"></i> Informasi Periode</div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;">
            <div style="background:#1a1a1a;padding:20px;border-radius:8px;">
                <h4 style="color:#4A90E2;margin-top:0;"><i class="fas fa-check-circle"></i> Periode Aktif</h4>
                <p>Periode yang sedang ditampilkan di website. Admin biasa hanya bisa mengelola periode aktif ini.</p>
            </div>
            <div style="background:#1a1a1a;padding:20px;border-radius:8px;">
                <h4 style="color:#ffaa00;margin-top:0;"><i class="fas fa-archive"></i> Periode Arsip</h4>
                <p>Data periode lalu yang masih tersimpan. Bisa dilihat tapi tidak bisa diedit oleh admin biasa.</p>
            </div>
            <div style="background:#1a1a1a;padding:20px;border-radius:8px;">
                <h4 style="color:#f44336;margin-top:0;"><i class="fas fa-exclamation-triangle"></i> Hapus Periode</h4>
                <p>Menghapus periode akan menghapus SEMUA data BPH, kementerian, anggota, dan file terkait.</p>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript — tidak diubah -->
<script>
function toggleForm() {
    const form = document.getElementById('formTambah');
    form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

function editPeriode(id) {
    document.querySelectorAll('.edit-row').forEach(row => { row.style.display = 'none'; });
    const editRow = document.getElementById('edit-' + id);
    if (editRow) {
        editRow.style.display = 'table-row';
        editRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function cancelEdit(id) {
    document.getElementById('edit-' + id).style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>