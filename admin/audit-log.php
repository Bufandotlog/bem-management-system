<?php
// admin/audit-log.php - Halaman audit log (superadmin only)
// VERSI: 1.1 - Perbaikan CSRF download + audit log
//   CHANGED: Validasi CSRF untuk download sekarang hanya menggunakan token GET
//   ADDED: Pembersihan buffer sebelum kirim CSV
//   ADDED: Audit log untuk download CSV
//   UNCHANGED: Filter, pagination, tampilan

require_once __DIR__ . '/header.php';

if ($_SESSION['admin_role'] !== 'superadmin' && !$user_can_access_all) {
    redirect('admin/dashboard.php', 'Akses ditolak!', 'error');
    exit();
}

// ============================================
// DOWNLOAD CSV
// ============================================
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    // Validasi CSRF token via GET
    $tokenGet = $_GET['csrf_token'] ?? '';
    $tokenSes = $_SESSION['csrf_token'] ?? '';
    if (empty($tokenGet) || empty($tokenSes) || !hash_equals($tokenSes, $tokenGet)) {
        redirect('admin/audit-log.php', 'Request tidak valid.', 'error');
        exit();
    }

    // Ambil semua data log
    $logs = dbFetchAll(
        "SELECT al.*, u.nama as nama_lengkap
         FROM audit_log al
         LEFT JOIN users u ON al.user_id = u.id
         ORDER BY al.created_at DESC"
    );

    // Catat aksi download di audit log
    auditLog('DOWNLOAD', 'audit_log', null, 'Download CSV audit log');

    // Bersihkan buffer sebelum mengirim header
    if (ob_get_level()) ob_end_clean();

    $filename = 'audit-log-bem-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    // BOM untuk Excel agar bisa baca UTF-8
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Waktu', 'Username', 'Nama Lengkap', 'Aksi', 'Tabel', 'ID Target', 'Deskripsi', 'IP Address']);
    foreach ($logs as $log) {
        fputcsv($out, [
            $log['id'],
            $log['created_at'],
            $log['username'] ?? '-',
            $log['nama_lengkap'] ?? '-',
            $log['action'],
            $log['target_table'] ?? '-',
            $log['target_id'] ?? '-',
            $log['deskripsi'] ?? '-',
            $log['ip_address'] ?? '-',
        ]);
    }
    fclose($out);
    exit();
}

// ============================================
// FILTER
// ============================================
$filterAction = sanitizeText($_GET['action'] ?? '', 20);
$filterUser   = sanitizeText($_GET['user']   ?? '', 50);
$filterDate   = $_GET['date'] ?? '';
if ($filterDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = '';
}

// Build query dengan filter
$where  = [];
$params = [];
$types  = '';

if ($filterAction) {
    $where[]  = "al.action = ?";
    $params[] = strtoupper($filterAction);
    $types   .= 's';
}
if ($filterUser) {
    $where[]  = "al.username LIKE ?";
    $params[] = '%' . $filterUser . '%';
    $types   .= 's';
}
if ($filterDate) {
    $where[]  = "DATE(al.created_at) = ?";
    $params[] = $filterDate;
    $types   .= 's';
}

$whereStr = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Pagination
$perPage     = 50;
$page        = max(1, (int)($_GET['p'] ?? 1));
$offset      = ($page - 1) * $perPage;

$totalRow = dbFetchOne(
    "SELECT COUNT(*) as total FROM audit_log al {$whereStr}",
    $params, $types
);
$total    = (int)($totalRow['total'] ?? 0);
$totalPage = max(1, ceil($total / $perPage));

$paramsPaged  = array_merge($params, [$perPage, $offset]);
$typesPaged   = $types . 'ii';

$logs = dbFetchAll(
    "SELECT al.*, u.nama as nama_lengkap
     FROM audit_log al
     LEFT JOIN users u ON al.user_id = u.id
     {$whereStr}
     ORDER BY al.created_at DESC
     LIMIT ? OFFSET ?",
    $paramsPaged, $typesPaged
);

// Statistik ringkas - Hybrid Syntax (MySQL vs PostgreSQL)
$isMysql = true; // Default ke mysql jika di produksi
if (function_exists('dbGetDriver')) {
    $isMysql = (dbGetDriver() === 'mysql');
}
$intervalSql = $isMysql ? "NOW() - INTERVAL 30 DAY" : "now() - INTERVAL '30 days'";

$stats = dbFetchAll(
    "SELECT action, COUNT(*) as total
     FROM audit_log
     WHERE created_at >= $intervalSql
     GROUP BY action ORDER BY total DESC"
);

