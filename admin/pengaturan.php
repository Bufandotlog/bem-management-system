<?php
// admin/pengaturan.php
// VERSI: 4.1 - Tambah CSRF token pada link backup database
//   CHANGED: Backup link menyertakan ?csrf_token=...
//   UNCHANGED: Semua fitur lainnya tetap sama

$page_css = 'pengaturan';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/totp.php';

$is_superadmin = (($_SESSION['admin_role'] ?? '') === 'superadmin' || ($user_can_access_all ?? false));
$admin_id      = (int) $_SESSION['admin_id'];

$user = dbFetchOne(
    "SELECT id, username, nama, email, role, periode_id, last_login, created_at,
            totp_enabled, last_ip
     FROM users WHERE id = ?",
    [$admin_id], "i"
);
if (!$user) {
    redirect('admin/login.php', 'Sesi tidak valid.', 'error');
    exit();
}

$periode_user = null;
if (!empty($user['periode_id'])) {
    $periode_user = dbFetchOne(
        "SELECT nama, tahun_mulai, tahun_selesai FROM periode_kepengurusan WHERE id = ?",
        [$user['periode_id']], "i"
    );
}

$msg_profil   = null;
$msg_password = null;
$msg_2fa      = null;

// ============================================
// PROSES UPDATE PROFIL
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!csrfVerify()) {
        $msg_profil = ['type'=>'error','text'=>'Request tidak valid.'];
    } else {
        $nama  = sanitizeText($_POST['nama']  ?? '', 100);
        $email = sanitizeText($_POST['email'] ?? '', 100);

        if (empty($nama)) {
            $msg_profil = ['type'=>'error','text'=>'Nama tidak boleh kosong!'];
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg_profil = ['type'=>'error','text'=>'Format email tidak valid!'];
        } else {
            dbQuery("UPDATE users SET nama=?, email=? WHERE id=?", [$nama, $email, $admin_id], "ssi");
            $_SESSION['admin_name'] = $nama;
            auditLog('UPDATE', 'users', $admin_id, 'Update profil: ' . $nama);
            redirect('admin/pengaturan.php', 'Profil berhasil diperbarui!', 'success');
            exit();
        }
    }
}

// ============================================
// PROSES GANTI PASSWORD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!csrfVerify()) {
        $msg_password = ['type'=>'error','text'=>'Request tidak valid.'];
    } else {
        // Ambil password fresh dari DB — bukan dari $user yang di-fetch di awal
        $freshUser = dbFetchOne("SELECT password FROM users WHERE id = ?", [$admin_id], "i");
        $current   = $_POST['current_password'] ?? '';
        $new       = $_POST['new_password']     ?? '';
        $confirm   = $_POST['confirm_password'] ?? '';

        if (!$freshUser || !password_verify($current, $freshUser['password'])) {
            $msg_password = ['type'=>'error','text'=>'Password saat ini salah!'];
        } elseif ($new !== $confirm) {
            $msg_password = ['type'=>'error','text'=>'Password baru tidak cocok!'];
        } elseif (strlen($new) < 8) {
            $msg_password = ['type'=>'error','text'=>'Password minimal 8 karakter!'];
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $msg_password = ['type'=>'error','text'=>'Password harus mengandung huruf dan angka!'];
        } else {
            $hashed = password_hash($new, PASSWORD_BCRYPT, ['cost'=>12]);
            dbQuery("UPDATE users SET password=? WHERE id=?", [$hashed, $admin_id], "si");
            auditLog('UPDATE', 'users', $admin_id, 'Ganti password');
            redirect('admin/pengaturan.php', 'Password berhasil diubah!', 'success');
            exit();
        }
    }
}

