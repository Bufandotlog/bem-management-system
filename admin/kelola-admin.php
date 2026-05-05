<?php
// admin/kelola-admin.php
// VERSI: 4.1 - REMOVE reset_2fa, ADD toggle_aktif
//   CHANGED: Hapus fitur Reset 2FA — superadmin tidak bisa ubah 2FA orang lain
//   CHANGED: Tambah toggle Aktif/Nonaktif — cara yang benar untuk putus akses
//   CHANGED: Nonaktifkan akun langsung putuskan semua sesi aktifnya

$page_css = 'kelola-admin';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/totp.php';

if ($_SESSION['admin_role'] !== 'superadmin' && !$user_can_access_all) {
    redirect('admin/dashboard.php', 'Akses ditolak!', 'error');
    exit();
}

$periode_list = dbFetchAll("SELECT * FROM periode_kepengurusan ORDER BY tahun_mulai DESC");
$admin_list   = dbFetchAll("
    SELECT u.id, u.username, u.nama, u.email, u.role, u.can_access_all,
           u.periode_id, u.totp_enabled, u.is_active, u.created_at,
           p.nama as periode_nama, p.tahun_mulai, p.tahun_selesai
    FROM users u
    LEFT JOIN periode_kepengurusan p ON u.periode_id = p.id
    ORDER BY u.role DESC, u.created_at DESC
");

$error   = '';
$success = '';

// Data untuk tampilkan QR setelah akun baru dibuat
$newAdminSecret   = null;
$newAdminUsername = null;
$newAdminQrUrl    = null;

// ============================================
// PROSES TAMBAH ADMIN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tambah') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $username   = sanitizeText($_POST['username'] ?? '', 50);
        $password   = $_POST['password'] ?? '';
        $nama       = sanitizeText($_POST['nama']  ?? '', 100);
        $emailRaw   = sanitizeText($_POST['email'] ?? '', 100);
        $email      = filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
        
        $roleInput  = strtolower($_POST['role'] ?? 'admin');
        // Normalisasi ejaan sekretaris
        if ($roleInput === 'sekertaris' || $roleInput === 'sekretaris') {
            $role = 'sekretaris';
        } else {
            $role = in_array($roleInput, ['admin','superadmin']) ? $roleInput : 'admin';
        }

        $periode_id = !empty($_POST['periode_id']) ? (int)$_POST['periode_id'] : null;
        $can_access_all = ($role === 'superadmin') ? 1 : 0;

        if (empty($username) || empty($password) || empty($nama)) {
            $error = 'Username, password, dan nama wajib diisi!';
        } elseif (($role === 'admin' || $role === 'sekretaris') && !$periode_id) {
            $error = 'Pilih periode untuk admin/sekretaris!';
        } elseif (strlen($password) < 8) {
            $error = 'Password minimal 8 karakter!';
        } else {
            $cek = dbFetchOne("SELECT id FROM users WHERE username = ?", [$username], "s");
            if ($cek) {
                $error = "Username '{$username}' sudah digunakan!";
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT, ['cost'=>12]);

                $enable_2fa = isset($_POST['enable_2fa']) && $_POST['enable_2fa'] === '1';
                $secret = $enable_2fa ? totpGenerateSecret() : null;
                $totp_enabled = $enable_2fa ? 1 : 0;

                dbBeginTransaction();
                try {
                    if ($role === 'superadmin') {
                        dbQuery(
                            "INSERT INTO users (username, password, nama, email, role, can_access_all, totp_secret, totp_enabled, is_active)
                             VALUES (?, ?, ?, ?, 'superadmin', 1, ?, ?, 1)",
                            [$username, $hashed, $nama, $email, $secret, $totp_enabled], "sssssi"
                        );
                    } else {
                        dbQuery(
                            "INSERT INTO users (username, password, nama, email, role, periode_id, can_access_all, totp_secret, totp_enabled, is_active)
                             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, 1)",
                            [$username, $hashed, $nama, $email, $role, $periode_id, $secret, $totp_enabled], "sssssisi"
                        );
                    }
                    dbCommit();
                    $newId = dbLastId();
                    auditLog('CREATE', 'users', $newId, 'Tambah admin: ' . $username . ' (' . $role . ')');

                    if ($enable_2fa) {
                        $newAdminSecret   = $secret;
                        $newAdminUsername = $username;
                        $newAdminQrUrl    = totpGetUri($secret, $username, 'BEM Admin');
                        $success = "Admin '{$username}' berhasil dibuat! Bagikan QR/secret di bawah ke admin tersebut.";
                    } else {
                        $success = "Admin '{$username}' berhasil dibuat TANPA 2FA aktif.";
                    }

                    // Refresh daftar
                    $admin_list = dbFetchAll("
                        SELECT u.id, u.username, u.nama, u.email, u.role, u.can_access_all,
                               u.periode_id, u.totp_enabled, u.is_active, u.created_at,
                               p.nama as periode_nama, p.tahun_mulai, p.tahun_selesai
                        FROM users u LEFT JOIN periode_kepengurusan p ON u.periode_id = p.id
                        ORDER BY u.role DESC, u.created_at DESC
                    ");

                } catch (Exception $e) {
                    dbRollback();
                    error_log("[KELOLA-ADMIN] Tambah: " . $e->getMessage());
                    $error = 'Gagal menambahkan admin. Silakan coba lagi.';
                }
            }
        }
    }
}