// Warna dan ikon per aksi
function actionStyle(string $action): array {
    return match(strtoupper($action)) {
        'CREATE'  => ['color'=>'#4caf50', 'icon'=>'fa-plus-circle',    'bg'=>'rgba(76,175,80,.1)'],
        'UPDATE'  => ['color'=>'#4A90E2', 'icon'=>'fa-edit',           'bg'=>'rgba(74,144,226,.1)'],
        'DELETE'  => ['color'=>'#f44336', 'icon'=>'fa-trash',          'bg'=>'rgba(244,67,54,.1)'],
        'LOGIN'   => ['color'=>'#8bc34a', 'icon'=>'fa-sign-in-alt',    'bg'=>'rgba(139,195,74,.1)'],
        'LOGOUT'  => ['color'=>'#ff9800', 'icon'=>'fa-sign-out-alt',   'bg'=>'rgba(255,152,0,.1)'],
        'DOWNLOAD'=> ['color'=>'#9c27b0', 'icon'=>'fa-download',       'bg'=>'rgba(156,39,176,.1)'],
        default   => ['color'=>'#888',    'icon'=>'fa-circle',         'bg'=>'rgba(128,128,128,.1)'],
    };
}

$downloadToken = csrfToken();
?>

<div class="page-header">
    <h1><i class="fas fa-clipboard-list"></i> Audit Log</h1>
    <p>Riwayat semua aksi admin — data disimpan 30 hari</p>
</div>

<?php flashMessage(); ?>

<!-- Statistik 30 hari terakhir -->
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem;">
    <?php foreach ($stats as $s):
        $style = actionStyle($s['action']);
    ?>
    <div style="flex:1;min-width:120px;padding:.75rem 1rem;background:<?php echo $style['bg']; ?>;border:1px solid <?php echo $style['color']; ?>33;border-radius:10px;">
        <div style="font-size:1.4rem;font-weight:600;color:<?php echo $style['color']; ?>"><?php echo (int)$s['total']; ?></div>
        <div style="font-size:.75rem;color:var(--color-text-secondary);margin-top:2px;">
            <i class="fas <?php echo $style['icon']; ?>" style="color:<?php echo $style['color']; ?>;margin-right:4px;"></i>
            <?php echo htmlspecialchars($s['action']); ?>
        </div>
    </div>
    <?php endforeach; ?>
    <div style="flex:1;min-width:120px;padding:.75rem 1rem;background:rgba(74,144,226,.08);border:1px solid rgba(74,144,226,.2);border-radius:10px;">
        <div style="font-size:1.4rem;font-weight:600;color:#4A90E2;"><?php echo $total; ?></div>
        <div style="font-size:.75rem;color:var(--color-text-secondary);margin-top:2px;">
            <i class="fas fa-filter" style="color:#4A90E2;margin-right:4px;"></i>
            Total (filter)
        </div>
    </div>
</div>

<!-- Filter + Download -->
<div style="background:var(--bg-card,#1a1a2e);border:1px solid #2a2a3e;border-radius:10px;padding:1rem;margin-bottom:1rem;">
    <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;">
        <div style="flex:1;min-width:140px;">
            <label style="font-size:.8rem;color:#888;display:block;margin-bottom:4px;">Aksi</label>
            <select name="action" style="width:100%;padding:8px 10px;background:#111;border:1px solid #333;color:white;border-radius:6px;font-size:.85rem;">
                <option value="">Semua Aksi</option>
                <?php foreach (['CREATE','UPDATE','DELETE','LOGIN','LOGOUT','DOWNLOAD'] as $a): ?>
                    <option value="<?php echo $a; ?>" <?php echo $filterAction === $a ? 'selected' : ''; ?>><?php echo $a; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex:1;min-width:140px;">
            <label style="font-size:.8rem;color:#888;display:block;margin-bottom:4px;">Username</label>
            <input type="text" name="user" value="<?php echo htmlspecialchars($filterUser); ?>"
                   placeholder="Cari username..."
                   style="width:100%;padding:8px 10px;background:#111;border:1px solid #333;color:white;border-radius:6px;font-size:.85rem;box-sizing:border-box;">
        </div>
        <div style="flex:1;min-width:140px;">
            <label style="font-size:.8rem;color:#888;display:block;margin-bottom:4px;">Tanggal</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>"
                   style="width:100%;padding:8px 10px;background:#111;border:1px solid #333;color:white;border-radius:6px;font-size:.85rem;box-sizing:border-box;">
        </div>
        <div style="display:flex;gap:.5rem;">
            <button type="submit" style="padding:8px 16px;background:#4A90E2;color:white;border:none;border-radius:6px;cursor:pointer;font-size:.85rem;">
                <i class="fas fa-search"></i> Filter
            </button>
            <a href="audit-log.php" style="padding:8px 16px;background:#333;color:#aaa;border-radius:6px;text-decoration:none;font-size:.85rem;">
                Reset
            </a>
        </div>
    </form>
</div>