// ============================================
// PROSES NONAKTIFKAN 2FA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_2fa'])) {
    if (!csrfVerify()) {
        $msg_2fa = ['type'=>'error','text'=>'Request tidak valid.'];
    } else {
        $current = $_POST['confirm_password_2fa'] ?? '';
        $pwRow   = dbFetchOne("SELECT password FROM users WHERE id=?", [$admin_id], "i");
        if (!$pwRow || !password_verify($current, $pwRow['password'])) {
            $msg_2fa = ['type'=>'error','text'=>'Password salah. Konfirmasi diperlukan.'];
        } else {
            dbQuery(
                "UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_verified_at=NULL WHERE id=?",
                [$admin_id], "i"
            );
            unset($_SESSION['2fa_verified']);
            auditLog('UPDATE', 'users', $admin_id, 'Nonaktifkan 2FA');
            redirect('admin/pengaturan.php', '2FA berhasil dinonaktifkan.', 'success');
            exit();
        }
    }
}

$totpEnabled = (bool) ($user['totp_enabled'] ?? false);

// Hitung jumlah sesi aktif
$jumlahSesi = dbFetchOne(
    "SELECT COUNT(*) AS total FROM user_sessions WHERE user_id = ?",
    [$admin_id], "i"
);
$totalSesi = (int) ($jumlahSesi['total'] ?? 0);

// Info DB untuk superadmin
$db_size_mb = '-';
$db_tables  = '-';
if ($is_superadmin) {
    try {
        if (strtolower(DB_CONNECTION) === 'pgsql') {
            // PostgreSQL (Supabase)
            $r = dbFetchOne("SELECT ROUND(pg_database_size(current_database()) / (1024.0 * 1024.0), 2) AS s");
            $db_size_mb = ($r['s'] ?? '0') . ' MB';
            
            $c = dbFetchOne("SELECT COUNT(*) AS t FROM information_schema.tables WHERE table_schema = 'public'");
            $db_tables  = ($c['t'] ?? '0') . ' tabel';
        } else {
            // MySQL (InfinityFree)
            $r = dbFetchOne("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) AS s FROM information_schema.tables WHERE table_schema=?", [DB_NAME], "s");
            $db_size_mb = ($r['s'] ?? '0') . ' MB';
            
            $c = dbFetchOne("SELECT COUNT(*) AS t FROM information_schema.tables WHERE table_schema=?", [DB_NAME], "s");
            $db_tables  = ($c['t'] ?? '0') . ' tabel';
        }
    } catch (Exception $e) {
        $db_size_mb = 'N/A';
        $db_tables = 'N/A';
    }
}

function renderAlert($msg) {
    if (!$msg) return;
    $cls = $msg['type'] === 'success' ? 'alert-success' : 'alert-error';
    echo "<div class='{$cls}'>" . htmlspecialchars($msg['text'], ENT_QUOTES, 'UTF-8') . "</div>";
}
?>

<h1>Pengaturan</h1>

<div class="role-badge">
    <i class="fas fa-user-tag"></i>
    <span>Login sebagai: <strong style="color:<?php echo $is_superadmin ? 'gold' : '#4A90E2'; ?>">
        <?php echo $is_superadmin ? 'Superadmin' : 'Admin Biasa'; ?>
    </strong></span>
    <?php if (!$is_superadmin && $periode_user): ?>
    <span style="color:#aaa;">— Periode:
        <span style="color:#8BB9F0;"><?php echo htmlspecialchars($periode_user['nama']); ?>
        (<?php echo (int)$periode_user['tahun_mulai']; ?>/<?php echo (int)$periode_user['tahun_selesai']; ?>)</span>
    </span>
    <?php endif; ?>
</div>

<?php flashMessage(); ?>

<div class="settings-grid">

