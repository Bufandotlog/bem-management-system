<?php
// admin/kelola-perangkat.php - Manajemen sesi aktif / perangkat terhubung
// VERSI: 1.0
require_once __DIR__ . '/header.php';
#error_log("=== kelola-perangkat.php POST: " . print_r($_POST, true));

$admin_id = (int) $_SESSION['admin_id'];
$session_token_saat_ini = $_SESSION['session_token'] ?? '';

// ============================================
// PROSES PUTUSKAN SESI TERTENTU
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_putus'])) {
    if (!csrfVerify()) {
        redirect('admin/kelola-perangkat.php', 'Request tidak valid.', 'error');
        exit();
    }
    $putusId = (int) ($_POST['session_id'] ?? 0);
    if ($putusId > 0) {
        // Pastikan sesi ini milik admin yang sedang login
        $cek = dbFetchOne(
            "SELECT id, session_token FROM user_sessions WHERE id = ? AND user_id = ?",
            [$putusId, $admin_id], "ii"
        );
        if ($cek) {
            dbQuery("DELETE FROM user_sessions WHERE id = ? AND user_id = ?",
                    [$putusId, $admin_id], "ii");
            auditLog('DELETE', 'user_sessions', $putusId, 'Putuskan sesi ID: ' . $putusId);
            redirect('admin/kelola-perangkat.php', 'Sesi berhasil diputuskan.', 'success');
        } else {
            redirect('admin/kelola-perangkat.php', 'Sesi tidak ditemukan.', 'error');
        }
    }
    exit();
}

// ============================================
// PROSES PUTUSKAN SEMUA SESI LAIN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_putus_semua'])) {
    if (!csrfVerify()) {
        redirect('admin/kelola-perangkat.php', 'Request tidak valid.', 'error');
        exit();
    }
    // Hapus semua sesi kecuali sesi yang sedang aktif sekarang
    dbQuery(
        "DELETE FROM user_sessions WHERE user_id = ? AND session_token != ?",
        [$admin_id, $session_token_saat_ini], "is"
    );
    auditLog('DELETE', 'user_sessions', $admin_id, 'Putuskan semua sesi lain');
    redirect('admin/kelola-perangkat.php', 'Semua sesi lain berhasil diputuskan.', 'success');
    exit();
}

// Ambil semua sesi aktif milik admin ini
$sesi_aktif = dbFetchAll(
    "SELECT id, session_token, device_info, ip_address, created_at, last_active
     FROM user_sessions
     WHERE user_id = ?
     ORDER BY last_active DESC",
    [$admin_id], "i"
);

// Helper parse User-Agent jadi nama perangkat yang lebih ramah
function parseDevice(string $ua): string {
    if (empty($ua) || $ua === 'Unknown') return 'Perangkat tidak diketahui';
    // Browser
    $browser = 'Browser lain';
    if (str_contains($ua, 'Firefox'))        $browser = 'Firefox';
    elseif (str_contains($ua, 'Edg'))        $browser = 'Edge';
    elseif (str_contains($ua, 'OPR'))        $browser = 'Opera';
    elseif (str_contains($ua, 'Chrome'))     $browser = 'Chrome';
    elseif (str_contains($ua, 'Safari'))     $browser = 'Safari';
    // OS
    $os = 'OS lain';
    if (str_contains($ua, 'Windows NT 10')) $os = 'Windows 10/11';
    elseif (str_contains($ua, 'Windows'))   $os = 'Windows';
    elseif (str_contains($ua, 'Macintosh')) $os = 'macOS';
    elseif (str_contains($ua, 'iPhone'))    $os = 'iPhone';
    elseif (str_contains($ua, 'iPad'))      $os = 'iPad';
    elseif (str_contains($ua, 'Android'))   $os = 'Android';
    elseif (str_contains($ua, 'Linux'))     $os = 'Linux';
    return "{$browser} di {$os}";
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Baru saja';
    if ($diff < 3600)   return floor($diff/60) . ' menit lalu';
    if ($diff < 86400)  return floor($diff/3600) . ' jam lalu';
    return floor($diff/86400) . ' hari lalu';
}
?>

<div class="page-header">
    <h1><i class="fas fa-laptop"></i> Kelola Perangkat</h1>
    <p>Lihat dan putuskan sesi login dari perangkat lain</p>
</div>

<?php flashMessage(); ?>

