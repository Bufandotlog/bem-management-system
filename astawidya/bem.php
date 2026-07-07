<?php
// astawidya/bem.php - Login Panel BEM
// Terlindungi oleh Cookie Gate

require_once __DIR__ . '/../includes/functions.php';

// Ambil kunci gerbang dari .env, default jika tidak ada
$adminGateKey = $_ENV['ADMIN_GATE_KEY'] ?? 'astawidya-secret';

// Jika mengakses dengan query string kunci (?key=xxx), pasang cookie
if (isset($_GET['key']) && $_GET['key'] === $adminGateKey) {
    $cookieOptions = [
        'expires' => 0, // Sesi
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ];
    setcookie('admin_access', '1', $cookieOptions);
    // Redirect ke URL bersih tanpa query string
    $currentUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]";
    header("Location: " . $currentUrl);
    exit();
}

// Cookie Gate - Proteksi Halaman Login dari Akses Langsung
if (!isset($_COOKIE['admin_access']) || $_COOKIE['admin_access'] !== '1') {
    header("HTTP/1.1 404 Not Found");
    if (file_exists(__DIR__ . '/../404.html')) {
        include __DIR__ . '/../404.html';
    } else {
        echo "<h1>404 Not Found</h1>The requested URL was not found on this server.";
    }
    exit();
}

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

// IP-based lockout
$ipMaxAttempts = 15;
$ipAttempts    = countIpFailedAttempts(30);
if (!$isLocked && $ipAttempts >= $ipMaxAttempts) {
    $isLocked     = true;
    $lockWaitMins = 30;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        $appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production');
        $turnstileToken = $_POST['cf-turnstile-response'] ?? '';
        $turnstileSiteKey = $_ENV['TURNSTILE_SITE_KEY'] ?? getenv('TURNSTILE_SITE_KEY') ?: '';
        $turnstileSecret = $_ENV['TURNSTILE_SECRET_KEY'] ?? getenv('TURNSTILE_SECRET_KEY') ?: '';

        $turnstileSuccess = true;
        if ($appEnv !== 'development' && !empty($turnstileSiteKey) && !empty($turnstileSecret)) {
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
                $attemptUsername = trim(substr($_POST['username'] ?? '', 0, 100));
                recordFailedAttempt('turnstile_failed', $attemptUsername ?: null);
                auditLog('TURNSTILE_FAIL', null, null, 'Turnstile verification failed for IP: ' . getClientIp() . ($attemptUsername ? " user: {$attemptUsername}" : ''));
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
                    recordFailedAttempt('login_failed', $username);
                } elseif ($user && password_verify($password, $user['password'])) {
                    $_SESSION['login_attempts']     = 0;
                    $_SESSION['login_locked_until'] = 0;

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
                        session_regenerate_id(true);
                        $_SESSION['2fa_pending']    = true;
                        $_SESSION['2fa_user_id']    = $user['id'];
                        $_SESSION['2fa_attempts']   = 0;
                        $_SESSION['_last_activity'] = time();
                        redirect('admin/2fa-verify.php');
                        exit();
                    }
                } else {
                    $newAttempts = $attempts + 1;
                    $_SESSION['login_attempts'] = $newAttempts;
                    recordFailedAttempt('login_failed', $username);
                    if ($newAttempts >= $maxAttempts) {
                        $_SESSION['login_locked_until'] = time() + $lockoutTime;
                        $error = "Terlalu banyak percobaan gagal. Akun dikunci selama 15 menit.";
                        recordFailedAttempt('lockout', $username);
                        auditLog('LOCKOUT', null, null, 'Account locked for IP: ' . getClientIp() . " user: {$username}");
                    } else {
                        $remaining = $maxAttempts - $newAttempts;
                        $error = "Username atau password salah. Sisa percobaan: {$remaining}.";
                    }
                }
            }
        }
    }
}