<!-- PROFIL -->
<div class="settings-card">
    <h2><i class="fas fa-user"></i> Profil Admin</h2>
    <?php renderAlert($msg_profil); ?>
    <form method="POST" class="settings-form">
        <?php echo csrfField(); ?>
        <div class="form-group">
            <label>Username</label>
            <input type="text" value="<?php echo htmlspecialchars($user['username']); ?>" disabled>
            <small>Username tidak dapat diubah</small>
        </div>
        <div class="form-group">
            <label>Nama Lengkap <span class="required">*</span></label>
            <input type="text" name="nama" value="<?php echo htmlspecialchars($user['nama']); ?>" required maxlength="100">
        </div>
        <div class="form-group">
            <label>Email <span class="required">*</span></label>
            <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required maxlength="100">
        </div>
        <button type="submit" name="update_profile" class="btn-primary">
            <i class="fas fa-save"></i> Simpan Profil
        </button>
    </form>
</div>

<!-- GANTI PASSWORD -->
<div class="settings-card">
    <h2><i class="fas fa-key"></i> Ganti Password</h2>
    <?php renderAlert($msg_password); ?>
    <form method="POST" class="settings-form" id="passwordForm">
        <?php echo csrfField(); ?>
        <div class="form-group">
            <label>Password Saat Ini <span class="required">*</span></label>
            <div class="input-password-wrap">
                <input type="password" name="current_password" id="currentPwd" required autocomplete="current-password">
                <button type="button" class="toggle-pwd" onclick="togglePwd('currentPwd',this)"><i class="fas fa-eye"></i></button>
            </div>
        </div>
        <div class="form-group">
            <label>Password Baru <span class="required">*</span></label>
            <div class="input-password-wrap">
                <input type="password" name="new_password" id="newPwd" required minlength="8" autocomplete="new-password" placeholder="Min. 8 karakter, huruf + angka" oninput="cekKekuatan(this.value)">
                <button type="button" class="toggle-pwd" onclick="togglePwd('newPwd',this)"><i class="fas fa-eye"></i></button>
            </div>
            <div class="pwd-strength" id="pwdStrength" style="display:none;">
                <div class="pwd-bar"><div class="pwd-bar-fill" id="pwdBarFill"></div></div>
                <small id="pwdStrengthLabel"></small>
            </div>
        </div>
        <div class="form-group">
            <label>Konfirmasi Password Baru <span class="required">*</span></label>
            <div class="input-password-wrap">
                <input type="password" name="confirm_password" id="confirmPwd" required autocomplete="new-password">
                <button type="button" class="toggle-pwd" onclick="togglePwd('confirmPwd',this)"><i class="fas fa-eye"></i></button>
            </div>
            <small id="matchInfo" style="display:none;"></small>
        </div>
        <button type="submit" name="change_password" class="btn-primary">
            <i class="fas fa-lock"></i> Ganti Password
        </button>
    </form>
</div>