<!-- Info sesi aktif -->
<div style="margin-bottom:1.5rem;padding:.85rem 1rem;background:rgba(74,144,226,.08);border:1px solid rgba(74,144,226,.3);border-radius:8px;font-size:13px;color:#8BB9F0;">
    <i class="fas fa-info-circle"></i>
    Kamu memiliki <strong><?php echo count($sesi_aktif); ?></strong> sesi aktif.
    Sesi bertanda <span style="color:#4caf50;font-weight:600;">★ Ini Kamu</span> adalah sesi yang sedang digunakan sekarang.
</div>

<?php if (count($sesi_aktif) > 1): ?>
<!-- Tombol putuskan semua sesi lain -->
<form method="POST" style="margin-bottom:1.5rem"
      onsubmit="return confirm('Yakin ingin memutuskan semua sesi lain? Perangkat lain akan logout otomatis.')">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action_putus_semua" value="1">
    <button type="submit" class="btn-danger" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#f44336;color:white;border:none;border-radius:6px;cursor:pointer;font-size:13px;">
        <i class="fas fa-sign-out-alt"></i> Putuskan Semua Perangkat Lain
    </button>
</form>
<?php endif; ?>

<!-- Daftar sesi -->
<div style="display:flex;flex-direction:column;gap:.75rem;">
    <?php foreach ($sesi_aktif as $sesi):
        $isCurrentSession = ($sesi['session_token'] === $session_token_saat_ini);
        $deviceName = parseDevice($sesi['device_info'] ?? '');
        $borderColor = $isCurrentSession ? '#4caf50' : '#333';
    ?>
    <div style="background:#1a1a2e;border:1px solid <?php echo $borderColor; ?>;border-radius:10px;padding:1rem 1.2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <!-- Icon perangkat -->
        <div style="width:40px;height:40px;border-radius:50%;background:rgba(74,144,226,.15);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <?php
            $ua = $sesi['device_info'] ?? '';
            $icon = (str_contains($ua,'iPhone') || str_contains($ua,'Android') || str_contains($ua,'iPad'))
                ? 'fa-mobile-alt' : 'fa-desktop';
            ?>
            <i class="fas <?php echo $icon; ?>" style="color:#4A90E2;font-size:1.1rem;"></i>
        </div>

        <!-- Info perangkat -->
        <div style="flex:1;min-width:180px;">
            <div style="font-size:13px;font-weight:600;color:#e0e0e0;margin-bottom:3px;">
                <?php echo htmlspecialchars($deviceName, ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($isCurrentSession): ?>
                    <span style="color:#4caf50;font-size:11px;margin-left:6px;">★ Ini Kamu</span>
                <?php endif; ?>
            </div>
            <div style="font-size:11px;color:#888;line-height:1.6;">
                <i class="fas fa-map-marker-alt" style="width:14px;"></i>
                IP: <?php echo htmlspecialchars($sesi['ip_address'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>
                &nbsp;·&nbsp;
                <i class="fas fa-clock" style="width:14px;"></i>
                Aktif <?php echo timeAgo($sesi['last_active']); ?>
                &nbsp;·&nbsp;
                <i class="fas fa-sign-in-alt" style="width:14px;"></i>
                Login <?php echo date('d/m/Y H:i', strtotime($sesi['created_at'])); ?>
            </div>
        </div>

        <!-- Tombol putus (tidak tampil untuk sesi sendiri) -->
        <?php if (!$isCurrentSession): ?>
        <form method="POST" onsubmit="return confirm('Putuskan sesi dari perangkat ini?')" style="flex-shrink:0;">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action_putus" value="1">
            <input type="hidden" name="session_id" value="<?php echo (int)$sesi['id']; ?>">
            <button type="submit" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:transparent;color:#f44336;border:1px solid #f44336;border-radius:6px;cursor:pointer;font-size:12px;transition:all .2s;">
                <i class="fas fa-times"></i> Putuskan
            </button>
        </form>
        <?php else: ?>
        <span style="font-size:11px;color:#4caf50;padding:7px 14px;border:1px solid #4caf50;border-radius:6px;">
            <i class="fas fa-check"></i> Sesi Ini
        </span>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if (empty($sesi_aktif)): ?>
    <div style="text-align:center;padding:3rem;color:#555;">
        <i class="fas fa-laptop" style="font-size:2.5rem;margin-bottom:1rem;"></i>
        <p>Tidak ada sesi aktif tercatat.</p>
        <small>Sesi baru akan tercatat saat login berikutnya.</small>
    </div>
    <?php endif; ?>
</div>

<div style="margin-top:1.5rem;">
    <a href="pengaturan.php" style="display:inline-flex;align-items:center;gap:6px;color:#4A90E2;font-size:13px;text-decoration:none;">
        <i class="fas fa-arrow-left"></i> Kembali ke Pengaturan
    </a>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>