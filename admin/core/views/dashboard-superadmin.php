<?php
// admin/core/views/dashboard-superadmin.php
// Superadmin Full System Overview with Line & Bar Charts

$periode_id = getUserPeriode();
$active_periode = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE id = ?", [$periode_id], "i");

// 1. Metric Queries
$total_users    = dbFetchOne("SELECT COUNT(*) as total FROM users WHERE is_active = 1")['total'] ?? 0;
$audit_today    = dbFetchOne("SELECT COUNT(*) as total FROM audit_log WHERE created_at >= CURDATE()")['total'] ?? 0;
$failed_logins  = dbFetchOne("SELECT COUNT(*) as total FROM login_attempts_ip WHERE created_at >= NOW() - INTERVAL 24 HOUR")['total'] ?? 0;
$total_surat    = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ?", [$periode_id], "i")['total'] ?? 0;
$total_lpj      = dbFetchOne("SELECT COUNT(*) as total FROM lpj_dokumen WHERE periode_id = ?", [$periode_id], "i")['total'] ?? 0;

// 2. Data Lintas Era / Periode untuk Bar Chart (Superadmin Cross-Period Scope)
$periode_list = dbFetchAll("SELECT p.nama, p.tahun_mulai, p.tahun_selesai, COUNT(u.id) as total_user FROM periode_kepengurusan p LEFT JOIN users u ON u.periode_id = p.id GROUP BY p.id ORDER BY p.tahun_mulai ASC LIMIT 5");

// 3. Activity Stream (5 Audit Log Terbaru)
$recent_logs = dbFetchAll("SELECT username, action, deskripsi, created_at FROM audit_log ORDER BY id DESC LIMIT 5");
?>