<!-- Tombol Download -->
<div style="margin-bottom:1rem;display:flex;justify-content:flex-end;">
    <a href="audit-log.php?download=csv&csrf_token=<?php echo htmlspecialchars($downloadToken); ?>"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#4caf50;color:white;border-radius:6px;text-decoration:none;font-size:.85rem;">
        <i class="fas fa-download"></i> Download CSV (semua data)
    </a>
</div>

<!-- Tabel Log -->
<div style="background:var(--bg-card,#1a1a2e);border:1px solid #2a2a3e;border-radius:10px;overflow:hidden;">
    <?php if (empty($logs)): ?>
        <div style="text-align:center;padding:3rem;color:#555;">
            <i class="fas fa-clipboard-list" style="font-size:2.5rem;margin-bottom:1rem;"></i>
            <p>Belum ada log <?php echo $filterAction || $filterUser || $filterDate ? 'yang sesuai filter' : 'tercatat'; ?>.</p>
        </div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
            <thead>
                <tr style="background:#111;color:#888;text-transform:uppercase;font-size:.7rem;letter-spacing:.05em;">
                    <th style="padding:.6rem 1rem;text-align:left;white-space:nowrap;">Waktu</th>
                    <th style="padding:.6rem 1rem;text-align:left;">Admin</th>
                    <th style="padding:.6rem 1rem;text-align:left;">Aksi</th>
                    <th style="padding:.6rem 1rem;text-align:left;">Target</th>
                    <th style="padding:.6rem 1rem;text-align:left;">Deskripsi</th>
                    <th style="padding:.6rem 1rem;text-align:left;white-space:nowrap;">IP</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log):
                    $style = actionStyle($log['action'] ?? '');
                ?>
                <tr style="border-top:1px solid #222;">
                    <td style="padding:.6rem 1rem;color:#666;white-space:nowrap;">
                        <?php echo date('d/m/Y', strtotime($log['created_at'])); ?>
                        <br><span style="font-size:.75rem;"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></span>
                    </td>
                    <td style="padding:.6rem 1rem;">
                        <div style="font-weight:600;color:#e0e0e0;"><?php echo htmlspecialchars($log['username'] ?? '-'); ?></div>
                        <div style="font-size:.75rem;color:#666;"><?php echo htmlspecialchars($log['nama_lengkap'] ?? ''); ?></div>
                    </td>
                    <td style="padding:.6rem 1rem;">
                        <span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;background:<?php echo $style['bg']; ?>;color:<?php echo $style['color']; ?>;border-radius:20px;font-size:.75rem;font-weight:600;white-space:nowrap;">
                            <i class="fas <?php echo $style['icon']; ?>"></i>
                            <?php echo htmlspecialchars($log['action'] ?? ''); ?>
                        </span>
                    </td>
                    <td style="padding:.6rem 1rem;color:#888;">
                        <?php if ($log['target_table']): ?>
                            <code style="background:#111;padding:2px 6px;border-radius:4px;font-size:.75rem;color:#8BB9F0;">
                                <?php echo htmlspecialchars($log['target_table']); ?>
                            </code>
                            <?php if ($log['target_id']): ?>
                                <span style="color:#555;font-size:.75rem;"> #<?php echo (int)$log['target_id']; ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#444;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:.6rem 1rem;color:#aaa;max-width:280px;">
                        <?php echo htmlspecialchars($log['deskripsi'] ?? '-'); ?>
                    </td>
                    <td style="padding:.6rem 1rem;color:#555;font-family:monospace;font-size:.75rem;white-space:nowrap;">
                        <?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPage > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.75rem 1rem;border-top:1px solid #222;font-size:.82rem;color:#666;flex-wrap:wrap;gap:.5rem;">
        <span>Menampilkan <?php echo count($logs); ?> dari <?php echo $total; ?> log</span>
        <div style="display:flex;gap:.35rem;">
            <?php
            $baseUrl = 'audit-log.php?' . http_build_query(array_filter([
                'action' => $filterAction,
                'user'   => $filterUser,
                'date'   => $filterDate,
            ]));
            for ($i = 1; $i <= min($totalPage, 10); $i++):
                $active = $i === $page;
            ?>
            <a href="<?php echo htmlspecialchars($baseUrl . '&p=' . $i); ?>"
               style="padding:4px 10px;border-radius:5px;text-decoration:none;<?php echo $active ? 'background:#4A90E2;color:white;' : 'background:#222;color:#888;'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            <?php if ($totalPage > 10): ?>
                <span style="color:#555;padding:4px;">... <?php echo $totalPage; ?></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div style="margin-top:.75rem;font-size:.75rem;color:#444;text-align:center;">
    <i class="fas fa-lock"></i> Log tidak dapat dihapus secara manual. Data otomatis dihapus setelah 30 hari.
</div>

<?php require_once __DIR__ . '/footer.php'; ?>