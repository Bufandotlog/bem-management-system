<?php
// admin/reader-teks-mc.php
require_once __DIR__ . '/../core/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    die("ID Teks MC tidak valid.");
}

$periode_id = getUserPeriode();
$mc_data = dbFetchOne(
    "SELECT mc.*, k.nama_kegiatan 
     FROM arsip_teks_mc mc 
     LEFT JOIN kegiatan k ON mc.kegiatan_id = k.id 
     WHERE mc.id = ? AND mc.periode_id = ?", 
    [$id, $periode_id], 
    "ii"
);

if (!$mc_data) {
    die("Naskah Teks MC tidak ditemukan.");
}

$susunan = json_decode($mc_data['susunan_mc'], true) ?: [];
$tipe_label = [
    'formal' => 'Formal (Protokoler)',
    'semi_formal' => 'Semi Formal',
    'non_formal' => 'Non-Formal (Casual)'
][$mc_data['tipe_acara']] ?? 'Formal';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live MC Reader - <?php echo htmlspecialchars($mc_data['judul_naskah']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-color: #0b0e14;
            --card-bg: #151921;
            --text-color: #e6edf3;
            --accent-color: #f5576c;
            --accent-green: #2ecc71;
            --cue-color: #f1c40f;
            --font-scale: 1.15;
        }

        body.light-mode {
            --bg-color: #f8f9fa;
            --card-bg: #ffffff;
            --text-color: #1a1a1a;
            --border-color: #e1e4e8;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            background: var(--bg-color);
            color: var(--text-color);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            font-size: calc(16px * var(--font-scale));
            overflow-x: hidden;
            transition: background 0.3s, color 0.3s;
        }

        /* Fixed Top Controls Bar */
        .reader-controls {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 65px;
            background: rgba(15, 19, 26, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            z-index: 1000;
        }

        .reader-title {
            font-weight: 700;
            font-size: 1.1rem;
            color: var(--accent-color);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 350px;
        }

        .btn-ctrl {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
            padding: 8px 14px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-ctrl:hover {
            background: rgba(245, 87, 108, 0.2);
            border-color: var(--accent-color);
        }

        .reader-container {
            max-width: 900px;
            margin: 90px auto 60px auto;
            padding: 0 20px;
        }

        .header-box {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
            border-left: 5px solid var(--accent-color);
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }

        .header-box h1 {
            margin: 0 0 10px 0;
            font-size: 1.6em;
        }

        .meta-badges {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .badge {
            background: rgba(255,255,255,0.06);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75em;
            font-weight: 600;
        }

        /* Script Segment Cards */
        .segment-card {
            background: var(--card-bg);
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 25px;
            border: 1px solid rgba(255,255,255,0.08);
            transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
        }

        .segment-card.active {
            border-color: var(--accent-color);
            box-shadow: 0 0 25px rgba(245, 87, 108, 0.3);
            transform: scale(1.02);
        }

        .segment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .segment-title {
            font-size: 1.25em;
            font-weight: 700;
            color: #fff;
        }

        .segment-time {
            font-size: 0.85em;
            color: var(--accent-color);
            font-weight: 600;
            background: rgba(245, 87, 108, 0.1);
            padding: 4px 12px;
            border-radius: 8px;
        }

        .speaker-tag {
            display: inline-block;
            background: var(--accent-color);
            color: #fff;
            padding: 4px 14px;
            border-radius: 8px;
            font-weight: 800;
            font-size: 0.8em;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
        }

        .script-body {
            font-size: 1.15em;
            line-height: 1.7;
            white-space: pre-wrap;
            margin-bottom: 20px;
        }

        .stage-cue-box {
            background: rgba(241, 196, 15, 0.1);
            border: 1px solid rgba(241, 196, 15, 0.3);
            color: var(--cue-color);
            padding: 12px 18px;
            border-radius: 12px;
            font-size: 0.85em;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        /* Auto-scroll floating indicator */
        .autoscroll-bar {
            position: fixed;
            bottom: 25px;
            right: 25px;
            background: rgba(15, 19, 26, 0.95);
            border: 1px solid var(--accent-color);
            padding: 10px 18px;
            border-radius: 30px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
            z-index: 999;
        }

        @media (max-width: 600px) {
            .reader-title { display: none; }
            .reader-container { padding: 0 12px; }
            .segment-card { padding: 20px; }
        }
    </style>
</head>
<body>

    <!-- Controls Header -->
    <div class="reader-controls">
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="<?php echo baseUrl('admin/kegiatan/workspace-teks-mc.php?kegiatan_id=<?php echo $mc_data['); ?>"kegiatan_id']; ?>" class="btn-ctrl">
                <i class="fas fa-arrow-left"></i> Edit
            </a>
            <span class="reader-title"><?php echo htmlspecialchars($mc_data['judul_naskah']); ?></span>
        </div>

        <div style="display: flex; gap: 8px; align-items: center;">
            <button class="btn-ctrl" onclick="changeFontSize(-0.1)" title="Kecilkan Font">A-</button>
            <button class="btn-ctrl" onclick="changeFontSize(0.1)" title="Besarkan Font">A+</button>
            <button class="btn-ctrl" onclick="toggleFullscreen()" title="Layar Penuh"><i class="fas fa-expand"></i></button>
            <button class="btn-ctrl" onclick="toggleAutoScroll()" id="btnAutoScroll"><i class="fas fa-play"></i> Auto Scroll</button>
        </div>
    </div>

    <div class="reader-container">
        <div class="header-box">
            <h1><?php echo htmlspecialchars($mc_data['judul_naskah']); ?></h1>
            <div style="color: #aaa; font-size: 0.9em;">
                <i class="fas fa-calendar-alt"></i> <?php echo htmlspecialchars($mc_data['nama_kegiatan']); ?>
            </div>
            
            <div class="meta-badges">
                <span class="badge" style="color: var(--accent-color); border: 1px solid rgba(245,87,108,0.3);"><?php echo $tipe_label; ?></span>
                <span class="badge" style="color: var(--accent-green); border: 1px solid rgba(46,204,113,0.3);"><?php echo count($susunan); ?> Segmen</span>
            </div>

            <?php if (!empty($mc_data['catatan_mc'])): ?>
                <div style="margin-top: 15px; background: rgba(255,255,255,0.03); padding: 12px 16px; border-radius: 10px; font-size: 0.85em; color: #ddd;">
                    <strong><i class="fas fa-exclamation-circle" style="color: var(--cue-color);"></i> Catatan MC:</strong><br>
                    <?php echo nl2br(htmlspecialchars($mc_data['catatan_mc'])); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Script Cards -->
        <?php foreach ($susunan as $idx => $item): ?>
            <div class="segment-card" id="segment-<?php echo $idx; ?>" onclick="setActiveSegment(<?php echo $idx; ?>)">
                <div class="segment-header">
                    <div>
                        <span style="color: #666; font-size: 0.8em; font-weight: 700; margin-right: 8px;">SEGMEN #<?php echo $idx + 1; ?></span>
                        <span class="segment-title"><?php echo htmlspecialchars($item['segmen']); ?></span>
                        <?php if (!empty($item['pengisi'])): ?>
                            <span style="font-size: 0.8em; color: #888; margin-left: 8px;">(<?php echo htmlspecialchars($item['pengisi']); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($item['waktu'])): ?>
                        <span class="segment-time"><i class="far fa-clock"></i> <?php echo htmlspecialchars($item['waktu']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="speaker-tag">
                    <i class="fas fa-microphone"></i> <?php echo htmlspecialchars($item['mc_speaker'] ?? 'MC'); ?>
                </div>

                <div class="script-body">
                    <?php echo nl2br(htmlspecialchars($item['script_teks'])); ?>
                </div>

                <?php if (!empty($item['stage_cue'])): ?>
                    <div class="stage-cue-box">
                        <i class="fas fa-lightbulb" style="margin-top: 3px;"></i>
                        <div>
                            <strong>Stage & Technical Cue:</strong><br>
                            <?php echo nl2br(htmlspecialchars($item['stage_cue'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- AutoScroll Control Bar -->
    <div class="autoscroll-bar" id="scrollBar" style="display: none;">
        <span style="font-size: 0.8em; font-weight: bold; color: var(--accent-color);">SPEED</span>
        <input type="range" id="scrollSpeed" min="1" max="10" value="3" style="accent-color: var(--accent-color);">
        <button class="btn-ctrl" onclick="toggleAutoScroll()" style="padding: 4px 10px;"><i class="fas fa-pause"></i> Stop</button>
    </div>

    <script>
        let currentScale = 1.15;
        let isAutoScrolling = false;
        let scrollInterval = null;

        function changeFontSize(delta) {
            currentScale = Math.max(0.8, Math.min(2.0, currentScale + delta));
            document.documentElement.style.setProperty('--font-scale', currentScale);
        }

        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {
                    alert(`Error attempting to enable fullscreen: ${err.message}`);
                });
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }

        function setActiveSegment(idx) {
            document.querySelectorAll('.segment-card').forEach(card => card.classList.remove('active'));
            const activeCard = document.getElementById('segment-' + idx);
            if (activeCard) {
                activeCard.classList.add('active');
            }
        }

        function toggleAutoScroll() {
            isAutoScrolling = !isAutoScrolling;
            const btn = document.getElementById('btnAutoScroll');
            const bar = document.getElementById('scrollBar');

            if (isAutoScrolling) {
                btn.innerHTML = '<i class="fas fa-pause"></i> Pause Scroll';
                btn.style.borderColor = '#2ecc71';
                bar.style.display = 'flex';

                scrollInterval = setInterval(() => {
                    const speed = parseInt(document.getElementById('scrollSpeed').value);
                    window.scrollBy(0, speed);

                    if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 10) {
                        toggleAutoScroll();
                    }
                }, 50);
            } else {
                btn.innerHTML = '<i class="fas fa-play"></i> Auto Scroll';
                btn.style.borderColor = 'rgba(255,255,255,0.15)';
                bar.style.display = 'none';
                clearInterval(scrollInterval);
            }
        }
    </script>
</body>
</html>