<!-- TWO-FACTOR AUTHENTICATION -->
<div class="settings-card">
    <h2><i class="fas fa-shield-alt"></i> Two-Factor Authentication</h2>
    <?php renderAlert($msg_2fa); ?>
    <div class="twofa-status <?php echo $totpEnabled ? 'twofa-on' : 'twofa-off'; ?>">
        <i class="fas <?php echo $totpEnabled ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
        <div>
            <strong><?php echo $totpEnabled ? '2FA Aktif' : '2FA Tidak Aktif'; ?></strong>
            <div style="font-size:.85rem;opacity:.85;margin-top:2px;">
                <?php echo $totpEnabled ? 'Akun kamu dilindungi verifikasi dua langkah.' : 'Aktifkan 2FA untuk keamanan tambahan saat login.'; ?>
            </div>
        </div>
    </div>
    <div class="twofa-actions">
        <?php if (!$totpEnabled): ?>
            <a href="2fa-setup.php" class="btn-primary">
                <i class="fas fa-qrcode"></i> Setup 2FA Sekarang
            </a>
        <?php else: ?>
            <a href="2fa-setup.php" class="btn-secondary">
                <i class="fas fa-sync-alt"></i> Ganti Perangkat / Reset 2FA
            </a>
            <button type="button" class="btn-danger" onclick="document.getElementById('disable2faForm').style.display='block';this.style.display='none'">
                <i class="fas fa-shield-alt"></i> Nonaktifkan 2FA
            </button>
        <?php endif; ?>
    </div>
    <?php if ($totpEnabled): ?>
    <form method="POST" id="disable2faForm" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid #222;">
        <?php echo csrfField(); ?>
        <p style="color:#f44336;font-size:.9rem;margin-bottom:12px;">
            <i class="fas fa-exclamation-triangle"></i>
            Masukkan password untuk konfirmasi menonaktifkan 2FA:
        </p>
        <div class="form-group">
            <div class="input-password-wrap">
                <input type="password" name="confirm_password_2fa" id="pwd2fa" required placeholder="Password kamu" autocomplete="current-password"
                    style="width:100%;padding:10px 42px 10px 12px;background:#222;border:1px solid #f44336;color:white;border-radius:6px;font-size:.95rem;box-sizing:border-box;">
                <button type="button" class="toggle-pwd" onclick="togglePwd('pwd2fa',this)"><i class="fas fa-eye"></i></button>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="submit" name="disable_2fa" class="btn-danger">
                <i class="fas fa-times"></i> Ya, Nonaktifkan 2FA
            </button>
            <button type="button" class="btn-secondary" onclick="document.getElementById('disable2faForm').style.display='none'">
                Batal
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- PERANGKAT AKTIF -->
<div class="settings-card">
    <h2><i class="fas fa-laptop"></i> Perangkat Aktif</h2>
    <p style="color:#aaa;font-size:.9rem;margin-bottom:1rem;">
        Lihat semua perangkat yang sedang terhubung ke akun kamu dan putuskan akses yang mencurigakan.
    </p>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;background:rgba(74,144,226,.08);border:1px solid rgba(74,144,226,.2);border-radius:8px;margin-bottom:1rem;">
        <div>
            <div style="font-size:1.4rem;font-weight:600;color:#4A90E2;"><?php echo $totalSesi; ?></div>
            <div style="font-size:.8rem;color:#888;">Sesi Aktif Tercatat</div>
        </div>
        <i class="fas fa-mobile-alt" style="font-size:1.8rem;color:rgba(74,144,226,.4);"></i>
    </div>
    <a href="kelola-perangkat.php" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;width:100%;justify-content:center;text-decoration:none;">
        <i class="fas fa-cog"></i> Kelola Perangkat
    </a>
    <?php if ($totalSesi > 1): ?>
    <p style="font-size:.8rem;color:#f44336;margin-top:.75rem;text-align:center;">
        <i class="fas fa-exclamation-triangle"></i>
        Ada <?php echo $totalSesi - 1; ?> sesi lain yang aktif selain sesi ini.
    </p>
    <?php endif; ?>
</div>

