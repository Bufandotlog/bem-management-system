<?php
/**
 * Halaman download aplikasi mobile BEM Astawidya.
 *
 * Endpoint publik: https://bembudiutomo.my.id/downloads/
 *
 * Membaca metadata dari releases.json (di-generate oleh GitHub Actions).
 */

declare(strict_types=1);

$releasesFile = __DIR__ . '/releases.json';
$releases = null;
$error = null;

if (!is_readable($releasesFile)) {
    http_response_code(503);
    $error = 'Metadata rilis belum tersedia. Silakan coba lagi beberapa saat.';
} else {
    $raw = file_get_contents($releasesFile);
    if ($raw === false) {
        http_response_code(503);
        $error = 'Gagal membaca metadata rilis.';
    } else {
        $releases = json_decode($raw, true);
        if (!is_array($releases) || !isset($releases['apks']) || !is_array($releases['apks'])) {
            http_response_code(503);
            $error = 'Metadata rilis tidak valid.';
        }
    }
}

if ($error !== null) {
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <title>Download Aplikasi Mobile BEM</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                   background: #f7f9fc; color: #222; margin: 0; padding: 2rem; }
            .container { max-width: 720px; margin: 0 auto; background: #fff;
                         padding: 2rem; border-radius: 12px;
                         box-shadow: 0 2px 8px rgba(0,0,0,.08); }
            h1 { margin-top: 0; color: #b91c1c; }
            p { line-height: 1.6; }
        </style>
    </head>
    <body>
        <main class="container">
            <h1>Download Aplikasi Mobile</h1>
            <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$appName  = (string)($releases['app'] ?? 'BEM Astawidya');
$latest   = (string)($releases['latest'] ?? '0.0.0');
$build    = (int)($releases['build'] ?? 0);
$released = (string)($releases['released_at'] ?? '');
$apks     = $releases['apks'];

function human_arch(string $arch): string {
    return match ($arch) {
        'arm64-v8a'    => 'ARM64 (arm64-v8a)',
        'armeabi-v7a'  => 'ARMv7 (armeabi-v7a)',
        'x86_64'       => 'x86_64 (Emulator/Chromebook)',
        'x86'          => 'x86 (Emulator)',
        'universal'    => 'Universal (semua perangkat)',
        default        => $arch,
    };
}

function human_size(float $mb): string {
    if ($mb >= 1024) {
        return number_format($mb / 1024, 2) . ' GB';
    }
    return number_format($mb, 1) . ' MB';
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Download Aplikasi Mobile BEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Download aplikasi mobile resmi BEM INSTBUNAS Kabinet Astawidya untuk Android.">
    <style>
        :root {
            --primary: #b91c1c;
            --primary-dark: #7f1d1d;
            --bg: #f7f9fc;
            --card: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 1.5rem;
            line-height: 1.6;
        }
        .container {
            max-width: 880px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #fff;
            padding: 2rem;
            border-radius: 12px 12px 0 0;
            text-align: center;
        }
        .header h1 { margin: 0; font-size: 1.6rem; }
        .header .version {
            margin-top: 0.5rem;
            opacity: 0.92;
            font-size: 0.95rem;
        }
        .card {
            background: var(--card);
            padding: 1.5rem 2rem 2rem;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem 1.5rem;
            margin-bottom: 1.5rem;
            color: var(--muted);
            font-size: 0.9rem;
        }
        .meta-item strong { color: var(--text); }
        .recommendation {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 0.75rem 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.92rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }
        th, td {
            text-align: left;
            padding: 0.85rem 0.75rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.95rem;
        }
        th {
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            font-size: 0.78rem;
            letter-spacing: 0.04em;
        }
        tr:last-child td { border-bottom: none; }
        .btn {
            display: inline-block;
            background: var(--primary);
            color: #fff !important;
            text-decoration: none;
            padding: 0.55rem 1.1rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: background 0.15s;
        }
        .btn:hover { background: var(--primary-dark); }
        .btn-small {
            display: inline-block;
            background: transparent;
            color: var(--muted) !important;
            text-decoration: none;
            padding: 0.25rem 0.55rem;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 0.78rem;
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
        }
        .btn-small:hover { background: #f3f4f6; }
        code {
            font-family: ui-monospace, "SF Mono", Menlo, monospace;
            font-size: 0.8rem;
            background: #f3f4f6;
            padding: 0.15rem 0.35rem;
            border-radius: 3px;
            word-break: break-all;
            display: inline-block;
            max-width: 100%;
        }
        details {
            margin-top: 1.5rem;
            padding: 0.75rem 1rem;
            background: #f9fafb;
            border-radius: 6px;
        }
        details summary {
            cursor: pointer;
            font-weight: 600;
            color: var(--text);
        }
        details[open] summary { margin-bottom: 0.5rem; }
        .footer-note {
            margin-top: 1.5rem;
            color: var(--muted);
            font-size: 0.85rem;
            text-align: center;
        }
        @media (max-width: 600px) {
            body { padding: 0.75rem; }
            .header { padding: 1.5rem 1rem; }
            .header h1 { font-size: 1.3rem; }
            .card { padding: 1rem; }
            th, td { padding: 0.6rem 0.4rem; font-size: 0.85rem; }
            .btn { padding: 0.5rem 0.8rem; font-size: 0.85rem; }
            code { font-size: 0.72rem; }
        }
    </style>
</head>
<body>
<div class="container">
    <header class="header">
        <h1><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?> Mobile</h1>
        <div class="version">
            Versi <strong>v<?= htmlspecialchars($latest, ENT_QUOTES, 'UTF-8') ?></strong>
            · Build <strong><?= $build ?></strong>
        </div>
    </header>

    <main class="card">
        <div class="meta">
            <div class="meta-item">Dirilis: <strong><?= htmlspecialchars($released, ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div class="meta-item">Total varian: <strong><?= count($apks) ?> APK</strong></div>
        </div>

        <div class="recommendation">
            💡 <strong>Rekomendasi:</strong> Untuk sebagian besar perangkat Android modern (2018 ke atas), gunakan <strong>ARM64</strong>.
        </div>

        <table>
            <thead>
                <tr>
                    <th>Arsitektur</th>
                    <th>Ukuran</th>
                    <th>SHA-256</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($apks as $apk):
                $arch  = (string)($apk['arch'] ?? 'unknown');
                $file  = (string)($apk['file'] ?? '');
                $size  = (float)($apk['size_mb'] ?? 0);
                $sha   = (string)($apk['sha256'] ?? '');
                $short = substr($sha, 0, 12) . '…' . substr($sha, -8);
                $href  = '/downloads/' . $file;
            ?>
                <tr>
                    <td><?= htmlspecialchars(human_arch($arch), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars(human_size($size), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <a class="btn-small" href="/downloads/sha256.txt"
                           title="<?= htmlspecialchars($sha, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($short, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </td>
                    <td>
                        <a class="btn" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" download>
                            ⬇ Unduh
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <details>
            <summary>Verifikasi checksum</summary>
            <p>Untuk memastikan file APK yang Anda unduh benar dan tidak dimodifikasi, jalankan perintah berikut di terminal Anda setelah mengunduh:</p>
            <p><code>sha256sum BEM-Astawidya-v<?= htmlspecialchars($latest, ENT_QUOTES, 'UTF-8') ?>-arm64.apk</code></p>
            <p>Bandingkan output dengan nilai SHA-256 di kolom tabel (klik untuk membuka <code>sha256.txt</code>). Jika sama, file aman untuk diinstal.</p>
        </details>

        <details>
            <summary>Cara install APK di Android</summary>
            <ol>
                <li>Buka <strong>Pengaturan → Keamanan</strong> → aktifkan <strong>"Sumber tidak dikenal"</strong> (atau izinkan browser Anda).</li>
                <li>Buka file APK yang diunduh, ketuk <strong>Install</strong>.</li>
                <li>Jika muncul peringatan update dari versi lama (beda signing key), uninstall versi lama dulu.</li>
            </ol>
        </details>

        <p class="footer-note">
            ⓘ Halaman ini dibuat otomatis oleh GitHub Actions setiap kali ada rilis baru.
        </p>
    </main>
</div>
</body>
</html>