// ============================================
// PROSES HAPUS ADMIN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['admin_id']) {
            $error = 'Tidak dapat menghapus akun sendiri!';
        } elseif ($id > 0) {
            dbBeginTransaction();
            try {
                // Matikan cek foreign key sementara agar tidak macet
                dbQuery("SET FOREIGN_KEY_CHECKS = 0");

                $existingTables = [];
                $res = dbFetchAll("SHOW TABLES");
                foreach ($res as $r) { $existingTables[] = reset($r); }

                // 1. Bersihkan referensi created_by di tabel yang memilikinya
                $created_by_tables = [
                    'anggota_bph', 'kementerian', 'anggota_kementerian', 
                    'struktur_organisasi', 'berita', 'arsip_surat', 'short_links'
                ];
                foreach ($created_by_tables as $table) {
                    if (in_array($table, $existingTables)) {
                        try {
                            dbQuery("UPDATE `$table` SET created_by = NULL WHERE created_by = ?", [$id], "i");
                        } catch (Exception $e) {
                            // Abaikan jika error kolom tidak ada, lanjut ke tabel berikutnya
                            continue;
                        }
                    }
                }
                
                // 2. Bersihkan referensi updated_by di tabel yang memilikinya
                $updated_by_tables = ['struktur_organisasi'];
                foreach ($updated_by_tables as $table) {
                    if (in_array($table, $existingTables)) {
                        try {
                            dbQuery("UPDATE `$table` SET updated_by = NULL WHERE updated_by = ?", [$id], "i");
                        } catch (Exception $e) {
                            continue;
                        }
                    }
                }

                // 3. Hapus data terkait di tabel user_id
                $user_id_tables = ['user_sessions', 'audit_log', 'signatures', 'notifikasi'];
                foreach ($user_id_tables as $table) {
                    if (in_array($table, $existingTables)) {
                        try {
                            dbQuery("DELETE FROM `$table` WHERE user_id = ?", [$id], "i");
                        } catch (Exception $e) {
                            continue;
                        }
                    }
                }

                // 4. Baru hapus user-nya
                dbQuery("DELETE FROM users WHERE id = ?", [$id], "i");
                
                // Hidupkan kembali cek foreign key
                dbQuery("SET FOREIGN_KEY_CHECKS = 1");
                
                dbCommit();
                auditLog('DELETE', 'users', $id, 'Hapus admin ID: ' . $id);
                redirect('admin/kelola-admin.php', 'Admin berhasil dihapus secara permanen!', 'success');
                exit();
            } catch (Exception $e) {
                dbRollback();
                dbQuery("SET FOREIGN_KEY_CHECKS = 1");
                $error = 'Gagal menghapus admin: ' . $e->getMessage();
            }
        }
    }
}

