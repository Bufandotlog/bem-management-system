<?php
// admin/workspace-panitia.php
require_once __DIR__ . '/header.php';

$kegiatan_id = isset($_GET['kegiatan_id']) ? (int)$_GET['kegiatan_id'] : 0;
if (!$kegiatan_id) {
    redirect('admin/dashboard.php', 'ID Kegiatan tidak valid.', 'error');
}

// Hanya Ketuplat, Sekretaris Panitia, dan Admin/Superadmin yang bisa mengakses
requireEventAccess($kegiatan_id, ['ketuplat', 'sekretaris_panitia']);

$error = '';
$success = '';

// Ambil info kegiatan
$kegiatan = dbFetchOne("SELECT * FROM kegiatan WHERE id = ?", [$kegiatan_id], "i");
if (!$kegiatan) {
    redirect('admin/dashboard.php', 'Kegiatan tidak ditemukan.', 'error');
}

// Proses Hapus Panitia
if (isset($_GET['del_panitia']) && is_numeric($_GET['del_panitia']) && csrfVerify($_GET['token'] ?? '')) {
    $del_id = (int)$_GET['del_panitia'];
    try {
        dbQuery("DELETE FROM kegiatan_panitia WHERE id = ? AND kegiatan_id = ? AND event_role != 'ketuplat'", [$del_id, $kegiatan_id], "ii");
        $success = "Anggota panitia berhasil dihapus.";
    } catch (Exception $e) {
        $error = "Gagal menghapus panitia: " . $e->getMessage();
    }
}

// Proses Tambah Panitia
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_panitia') {
    if (!csrfVerify($_POST['csrf_token'] ?? '')) {
        $error = "Token keamanan tidak valid.";
    } else {
        $user_id = (int)$_POST['user_id'];
        $event_role = $_POST['event_role'];
        
        $valid_roles = ['sekretaris_panitia', 'sie_acara', 'sie_logistik', 'sie_humas', 'sie_konsumsi', 'anggota_panitia'];
        
        if (empty($user_id) || !in_array($event_role, $valid_roles)) {
            $error = "Input tidak valid.";
        } else {
            // Cek apakah user sudah ada di kepanitiaan ini
            $exists = dbFetchOne("SELECT id FROM kegiatan_panitia WHERE kegiatan_id = ? AND user_id = ?", [$kegiatan_id, $user_id], "ii");
            
            if ($exists) {
                $error = "Anggota ini sudah tergabung dalam kepanitiaan acara ini.";
            } else {
                try {
                    dbQuery("INSERT INTO kegiatan_panitia (kegiatan_id, user_id, event_role, ditunjuk_oleh) VALUES (?, ?, ?, ?)", [
                        $kegiatan_id, $user_id, $event_role, $_SESSION['admin_id']
                    ]);
                    $success = "Anggota berhasil ditambahkan ke panitia.";
                } catch (Exception $e) {
                    $error = "Gagal menambahkan anggota: " . $e->getMessage();
                }
            }
        }
    }
}

// Ambil list semua anggota BEM yang bisa dipilih
$list_anggota = dbFetchAll("SELECT id, nama, username FROM users WHERE role = 'anggota' AND is_active = 1 AND (periode_id = ? OR periode_id IS NULL) ORDER BY nama ASC", [getUserPeriode()]);

