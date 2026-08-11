<?php
// admin/pendaftaran.php - Kelola pendaftaran publik
require_once __DIR__ . '/header.php';

// Hanya superadmin atau admin yang bisa kelola
if (!in_array($admin_role, ['superadmin', 'admin'])) {
    redirect('admin/dashboard.php', 'Akses Ditolak', 'error');
}

$status_row = dbFetchOne("SELECT nilai FROM pengaturan WHERE kunci = 'status_pendaftaran'");
$status_pendaftaran = $status_row ? $status_row['nilai'] : 'tutup';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerify();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_status') {
        $new_status = ($status_pendaftaran === 'buka') ? 'tutup' : 'buka';
        dbUpsertPengaturan('status_pendaftaran', $new_status);
        redirect('admin/pendaftaran.php', "Status pendaftaran publik diubah menjadi: " . strtoupper($new_status), 'success');
    }
    
    if ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $row = dbFetchOne("SELECT * FROM pendaftaran_anggota WHERE id = ?", [$id]);
        
        if ($row && $row['status'] === 'pending') {
            // Validasi username exist
            $cek = dbFetchOne("SELECT id FROM users WHERE username = ?", [$row['username']]);
            if ($cek) {
                redirect('admin/pendaftaran.php', "Gagal: Username {$row['username']} sudah ada di sistem.", 'error');
            } else {
                $default_password = password_hash('Bem2026!', PASSWORD_DEFAULT);
                $role = 'anggota'; // default role users
                
                // Cek apakah mendaftar ke Kominfo
                if (!empty($row['kementerian_id'])) {
                    $kem_row = dbFetchOne("SELECT nama FROM kementerian WHERE id = ?", [$row['kementerian_id']]);
                    if ($kem_row && stripos($kem_row['nama'], 'kominfo') !== false) {
                        $role = 'kominfo';
                    }
                }
                
                dbBeginTransaction();
                try {
                    $periode_id = getUserPeriode();
                    
                    // Insert Users
                    $user_id = dbInsert("INSERT INTO users (username, password, nama, role, periode_id, file_ttd) VALUES (?, ?, ?, ?, ?, ?)", [
                        $row['username'], $default_password, $row['nama_lengkap'], $role, $periode_id, $row['file_ttd']
                    ]);
                    
                    // Insert Kepengurusan
                    if ($row['penempatan'] === 'BPH') {
                        $bph_id = 1; // Inti
                        if (strpos(strtolower($row['jabatan']), 'sekretaris') !== false) {
                            $bph_id = 2; // Sekretariat
                            if (strtolower(trim($row['jabatan'])) === 'sekretaris umum i' && !empty($row['file_ttd'])) {
                                dbUpsertPengaturan('ttd_sekretaris_name', strtoupper($row['nama_lengkap']));
                                dbUpsertPengaturan('ttd_sekretaris_jabatan', 'Sekretaris BEM INSTBUNAS Majalengka');
                                dbUpsertPengaturan('ttd_sekretaris_image', $row['file_ttd']);
                            }
                        }
                        if (strpos(strtolower($row['jabatan']), 'bendahara') !== false) $bph_id = 3; // Kebendaharaan
                        
                        dbInsert("INSERT INTO anggota_bph (periode_id, created_by, bph_id, user_id, nama, jabatan, foto) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                            $periode_id, $_SESSION['admin_id'], $bph_id, $user_id, $row['nama_lengkap'], $row['jabatan'], $row['file_ttd']
                        ]);
                    } else {
                        dbInsert("INSERT INTO anggota_kementerian (periode_id, created_by, kementerian_id, user_id, nama, jabatan, foto) VALUES (?, ?, ?, ?, ?, ?, ?)", [
                            $periode_id, $_SESSION['admin_id'], $row['kementerian_id'], $user_id, $row['nama_lengkap'], $row['jabatan'], $row['file_ttd']
                        ]);
                    }
                    
                    dbQuery("UPDATE pendaftaran_anggota SET status = 'approved' WHERE id = ?", [$id]);
                    dbCommit();
                    redirect('admin/pendaftaran.php', 'Pendaftaran disetujui! Akun berhasil dibuat dan ditempatkan.', 'success');
                } catch (Exception $e) {
                    dbRollback();
                    redirect('admin/pendaftaran.php', 'Gagal memproses persetujuan: ' . $e->getMessage(), 'error');
                }
            }
        }
    }
    
    if ($action === 'reject') {
        $id = (int)($_POST['id'] ?? 0);
        dbQuery("UPDATE pendaftaran_anggota SET status = 'rejected' WHERE id = ?", [$id]);
        redirect('admin/pendaftaran.php', 'Pendaftaran berhasil ditolak.', 'success');
    }
}

$pending_list = dbFetchAll("SELECT p.*, k.nama as nama_kementerian FROM pendaftaran_anggota p LEFT JOIN kementerian k ON p.kementerian_id = k.id WHERE p.status = 'pending' ORDER BY p.created_at DESC");
?>

