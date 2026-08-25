<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Admin - BEM Kabinet Astawidya</title>
    <meta name="robots" content="noindex, nofollow">

    <style>
        /* ── Login Page Styles (Migrated from legacy login.css) ── */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0a0a0a, #1a2f4a);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-container { width: 100%; max-width: 400px; padding: 20px; }

        .login-card {
            background: #111;
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(74, 144, 226, 0.3);
            border: 1px solid #333;
        }

        .login-header { text-align: center; margin-bottom: 30px; }
        .login-header h1 { color: #4A90E2; font-size: 2rem; margin-bottom: 10px; }
        .login-header p { color: #888; font-size: 0.9rem; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; color: #ccc; margin-bottom: 5px; font-size: 0.9rem; }
        .form-group input {
            width: 100%; padding: 12px 15px;
            background: #222; border: 1px solid #333; border-radius: 8px;
            color: white; font-size: 1rem; transition: all 0.3s;
        }
        .form-group input:focus { outline: none; border-color: #4A90E2; box-shadow: 0 0 10px rgba(74, 144, 226, 0.3); }
        .form-group input:disabled { opacity: 0.5; cursor: not-allowed; }

        .btn-login {
            width: 100%; padding: 14px;
            background: #4A90E2; color: white; border: none; border-radius: 8px;
            font-size: 1rem; font-weight: 600; cursor: pointer; transition: all 0.3s;
        }
        .btn-login:hover:not(:disabled) { background: #6BA5E8; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(74, 144, 226, 0.4); }
        .btn-login:disabled { background: #555; cursor: not-allowed; transform: none; }

        .alert-error {
            background: rgba(244, 67, 54, 0.1); border: 1px solid #f44336; color: #f44336;
            padding: 12px; border-radius: 8px; margin-bottom: 20px; text-align: center;
            font-size: 0.9rem; line-height: 1.5;
        }
        .alert-success {
            background: rgba(76, 175, 80, 0.1); border: 1px solid #4caf50; color: #4caf50;
            padding: 12px; border-radius: 8px; margin-bottom: 20px; text-align: center;
            font-size: 0.9rem; line-height: 1.5;
        }
        .alert-lockout {
            background: rgba(255, 152, 0, 0.1); border: 1px solid #FF9800; color: #FF9800;
            padding: 12px; border-radius: 8px; margin-bottom: 20px; text-align: center;
            font-size: 0.9rem; line-height: 1.5;
        }

        .login-footer { margin-top: 20px; text-align: center; color: #666; font-size: 0.8rem; }

        /* Password toggle */
        .password-wrapper { position: relative; width: 100%; }
        .password-wrapper input { padding-right: 45px; }
        .toggle-password {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: #888; cursor: pointer;
            font-size: 1.1rem; padding: 4px; line-height: 1; transition: color 0.2s;
            display: flex; align-items: center; justify-content: center;
        }
        .toggle-password:hover { color: #4A90E2; }
        .toggle-password svg { width: 20px; height: 20px; fill: currentColor; }

        /* Turnstile locked state */
        .form-locked input, .form-locked .btn-login { opacity: 0.4; pointer-events: none; cursor: not-allowed; }
        .form-locked .toggle-password { pointer-events: none; opacity: 0.4; }
        .turnstile-notice { text-align: center; color: #FF9800; font-size: 0.8rem; margin-bottom: 12px; animation: pulse-notice 2s infinite; }
        @keyframes pulse-notice { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

        /* Google login button */
        .btn-google-login {
            display: flex; align-items: center; justify-content: center; gap: 10px;
            width: 100%; padding: 12px; background: #ffffff; color: #333333;
            font-weight: 600; font-size: 0.95rem; border-radius: 8px;
            text-decoration: none; transition: all 0.2s; box-sizing: border-box;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2); border: none; cursor: pointer;
        }
        .btn-google-login:hover { background: #f5f5f5; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }

        .divider { display: flex; align-items: center; margin: 1.5rem 0 1rem 0; color: #666; }
        .divider-line { flex: 1; height: 1px; background: #2a3545; }
        .divider-text { padding: 0 12px; font-size: 0.85rem; color: #888; }
    </style>

    <?php if(config('services.turnstile.site_key') && app()->environment('production')): ?>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    <?php endif; ?>
</head>
<body>
<div class="login-container">
    <div class="login-card">
        <div class="login-header">
            <h1>BEM Admin</h1>
            <p>Kabinet Astawidya 2026/2027</p>
        </div>

        
        <?php if(session('success')): ?>
            <div class="alert-success"><?php echo e(session('success')); ?></div>
        <?php endif; ?>

        
        <?php if($errors->has('login')): ?>
            <?php $loginError = $errors->first('login'); ?>
            <div class="<?php echo e(str_contains($loginError, 'dikunci') || str_contains($loginError, 'menit') ? 'alert-lockout' : 'alert-error'); ?>">
                <?php echo e($loginError); ?>

            </div>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(route('login.post')); ?>" autocomplete="off" id="loginForm"
              class="<?php echo e(config('services.turnstile.site_key') && app()->environment('production') ? 'form-locked' : ''); ?>">
            <?php echo csrf_field(); ?>

            <?php if(config('services.turnstile.site_key') && app()->environment('production')): ?>
                <div class="turnstile-notice" id="turnstileNotice">
                    ⚠ Selesaikan verifikasi keamanan terlebih dahulu
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       maxlength="100" required autofocus
                       value="<?php echo e(old('username')); ?>">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input type="password" id="password" name="password"
                           maxlength="200" required>
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

            <?php if(config('services.turnstile.site_key') && app()->environment('production')): ?>
                <div class="form-group" style="display: flex; justify-content: center; margin-bottom: 1.5rem;">
                    <div class="cf-turnstile"
                         data-sitekey="<?php echo e(config('services.turnstile.site_key')); ?>"
                         data-theme="dark"
                         data-callback="onTurnstileSuccess"
                         data-error-callback="onTurnstileError"
                         data-expired-callback="onTurnstileExpired"></div>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn-login" id="btnLogin">Login</button>
        </form>

        
        <?php if(config('services.google.client_id')): ?>
            <div class="divider">
                <div class="divider-line"></div>
                <span class="divider-text">atau</span>
                <div class="divider-line"></div>
            </div>

            <a href="<?php echo e(route('google.redirect')); ?>" class="btn-google-login">
                <svg width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.52-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.665-5.17 3.665-9.17z"/>
                    <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.11 0-5.74-2.1-6.68-4.93H1.34v3.13C3.33 21.34 7.38 24 12 24z"/>
                    <path fill="#FBBC05" d="M5.32 14.25c-.24-.72-.38-1.49-.38-2.25s.14-1.53.38-2.25V6.62H1.34C.48 8.33 0 10.11 0 12s.48 3.67 1.34 5.38l3.98-3.13z"/>
                    <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.38 0 3.33 2.66 1.34 6.62l3.98 3.13c.94-2.83 3.57-5 6.68-5z"/>
                </svg>
                <span>Login dengan Google</span>
            </a>
        <?php endif; ?>

        <div class="login-footer">
            &copy; <?php echo e(date('Y')); ?> BEM Kabinet Astawidya
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
    if (form) { form.classList.remove('form-locked'); document.getElementById('username').focus(); }
    if (notice) notice.style.display = 'none';
}
function onTurnstileError() {
    var form   = document.getElementById('loginForm');
    var notice = document.getElementById('turnstileNotice');
    if (form) form.classList.add('form-locked');
    if (notice) { notice.textContent = '✖ Verifikasi gagal. Silakan muat ulang halaman.'; notice.style.color = '#f44336'; notice.style.display = 'block'; }
}
function onTurnstileExpired() {
    var form   = document.getElementById('loginForm');
    var notice = document.getElementById('turnstileNotice');
    if (form) form.classList.add('form-locked');
    if (notice) { notice.textContent = '⚠ Verifikasi kedaluwarsa. Selesaikan ulang verifikasi.'; notice.style.color = '#FF9800'; notice.style.display = 'block'; }
}
</script>
</body>
</html>
<?php /**PATH /var/www/html/bem/resources/views/auth/login.blade.php ENDPATH**/ ?>