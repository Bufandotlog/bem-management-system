<?php
// admin/core/views/dashboard-panitia.php
// Integrated Event Command Center & Role-Specific Division Health Monitor (Theme: Muted Black, White & Soft Blue)

if (!isset($active_panitia) || !$active_panitia) {
    return;
}

$kegiatan_id   = $active_panitia['kegiatan_id'];
$nama_kegiatan = $active_panitia['nama_kegiatan'];
$event_role    = $active_panitia['event_role'];
$tanggal_mulai = $active_panitia['tanggal_mulai'];

// 1. Kalkulasi Progress Divisi Acara (%)
$rundown = dbFetchOne("SELECT id, rundown_json FROM arsip_rundown WHERE periode_id = ? LIMIT 1", [$periode_id], "i");
$rundown_score = $rundown ? 50 : 0;
$mc_score = 50; 
$progress_acara = min(100, $rundown_score + $mc_score);

// 2. Kalkulasi Progress Divisi Logistik (%)
$total_barang  = dbFetchOne("SELECT COUNT(*) as total FROM barang_master")['total'] ?? 0;
$total_tempat  = dbFetchOne("SELECT COUNT(*) as total FROM tempat_master")['total'] ?? 0;
$barang_score  = $total_barang > 0 ? 70 : 35;
$tempat_score  = $total_tempat > 0 ? 30 : 15;
$progress_logistik = min(100, $barang_score + $tempat_score);

// Fetch data spesifik logistik untuk event aktif (barang & tempat dari JSON)
$lampiran_event = dbFetchOne("SELECT id, barang_json, readiness_json FROM lampiran_pinjam WHERE LOWER(TRIM(nama_acara)) = LOWER(TRIM(?)) AND periode_id = ? ORDER BY id DESC LIMIT 1", [$nama_kegiatan, $periode_id]);
$items_pinjam_cnt = 0;
$tempat_list = [];
$logistik_items_list = [];
$readiness_map = [];
$lampiran_id = 0;

if ($lampiran_event) {
    $lampiran_id = $lampiran_event['id'];
    if (!empty($lampiran_event['readiness_json'])) {
        $readiness_map = json_decode($lampiran_event['readiness_json'], true) ?: [];
    }
    if (!empty($lampiran_event['barang_json'])) {
        $arr_b = json_decode($lampiran_event['barang_json'], true);
        if (is_array($arr_b)) {
            foreach ($arr_b as $item) {
                if (empty($item['nama'])) continue;
                $iid = $item['id'] ?? '';
                $is_ready = !empty($readiness_map[$iid]) ? 1 : 0;
                $is_tempat = (strpos($iid, 't_') === 0);
                
                if ($is_tempat) {
                    $tempat_list[] = $item['nama'];
                } else {
                    $items_pinjam_cnt += (int)($item['qty'] ?? 1);
                }

                $logistik_items_list[] = [
                    'id' => $iid,
                    'nama' => $item['nama'],
                    'qty' => $item['qty'] ?? 1,
                    'is_tempat' => $is_tempat,
                    'is_ready' => $is_ready
                ];
            }
        }
    }
}
$nama_tempat_event = !empty($tempat_list) ? implode(', ', $tempat_list) : 'Belum Diatur';

// Kalkulasi Persentase Kesiapan Logistik Nyata (Berdasarkan Check Kesiapan Hari H)
$total_logistik_items = count($logistik_items_list);
$ready_logistik_items = 0;
foreach ($logistik_items_list as $l_item) {
    if ($l_item['is_ready']) {
        $ready_logistik_items++;
    }
}

if ($total_logistik_items > 0) {
    $progress_logistik = round(($ready_logistik_items / $total_logistik_items) * 100);
} else {
    $progress_logistik = 0;
}

// 3. Kalkulasi Progress Divisi Humas (%)
$total_surat_event = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND jenis_surat = 'L'", [$periode_id], "i")['total'] ?? 0;
$pending_staging   = dbFetchOne("SELECT COUNT(*) as total FROM arsip_surat WHERE periode_id = ? AND status_arsip = 'staging'", [$periode_id], "i")['total'] ?? 0;
$progress_humas    = $total_surat_event > 0 ? min(100, $total_surat_event * 25) : 40;