// ============================================
// PROSES RESET PASSWORD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $id          = (int) ($_POST['id'] ?? 0);
        $new_password = $_POST['new_password'] ?? '';

        if ($id > 0 && strlen($new_password) >= 8) {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT, ['cost'=>12]);
            dbQuery("UPDATE users SET password = ? WHERE id = ?", [$hashed, $id], "si");
            auditLog('UPDATE', 'users', $id, 'Reset password admin ID: ' . $id);
            redirect('admin/kelola-admin.php', 'Password berhasil direset!', 'success');
            exit();
        } else {
            $error = 'Password minimal 8 karakter.';
        }
    }
}

// ============================================
// PROSES TOGGLE AKTIF / NONAKTIF AKUN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_aktif') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['admin_id']) {
            $error = 'Tidak dapat menonaktifkan akun sendiri!';
        } elseif ($id > 0) {
            $target = dbFetchOne(
                "SELECT username, is_active FROM users WHERE id = ?", [$id], "i"
            );
            if ($target) {
                $newStatus   = $target['is_active'] ? 0 : 1;
                dbQuery("UPDATE users SET is_active = ? WHERE id = ?", [$newStatus, $id], "ii");
                $statusLog = $newStatus ? 'diaktifkan' : 'dinonaktifkan';
                auditLog('UPDATE', 'users', $id, 'Akun ' . $target['username'] . ' ' . $statusLog);
                // Nonaktifkan → putuskan semua sesi aktifnya seketika
                if (!$newStatus) {
                    dbQuery("DELETE FROM user_sessions WHERE user_id = ?", [$id], "i");
                }
                $statusText = $newStatus ? 'diaktifkan kembali' : 'dinonaktifkan';
                redirect('admin/kelola-admin.php',
                    "Akun '" . htmlspecialchars($target['username']) . "' berhasil {$statusText}.",
                    'success');
                exit();
            }
        }
    }
}

// ============================================
// PROSES UBAH ROLE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ubah_role') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } else {
        $id         = (int) ($_POST['id'] ?? 0);
        $newRole    = in_array($_POST['new_role'] ?? '', ['admin','superadmin','sekretaris']) ? $_POST['new_role'] : 'admin';
        $newPeriode = !empty($_POST['new_periode']) ? (int)$_POST['new_periode'] : null;
        
        if ($id === (int)$_SESSION['admin_id'] && $newRole !== 'superadmin') {
            $error = 'Jangan turunkan pangkat diri sendiri!';
        } elseif (($newRole === 'admin' || $newRole === 'sekretaris') && !$newPeriode) {
            $error = 'Admin/Sekretaris wajib dikaitkan dengan satu periode!';
        } elseif ($id > 0) {
            // Jika superadmin, periode bisa dikosongkan (akses semua)
            if ($newRole === 'superadmin') $newPeriode = null;
            
            dbQuery("UPDATE users SET role = ?, periode_id = ? WHERE id = ?", [$newRole, $newPeriode, $id], "sii");
            auditLog('UPDATE', 'users', $id, 'Ubah role/periode admin ID ' . $id . ' menjadi ' . $newRole . ' (Periode: ' . ($newPeriode ?? 'Semua') . ')');
            redirect('admin/kelola-admin.php', 'Peran dan Periode admin berhasil diperbarui!', 'success');
            exit();
        }
    }
}

// Flash message dari redirect
if (isset($_SESSION['flash'])) {
    $success = $_SESSION['flash']['message'];
    unset($_SESSION['flash']);
}
?>

<!-- Page Header -->
<div class="page-header">
    <h1><i class="fas fa-user-shield"></i> Kelola Admin Periode</h1>
    <p style="margin-bottom: 30px;">Atur admin untuk setiap periode kepengurusan (Superadmin Only)</p>
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