$cssVer = file_exists(__DIR__ . '/../admin/css/login.css') ? filemtime(__DIR__ . '/../admin/css/login.css') : '1';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin - BEM Kabinet Astawidya</title>
    <link rel="stylesheet" href="../admin/css/login.css?v=<?php echo $cssVer; ?>">
    <?php
    $appEnvFront = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production');
    $turnstileSiteKey = $_ENV['TURNSTILE_SITE_KEY'] ?? getenv('TURNSTILE_SITE_KEY') ?: '';
    $hasTurnstile = ($appEnvFront !== 'development') && !empty($turnstileSiteKey) && !$isLocked;
    if ($hasTurnstile):
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

        <form method="POST" autocomplete="off" id="loginForm" class="<?php echo $hasTurnstile ? 'form-locked' : ''; ?>">
            <input type="hidden" name="csrf_token"
                   value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

            <?php if ($hasTurnstile): ?>
            <div class="turnstile-notice" id="turnstileNotice">
                ⚠ Selesaikan verifikasi keamanan terlebih dahulu
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       maxlength="100" required <?php echo $hasTurnstile ? '' : 'autofocus'; ?>
                       <?php echo $isLocked ? 'disabled' : ''; ?>>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password"
                           maxlength="200" required
                           <?php echo $isLocked ? 'disabled' : ''; ?>>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Tampilkan password" tabindex="-1">
                        <svg id="eyeShow" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                        </svg>
                        <svg id="eyeHide" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="display:none;">
                            <path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.804 11.804 0 001 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <?php if ($hasTurnstile): ?>
            <div class="form-group" style="display: flex; justify-content: center; margin-bottom: 1.5rem;">
                <div class="cf-turnstile"
                     data-sitekey="<?php echo htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8'); ?>"
                     data-theme="dark"
                     data-callback="onTurnstileSuccess"
                     data-error-callback="onTurnstileError"
                     data-expired-callback="onTurnstileExpired"></div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-login" id="btnLogin"
                    <?php echo $isLocked ? 'disabled' : ''; ?>>
                <?php echo $isLocked ? "Dikunci ({$lockWaitMins} menit)" : 'Login'; ?>
            </button>
        </form>

        <div class="login-footer">
            &copy; 2025 BEM Kabinet Astawidya
        </div>
    </div>
</div>

<script>
(function() {
    var toggle = document.getElementById('togglePassword');
    var input  = document.getElementById('password');
    var eyeShow = document.getElementById('eyeShow');
    var eyeHide = document.getElementById('eyeHide');

    if (toggle && input) {
        toggle.addEventListener('click', function() {
            if (input.type === 'password') {
                input.type = 'text';
                eyeShow.style.display = 'none';
                eyeHide.style.display = 'block';
                toggle.setAttribute('aria-label', 'Sembunyikan password');
            } else {
                input.type = 'password';
                eyeShow.style.display = 'block';
                eyeHide.style.display = 'none';
                toggle.setAttribute('aria-label', 'Tampilkan password');
            }
        });
    }
})();

function onTurnstileSuccess(token) {
    var form   = document.getElementById('loginForm');
    var notice = document.getElementById('turnstileNotice');
    if (form) {
        form.classList.remove('form-locked');
        document.getElementById('username').focus();
    }
    if (notice) notice.style.display = 'none';
}

function onTurnstileError() {
    var form   = document.getElementById('loginForm');
    var notice = document.getElementById('turnstileNotice');
    if (form) form.classList.add('form-locked');
    if (notice) {
        notice.textContent = '✖ Verifikasi gagal. Silakan muat ulang halaman.';
        notice.style.color = '#f44336';
        notice.style.display = 'block';
    }
}

function onTurnstileExpired() {
    var form   = document.getElementById('loginForm');
    var notice = document.getElementById('turnstileNotice');
    if (form) form.classList.add('form-locked');
    if (notice) {
        notice.textContent = '⚠ Verifikasi kedaluwarsa. Selesaikan ulang verifikasi.';
        notice.style.color = '#FF9800';
        notice.style.display = 'block';
    }
}
</script>
</body>
</html>
