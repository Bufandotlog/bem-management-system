<?php
// admin/2fa-setup.php
// VERSI: 2.0 - Rate limiting + audit log + session regeneration

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../includes/totp.php';

if (!isLoggedIn() && empty($_SESSION['2fa_pending'])) {
    redirect('admin/login.php');
    exit();
}

$admin_id       = (int) ($_SESSION['admin_id'] ?? $_SESSION['2fa_user_id'] ?? 0);
$admin_username = $_SESSION['admin_username'] ?? ($_SESSION['2fa_setup_user']['username'] ?? '');
$error = '';
$step  = 1;

// Rate limiting untuk setup 2FA
$maxAttempts = 5;
$lockoutTime = 15 * 60;
$attempts    = $_SESSION['2fa_setup_attempts']    ?? 0;
$lockedUntil = $_SESSION['2fa_setup_locked_until'] ?? 0;
$isLocked    = $lockedUntil > 0 && time() < $lockedUntil;

$userData    = dbFetchOne("SELECT totp_secret, totp_enabled FROM users WHERE id = ?", [$admin_id], "i");
$totpEnabled = (bool)($userData['totp_enabled'] ?? false);

if (empty($_SESSION['2fa_setup_secret'])) {
    $_SESSION['2fa_setup_secret'] = totpGenerateSecret();
}
$secret = $_SESSION['2fa_setup_secret'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $error = 'Request tidak valid.';
    } elseif ($isLocked) {
        $error = 'Terlalu banyak percobaan. Coba lagi dalam ' . ceil(($lockedUntil - time()) / 60) . ' menit.';
    } else {
        $code = preg_replace('/\D/', '', $_POST['totp_code'] ?? '');
        if (strlen($code) !== 6) {
            $error = 'Kode harus 6 digit.';
        } else {
            // Verifikasi kode TOTP (tanpa replay protection karena secret baru)
            if (!totpVerify($secret, $code)) {
                $attempts++;
                $_SESSION['2fa_setup_attempts'] = $attempts;
                if ($attempts >= $maxAttempts) {
                    $_SESSION['2fa_setup_locked_until'] = time() + $lockoutTime;
                    $error = 'Terlalu banyak percobaan. Akun dikunci 15 menit.';
                } else {
                    $remaining = $maxAttempts - $attempts;
                    $error = "Kode tidak valid atau kedaluarsa. Sisa percobaan: {$remaining}.";
                }
            } else {
                // Reset rate limit
                unset($_SESSION['2fa_setup_attempts'], $_SESSION['2fa_setup_locked_until']);

                // Simpan secret ke database
                dbQuery(
                    "UPDATE users SET totp_secret=?, totp_enabled=1, totp_verified_at=now(), totp_last_counter=0 WHERE id=?",
                    [$secret, $admin_id], "si"
                );

                // Audit log: catat aktivasi/reset 2FA
                if ($totpEnabled) {
                    auditLog('UPDATE', 'users', $admin_id, 'Reset 2FA: secret baru diaktifkan');
                } else {
                    auditLog('UPDATE', 'users', $admin_id, 'Aktivasi 2FA');
                }

                // Bersihkan session setup
                unset($_SESSION['2fa_setup_secret'], $_SESSION['2fa_pending'], $_SESSION['2fa_user_id']);

                // Jika berasal dari alur login (2fa_setup_user ada), selesaikan login
                if (!empty($_SESSION['2fa_setup_user'])) {
                    $u = $_SESSION['2fa_setup_user'];
                    session_regenerate_id(true);
                    $_SESSION['admin_logged_in']      = true;
                    $_SESSION['admin_id']             = $u['id'];
                    $_SESSION['admin_name']           = $u['nama'];
                    $_SESSION['admin_username']       = $u['username'];
                    $_SESSION['admin_role']           = $u['role'];
                    $_SESSION['admin_periode_id']     = $u['periode_id'];
                    $_SESSION['admin_can_access_all'] = $u['can_access_all'];
                    $_SESSION['2fa_verified']         = true;
                    $_SESSION['_last_activity']       = time();
                    $_SESSION['_auth_last_check']     = time();
                    unset($_SESSION['2fa_setup_user']);
                } else {
                    // Setup dari dashboard: regenerasi session untuk keamanan
                    session_regenerate_id(true);
                }
                $_SESSION['2fa_verified'] = true;
                $step = 2;
            }
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Dapatkan URI untuk dibangkitkan oleh QR JS Client-side
$totpUri = totpGetUri($secret, $admin_username, 'BEM Admin');
?>
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-shield-alt"></i> Setup Two-Factor Authentication</h1>
        <p>Amankan akun dengan Google Authenticator atau Authy</p>
    </div>
</div>
<div style="max-width:560px;margin:0 auto;">
<?php if ($step === 2): ?>
    <div style="text-align:center;padding:40px 20px;">
        <div style="font-size:4rem;margin-bottom:20px;">✅</div>
        <h2 style="color:#4CAF50;margin-bottom:10px;">2FA Aktif!</h2>
        <p style="color:#888;margin-bottom:30px;">Setiap login kamu akan diminta kode 6 digit dari authenticator.</p>
        <a href="dashboard.php" style="display:inline-block;padding:12px 30px;background:#4A90E2;color:white;border-radius:8px;text-decoration:none;font-weight:600;">Ke Dashboard</a>
    </div>
<?php else: ?>
    <?php if ($totpEnabled): ?>
    <div style="background:rgba(255,152,0,.1);border:1px solid #FF9800;color:#FF9800;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;">
        ⚠️ 2FA sudah aktif. Setup ulang akan mengganti secret lama.
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div style="background:rgba(244,67,54,.1);border:1px solid #f44336;color:#f44336;padding:12px;border-radius:8px;margin-bottom:16px;font-size:13px;">
        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>

    <div style="background:#1a1a2e;border-radius:12px;padding:24px;margin-bottom:16px;">
        <h3 style="color:#4A90E2;margin-bottom:12px;font-size:1rem;">
            <span style="background:#4A90E2;color:white;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;margin-right:8px;">1</span>
            Install Authenticator App
        </h3>
        <p style="color:#888;font-size:13px;">Download salah satu: <strong style="color:#ccc;">Google Authenticator</strong>, <strong style="color:#ccc;">Authy</strong>, atau <strong style="color:#ccc;">Aegis</strong></p>
    </div>

    <div style="background:#1a1a2e;border-radius:12px;padding:24px;margin-bottom:16px;">
        <h3 style="color:#4A90E2;margin-bottom:12px;font-size:1rem;">
            <span style="background:#4A90E2;color:white;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;margin-right:8px;">2</span>
            Scan QR Code atau Masukkan Manual
        </h3>
        <!-- Kontainer QR Code dengan Javascript Library -->
        <div style="text-align:center;margin:16px 0;">
            <div id="qrcode" style="display:inline-block;padding:12px;background:white;border-radius:8px;"></div>
            <br>
            <button type="button" id="downloadQrBtn" style="margin-top:12px;padding:8px 16px;background:#2E7D32;color:white;border:none;border-radius:6px;font-size:.9rem;cursor:pointer;"><i class="fas fa-download"></i> Download QR</button>
        </div>
        <p style="color:#666;font-size:12px;text-align:center;">Atau masukkan secret key ini secara manual di Aegis:</p>
        <div style="display:flex;align-items:center;justify-content:center;gap:10px;margin-top:8px;">
            <div id="manualSecret" style="background:#222;border-radius:8px;padding:12px;text-align:center;font-family:monospace;font-size:1.2rem;letter-spacing:4px;color:#4A90E2;word-break:break-all;">
                <?php echo htmlspecialchars(strtoupper($secret), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <button type="button" id="copySecretBtn" style="padding:12px;background:#333;color:white;border:none;border-radius:8px;cursor:pointer;" title="Salin Key">
                <i class="fas fa-copy"></i>
            </button>
        </div>
        <p style="color:#555;font-size:11px;text-align:center;margin-top:8px;">Type: TOTP &bull; Algorithm: SHA1 &bull; Digits: 6 &bull; Period: 30s</p>
    </div>

    <div style="background:#1a1a2e;border-radius:12px;padding:24px;margin-bottom:16px;">
        <h3 style="color:#4A90E2;margin-bottom:12px;font-size:1rem;">
            <span style="background:#4A90E2;color:white;border-radius:50%;width:24px;height:24px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;margin-right:8px;">3</span>
            Verifikasi Kode dari Aegis
        </h3>
        <p style="color:#888;font-size:13px;margin-bottom:16px;">Masukkan kode 6 digit yang tampil di Aegis setelah menambahkan akun:</p>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <div style="display:flex;gap:12px;align-items:center;">
                <input type="text" name="totp_code" maxlength="6" pattern="\d{6}" inputmode="numeric" placeholder="000000" required autofocus
                    style="flex:1;padding:14px;background:#222;border:1px solid #333;border-radius:8px;color:white;font-size:1.5rem;letter-spacing:8px;text-align:center;font-family:monospace;">
                <button type="submit" style="padding:14px 24px;background:#4A90E2;color:white;border:none;border-radius:8px;font-size:1rem;font-weight:600;cursor:pointer;">
                    Verifikasi
                </button>
            </div>
        </form>
        <?php if ($isLocked): ?>
        <p style="color:#f44336;margin-top:12px;font-size:.8rem;">Akun dikunci sementara. Coba lagi nanti.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>

<!-- Implementasi Library qrcode.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    var qrContainer = document.getElementById("qrcode");
    if(qrContainer) {
        var qrContent = <?php echo json_encode($totpUri ?? ''); ?>;
        // Generate QR code dengan library
        new QRCode(qrContainer, {
            text: qrContent,
            width: 200,
            height: 200,
            colorDark : "#000000",
            colorLight : "#ffffff",
            correctLevel : QRCode.CorrectLevel.M
        });

        // Event listener unduh QR
        document.getElementById('downloadQrBtn').addEventListener('click', function() {
            var canvas = qrContainer.querySelector("canvas");
            if(canvas) {
                var url = canvas.toDataURL("image/png");
                var a = document.createElement("a");
                a.href = url;
                a.download = "BEM_Astawidya_2FA_<?php echo htmlspecialchars($admin_username, ENT_QUOTES, 'UTF-8'); ?>.png";
                a.click();
            } else {
                alert("Proses QR Code belum selesai. Coba lagi sepersekian detik.");
            }
        });
        
        // Event listener salin key
        document.getElementById('copySecretBtn').addEventListener('click', function() {
            var text = "<?php echo htmlspecialchars(strtoupper($secret ?? ''), ENT_QUOTES, 'UTF-8'); ?>";
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    var btn = document.getElementById('copySecretBtn');
                    btn.innerHTML = '<i class="fas fa-check"></i>';
                    setTimeout(function(){ btn.innerHTML = '<i class="fas fa-copy"></i>'; }, 2000);
                });
            } else {
                alert("Browser anda tidak mendukung fitur copy otomatis. Silakan salin manual.");
            }
        });
    }
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>