<?php /* ===== TAMPILKAN QR CODE SETELAH BUAT AKUN BARU / RESET 2FA ===== */ ?>
<?php if ($newAdminSecret && $newAdminQrUrl): ?>
<div style="background:#1a1a2e;border:2px solid #4A90E2;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;">
    <h3 style="color:#4A90E2;margin-bottom:.5rem;font-size:1rem;">
        <i class="fas fa-qrcode"></i> Setup 2FA untuk: <strong><?php echo htmlspecialchars($newAdminUsername, ENT_QUOTES, 'UTF-8'); ?></strong>
    </h3>
    <p style="color:#f44336;font-size:.85rem;margin-bottom:1rem;">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Simpan sekarang!</strong> Secret ini hanya ditampilkan sekali dan tidak bisa dilihat lagi.
    </p>
    <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start;">
        <!-- QR Code -->
        <div style="text-align:center;flex-shrink:0;">
            <div id="qrcode" style="display:inline-block;padding:12px;background:white;border-radius:8px;"></div>
            <br>
            <button type="button" id="downloadQrBtn" style="margin-top:12px;padding:8px 16px;background:#2E7D32;color:white;border:none;border-radius:6px;font-size:.9rem;cursor:pointer;"><i class="fas fa-download"></i> Download QR</button>
            <p style="color:#888;font-size:.75rem;margin-top:.5rem;">Scan dengan Aegis / Google Authenticator</p>
        </div>
        <!-- Secret text -->
        <div style="flex:1;min-width:220px;">
            <p style="color:#aaa;font-size:.85rem;margin-bottom:.5rem;">Atau masukkan secret key manual:</p>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <div style="background:#111;border:1px solid #333;border-radius:8px;padding:1rem;font-family:monospace;font-size:1.1rem;letter-spacing:4px;color:#4A90E2;word-break:break-all;flex:1;text-align:center;">
                    <?php echo htmlspecialchars(strtoupper($newAdminSecret), ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <button type="button" id="copySecretBtn" style="padding:16px;background:#333;color:white;border:none;border-radius:8px;cursor:pointer;" title="Salin Key">
                    <i class="fas fa-copy"></i>
                </button>
            </div>
            <p style="color:#555;font-size:.75rem;margin-top:.5rem;">Type: TOTP &bull; Algorithm: SHA1 &bull; Digits: 6 &bull; Period: 30s</p>
            <p style="color:#aaa;font-size:.8rem;margin-top:1rem;">
                Admin tinggal scan/masukkan secret ini di Aegis, lalu bisa langsung login dengan 2FA.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Grid 2 Kolom -->
<div class="admin-grid">

    <!-- Kolom Kiri: Form Tambah Admin -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-user-plus"></i> Tambah Admin Baru
        </div>
        <div class="card-body">
            <form method="POST" id="formTambah">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="tambah">

                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username:</label>
                    <input type="text" name="username" class="form-control" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password:</label>
                    <input type="password" name="password" class="form-control" required minlength="8" autocomplete="new-password">
                    <small>Minimal 8 karakter. Admin bisa ganti sendiri via pengaturan.</small>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-id-card"></i> Nama Lengkap:</label>
                    <input type="text" name="nama" class="form-control" required>
                </div>

                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email:</label>
                    <input type="email" name="email" class="form-control">
                </div>

                <div class="form-group">
                    <label><i class="fas fa-user-tag"></i> Role:</label>
                    <select name="role" class="form-control" id="roleSelect" onchange="togglePeriodeField()">
                        <option value="admin" selected>Admin Biasa</option>
                        <option value="sekretaris">Sekretaris</option>
                        <option value="superadmin">Superadmin</option>
                    </select>
                </div>

                <div class="form-group" id="periodeField">
                    <label><i class="fas fa-calendar-alt"></i> Periode:</label>
                    <select name="periode_id" class="form-control">
                        <option value="">-- Pilih Periode --</option>
                        <?php foreach ($periode_list as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>">
                                <?php echo htmlspecialchars($p['nama']); ?>
                                (<?php echo (int)$p['tahun_mulai']; ?>/<?php echo (int)$p['tahun_selesai']; ?>)
                                <?php echo $p['is_active'] ? '• AKTIF' : ''; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Admin biasa hanya bisa mengelola SATU periode</small>
                </div>

                <div class="form-group" style="background:rgba(74,144,226,.08);border:1px solid rgba(74,144,226,.3);border-radius:8px;padding:1rem;">
                    <label style="margin-bottom: 5px; color:#4A90E2;"><i class="fas fa-shield-alt"></i> Pengaturan Keamanan</label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="checkbox" name="enable_2fa" value="1"> <strong>Aktifkan 2FA Langsung</strong>
                    </label>
                    <small style="display:block; margin-top:5px; color:#8BB9F0;">Jika dicentang, sistem akan men-generate Secret Key & QR Code saat akun dibuat. Lepas centang jika Admin ingin menyetel 2FA-nya sendiri nanti secara mandiri di profilnya.</small>
                </div>

                <button type="submit" class="btn-primary" style="width:100%;padding:12px;">
                    <i class="fas fa-save"></i> Tambah Admin
                </button>
            </form>
        </div>
    </div>

    <!-- Kolom Kanan: Daftar Admin -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list"></i> Daftar Admin (<?php echo count($admin_list); ?>)
        </div>
        <div class="card-body">
            <div style="overflow-x: auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Nama</th>
                            <th>Role</th>
                            <th>Periode</th>
                            <th>2FA</th>
                            <th>Status</th>
                            <th style="text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($admin_list)): ?>
                            <tr>
                                <td colspan="6" style="padding:30px;text-align:center;color:#666;">
                                    <i class="fas fa-users" style="font-size:2rem;margin-bottom:10px;display:block;"></i>
                                    Belum ada admin
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($admin_list as $admin):
                                $adminId = (int)$admin['id'];
                                $isSelf  = ($adminId === (int)$_SESSION['admin_id']);
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($admin['username']); ?></strong>
                                    <?php if ($isSelf): ?>
                                        <span class="badge" style="background:#4A90E2;color:white;margin-left:5px;">Anda</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($admin['nama']); ?></td>
                                <td>
                                    <?php 
                                    $roleVal = strtolower($admin['role'] ?? '');
                                    $isRowSekretaris = (strpos($roleVal, 'sekretaris') !== false || strpos($roleVal, 'sekertaris') !== false);
                                    
                                    if ($roleVal === 'superadmin' || $admin['can_access_all']): ?>
                                        <span class="badge" style="background:gold;color:black;">
                                            <i class="fas fa-crown"></i> Superadmin
                                        </span>
                                    <?php elseif ($isRowSekretaris): ?>
                                        <span class="badge" style="background:#2E7D32;color:white;">
                                            <i class="fas fa-file-signature"></i> Sekretaris
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#4A90E2;color:white;">
                                            <i class="fas fa-user"></i> Admin
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($admin['periode_id']): ?>
                                        <span class="badge" style="background:#1a2f4a;color:#8BB9F0;">
                                            <?php echo htmlspecialchars($admin['periode_nama'] ?? 'Periode '.$admin['periode_id']); ?>
                                        </span>
                                    <?php elseif ($admin['role'] === 'superadmin' || $admin['can_access_all']): ?>
                                        <span style="color:#888;"><i class="fas fa-globe"></i> Semua</span>
                                    <?php else: ?>
                                        <span style="color:#888;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ($admin['totp_enabled']): ?>
                                        <span style="color:#4caf50;font-size:.8rem;"><i class="fas fa-shield-alt"></i> Aktif</span>
                                    <?php else: ?>
                                        <span style="color:#f44336;font-size:.8rem;"><i class="fas fa-times"></i> Belum</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if ((int)($admin['is_active'] ?? 0)): ?>
                                        <span style="color:#4caf50;font-size:.8rem;"><i class="fas fa-check-circle"></i> Aktif</span>
                                    <?php else: ?>
                                        <span style="color:#f44336;font-size:.8rem;"><i class="fas fa-ban"></i> Nonaktif</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align:center;">
                                    <?php if (!$isSelf): ?>
                                    <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap;">
                                        <!-- Ubah Role -->
                                        <button type="button" class="btn-edit" title="Ubah Role" style="background:#673AB7;"
                                                onclick='handleAdminAction(<?php echo $adminId; ?>, "ubah_role", <?php echo json_encode($admin["username"]); ?>, 0, <?php echo json_encode($admin["role"]); ?>, <?php echo (int)($admin["periode_id"] ?? 0); ?>)'>
                                            <i class="fas fa-user-tag"></i>
                                        </button>

                                        <!-- Reset Password -->
                                        <button type="button" class="btn-edit" title="Reset Password"
                                                onclick='handleAdminAction(<?php echo $adminId; ?>, "reset_password", <?php echo json_encode($admin["username"]); ?>)'>
                                            <i class="fas fa-key"></i>
                                        </button>

                                        <!-- Toggle Aktif/Nonaktif -->
                                        <button type="button"
                                                class="btn-edit"
                                                title="<?php echo (int)($admin['is_active'] ?? 0) ? 'Nonaktifkan' : 'Aktifkan'; ?>"
                                                style="background:<?php echo (int)($admin['is_active'] ?? 0) ? '#f44336' : '#4caf50'; ?>;"
                                                onclick='handleAdminAction(<?php echo $adminId; ?>, "toggle_aktif", <?php echo json_encode($admin["username"]); ?>, <?php echo (int)($admin['is_active'] ?? 0); ?>)'>
                                            <i class="fas <?php echo (int)($admin['is_active'] ?? 0) ? 'fa-ban' : 'fa-check'; ?>"></i>
                                        </button>

                                        <!-- Hapus -->
                                        <button type="button" class="btn-delete" title="Hapus Admin"
                                                onclick='handleAdminAction(<?php echo $adminId; ?>, "hapus", <?php echo json_encode($admin["username"]); ?>)'>
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                    <?php else: ?>
                                        <span style="color:#666;font-size:.8rem;">(Akun sendiri)</span>
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
</div>

<!-- Informasi Hak Akses -->
<div class="card info-card-access">
    <div class="card-header">
        <i class="fas fa-info-circle"></i> Informasi Hak Akses
    </div>
    <div class="card-body">
        <div class="access-grid">
            <div class="access-card superadmin">
                <div class="access-icon"><i class="fas fa-crown"></i></div>
                <h3 class="access-title">Superadmin</h3>
                <ul class="access-list">
                    <li><i class="fas fa-check-circle"></i> Bisa mengakses SEMUA periode</li>
                    <li><i class="fas fa-check-circle"></i> Bisa menambah/menghapus admin</li>
                    <li><i class="fas fa-check-circle"></i> Bisa membuat periode baru</li>
                    <li><i class="fas fa-check-circle"></i> Bisa mengaktifkan/menonaktifkan periode</li>
                    <li><i class="fas fa-check-circle"></i> Bisa mengganti periode yang dikelola</li>
                </ul>
            </div>
            <div class="access-card admin">
                <div class="access-icon"><i class="fas fa-user"></i></div>
                <h3 class="access-title">Admin Biasa</h3>
                <ul class="access-list">
                    <li><i class="fas fa-check-circle"></i> Hanya bisa mengelola SATU periode</li>
                    <li><i class="fas fa-check-circle"></i> Tidak bisa melihat periode lain</li>
                    <li><i class="fas fa-check-circle"></i> Tidak bisa menambah admin</li>
                    <li><i class="fas fa-check-circle"></i> Tidak bisa menghapus periode</li>
                    <li><i class="fas fa-check-circle"></i> Data terisolasi per periode</li>
                </ul>
            </div>
            <div class="access-card admin">
                <div class="access-icon"><i class="fas fa-file-signature"></i></div>
                <h3 class="access-title">Sekretaris</h3>
                <ul class="access-list">
                    <li><i class="fas fa-check-circle"></i> Sama seperti Admin Biasa</li>
                    <li><i class="fas fa-check-circle"></i> Tambahan Akses ke Manajemen Arsip & Buat Surat otomatis</li>
                    <li><i class="fas fa-check-circle"></i> Data terisolasi per periode kepengurusan</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Hidden form untuk aksi admin -->
<form id="actionForm" method="POST" style="display:none;">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" id="actionInput">
    <input type="hidden" name="id" id="idInput">
    <input type="hidden" name="new_password" id="pwInput">
    <input type="hidden" name="new_role" id="roleInput">
    <input type="hidden" name="new_periode" id="periodeInput">
</form>

<!-- Modal Konfirmasi Custom -->
<div id="confirmModal" class="custom-modal">
    <div class="modal-content">
        <div class="modal-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h4 id="modalTitle">Konfirmasi Aksi</h4>
        </div>
        <div class="modal-body">
            <p id="modalMessage">Apakah Anda yakin ingin melakukan aksi ini?</p>
            
            <div id="passwordInputArea" style="display:none; margin-top:15px;">
                <label style="display:block; margin-bottom:5px; font-size:0.9rem;">Password Baru:</label>
                <input type="text" id="customNewPw" class="form-control" placeholder="Minimal 8 karakter">
            </div>

            <div id="roleInputArea" style="display:none; margin-top:15px;">
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px; font-size:0.9rem;">Pilih Role Baru:</label>
                    <select id="customNewRole" class="form-control" onchange="toggleModalPeriode(this.value)">
                        <option value="admin">Admin Biasa</option>
                        <option value="sekretaris">Sekretaris</option>
                        <option value="superadmin">Superadmin</option>
                    </select>
                </div>
                <div class="form-group" id="modalPeriodeGroup" style="display:none;">
                    <label style="display:block; margin-bottom:5px; font-size:0.9rem;">Tugaskan ke Periode:</label>
                    <select id="customNewPeriode" class="form-control">
                        <option value="">-- Pilih Periode --</option>
                        <?php foreach ($periode_list as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>">
                                <?php echo htmlspecialchars($p['nama']); ?> (<?php echo $p['tahun_mulai']; ?>/<?php echo $p['tahun_selesai']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeConfirmModal()">Batal</button>
            <button type="button" class="btn-modal-confirm" id="confirmBtn">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<style>
.custom-modal {
    display: none;
    position: fixed;
    z-index: 9999;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.7);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
}
.modal-content {
    background: #222;
    border: 1px solid #444;
    border-radius: 12px;
    width: 90%;
    max-width: 400px;
    padding: 25px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.4);
    animation: modalSlide 0.3s ease-out;
}
@keyframes modalSlide {
    from { transform: translateY(-30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}
.modal-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 15px;
    color: #ffc107;
}
.modal-header h4 { margin: 0; font-size: 1.2rem; }
.modal-body p { color: #ccc; line-height: 1.5; margin: 0; }
.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 25px;
}
.btn-modal-cancel {
    background: #444;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}
.btn-modal-confirm {
    background: #f44336;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
}
.btn-modal-confirm:hover { background: #d32f2f; }
</style>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
var pendingAction = null;

function closeConfirmModal() {
    document.getElementById('confirmModal').style.display = 'none';
    pendingAction = null;
}

function toggleModalPeriode(role) {
    var group = document.getElementById('modalPeriodeGroup');
    if (role === 'admin' || role === 'sekretaris') {
        group.style.display = 'block';
    } else {
        group.style.display = 'none';
    }
}

window.handleAdminAction = function(id, action, username, isActive, currentRole, currentPeriode) {
    pendingAction = { id: id, action: action, username: username, isActive: isActive, currentRole: currentRole, currentPeriode: currentPeriode };
    
    var modal = document.getElementById('confirmModal');
    var modalTitle = document.getElementById('modalTitle');
    var modalMessage = document.getElementById('modalMessage');
    var confirmBtn = document.getElementById('confirmBtn');
    var pwArea = document.getElementById('passwordInputArea');
    var roleArea = document.getElementById('roleInputArea');
    
    pwArea.style.display = 'none';
    roleArea.style.display = 'none';
    confirmBtn.style.background = '#f44336';
    modalTitle.innerHTML = 'Konfirmasi Aksi';

    if (action === 'reset_password') {
        modalTitle.innerHTML = 'Reset Password';
        modalMessage.innerHTML = 'Masukkan password baru untuk admin <b>' + username + '</b>:';
        pwArea.style.display = 'block';
        confirmBtn.style.background = '#4A90E2';
    } else if (action === 'ubah_role') {
        modalTitle.innerHTML = 'Ubah Role Admin';
        modalMessage.innerHTML = 'Ubah peran untuk akun <b>' + username + '</b>:';
        roleArea.style.display = 'block';
        confirmBtn.style.background = '#673AB7';
        
        if (currentRole) {
            document.getElementById('customNewRole').value = currentRole;
            toggleModalPeriode(currentRole);
        }
        if (currentPeriode) {
            document.getElementById('customNewPeriode').value = currentPeriode;
        }
    } else if (action === 'toggle_aktif') {
        var msg = (isActive == 1) ? 'menonaktifkan' : 'mengaktifkan kembali';
        modalMessage.innerHTML = 'Apakah Anda yakin ingin ' + msg + ' akun <b>' + username + '</b>?';
        confirmBtn.style.background = (isActive == 1) ? '#f44336' : '#4caf50';
    } else if (action === 'hapus') {
        modalMessage.innerHTML = 'PERINGATAN: Akun <b>' + username + '</b> akan dihapus secara permanen. Tindakan ini tidak dapat dibatalkan!';
    }

    modal.style.display = 'flex';
};

document.getElementById('confirmBtn').addEventListener('click', function() {
    if (!pendingAction) return;

    var actionForm = document.getElementById('actionForm');
    var actionInput = document.getElementById('actionInput');
    var idInput = document.getElementById('idInput');
    var pwInput = document.getElementById('pwInput');
    var roleInput = document.getElementById('roleInput');
    var periodeInput = document.getElementById('periodeInput');
    var customNewPw = document.getElementById('customNewPw');
    var customNewRole = document.getElementById('customNewRole');
    var customNewPeriode = document.getElementById('customNewPeriode');

    idInput.value = pendingAction.id;
    actionInput.value = pendingAction.action;

    if (pendingAction.action === 'reset_password') {
        if (customNewPw.value.length < 8) {
            alert('Password minimal 8 karakter!');
            return;
        }
        pwInput.value = customNewPw.value;
    }

    if (pendingAction.action === 'ubah_role') {
        roleInput.value = customNewRole.value;
        periodeInput.value = customNewPeriode.value;
        if ((roleInput.value === 'admin' || roleInput.value === 'sekretaris') && !periodeInput.value) {
            alert('Pilih periode untuk admin/sekretaris!');
            return;
        }
    }

    console.log("Submitting form for action:", pendingAction.action);
    actionForm.submit();
});

function togglePeriodeField() {
    var roleSelect = document.getElementById('roleSelect');
    var periodeField = document.getElementById('periodeField');
    if (roleSelect && periodeField) {
        periodeField.style.display = roleSelect.value === 'superadmin' ? 'none' : 'block';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    togglePeriodeField();

    var formTambah = document.getElementById('formTambah');
    if (formTambah) {
        formTambah.addEventListener('submit', function(e) {
            var roleSelect = document.getElementById('roleSelect');
            var role = roleSelect ? roleSelect.value : '';
            var selectPeriode = document.querySelector('select[name="periode_id"]');
            var periodeId = selectPeriode ? selectPeriode.value : '';
            
            if ((role === 'admin' || role === 'sekretaris') && !periodeId) {
                e.preventDefault();
                alert('Pilih periode untuk admin/sekretaris!');
            }
        });
    }

    var qrContainer = document.getElementById("qrcode");
    if (qrContainer && typeof QRCode !== 'undefined') {
        var qrContent = <?php echo json_encode($newAdminQrUrl ?? ''); ?>;
        if (qrContent) {
            try {
                new QRCode(qrContainer, {
                    text: qrContent,
                    width: 180,
                    height: 180,
                    colorDark : "#000000",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.Level.M
                });
            } catch (err) {}
        }
    }
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>