<!-- AKTIVITAS AKUN -->
<div class="settings-card">
    <h2><i class="fas fa-history"></i> Aktivitas Akun</h2>
    <div class="info-item">
        <label>Terakhir Login</label>
        <span><?php echo !empty($user['last_login']) ? date('d/m/Y H:i', strtotime($user['last_login'])).' WIB' : '-'; ?></span>
    </div>
    <div class="info-item">
        <label>IP Terakhir</label>
        <span><?php echo htmlspecialchars($user['last_ip'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="info-item">
        <label>Bergabung Sejak</label>
        <span><?php echo date('d/m/Y', strtotime($user['created_at'])); ?></span>
    </div>
    <div class="info-item">
        <label>Role</label>
        <span style="color:<?php echo $is_superadmin ? 'gold' : '#4A90E2'; ?>">
            <?php echo $is_superadmin ? 'Superadmin' : 'Admin'; ?>
        </span>
    </div>
    <div class="info-item">
        <label>Status 2FA</label>
        <span style="color:<?php echo $totpEnabled ? '#4caf50' : '#f44336'; ?>">
            <?php echo $totpEnabled ? '✓ Aktif' : '✗ Tidak Aktif'; ?>
        </span>
    </div>
    <div class="info-item">
        <label>Sesi Aktif</label>
        <span style="color:<?php echo $totalSesi > 1 ? '#ffaa00' : '#4caf50'; ?>">
            <?php echo $totalSesi; ?> perangkat
        </span>
    </div>
    <?php if (!$is_superadmin && $periode_user): ?>
    <div class="info-item">
        <label>Periode</label>
        <span style="color:#8BB9F0;"><?php echo htmlspecialchars($periode_user['nama']); ?></span>
    </div>
    <?php endif; ?>
</div>

<?php if ($is_superadmin): ?>
<!-- INFO DATABASE -->
<div class="settings-card superadmin-only">
    <h2><i class="fas fa-database"></i> Informasi Database</h2>
    <div class="info-item"><label>Database</label><span><?php echo htmlspecialchars(DB_NAME); ?></span></div>
    <div class="info-item"><label>Ukuran</label><span><?php echo htmlspecialchars($db_size_mb); ?></span></div>
    <div class="info-item"><label>Total Tabel</label><span><?php echo htmlspecialchars($db_tables); ?></span></div>
</div>

<!-- BACKUP DATABASE -->
<div class="settings-card superadmin-only">
    <h2><i class="fas fa-download"></i> Backup Database</h2>
    <p style="color:#aaa;margin-bottom:15px;font-size:.9rem;">Download backup seluruh data dalam format SQL.</p>
    <a href="backup-database.php?csrf_token=<?php echo csrfToken(); ?>" class="btn-secondary" onclick="return confirm('Download backup sekarang?')">
        <i class="fas fa-database"></i> Download Backup (.sql)
    </a>
</div>
<?php endif; ?>

</div><!-- .settings-grid -->

<script>
function togglePwd(id,btn){
    const i=document.getElementById(id),ic=btn.querySelector('i');
    i.type=i.type==='password'?'text':'password';
    ic.classList.toggle('fa-eye');ic.classList.toggle('fa-eye-slash');
}
function cekKekuatan(v){
    const w=document.getElementById('pwdStrength'),b=document.getElementById('pwdBarFill'),l=document.getElementById('pwdStrengthLabel');
    if(!v){w.style.display='none';return;}w.style.display='block';
    let s=0;
    if(v.length>=8)s++;if(v.length>=12)s++;if(/[A-Z]/.test(v))s++;if(/[0-9]/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
    const lv=[{p:'20%',c:'#f44336',t:'Sangat Lemah'},{p:'40%',c:'#ff9800',t:'Lemah'},{p:'60%',c:'#ffeb3b',t:'Cukup'},{p:'80%',c:'#8bc34a',t:'Kuat'},{p:'100%',c:'#4caf50',t:'Sangat Kuat'}];
    const x=lv[Math.min(s,4)];b.style.width=x.p;b.style.background=x.c;l.textContent=x.t;l.style.color=x.c;
}
document.getElementById('confirmPwd').addEventListener('input',function(){
    const n=document.getElementById('newPwd').value,i=document.getElementById('matchInfo');
    i.style.display='block';
    if(this.value===n){i.textContent='✓ Password cocok';i.style.color='#4caf50';}
    else{i.textContent='✗ Tidak cocok';i.style.color='#f44336';}
});
document.getElementById('passwordForm').addEventListener('submit',function(e){
    const n=document.getElementById('newPwd').value,c=document.getElementById('confirmPwd').value;
    if(n!==c){e.preventDefault();alert('Password tidak cocok!');return;}
    if(n.length<8){e.preventDefault();alert('Password minimal 8 karakter!');return;}
    if(!/[A-Za-z]/.test(n)||!/[0-9]/.test(n)){e.preventDefault();alert('Password harus huruf + angka!');}
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>