<style>
:root {
    --primary-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
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
    border-spacing: 0 12px;
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
    padding: 18px 20px;
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

.badge {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-block;
}
.badge-warning { background: rgba(241, 196, 15, 0.15); color: #f1c40f; border: 1px solid rgba(241, 196, 15, 0.3); }
.badge-primary { background: rgba(52, 152, 219, 0.15); color: #3498db; border: 1px solid rgba(52, 152, 219, 0.3); }
.badge-success { background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); }

.btn-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
}
.btn-approve {
    background: rgba(46, 204, 113, 0.15);
    color: #2ecc71;
    border: 1px solid rgba(46, 204, 113, 0.3);
}
.btn-approve:hover { background: rgba(46, 204, 113, 0.25); transform: translateY(-2px); }
.btn-reject {
    background: rgba(231, 76, 60, 0.15);
    color: #e74c3c;
    border: 1px solid rgba(231, 76, 60, 0.3);
}
.btn-reject:hover { background: rgba(231, 76, 60, 0.25); transform: translateY(-2px); }

.header-btn {
    padding: 12px 24px;
    border-radius: 12px;
    font-weight: 700;
    font-size: 0.95rem;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}
.btn-tutup { background: rgba(231, 76, 60, 0.1); color: #e74c3c; border: 1px solid rgba(231, 76, 60, 0.3); }
.btn-tutup:hover { background: rgba(231, 76, 60, 0.2); transform: translateY(-2px); }
.btn-buka { background: rgba(46, 204, 113, 0.1); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); }
.btn-buka:hover { background: rgba(46, 204, 113, 0.2); transform: translateY(-2px); }
</style>

<div class="page-header">
    <div>
        <h1><i class="fas fa-user-plus"></i> Kelola Pendaftaran Anggota</h1>
        <p>Setujui atau tolak pendaftaran dari jalur publik.</p>
    </div>
    <form method="POST" style="margin: 0;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="toggle_status">
        <?php if ($status_pendaftaran === 'buka'): ?>
            <button type="submit" class="header-btn btn-tutup"><i class="fas fa-lock"></i> Tutup Pendaftaran Publik</button>
        <?php else: ?>
            <button type="submit" class="header-btn btn-buka"><i class="fas fa-lock-open"></i> Buka Pendaftaran Publik</button>
        <?php endif; ?>
    </form>
</div>

<div class="card" style="padding: 20px;">
    <?php if (empty($pending_list)): ?>
        <div style="text-align: center; color: #888; padding: 40px;">
            <i class="fas fa-inbox fa-3x" style="margin-bottom: 10px;"></i>
            <p>Tidak ada pendaftaran baru yang menunggu persetujuan.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                    <tr>
                        <th style="width: 15%;">Tanggal</th>
                        <th style="width: 25%;">Nama Lengkap</th>
                        <th style="width: 20%;">Username Req.</th>
                        <th style="width: 25%;">Penempatan</th>
                        <th style="width: 15%;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_list as $row): ?>
                        <tr>
                            <td data-label="Tanggal">
                                <span style="color: #aaa; font-size: 0.9rem;"><i class="fas fa-clock" style="margin-right: 5px; color: #555;"></i><?php echo date('d M Y', strtotime($row['created_at'])); ?></span>
                            </td>
                            <td data-label="Nama">
                                <strong style="color: #eee; font-size: 1.05rem; display: block; margin-bottom: 4px;"><?php echo htmlspecialchars($row['nama_lengkap']); ?></strong>
                                <?php if(!empty($row['file_ttd'])): ?>
                                    <span class="badge badge-success" style="font-size: 0.65rem; padding: 4px 8px;"><i class="fas fa-signature"></i> TTD Tersimpan</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Username">
                                <code style="background: rgba(255,255,255,0.05); padding: 6px 10px; border-radius: 6px; color: var(--accent-color); font-size: 0.9rem;"><i class="fas fa-user-circle" style="margin-right: 5px;"></i><?php echo htmlspecialchars($row['username']); ?></code>
                            </td>
                            <td data-label="Penempatan">
                                <?php if ($row['penempatan'] === 'BPH'): ?>
                                    <span class="badge badge-warning" style="margin-bottom: 5px;"><i class="fas fa-crown"></i> BPH</span>
                                <?php else: ?>
                                    <span class="badge badge-primary" style="margin-bottom: 5px;"><i class="fas fa-users"></i> <?php echo htmlspecialchars($row['nama_kementerian']); ?></span>
                                <?php endif; ?>
                                <br>
                                <span style="color: #bbb; font-size: 0.85rem;"><i class="fas fa-briefcase" style="margin-right: 5px; color: #555;"></i><?php echo htmlspecialchars($row['jabatan']); ?></span>
                            </td>
                            <td class="td-aksi">
                                <div style="display: flex; gap: 8px;">
                                    <form method="POST" onsubmit="return confirm('Setujui dan buatkan akun untuk anggota ini? Password default: Bem2026!');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn-action btn-approve" title="Setujui"><i class="fas fa-check-circle"></i> Setujui</button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Tolak pendaftaran ini?');">
                                        <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                        <button type="submit" class="btn-action btn-reject" title="Tolak"><i class="fas fa-times-circle"></i> Tolak</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
