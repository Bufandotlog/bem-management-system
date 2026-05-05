<?php
// admin/2fa-verify.php
// VERSI: 1.3 - Replay protection via totp_last_counter
//   CHANGED: Gunakan totpVerifyWithReplay() dari functions.php
//   CHANGED: recordUserSession() sekarang di functions.php
//   UNCHANGED: Rate limiting, HTML, CSRF

require_once __DIR__ . '/../includes/functions.php';
// totp.php akan di-load otomatis oleh totpVerifyWithReplay()

if (empty($_SESSION['2fa_pending']) || empty($_SESSION['2fa_user_id'])) {
    redirect('admin/login.php');
    exit();
}

$userId         = (int) $_SESSION['2fa_user_id'];
$error          = '';
$attempts2fa    = $_SESSION['2fa_attempts']     ?? 0;
$locked2faUntil = $_SESSION['2fa_locked_until'] ?? 0;
$isLocked       = $locked2faUntil > 0 && time() < $locked2faUntil;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfVerify()) {
        $error = 'Request tidak valid.';
    } elseif ($isLocked) {
        $error = 'Terlalu banyak percobaan. Coba lagi dalam ' . ceil(($locked2faUntil - time()) / 60) . ' menit.';
    } else {
        $code = preg_replace('/\D/', '', $_POST['totp_code'] ?? '');
        if (strlen($code) !== 6) {
            $error = 'Kode harus 6 digit.';
        } else {
            // Ambil data user (termasuk totp_secret)
            $user = dbFetchOne(
                "SELECT id, nama, username, role, periode_id, can_access_all,
                        totp_secret, totp_enabled
                 FROM users WHERE id = ? AND is_active = 1",
                [$userId], "i"
            );
            if (!$user || !$user['totp_enabled'] || empty($user['totp_secret'])) {
                session_unset();
                session_destroy();
                redirect('admin/login.php', 'Sesi tidak valid.', 'error');
                exit();
            }

            // Verifikasi dengan replay protection (menggunakan totp_last_counter)
            if (totpVerifyWithReplay($user['totp_secret'], $code, $userId)) {
                // Berhasil — atur session login
                session_regenerate_id(true);
                unset($_SESSION['2fa_pending'], $_SESSION['2fa_user_id'],
                      $_SESSION['2fa_attempts'], $_SESSION['2fa_locked_until']);

                $_SESSION['admin_logged_in']      = true;
                $_SESSION['admin_id']             = $user['id'];
                $_SESSION['admin_name']           = $user['nama'];
                $_SESSION['admin_username']       = $user['username'];
                $_SESSION['admin_role']           = $user['role'];
                $_SESSION['admin_periode_id']     = $user['periode_id'];
                $_SESSION['admin_can_access_all'] = $user['can_access_all'];
                $_SESSION['2fa_verified']         = true;
                $_SESSION['_last_activity']       = time();
                $_SESSION['_auth_last_check']     = time();

                // Catat sesi di user_sessions (fungsi dari functions.php)
                recordUserSession($user['id']);

                // Update last_login dan last_ip
                $ip = mb_substr(trim(explode(',',
                    $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''
                )[0]), 0, 45);
                dbQuery("UPDATE users SET last_login = now(), last_ip = ? WHERE id = ?",
                        [$ip, $user['id']], "si");

                auditLog('LOGIN', 'users', $user['id'], 'Login berhasil');

                redirect('admin/dashboard.php', "Selamat datang, {$user['nama']}!", 'success');
                exit();
            } else {
                // Kode salah
                $n = $attempts2fa + 1;
                $_SESSION['2fa_attempts'] = $n;
                if ($n >= 5) {
                    $_SESSION['2fa_locked_until'] = time() + 900;
                    $error = 'Terlalu banyak percobaan. Akun dikunci 15 menit.';
                } else {
                    $error = 'Kode tidak valid. Sisa percobaan: ' . (5 - $n) . '.';
                }
            }
        }
    }
}

// CSRF token untuk form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$cssVer = file_exists(__DIR__ . '/css/login.css') ? filemtime(__DIR__ . '/css/login.css') : '1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi 2FA - BEM Admin</title>
    <link rel="stylesheet" href="css/login.css?v=<?php echo $cssVer; ?>">
    <style>
        .totp-input{font-size:2rem;letter-spacing:12px;text-align:center;font-family:monospace}
        .back-link{display:block;text-align:center;margin-top:16px;color:#666;font-size:.8rem;text-decoration:none}
        .back-link:hover{color:#4A90E2}
        .totp-hint{color:#666;font-size:.8rem;text-align:center;margin-top:8px;line-height:1.5}
    </style>
</head>
<body>
<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <h2 style="color:#4A90E2;margin-bottom:8px;">&#x1F6E1; Verifikasi 2FA</h2>
            <p>Masukkan kode 6 digit dari Aegis / Authenticator</p>
        </div>
        <?php if ($error): ?>
        <div class="<?php echo (str_contains($error,'dikunci')||str_contains($error,'menit')) ? 'alert-lockout' : 'alert-error'; ?>">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="totp_code">Kode Authenticator</label>
                <input type="text" id="totp_code" name="totp_code" class="totp-input"
                       maxlength="6" pattern="\d{6}" inputmode="numeric"
                       placeholder="000000" required autofocus
                       <?php echo $isLocked ? 'disabled' : ''; ?>>
                <p class="totp-hint">Buka Aegis &bull; Kode berubah setiap 30 detik</p>
            </div>
            <button type="submit" class="btn-login" <?php echo $isLocked ? 'disabled' : ''; ?>>
                <?php echo $isLocked ? 'Dikunci ('.ceil(($locked2faUntil-time())/60).' menit)' : 'Verifikasi'; ?>
            </button>
        </form>
        <a href="login.php" class="back-link">&#8592; Kembali ke Login</a>
    </div>
</div>
</body>
</html>