// Ambil list panitia saat ini
$list_panitia = dbFetchAll("
    SELECT kp.*, u.nama, u.username 
    FROM kegiatan_panitia kp
    JOIN users u ON kp.user_id = u.id
    WHERE kp.kegiatan_id = ?
    ORDER BY FIELD(kp.event_role, 'ketuplat', 'sekretaris_panitia', 'sie_acara', 'sie_logistik', 'sie_humas', 'sie_konsumsi', 'anggota_panitia'), u.nama ASC
", [$kegiatan_id]);

?>

<div class="page-header">
    <div>
        <h1><i class="fas fa-users-cog"></i> Workspace: Susunan Panitia</h1>
        <p>Kegiatan: <strong><?php echo htmlspecialchars($kegiatan['nama_kegiatan']); ?></strong></p>
    </div>
    <div style="display: flex; gap: 10px;">
        <span class="badge" style="background:#f39c12; font-size:1rem; padding: 10px 15px;"><i class="fas fa-calendar"></i> <?php echo date('d M Y', strtotime($kegiatan['tanggal_mulai'])); ?></span>
    </div>
</div>

<?php if ($error) echo "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle'></i> $error</div>"; ?>
<?php if ($success) echo "<div class='alert alert-success'><i class='fas fa-check-circle'></i> $success</div>"; ?>

<div class="dashboard-content-grid" style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px;">
    
    <!-- Form Tambah Panitia -->
    <div class="card">
        <div class="card-header">
            <h3>Plotting Anggota Panitia</h3>
        </div>
        <div class="card-body">
            <form action="" method="POST">
                <input type="hidden" name="action" value="add_panitia">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrfToken()); ?>">
                
                <div class="form-group">
                    <label>Pilih Anggota</label>
                    <select name="user_id" class="form-control" required>
                        <option value="">-- Pilih --</option>
                        <?php foreach($list_anggota as $anggota): ?>
                            <option value="<?php echo $anggota['id']; ?>"><?php echo htmlspecialchars($anggota['nama']); ?> (@<?php echo htmlspecialchars($anggota['username']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Posisi / Divisi</label>
                    <select name="event_role" class="form-control" required>
                        <option value="sekretaris_panitia">Sekretaris Panitia</option>
                        <option value="sie_acara">Seksi Acara (Rundown)</option>
                        <option value="sie_logistik">Seksi Logistik (Barang)</option>
                        <option value="sie_humas">Seksi Humas</option>
                        <option value="sie_konsumsi">Seksi Konsumsi</option>
                        <option value="anggota_panitia">Anggota / Staff Biasa</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-primary" style="width: 100%;"><i class="fas fa-plus"></i> Tambahkan ke Panitia</button>
            </form>
        </div>
    </div>
    
    <!-- Tabel Susunan Panitia -->
    <div class="card">
        <div class="card-header">
            <h3>Susunan Kepanitiaan</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Nama Anggota</th>
                        <th>Username</th>
                        <th>Posisi (Event Role)</th>
                        <th width="80">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($list_panitia)): ?>
                        <tr><td colspan="4" style="text-align:center;">Belum ada panitia yang ditambahkan.</td></tr>
                    <?php else: ?>
                        <?php foreach($list_panitia as $p): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['nama']); ?></strong></td>
                            <td>@<?php echo htmlspecialchars($p['username']); ?></td>
                            <td>
                                <?php
                                $role_colors = [
                                    'ketuplat' => '#e74c3c',
                                    'sekretaris_panitia' => '#9b59b6',
                                    'sie_acara' => '#3498db',
                                    'sie_logistik' => '#e67e22',
                                    'sie_humas' => '#1abc9c',
                                    'sie_konsumsi' => '#f1c40f',
                                    'anggota_panitia' => '#95a5a6'
                                ];
                                $bg = $role_colors[$p['event_role']] ?? '#666';
                                ?>
                                <span class="badge" style="background: <?php echo $bg; ?>; color: #fff;">
                                    <?php echo strtoupper(str_replace('_', ' ', $p['event_role'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if($p['event_role'] !== 'ketuplat'): ?>
                                <a href="?kegiatan_id=<?php echo $kegiatan_id; ?>&del_panitia=<?php echo $p['id']; ?>&token=<?php echo htmlspecialchars(csrfToken()); ?>" class="btn-icon btn-danger" onclick="return confirm('Keluarkan anggota ini dari panitia?');" title="Hapus"><i class="fas fa-times"></i></a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
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