// 4. Combined Total Event Progress (%)
$progress_total = round(($progress_acara + $progress_logistik + $progress_humas) / 3);

// Hitung Countdown
$days_left = null;
if ($tanggal_mulai) {
    $diff = strtotime($tanggal_mulai) - strtotime(date('Y-m-d'));
    $days_left = max(0, round($diff / (60 * 60 * 24)));
}

$role_labels = [
    'ketuplat'           => 'Ketua Pelaksana',
    'sekretaris_panitia' => 'Sekretaris Panitia',
    'sie_acara'          => 'Sie Acara',
    'sie_logistik'       => 'Sie Logistik',
    'sie_humas'          => 'Sie Humas',
    'sie_konsumsi'       => 'Sie Konsumsi',
    'anggota_panitia'    => 'Panitia Event'
];
$role_title = $role_labels[$event_role] ?? 'Panitia Event';
?>

<!-- EVENT COMMAND CENTER BANNER (BLACK, WHITE & SOFT MUTED BLUE) -->
<div class="event-command-center" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.4) 0%, rgba(15, 18, 23, 0.95) 100%); border: 1px solid rgba(74, 144, 226, 0.18); border-radius: 16px; padding: 20px; margin-bottom: 25px; box-shadow: 0 8px 24px rgba(0,0,0,0.3);">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px;">
        <div style="flex: 1; min-width: 250px;">
            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap;">
                <span class="badge" style="background: rgba(74, 144, 226, 0.12); color: #70a1ff; border: 1px solid rgba(74, 144, 226, 0.25); font-weight: 700; padding: 4px 10px; border-radius: 20px; font-size: 0.68rem; text-transform: uppercase;">
                    <i class="fas fa-play-circle"></i> Event Aktif
                </span>
                <span class="badge" style="background: rgba(255, 255, 255, 0.05); color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.1); padding: 4px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 600;">
                    Role: <?php echo htmlspecialchars($role_title, ENT_QUOTES, 'UTF-8'); ?>
                </span>
            </div>
            <h2 style="font-size: 1.35rem; color: #ffffff; margin: 0 0 6px 0; display: flex; align-items: center; gap: 8px; line-height: 1.3; font-weight: 700;">
                <i class="fas fa-bullhorn" style="color: #70a1ff;"></i> <?php echo htmlspecialchars($nama_kegiatan, ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            <p style="color: #888888; font-size: 0.82rem; margin: 0; display: flex; align-items: center; gap: 6px;">
                <i class="far fa-calendar-alt" style="color: #70a1ff;"></i> Pelaksanaan: <?php echo $tanggal_mulai ? date('d F Y', strtotime($tanggal_mulai)) : 'Dalam Persiapan'; ?>
            </p>
        </div>

        <?php if ($days_left !== null): ?>
        <div style="text-align: center; background: rgba(0, 0, 0, 0.35); border: 1px solid rgba(255, 255, 255, 0.08); padding: 10px 18px; border-radius: 12px; min-width: 120px;">
            <div style="font-size: 1.6rem; font-weight: 800; color: #70a1ff; line-height: 1;"><?php echo $days_left; ?></div>
            <div style="font-size: 0.68rem; color: #888888; text-transform: uppercase; margin-top: 4px; letter-spacing: 0.5px;">Hari H Event</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- IF ROLE IS SIE HUMAS: DEDICATED HUMAS EVENT METRIC CARDS -->
    <?php if ($event_role === 'sie_humas'): ?>
    <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(255, 255, 255, 0.06);">
        <!-- HUMAS METRIC CARDS GRID -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px;">
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 10px; border-radius: 10px;">
                <div style="font-size: 0.7rem; color: #888888; margin-bottom: 2px;"><i class="fas fa-paper-plane" style="color: #70a1ff;"></i> Surat Event</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #ffffff;"><?php echo $total_surat_event; ?> <span style="font-size: 0.7rem; font-weight: normal; color: #70a1ff;">Terkirim</span></div>
            </div>
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 10px; border-radius: 10px;">
                <div style="font-size: 0.7rem; color: #888888; margin-bottom: 2px;"><i class="fas fa-clock" style="color: #888888;"></i> Staging Pending</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #ffffff;"><?php echo $pending_staging; ?> <span style="font-size: 0.7rem; font-weight: normal; color: #888888;">Surat</span></div>
            </div>
            <div style="background: rgba(74, 144, 226, 0.08); border: 1px solid rgba(74, 144, 226, 0.2); padding: 10px; border-radius: 10px;">
                <div style="font-size: 0.7rem; color: #888888; margin-bottom: 2px;"><i class="fas fa-chart-line" style="color: #70a1ff;"></i> Kesiapan Humas</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #70a1ff;"><?php echo $progress_humas; ?>%</div>
            </div>
        </div>
    </div>

    <!-- ELSE IF ROLE IS SIE LOGISTIK: DEDICATED LOGISTIK EVENT METRIC CARDS & CHECKLIST FORM -->
    <?php elseif ($event_role === 'sie_logistik'): ?>
    <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid rgba(255, 255, 255, 0.06);">
        <!-- LOGISTIK METRIC CARDS GRID -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 10px;">
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 10px; border-radius: 10px;">
                <div style="font-size: 0.7rem; color: #888888; margin-bottom: 2px;"><i class="fas fa-boxes" style="color: #70a1ff;"></i> Barang Dipinjam</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #ffffff;"><?php echo $items_pinjam_cnt; ?> <span style="font-size: 0.7rem; font-weight: normal; color: #70a1ff;">Item</span></div>
            </div>
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 10px; border-radius: 10px;">
                <div style="font-size: 0.7rem; color: #888888; margin-bottom: 2px;"><i class="fas fa-map-marker-alt" style="color: #888888;"></i> Lokasi Acara</div>
                <div style="font-size: 0.95rem; font-weight: 700; color: #ffffff; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;"><?php echo htmlspecialchars($nama_tempat_event, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 10px; border-radius: 10px;">
                <div style="font-size: 0.7rem; color: #888888; margin-bottom: 2px;"><i class="fas fa-clipboard-check" style="color: #888888;"></i> Item Siap (Hari H)</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #ffffff;"><span id="logistik-ready-count"><?php echo $ready_logistik_items; ?></span> / <?php echo $total_logistik_items; ?> <span style="font-size: 0.7rem; font-weight: normal; color: #888888;">Ready</span></div>
            </div>
            <div style="background: rgba(74, 144, 226, 0.08); border: 1px solid rgba(74, 144, 226, 0.2); padding: 10px; border-radius: 10px;">
                <div style="font-size: 0.7rem; color: #888888; margin-bottom: 2px;"><i class="fas fa-chart-line" style="color: #70a1ff;"></i> Kesiapan Logistik</div>
                <div style="font-size: 1.2rem; font-weight: 800; color: #70a1ff;"><span id="logistik-progress-pct"><?php echo $progress_logistik; ?>%</span></div>
            </div>
        </div>

        <!-- FORM CHECKLIST KESIAPAN BARANG & TEMPAT LOGISTIK HARI H -->
        <div style="margin-top: 18px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.08);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                <h4 style="margin: 0; font-size: 0.9rem; color: #ffffff; display: flex; align-items: center; gap: 8px;">
                    <i class="fas fa-tasks" style="color: #70a1ff;"></i> Form Check Kesiapan Barang & Tempat (Hari H Event)
                </h4>
                <span style="font-size: 0.72rem; color: #888888;">
                    Default: <strong>BELUM SIAP</strong>. Aktifkan toggle saat barang/tempat siap untuk menaikkan % Kesiapan.
                </span>
            </div>

            <?php if ($total_logistik_items === 0): ?>
                <div style="background: rgba(255, 193, 7, 0.08); border: 1px dashed rgba(255, 193, 7, 0.3); padding: 14px 16px; border-radius: 12px; color: #ffc107; font-size: 0.82rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <span><i class="fas fa-exclamation-triangle"></i> Belum ada list persiapan barang/tempat untuk kegiatan ini. Silakan catat di Workspace Logistik.</span>
                    <a href="<?php echo baseUrl('admin/kegiatan/workspace-logistik.php?kegiatan_id=' . $kegiatan_id); ?>" style="background: rgba(255, 193, 7, 0.2); color: #fff; text-decoration: none; padding: 6px 14px; border-radius: 8px; font-weight: 700; font-size: 0.78rem;">
                        <i class="fas fa-plus-circle"></i> Catat di Workspace Logistik
                    </a>
                </div>
            <?php else: ?>
                <!-- List Items (Expanded full list without fixed card height constraint for natural scrolling) -->
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <?php foreach ($logistik_items_list as $l_item): ?>
                        <div class="switch-container" style="margin: 0; padding: 12px 14px; background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px;" onclick="toggleLogistikItem(this, <?php echo $lampiran_id; ?>, '<?php echo $l_item['id']; ?>')">
                            <div style="flex: 1; min-width: 0; display: flex; align-items: flex-start; gap: 10px;">
                                <i class="<?php echo $l_item['is_tempat'] ? 'fas fa-building' : 'fas fa-box'; ?>" style="color: #70a1ff; font-size: 0.95rem; margin-top: 3px; flex-shrink: 0;"></i>
                                <div style="min-width: 0;">
                                    <div style="font-size: 0.85rem; font-weight: 600; color: #eee; line-height: 1.35; word-break: break-word;">
                                        <?php echo htmlspecialchars($l_item['nama']); ?>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 6px; margin-top: 4px; flex-wrap: wrap;">
                                        <span style="font-size: 0.65rem; color: #888; background: rgba(255,255,255,0.06); padding: 1px 6px; border-radius: 4px; border: 1px solid rgba(255,255,255,0.05);"><?php echo $l_item['is_tempat'] ? 'Tempat' : 'Barang'; ?></span>
                                        <?php if (!$l_item['is_tempat'] && $l_item['qty'] > 1): ?>
                                            <span style="font-size: 0.65rem; color: #70a1ff; background: rgba(74, 144, 226, 0.12); padding: 1px 6px; border-radius: 4px; font-weight: 700; border: 1px solid rgba(74, 144, 226, 0.2);"><?php echo $l_item['qty']; ?> Qty</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div style="flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; justify-content: center; gap: 3px;">
                                <span class="status-text" style="font-size: 0.65rem; font-weight: 800; letter-spacing: 0.4px; color: <?php echo $l_item['is_ready'] ? '#2ecc71' : '#e74c3c'; ?>; text-transform: uppercase;">
                                    <?php echo $l_item['is_ready'] ? 'SIAP' : 'BELUM SIAP'; ?>
                                </span>
                                <label class="switch" style="margin:0;" onclick="event.stopPropagation();">
                                    <input type="checkbox" <?php echo $l_item['is_ready'] ? 'checked' : ''; ?> onchange="onLogistikSwitchChange(this, <?php echo $lampiran_id; ?>, '<?php echo $l_item['id']; ?>')">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ELSE IF ROLE IS KETUPLAT OR OTHER PANITIA: FULL DIVISION HEALTH MONITOR -->
    <?php else: ?>
    <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid rgba(255, 255, 255, 0.06);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h4 style="margin: 0; font-size: 0.88rem; color: #ffffff; display: flex; align-items: center; gap: 6px;">
                <i class="fas fa-tasks" style="color: #70a1ff;"></i> Progress Kesiapan Event Total
            </h4>
            <span style="font-size: 1rem; font-weight: 700; color: #70a1ff;"><?php echo $progress_total; ?>% Ready</span>
        </div>
        <div style="width: 100%; height: 8px; background: rgba(255, 255, 255, 0.05); border-radius: 20px; overflow: hidden; margin-bottom: 16px;">
            <div style="width: <?php echo $progress_total; ?>%; height: 100%; background: linear-gradient(90deg, #4A90E2 0%, #70a1ff 100%); border-radius: 20px; transition: width 0.5s ease;"></div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px;">
            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 12px; border-radius: 10px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 4px;">
                    <span style="color: #cccccc; font-weight: 600;"><i class="fas fa-calendar-check" style="color: #70a1ff;"></i> Divisi Acara</span>
                    <span style="color: #70a1ff; font-weight: 700;"><?php echo $progress_acara; ?>%</span>
                </div>
                <div style="width: 100%; height: 5px; background: rgba(255, 255, 255, 0.05); border-radius: 10px; overflow: hidden;">
                    <div style="width: <?php echo $progress_acara; ?>%; height: 100%; background: #70a1ff; border-radius: 10px;"></div>
                </div>
                <small style="font-size: 0.68rem; color: #777777; display: block; margin-top: 4px;">Rundown: <?php echo $rundown ? 'Tersedia' : 'Draft'; ?></small>
            </div>

            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 12px; border-radius: 10px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 4px;">
                    <span style="color: #cccccc; font-weight: 600;"><i class="fas fa-boxes" style="color: #70a1ff;"></i> Divisi Logistik</span>
                    <span style="color: #ffffff; font-weight: 700;"><?php echo $progress_logistik; ?>%</span>
                </div>
                <div style="width: 100%; height: 5px; background: rgba(255, 255, 255, 0.05); border-radius: 10px; overflow: hidden;">
                    <div style="width: <?php echo $progress_logistik; ?>%; height: 100%; background: #4A90E2; border-radius: 10px;"></div>
                </div>
                <small style="font-size: 0.68rem; color: #777777; display: block; margin-top: 4px;">Master Inventaris: <?php echo $total_barang; ?> item</small>
            </div>

            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid rgba(255, 255, 255, 0.06); padding: 12px; border-radius: 10px;">
                <div style="display: flex; justify-content: space-between; font-size: 0.78rem; margin-bottom: 4px;">
                    <span style="color: #cccccc; font-weight: 600;"><i class="fas fa-paper-plane" style="color: #70a1ff;"></i> Divisi Humas</span>
                    <span style="color: #70a1ff; font-weight: 700;"><?php echo $progress_humas; ?>%</span>
                </div>
                <div style="width: 100%; height: 5px; background: rgba(255, 255, 255, 0.05); border-radius: 10px; overflow: hidden;">
                    <div style="width: <?php echo $progress_humas; ?>%; height: 100%; background: #70a1ff; border-radius: 10px;"></div>
                </div>
                <small style="font-size: 0.68rem; color: #777777; display: block; margin-top: 4px;">Surat Undangan Event: <?php echo $total_surat_event; ?></small>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function onLogistikSwitchChange(input, lampiranId, itemId) {
    const isChecked = input.checked ? 1 : 0;
    const parentContainer = input.closest('.switch-container');
    const statusText = parentContainer ? parentContainer.querySelector('.status-text') : null;
    
    if (statusText) {
        statusText.innerText = isChecked ? 'SIAP' : 'BELUM SIAP';
        statusText.style.color = isChecked ? '#2ecc71' : '#e74c3c';
    }

    const formData = new FormData();
    formData.append('lampiran_id', lampiranId);
    formData.append('item_id', itemId);
    formData.append('status', isChecked);

    fetch('<?php echo baseUrl("api/save-logistik-readiness.php"); ?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            const readyCounter = document.getElementById('logistik-ready-count');
            const progressPct = document.getElementById('logistik-progress-pct');
            if (readyCounter) readyCounter.innerText = data.ready_count;
            if (progressPct) progressPct.innerText = data.percentage + '%';
        }
    })
    .catch(err => console.error('Gagal update kesiapan logistik:', err));
}

function toggleLogistikItem(container, lampiranId, itemId) {
    if (event.target.tagName !== 'INPUT' && !event.target.classList.contains('slider')) {
        const checkbox = container.querySelector('input[type=checkbox]');
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
            onLogistikSwitchChange(checkbox, lampiranId, itemId);
        }
    }
}
</script>
