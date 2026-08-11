<?php
// admin/pendaftaran.php - Kelola pendaftaran publik
require_once __DIR__ . '/../core/header.php';

// Hanya superadmin, admin, atau kominfo yang bisa kelola
if (!in_array($admin_role, ['superadmin', 'admin', 'kominfo'])) {
    redirect('admin/core/dashboard.php', 'Akses Ditolak', 'error');
}

$status_row = dbFetchOne("SELECT nilai FROM pengaturan WHERE kunci = 'status_pendaftaran'");
$status_pendaftaran = $status_row ? $status_row['nilai'] : 'tutup';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        redirect('admin/konten/pendaftaran.php', 'Token CSRF tidak valid atau telah kedaluwarsa.', 'error');
    }
    $action = $_POST['action'] ?? '';
    
    if ($action === 'toggle_status') {
        $new_status = ($status_pendaftaran === 'buka') ? 'tutup' : 'buka';
        dbUpsertPengaturan('status_pendaftaran', $new_status);
        redirect('admin/konten/pendaftaran.php', "Status pendaftaran publik diubah menjadi: " . strtoupper($new_status), 'success');
    }

    if ($action === 'update_default_password') {
        if (!in_array($admin_role, ['superadmin', 'admin'])) {
            redirect('admin/konten/pendaftaran.php', 'Akses Ditolak: Hanya Admin/Superadmin yang dapat mengubah password default.', 'error');
        }
        
        $target_periode_id = (int)($_POST['target_periode_id'] ?? getUserPeriode());
        $new_default_pw    = trim($_POST['new_default_password'] ?? '');
        
        if (mb_strlen($new_default_pw) < 8) {
            redirect('admin/konten/pendaftaran.php', 'Gagal: Password default minimal 8 karakter!', 'error');
        }
        if (!preg_match('/[A-Za-z]/', $new_default_pw) || !preg_match('/[0-9]/', $new_default_pw)) {
            redirect('admin/konten/pendaftaran.php', 'Gagal: Password default harus mengombinasikan huruf dan angka!', 'error');
        }
        
        dbUpsertPengaturan('default_password_periode_' . $target_periode_id, $new_default_pw);
        auditLog('UPDATE', 'pengaturan', $target_periode_id, 'Ubah password default pendaftaran periode ID: ' . $target_periode_id);
        redirect('admin/konten/pendaftaran.php', 'Password default pendaftaran berhasil diperbarui!', 'success');
    }
    
    if ($action === 'approve') {
        $id = (int)($_POST['id'] ?? 0);
        $row = dbFetchOne("SELECT * FROM pendaftaran_anggota WHERE id = ?", [$id]);
        
        if ($row && $row['status'] === 'pending') {
            // Validasi username exist
            $cek = dbFetchOne("SELECT id FROM users WHERE username = ?", [$row['username']]);
            if ($cek) {
                redirect('admin/konten/pendaftaran.php', "Gagal: Username {$row['username']} sudah ada di sistem.", 'error');
            } else {
                $periode_id = getUserPeriode();
                $raw_default_pw = getDefaultPassword($periode_id);
                $default_password = password_hash($raw_default_pw, PASSWORD_DEFAULT);
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

                    // FCM Notification ke pendaftar yang disetujui
                    sendFcmNotification(
                        $user_id,
                        "🎉 Selamat! Akun BEM Anda Aktif",
                        "Selamat " . $row['nama_lengkap'] . ", pendaftaran Anda disetujui. Akun Anda (" . $row['username'] . ") kini telah aktif.",
                        [
                            'click_action' => '/admin/auth/login.php',
                            'type' => 'account_approved'
                        ]
                    );

                    redirect('admin/konten/pendaftaran.php', 'Pendaftaran disetujui! Akun berhasil dibuat dengan password default.', 'success');
                } catch (Exception $e) {
                    dbRollback();
                    redirect('admin/konten/pendaftaran.php', 'Gagal memproses persetujuan: ' . $e->getMessage(), 'error');
                }
            }
        }
    }
    
    if ($action === 'reject') {
        $id = (int)($_POST['id'] ?? 0);
        dbQuery("UPDATE pendaftaran_anggota SET status = 'rejected' WHERE id = ?", [$id]);
        redirect('admin/konten/pendaftaran.php', 'Pendaftaran berhasil ditolak.', 'success');
    }
}

