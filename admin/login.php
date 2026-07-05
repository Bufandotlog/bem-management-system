<?php
// admin/login.php - VERSI: 3.6
// CHANGED: Hapus recordUserSession() — dipindah ke 2fa-verify.php
// CHANGED: Hapus alur "belum setup 2FA" — semua akun wajib punya 2FA dari awal
//          Jika totp_enabled=0, tampilkan pesan minta hubungi superadmin
// CHANGED: Kompatibel dengan replay protection TOTP (totp_last_counter)
// UNCHANGED: Rate limiting, CSRF, seluruh HTML

require_once __DIR__ . '/../includes/functions.php';

if (isLoggedIn()) {
    redirect('admin/dashboard.php');
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$maxAttempts  = 5;
$lockoutTime  = 15 * 60;
$attempts     = $_SESSION['login_attempts']    ?? 0;
$lockedUntil  = $_SESSION['login_locked_until'] ?? 0;
$isLocked     = $lockedUntil > 0 && time() < $lockedUntil;
$lockWaitMins = $isLocked ? ceil(($lockedUntil - time()) / 60) : 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Exponential Delay (Exponential Backoff) based on previous failed attempts in session
    $attempts = $_SESSION['login_attempts'] ?? 0;
    if ($attempts > 0) {
        $delay = min(15, pow(2, $attempts - 1));
        sleep($delay);
    }

    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $error = 'Sesi tidak valid, silakan muat ulang halaman dan coba lagi.';
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    } elseif ($isLocked) {
        $error = "Terlalu banyak percobaan gagal. Coba lagi dalam {$lockWaitMins} menit.";

    } else {
        // Cloudflare Turnstile Verification
        $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
        $turnstileSiteKey = $_ENV['TURNSTILE_SITE_KEY'] ?? '';
        $turnstileSecret = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';

        $turnstileSuccess = true;
        if (!empty($turnstileSiteKey) && !empty($turnstileSecret)) {
            $turnstileSuccess = false;
            $postData = http_build_query([
                'secret'   => $turnstileSecret,
                'response' => $turnstileToken,
                'remoteip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            if (function_exists('curl_version')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, "https://challenges.cloudflare.com/turnstile/v0/siteverify");
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                $response = curl_exec($ch);
                curl_close($ch);
            } else {
                $opts = ['http' => [
                    'method'  => 'POST',
                    'header'  => 'Content-type: application/x-www-form-urlencoded',
                    'content' => $postData,
                    'timeout' => 5
                ]];
                $context  = stream_context_create($opts);
                $response = @file_get_contents("https://challenges.cloudflare.com/turnstile/v0/siteverify", false, $context);
            }

            if ($response) {
                $responseKeys = json_decode($response, true);
                if (!empty($responseKeys["success"])) {
                    $turnstileSuccess = true;
                }
            }

            if (!$turnstileSuccess) {
                $error = 'Verifikasi Captcha (Turnstile) gagal. Silakan coba lagi.';
            }
        }

        if ($turnstileSuccess) {
            $username = trim(substr($_POST['username'] ?? '', 0, 100));
            $password = substr($_POST['password'] ?? '', 0, 200);

            if (empty($username) || empty($password)) {
                $error = 'Username dan password harus diisi.';
            } else {
                $user = dbFetchOne(
                    "SELECT id, nama, username, password, role,
                            periode_id, can_access_all, is_active,
                            totp_secret, totp_enabled
                     FROM users WHERE username = ? LIMIT 1",
                    [$username], "s"
                );

                if ($user && !$user['is_active']) {
                    $error = 'Username atau password salah.';
                    $_SESSION['login_attempts'] = $attempts + 1;

                } elseif ($user && password_verify($password, $user['password'])) {

                    $_SESSION['login_attempts']     = 0;
                    $_SESSION['login_locked_until'] = 0;

                    // Jika tidak wajib 2FA (tidak enabled/secret kosong), langsung login
                    if (!$user['totp_enabled'] || empty($user['totp_secret'])) {
                        session_regenerate_id(true);
                        $_SESSION['admin_logged_in']      = true;
                        $_SESSION['admin_id']             = $user['id'];
                        $_SESSION['admin_name']           = $user['nama'];
                        $_SESSION['admin_username']       = $user['username'];
                        $_SESSION['admin_role']           = $user['role'];
                        $_SESSION['admin_periode_id']     = $user['periode_id'];
                        $_SESSION['admin_can_access_all'] = $user['can_access_all'];
                        $_SESSION['2fa_verified']         = false;
                        $_SESSION['_last_activity']       = time();
                        $_SESSION['_auth_last_check']     = time();

                        recordUserSession($user['id']);

                        $ip = mb_substr(trim(explode(',',
                            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''
                        )[0]), 0, 45);
                        dbQuery("UPDATE users SET last_login = now(), last_ip = ? WHERE id = ?",
                                [$ip, $user['id']], "si");

                        auditLog('LOGIN', 'users', $user['id'], 'Login berhasil (2FA Bypassed)');

                        redirect('admin/dashboard.php', "Selamat datang, {$user['nama']}!", 'success');
                        exit();
                    } else {
                        // 2FA aktif — arahkan ke verifikasi
                        session_regenerate_id(true);
                        $_SESSION['2fa_pending']    = true;
                        $_SESSION['2fa_user_id']    = $user['id'];
                        $_SESSION['2fa_attempts']   = 0;
                        $_SESSION['_last_activity'] = time();
                        // Fix: gunakan URL yang konsisten terhadap BASE_URL
                        redirect('admin/2fa-verify.php');
                        exit();
                    }

                } else {
                    $newAttempts = $attempts + 1;
                    $_SESSION['login_attempts'] = $newAttempts;
                    if ($newAttempts >= $maxAttempts) {
                        $_SESSION['login_locked_until'] = time() + $lockoutTime;
                        $error = "Terlalu banyak percobaan gagal. Akun dikunci selama 15 menit.";
                    } else {
                        $remaining = $maxAttempts - $newAttempts;
                        $error = "Username atau password salah. Sisa percobaan: {$remaining}.";
                    }
                }
            }
        }
    }
}

