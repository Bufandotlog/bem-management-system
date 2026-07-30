<?php
// admin/master-kegiatan.php
require_once __DIR__ . '/header.php';

// Pastikan hanya admin / superadmin yang bisa akses
if (!($isSuperadmin || $admin_role === 'admin')) {
    redirect('admin/dashboard.php', 'Akses ditolak: Hanya Admin yang dapat mengelola Kegiatan.', 'error');
}

$periode_id = getUserPeriode();
$error = '';
$success = '';

// Proses Hapus
if (isset($_GET['del']) && is_numeric($_GET['del']) && csrfVerify($_GET['token'] ?? '')) {
    $del_id = (int)$_GET['del'];
    try {
        dbQuery("DELETE FROM kegiatan WHERE id = ? AND periode_id = ?", [$del_id, $periode_id]);
        $success = "Kegiatan berhasil dihapus beserta data panitia terkait.";
    } catch (Exception $e) {
        $error = "Gagal menghapus kegiatan: " . $e->getMessage();
    }
}

// Proses Simpan (Tambah)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    if (!csrfVerify($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $nama = trim($_POST['nama_kegiatan']);
        $deskripsi = trim($_POST['deskripsi'] ?? '');
        $tgl_mulai = $_POST['tanggal_mulai'] ? $_POST['tanggal_mulai'] : null;
        $tgl_selesai = $_POST['tanggal_selesai'] ? $_POST['tanggal_selesai'] : null;
        $ketuplat_id = !empty($_POST['ketuplat_id']) ? (int)$_POST['ketuplat_id'] : null;

        if (empty($nama)) {
            $error = "Nama kegiatan wajib diisi.";
        } else {
            try {
                $db = getDb();
                $db->begin_transaction();
                
                dbQuery("INSERT INTO kegiatan (periode_id, nama_kegiatan, deskripsi, tanggal_mulai, tanggal_selesai, created_by) VALUES (?, ?, ?, ?, ?, ?)", [
                    $periode_id, $nama, $deskripsi, $tgl_mulai, $tgl_selesai, $_SESSION['admin_id']
                ]);
                $kegiatan_id = $db->insert_id;
                
                if ($ketuplat_id) {
                    dbQuery("INSERT INTO kegiatan_panitia (kegiatan_id, user_id, event_role, ditunjuk_oleh) VALUES (?, ?, 'ketuplat', ?)", [
                        $kegiatan_id, $ketuplat_id, $_SESSION['admin_id']
                    ]);
                }
                
                $db->commit();
                $success = "Kegiatan berhasil ditambahkan.";
            } catch (Exception $e) {
                if (isset($db)) $db->rollback();
                $error = "Terjadi kesalahan: " . $e->getMessage();
            }
        }
    }
}

// Ambil daftar user ber-role 'anggota' untuk dropdown Ketuplat
$list_anggota = dbFetchAll("SELECT id, nama, username FROM users WHERE role = 'anggota' AND is_active = 1 AND (periode_id = ? OR periode_id IS NULL) ORDER BY nama ASC", [$periode_id]);

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

<div class="page-header">
    <div>
        <h1><i class="fas fa-calendar-check"></i> Manajemen Kegiatan</h1>
        <p>Mengelola daftar program kerja dan menunjuk Ketua Pelaksana (Ketuplat).</p>
    </div>
</div>

<?php if ($error) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> $error</div>"; ?>
<?php if ($success) echo "<div class='alert alert-success'><i class='fas fa-check-circle'></i> $success</div>"; ?>

<div class="dashboard-content-grid" style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
    <!-- Form Tambah -->
    <div class="card">
        <div class="card-header">
            <h3>Buat Kegiatan Baru</h3>
        </div>
        <div class="card-body">
            <form action="" method="POST">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                
                <div class="form-group">
                    <label>Nama Kegiatan <span style="color:red;">*</span></label>
                    <input type="text" name="nama_kegiatan" class="form-control" required placeholder="Contoh: MUBESMA 2026">
                </div>
                
                <div class="form-group">
                    <label>Deskripsi Acara</label>
                    <textarea name="deskripsi" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="form-group" style="display:flex; gap:10px;">
                    <div style="flex:1;">
                        <label>Tanggal Mulai</label>
                        <input type="date" name="tanggal_mulai" class="form-control">
                    </div>
                    <div style="flex:1;">
                        <label>Tanggal Selesai</label>
                        <input type="date" name="tanggal_selesai" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Tunjuk Ketua Pelaksana (Opsional)</label>
                    <select name="ketuplat_id" class="form-control">
                        <option value="">-- Pilih Anggota --</option>
                        <?php foreach($list_anggota as $anggota): ?>
                            <option value="<?php echo $anggota['id']; ?>"><?php echo htmlspecialchars($anggota['nama'] . ' (@' . $anggota['username'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #888;">Anggota yang ditunjuk akan otomatis memiliki hak akses Workspace Kegiatan ini.</small>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;"><i class="fas fa-save"></i> Buat Kegiatan</button>
            </form>
        </div>
    </div>
    
    <!-- Tabel Daftar Kegiatan -->
    <div class="card">
        <div class="card-header">
            <h3>Daftar Kegiatan</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="admin-table">
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
                                    <span class="badge" style="background: rgba(103, 58, 183, 0.2); color: #b388ff; border: 1px solid #673ab7;"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($k['nama_ketuplat']); ?></span>
                                <?php else: ?>
                                    <span style="color:#666; font-size:0.85rem;"><em>Belum Ditunjuk</em></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $s_bg = $k['status'] === 'selesai' ? '#2ecc71' : ($k['status'] === 'berjalan' ? '#3498db' : '#f1c40f');
                                ?>
                                <span class="badge" style="background: <?php echo $s_bg; ?>; color: <?php echo $k['status'] === 'persiapan' ? '#333' : '#fff'; ?>;">
                                    <?php echo ucfirst($k['status']); ?>
                                </span>
                            </td>
                            <td>
                                <a href="?del=<?php echo $k['id']; ?>&token=<?php echo htmlspecialchars(csrfToken()); ?>" class="btn-icon btn-danger" onclick="return confirm('Apakah Anda yakin ingin menghapus kegiatan ini beserta susunan kepanitiaannya?');" title="Hapus"><i class="fas fa-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
