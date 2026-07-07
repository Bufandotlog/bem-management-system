<?php
// admin/test_turnstile.php - Turnstile Debugging Script
require_once __DIR__ . '/../includes/functions.php';

$appEnv = defined('APP_ENV') ? APP_ENV : ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production');
$turnstileSiteKey = $_ENV['TURNSTILE_SITE_KEY'] ?? getenv('TURNSTILE_SITE_KEY') ?: '';
$turnstileSecret = $_ENV['TURNSTILE_SECRET_KEY'] ?? getenv('TURNSTILE_SECRET_KEY') ?: '';

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Debug Cloudflare Turnstile</title>
    <style>
        body { font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; line-height: 1.6; background: #f9f9f9; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        pre { background: #333; color: #fff; padding: 15px; border-radius: 4px; overflow-x: auto; }
        .error { color: #f44336; font-weight: bold; }
        .success { color: #4caf50; font-weight: bold; }
    </style>
</head>
<body>
<div class="card">
    <h2>Cloudflare Turnstile Debugger</h2>
    <p>Gunakan halaman ini untuk mendeteksi penyebab kegagalan verifikasi Captcha.</p>
    
    <hr>
    
    <h3>Konfigurasi Terdeteksi:</h3>
    <ul>
        <li><strong>APP_ENV:</strong> <?php echo htmlspecialchars($appEnv); ?></li>
        <li><strong>Site Key:</strong> <code><?php echo htmlspecialchars($turnstileSiteKey); ?></code></li>
        <li><strong>Secret Key:</strong> <code><?php echo htmlspecialchars(substr($turnstileSecret, 0, 10)); ?>... (tersembunyi)</code></li>
    </ul>

    <hr>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['cf-turnstile-response'] ?? '';
        echo "<h3>Hasil Pengujian Verifikasi:</h3>";
        
        if (empty($token)) {
            echo "<p class='error'>Token Turnstile kosong. Selesaikan widget terlebih dahulu.</p>";
        } else {
            echo "<p>Token diterima (panjang: " . strlen($token) . " karakter). Mengirim ke Cloudflare...</p>";
            
            $postData = http_build_query([
                'secret'   => $turnstileSecret,
                'response' => $token,
                'remoteip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://challenges.cloudflare.com/turnstile/v0/siteverify");
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            // Debugging SSL jika ada kendala CA Bundle
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($err) {
                echo "<p class='error'>cURL Error: " . htmlspecialchars($err) . "</p>";
            } else {
                echo "<p>HTTP Status Code: <strong>$httpCode</strong></p>";
                echo "<h4>Raw Response dari Cloudflare:</h4>";
                echo "<pre>" . htmlspecialchars($response) . "</pre>";
                
                $resData = json_decode($response, true);
                if (isset($resData['success']) && $resData['success'] === true) {
                    echo "<p class='success'>✔ Verifikasi Sukses! Captcha berhasil diverifikasi.</p>";
                } else {
                    echo "<p class='error'>❌ Verifikasi Gagal! Periksa kode error di atas.</p>";
                }
            }
        }
        echo "<hr>";
    }
    ?>

    <h3>Uji Widget Turnstile:</h3>
    <?php if (empty($turnstileSiteKey)): ?>
        <p class="error">Site Key kosong. Pastikan variabel TURNSTILE_SITE_KEY terdefinisi di .env Anda.</p>
    <?php else: ?>
        <form method="POST">
            <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
            <div style="display: flex; justify-content: center; margin: 20px 0;">
                <div class="cf-turnstile" data-sitekey="<?php echo htmlspecialchars($turnstileSiteKey); ?>" data-theme="light"></div>
            </div>
            <button type="submit" style="width: 100%; padding: 12px; background: #007bff; color: white; border: none; border-radius: 4px; font-size: 16px; cursor: pointer;">Kirim & Uji Verifikasi</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