$cssVer = file_exists(__DIR__ . '/css/login.css') ? filemtime(__DIR__ . '/css/login.css') : '1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin - BEM Kabinet Astawidya</title>
    <link rel="stylesheet" href="css/login.css?v=<?php echo $cssVer; ?>">
    <?php
    $turnstileSiteKey = $_ENV['TURNSTILE_SITE_KEY'] ?? '';
    if (!empty($turnstileSiteKey)):
    ?>
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>
<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <h1>BEM Admin</h1>
            <p>Kabinet Astawidya 2025/2026</p>
        </div>

        <?php if ($isLocked && empty($error)): ?>
            <div class="alert-lockout">
                Akun dikunci. Coba lagi dalam
                <strong><?php echo ceil(($lockedUntil - time()) / 60); ?> menit</strong>.
            </div>
        <?php elseif ($error): ?>
            <div class="<?php echo (str_contains($error,'dikunci')||str_contains($error,'menit'))
                                    ? 'alert-lockout' : 'alert-error'; ?>">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       maxlength="100" required autofocus
                       <?php echo $isLocked ? 'disabled' : ''; ?>>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password"
                       maxlength="200" required
                       <?php echo $isLocked ? 'disabled' : ''; ?>>
            </div>
            
            <?php
            $turnstileSiteKey = $_ENV['TURNSTILE_SITE_KEY'] ?? '';
            if (!empty($turnstileSiteKey) && !$isLocked):
            ?>
            <div class="form-group" style="display: flex; justify-content: center; margin-bottom: 1.5rem;">
                <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8'); ?>" data-theme="dark"></div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-login"
                    <?php echo $isLocked ? 'disabled' : ''; ?>>
                <?php echo $isLocked ? "Dikunci ({$lockWaitMins} menit)" : 'Login'; ?>
            </button>
        </form>

        <div class="login-footer">
            &copy; 2025 BEM Kabinet Astawidya
        </div>
    </div>
</div>
</body>
</html>