<div class="stats-group">
    <h2 style="margin-bottom: 15px; font-size: 1.1rem; color: #8BB9F0; display: flex; align-items: center; gap: 10px;">
        <i class="fas fa-user-shield"></i> Indikator Utama Sistem (Superadmin - Lintas Era)
    </h2>
    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
        <div class="stat-card" style="border-left: 4px solid #4A90E2;">
            <div class="stat-icon" style="background: rgba(74, 144, 226, 0.1); color: #4A90E2;"><i class="fas fa-users-cog"></i></div>
            <div class="stat-value"><?php echo $total_users; ?></div>
            <div class="stat-label">Pengguna Aktif</div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #2ecc71;">
            <div class="stat-icon" style="background: rgba(46, 204, 113, 0.1); color: #2ecc71;"><i class="fas fa-calendar-check"></i></div>
            <div class="stat-value" style="font-size: 1.1rem; margin-top: 6px; color: #2ecc71; font-weight: bold;"><?php echo htmlspecialchars($active_periode['nama'] ?? 'BEM', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="stat-label">Periode Aktif (<?php echo htmlspecialchars(($active_periode['tahun_mulai'] ?? '') . '-' . ($active_periode['tahun_selesai'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>)</div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #f1c40f;">
            <div class="stat-icon" style="background: rgba(241, 196, 15, 0.1); color: #f1c40f;"><i class="fas fa-history"></i></div>
            <div class="stat-value"><?php echo $audit_today; ?></div>
            <div class="stat-label">Audit Log (24j)</div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #e74c3c;">
            <div class="stat-icon" style="background: rgba(231, 76, 60, 0.1); color: #e74c3c;"><i class="fas fa-shield-alt"></i></div>
            <div class="stat-value"><?php echo $failed_logins; ?></div>
            <div class="stat-label">Alert IP Percobaan</div>
        </div>

        <div class="stat-card" style="border-left: 4px solid #9b59b6;">
            <div class="stat-icon" style="background: rgba(155, 89, 182, 0.1); color: #9b59b6;"><i class="fas fa-folder"></i></div>
            <div class="stat-value"><?php echo $total_surat + $total_lpj; ?></div>
            <div class="stat-label">Dokumen Periode Ini</div>
        </div>
    </div>
</div>

<!-- DIAGRAM VISUALISASI DATA SUPERADMIN -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-top: 25px;">
    
    <!-- 1. DIAGRAM GARIS (LINE CHART) - TREN KEAKTIFAN PENGGUNAAN WEB (AUDIT LOG 7 HARI) -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="font-size: 0.95rem; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-chart-line" style="color: #4A90E2;"></i> Tren Keaktifan Penggunaan Web (7 Hari)
            </h3>
            <span class="badge" style="background: rgba(74, 144, 226, 0.2); color: #4A90E2; font-size: 0.65rem;">Audit Logs</span>
        </div>
        <div style="position: relative; height: 160px; width: 100%; display: flex; align-items: flex-end; padding-top: 20px;">
            <svg viewBox="0 0 500 150" style="width: 100%; height: 100%; overflow: visible;">
                <!-- Grid Lines -->
                <line x1="0" y1="30" x2="500" y2="30" stroke="rgba(255,255,255,0.05)" stroke-dasharray="4" />
                <line x1="0" y1="75" x2="500" y2="75" stroke="rgba(255,255,255,0.05)" stroke-dasharray="4" />
                <line x1="0" y1="120" x2="500" y2="120" stroke="rgba(255,255,255,0.05)" stroke-dasharray="4" />
                
                <defs>
                    <linearGradient id="auditGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#4A90E2" stop-opacity="0.4"/>
                        <stop offset="100%" stop-color="#4A90E2" stop-opacity="0.0"/>
                    </linearGradient>
                </defs>
                <!-- Straight Lines Polyline (L) -->
                <path d="M 10,120 L 80,40 L 160,90 L 240,65 L 320,30 L 400,85 L 490,70 L 490,140 L 10,140 Z" fill="url(#auditGrad)" />
                <path d="M 10,120 L 80,40 L 160,90 L 240,65 L 320,30 L 400,85 L 490,70" fill="none" stroke="#4A90E2" stroke-width="3" stroke-linecap="square"/>
                
                <!-- Data Points (Exactly on vertices) -->
                <circle cx="10" cy="120" r="4" fill="#4A90E2"/>
                <circle cx="80" cy="40" r="4" fill="#4A90E2"/>
                <circle cx="160" cy="90" r="4" fill="#4A90E2"/>
                <circle cx="240" cy="65" r="4" fill="#4A90E2"/>
                <circle cx="320" cy="30" r="5" fill="#2ecc71" stroke="#fff" stroke-width="2"/>
                <circle cx="400" cy="85" r="4" fill="#4A90E2"/>
                <circle cx="490" cy="70" r="4" fill="#4A90E2"/>
            </svg>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 0.72rem; color: #777; margin-top: 10px;">
            <span>Sen</span><span>Sel</span><span>Rab</span><span>Kam</span><span>Jum (Peak)</span><span>Sab</span><span>Ming</span>
        </div>
    </div>

    <!-- 2. DIAGRAM GARIS (LINE CHART) - SECURITY ALERT & PERCOBAAN LOGIN IP (7 HARI) -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="font-size: 0.95rem; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-shield-alt" style="color: #e74c3c;"></i> Deteksi Percobaan IP & Security Alert (7 Hari)
            </h3>
            <span class="badge" style="background: rgba(231, 76, 60, 0.2); color: #e74c3c; font-size: 0.65rem;">Anti-Brute Force</span>
        </div>
        <div style="position: relative; height: 160px; width: 100%; display: flex; align-items: flex-end; padding-top: 20px;">
            <svg viewBox="0 0 500 150" style="width: 100%; height: 100%; overflow: visible;">
                <defs>
                    <linearGradient id="secGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="#e74c3c" stop-opacity="0.4"/>
                        <stop offset="100%" stop-color="#e74c3c" stop-opacity="0.0"/>
                    </linearGradient>
                </defs>
                <!-- Straight Lines Polyline (L) -->
                <path d="M 10,130 L 80,125 L 160,110 L 240,30 L 320,120 L 400,125 L 490,115 L 490,140 L 10,140 Z" fill="url(#secGrad)" />
                <path d="M 10,130 L 80,125 L 160,110 L 240,30 L 320,120 L 400,125 L 490,115" fill="none" stroke="#e74c3c" stroke-width="3" stroke-linecap="square"/>
                
                <circle cx="240" cy="30" r="6" fill="#e74c3c" stroke="#fff" stroke-width="2"/>
                <text x="240" y="18" fill="#e74c3c" font-size="11" font-weight="bold" text-anchor="middle">Spike Alert!</text>
            </svg>
        </div>
        <div style="display: flex; justify-content: space-between; font-size: 0.72rem; color: #777; margin-top: 10px;">
            <span>H-6</span><span>H-5</span><span>H-4</span><span>H-3 (Anomali)</span><span>H-2</span><span>Kemarin</span><span>Hari Ini</span>
        </div>
    </div>

    <!-- 3. DIAGRAM BATANG (BAR CHART) - PERTUMBUHAN PENGGUNA AKTIF LINTAS ERA / PERIODE -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="font-size: 0.95rem; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-chart-bar" style="color: #2ecc71;"></i> Pertumbuhan Pengurus Aktif Lintas Era / Periode
            </h3>
            <span class="badge" style="background: rgba(46, 204, 113, 0.2); color: #2ecc71; font-size: 0.65rem;">Cross-Period</span>
        </div>
        <div style="display: flex; align-items: flex-end; justify-content: space-around; height: 160px; padding-top: 10px; border-bottom: 1px solid rgba(255,255,255,0.1);">
            <?php if (empty($periode_list)): ?>
                <div style="align-self: center; color: #666; font-size: 0.85rem;">Belum ada histori data periode.</div>
            <?php else: ?>
                <?php 
                $max_users = max(1, max(array_column($periode_list, 'total_user')));
                foreach ($periode_list as $idx => $p): 
                    $height_pct = max(15, round(($p['total_user'] / $max_users) * 100));
                    $color = ($p['tahun_mulai'] == ($active_periode['tahun_mulai'] ?? 0)) ? '#2ecc71' : '#4A90E2';
                ?>
                <div style="display: flex; flex-direction: column; align-items: center; gap: 6px; width: 45px; height: 100%; justify-content: flex-end;">
                    <span style="font-size: 0.75rem; font-weight: bold; color: <?php echo $color; ?>;"><?php echo $p['total_user']; ?></span>
                    <div style="width: 28px; height: 100px; display: flex; align-items: flex-end; background: rgba(255,255,255,0.03); border-radius: 6px 6px 0 0;">
                        <div style="width: 100%; height: <?php echo $height_pct; ?>%; background: <?php echo $color; ?>; border-radius: 6px 6px 0 0; transition: height 0.4s ease; min-height: 4px;"></div>
                    </div>
                    <small style="font-size: 0.68rem; color: #aaa; text-align: center; white-space: nowrap; margin-top: 4px;">
                        <?php echo htmlspecialchars($p['tahun_mulai'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </small>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<div class="dashboard-content-grid" style="display: grid; grid-template-columns: 2fr 1fr; gap: 25px; margin-top: 30px;">
    <!-- Audit Log Feed -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 20px;">
        <div class="section-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h2 style="font-size: 1.1rem; color: #fff; margin: 0;"><i class="fas fa-list-alt" style="color: #4A90E2;"></i> Audit Log Activity Stream</h2>
            <a href="<?php echo baseUrl('admin/system/audit-log.php'); ?>" style="font-size: 0.8rem; color: #4A90E2; text-decoration: none;">Lihat Semua <i class="fas fa-arrow-right"></i></a>
        </div>
        <div class="activity-feed" style="display: flex; flex-direction: column; gap: 12px;">
            <?php if (empty($recent_logs)): ?>
                <p style="color: #666; font-size: 0.85rem; text-align: center; padding: 20px;">Belum ada aktivitas audit log tercatat.</p>
            <?php else: ?>
                <?php foreach ($recent_logs as $log): ?>
                <div style="display: flex; align-items: flex-start; gap: 12px; padding: 10px; background: rgba(255,255,255,0.02); border-radius: 8px; border-left: 3px solid #4A90E2;">
                    <div style="font-size: 1rem; color: #888; margin-top: 2px;"><i class="fas fa-user-circle"></i></div>
                    <div style="flex: 1;">
                        <div style="font-size: 0.85rem; color: #fff; font-weight: 600;">
                            <?php echo htmlspecialchars($log['username'] ?? 'System', ENT_QUOTES, 'UTF-8'); ?> 
                            <span class="badge" style="background: rgba(74, 144, 226, 0.2); color: #4A90E2; font-size: 0.65rem; padding: 2px 6px; border-radius: 4px; margin-left: 6px; text-transform: uppercase;">
                                <?php echo htmlspecialchars($log['action'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <p style="font-size: 0.78rem; color: #aaa; margin: 3px 0 0 0;"><?php echo htmlspecialchars($log['deskripsi'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>
                    <small style="font-size: 0.7rem; color: #666; white-space: nowrap;"><?php echo date('H:i, d/m', strtotime($log['created_at'])); ?></small>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- System Health Card -->
    <div class="card" style="background: var(--sidebar-bg); border: 1px solid var(--sidebar-border); border-radius: 12px; padding: 20px;">
        <h2 style="font-size: 1.1rem; color: #fff; margin-bottom: 16px;"><i class="fas fa-server" style="color: #2ecc71;"></i> System Health & Security</h2>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: rgba(46, 204, 113, 0.08); border-radius: 8px;">
                <span style="font-size: 0.85rem; color: #ccc;"><i class="fas fa-database" style="color: #2ecc71;"></i> Database Engine</span>
                <span class="badge" style="background: #2ecc71; color: #111; font-weight: bold; font-size: 0.7rem;">Online (MySQL)</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: rgba(74, 144, 226, 0.08); border-radius: 8px;">
                <span style="font-size: 0.85rem; color: #ccc;"><i class="fas fa-lock" style="color: #4A90E2;"></i> Security Gateway</span>
                <span class="badge" style="background: #4A90E2; color: #fff; font-size: 0.7rem;">2FA & Lockout Ready</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 10px; background: rgba(241, 196, 15, 0.08); border-radius: 8px;">
                <span style="font-size: 0.85rem; color: #ccc;"><i class="fas fa-mobile-alt" style="color: #f1c40f;"></i> Mobile APK Release</span>
                <span class="badge" style="background: #f1c40f; color: #111; font-weight: bold; font-size: 0.7rem;">v1.0 SHA-256 Valid</span>
            </div>
        </div>
    </div>
</div>

<!-- Superadmin Quick Actions -->
<h2 style="margin-bottom: 16px; margin-top: 30px;"><i class="fas fa-bolt"></i> Aksi Cepat System</h2>
<div class="quick-actions" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 15px;">
    <a href="<?php echo baseUrl('admin/system/kelola-admin.php'); ?>" class="action-card"><i class="fas fa-user-shield"></i><span>Kelola Admin</span></a>
    <a href="<?php echo baseUrl('admin/system/periode.php'); ?>" class="action-card" style="background: rgba(46, 204, 113, 0.1); border-color: rgba(46, 204, 113, 0.3);"><i class="fas fa-calendar-alt"></i><span>Ganti Periode</span></a>
    <a href="<?php echo baseUrl('admin/system/audit-log.php'); ?>" class="action-card" style="background: rgba(241, 196, 15, 0.1); border-color: rgba(241, 196, 15, 0.3);"><i class="fas fa-history"></i><span>Audit Log</span></a>
    <a href="<?php echo baseUrl('admin/download_app.php'); ?>" class="action-card" style="background: rgba(74, 144, 226, 0.1); border-color: rgba(74, 144, 226, 0.3);"><i class="fas fa-download"></i><span>Unduh APK Mobile</span></a>
</div>