$pending_list = dbFetchAll("SELECT p.*, k.nama as nama_kementerian FROM pendaftaran_anggota p LEFT JOIN kementerian k ON p.kementerian_id = k.id WHERE p.status = 'pending' ORDER BY p.created_at DESC");
$current_periode_id = getUserPeriode();
$active_def_pw = getDefaultPassword($current_periode_id);
$periode_list = dbFetchAll("SELECT id, nama, tahun_mulai, tahun_selesai, is_active FROM periode_kepengurusan ORDER BY tahun_mulai DESC");
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

<?php if (in_array($admin_role, ['superadmin', 'admin'])): ?>
<div class="card" style="padding: 24px; margin-bottom: 25px; border-left: 4px solid var(--accent-color);">
    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px; margin-bottom: 15px;">
        <div>
            <h3 style="margin: 0 0 5px 0; color: #fff; font-size: 1.15rem; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-key text-biru"></i> Pengaturan Password Default Pendaftaran
            </h3>
            <p style="margin: 0; color: #888; font-size: 0.85rem;">
                Password awal yang diberikan otomatis saat pendaftaran akun disetujui. 
            </p>
        </div>
        <div style="background: rgba(74, 144, 226, 0.1); border: 1px solid rgba(74, 144, 226, 0.2); padding: 8px 14px; border-radius: 8px; font-size: 0.85rem; color: var(--accent-color);">
            <i class="fas fa-shield-alt"></i> Password Default Saat Ini: <strong id="defPwText" style="font-family: monospace; letter-spacing: 1px; color: #fff; background: rgba(0,0,0,0.3); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($active_def_pw); ?></strong>
        </div>
    </div>

    <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) 140px; gap: 15px; align-items: end;">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="update_default_password">
        
        <?php if ($admin_role === 'superadmin'): ?>
        <div>
            <label style="display: block; font-size: 0.8rem; color: #aaa; margin-bottom: 6px; font-weight: 600;">Target Periode Kepengurusan</label>
            <select name="target_periode_id" required style="width: 100%; padding: 10px 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: #fff; font-size: 0.9rem;">
                <?php foreach ($periode_list as $p): ?>
                    <option value="<?php echo $p['id']; ?>" <?php echo $p['id'] == $current_periode_id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($p['nama'] . ' (' . $p['tahun_mulai'] . '/' . $p['tahun_selesai'] . ')' . ($p['is_active'] ? ' - AKTIF' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="target_periode_id" value="<?php echo $current_periode_id; ?>">
        <?php endif; ?>

        <div>
            <label style="display: block; font-size: 0.8rem; color: #aaa; margin-bottom: 6px; font-weight: 600;">Password Default Baru</label>
            <div style="position: relative; display: flex; align-items: center;">
                <input type="password" name="new_default_password" id="newDefaultPwInput" required minlength="8" placeholder="Min. 8 karakter, huruf + angka" style="width: 100%; padding: 10px 40px 10px 12px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 8px; color: #fff; font-size: 0.9rem;">
                <button type="button" onclick="toggleDefaultPwVisibility()" style="position: absolute; right: 10px; background: none; border: none; color: #888; cursor: pointer; padding: 4px;" title="Tampilkan/Sembunyikan">
                    <i class="fas fa-eye" id="toggleDefaultPwIcon"></i>
                </button>
            </div>
        </div>

        <div>
            <button type="submit" class="header-btn" style="background: var(--accent-color); color: #fff; width: 100%; justify-content: center; height: 42px;">
                <i class="fas fa-save"></i> Simpan
            </button>
        </div>
    </form>

    <div style="margin-top: 15px; padding: 10px 14px; background: rgba(241, 196, 15, 0.08); border-left: 3px solid #f1c40f; border-radius: 6px; font-size: 0.8rem; color: #ccc; line-height: 1.4;">
        <i class="fas fa-info-circle" style="color: #f1c40f; margin-right: 5px;"></i>
        <strong>Informasi Penting:</strong> Perubahan password default ini hanya berlaku untuk pendaftaran anggota baru yang disetujui di masa mendatang. Password milik anggota aktif yang sudah mengubah password-nya tidak akan ikut berubah.
    </div>
</div>

<script>
function toggleDefaultPwVisibility() {
    const input = document.getElementById('newDefaultPwInput');
    const icon = document.getElementById('toggleDefaultPwIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}
</script>
<?php endif; ?>

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
                                    <form method="POST" onsubmit="return confirm('Setujui dan buatkan akun untuk anggota ini? Password default: <?php echo htmlspecialchars(addslashes($active_def_pw)); ?>');">
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

<?php require_once __DIR__ . '/../core/footer.php'; ?>
