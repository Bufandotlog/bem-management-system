<?php
// admin/cetak-teks-mc-pdf.php
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config.php';

requireLogin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
    die("Data Teks MC tidak ditemukan.");
}

$susunan = json_decode($mc_data['susunan_mc'], true) ?: [];
$tipe_label = [
    'formal' => 'Formal (Protokoler)',
    'semi_formal' => 'Semi Formal',
    'non_formal' => 'Non-Formal (Casual)'
][$mc_data['tipe_acara']] ?? 'Formal';

$download_name = "TEKS MC - " . $mc_data['judul_naskah'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($download_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #525659; font-family: Arial, sans-serif; font-size: 13px; color: #000; line-height: 1.5; }
        
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 15mm 20mm;
            margin: 10mm auto;
            border: 1px solid #D3D3D3;
            border-radius: 5px;
            background: white;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .no-print {
            text-align: center;
            padding: 15px;
            background: #222;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .btn {
            background: #f5576c; color: #fff; border: none; padding: 10px 20px; font-size: 15px;
            border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; margin: 0 5px; font-weight: bold;
        }
        .btn-secondary { background: #555; }

        .mc-header-pdf {
            text-align: center;
            margin-bottom: 25px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }
        .mc-header-pdf h1 {
            font-size: 16pt;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .mc-header-pdf h2 {
            font-size: 12pt;
            font-weight: normal;
            color: #444;
        }

        .table-mc {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .table-mc tr {
            page-break-inside: avoid;
        }
        
        .table-mc th, .table-mc td {
            border: 1px solid #333;
            padding: 10px 12px;
            vertical-align: top;
        }

        .table-mc th {
            background-color: #f2f2f2;
            text-align: center;
            font-weight: bold;
            font-size: 11pt;
        }

        .cue-box-pdf {
            background: #fff8e1;
            border-left: 3px solid #ffb300;
            padding: 6px 10px;
            font-size: 9.5pt;
            margin-top: 8px;
            color: #795548;
        }

        .speaker-badge-pdf {
            display: inline-block;
            background: #000;
            color: #fff;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 8.5pt;
            margin-bottom: 6px;
        }

        @page {
            size: A4 portrait;
            margin: 0;
        }

        @media print {
            body { background: white; margin: 0; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .table-mc tr { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page { 
                margin: 0 !important; 
                padding: 15mm 20mm; 
                border: none !important; 
                border-radius: 0 !important; 
                width: 210mm; 
                min-height: 296mm;
                box-shadow: none !important; 
                background: white !important; 
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak / Simpan PDF</button>
        <a href="workspace-teks-mc.php?kegiatan_id=<?php echo $mc_data['kegiatan_id']; ?>&edit_id=<?php echo $mc_data['id']; ?>" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <div class="page">
        <div class="mc-header-pdf">
            <h1>NASKAH MASTER OF CEREMONY (MC)</h1>
            <h2><?php echo htmlspecialchars($mc_data['judul_naskah']); ?></h2>
            <div style="font-size: 10pt; color: #555; margin-top: 5px;">
                Kegiatan: <strong><?php echo htmlspecialchars($mc_data['nama_kegiatan']); ?></strong> | Format: <strong><?php echo $tipe_label; ?></strong>
            </div>
        </div>

        <?php if (!empty($mc_data['catatan_mc'])): ?>
            <div style="background: #f9f9f9; border: 1px solid #ddd; padding: 10px 14px; border-radius: 6px; margin-bottom: 20px; font-size: 9.5pt;">
                <strong><i class="fas fa-info-circle"></i> Catatan Protocoler MC:</strong><br>
                <?php echo nl2br(htmlspecialchars($mc_data['catatan_mc'])); ?>
            </div>
        <?php endif; ?>

        <table class="table-mc">
            <thead>
                <tr>
                    <th style="width: 6%;">NO</th>
                    <th style="width: 30%;">SEGMEN & PENGISI</th>
                    <th style="width: 64%;">NASKAH BICARA & TECHNICAL CUE</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($susunan as $idx => $item): 
                    $spk_raw = trim($item['mc_speaker'] ?? '');
                    $spk = strtolower($spk_raw);
                    
                    $row_bg = '';
                    $badge_bg = '#000000';

                    if (strpos($spk, 'mc 1') !== false && strpos($spk, 'mc 2') === false) {
                        $row_bg = 'background-color: #f0f7ff;'; // Warna biru pudar/lembut untuk MC 1
                        $badge_bg = '#1d4ed8';
                    } elseif (strpos($spk, 'mc 2') !== false && strpos($spk, 'mc 1') === false) {
                        $row_bg = 'background-color: #fff1f2;'; // Warna merah/pink pudar/lembut untuk MC 2
                        $badge_bg = '#be123c';
                    } elseif (strpos($spk, 'moderator') !== false) {
                        $row_bg = 'background-color: #f0fdf4;'; // Warna hijau pudar/lembut untuk Moderator
                        $badge_bg = '#15803d';
                    } else {
                        // MC 1 & MC 2 atau pengisi bersama
                        $row_bg = 'background-color: #ffffff;';
                        $badge_bg = '#000000';
                    }
                ?>
                    <tr style="<?php echo $row_bg; ?>">
                        <td style="text-align: center; font-weight: bold;"><?php echo $idx + 1; ?></td>
                        <td>
                            <div class="speaker-badge-pdf" style="background: <?php echo $badge_bg; ?>;">
                                <?php echo htmlspecialchars($spk_raw ?: 'MC'); ?>
                            </div>
                            <strong style="font-size: 10.5pt; display: block; margin-top: 4px;"><?php echo htmlspecialchars($item['segmen']); ?></strong>
                            <?php if (!empty($item['pengisi'])): ?>
                                <div style="font-size: 9pt; color: #555; margin-top: 3px;">
                                    Pengisi: <?php echo htmlspecialchars($item['pengisi']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-size: 10pt; line-height: 1.6;">
                                <?php echo nl2br(htmlspecialchars($item['script_teks'])); ?>
                            </div>

                            <?php if (!empty($item['stage_cue'])): ?>
                                <div class="cue-box-pdf">
                                    <strong>[Stage Cue]:</strong> <?php echo htmlspecialchars($item['stage_cue']); ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
