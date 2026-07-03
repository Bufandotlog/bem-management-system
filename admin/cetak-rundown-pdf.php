<?php
// admin/cetak-rundown-pdf.php
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config.php';

requireLogin();
requireSekretaris();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Akses tidak valid.");
}

$nama_acara      = sanitizeText($_POST['nama_acara'] ?? '');
$tahun           = sanitizeText($_POST['tahun'] ?? '');
$tanggal_mulai   = sanitizeText($_POST['tanggal_mulai'] ?? date('Y-m-d'));
$durasi_hari     = (int)($_POST['durasi_hari'] ?? 1);

// Format Tanggal Utama
$start_ts = strtotime($tanggal_mulai);
$end_ts = strtotime($tanggal_mulai . " + " . ($durasi_hari - 1) . " days");

$bulan_id = [
    'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
    'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli',
    'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober',
    'November' => 'November', 'December' => 'Desember'
];

$hari_id = [
    'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
    'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jum\'at', 'Saturday' => 'Sabtu'
];

$start_d = date('d', $start_ts);
$start_m = $bulan_id[date('F', $start_ts)];
$start_y = date('Y', $start_ts);

if ($durasi_hari > 1) {
    $end_d = date('d', $end_ts);
    $end_m = $bulan_id[date('F', $end_ts)];
    $end_y = date('Y', $end_ts);
    if ($start_y !== $end_y) {
        $tanggal_utama = "$start_d $start_m $start_y - $end_d $end_m $end_y";
    } elseif ($start_m !== $end_m) {
        $tanggal_utama = "$start_d $start_m - $end_d $end_m $start_y";
    } else {
        $tanggal_utama = "$start_d - $end_d $start_m $start_y";
    }
} else {
    $tanggal_utama = "$start_d $start_m $start_y";
}

$waktu_arr = $_POST['waktu'] ?? [];
$acara_arr = $_POST['acara'] ?? [];
$ket_arr   = $_POST['keterangan'] ?? [];
$pj_arr    = $_POST['penanggung_jawab'] ?? [];
$is_parallel_arr = $_POST['is_parallel'] ?? [];
$tipe_ket_arr = $_POST['tipe_ket'] ?? [];

$rundown_days = [];

for ($dayId = 1; $dayId <= $durasi_hari; $dayId++) {
    $current_ts = strtotime($tanggal_mulai . " + " . ($dayId - 1) . " days");
    $tanggal = $hari_id[date('l', $current_ts)] . ', ' . date('d', $current_ts) . ' ' . $bulan_id[date('F', $current_ts)] . ' ' . date('Y', $current_ts);

    $day_items = [];
    $waktus = $waktu_arr[$dayId] ?? [];
    $acaras = $acara_arr[$dayId] ?? [];
    $kets = $ket_arr[$dayId] ?? [];
    $pjs = $pj_arr[$dayId] ?? [];
    
    $total_rows = count($waktus);
    for ($i = 0; $i < $total_rows; $i++) {
        if (!empty($waktus[$i]) && !empty($acaras[$i])) {
            $day_items[] = [
                'waktu' => sanitizeText($waktus[$i]),
                'acara' => sanitizeText($acaras[$i]),
                'keterangan' => sanitizeText($kets[$i] ?? ''),
                'pj' => sanitizeText($pjs[$i] ?? ''),
                'is_parallel' => ($is_parallel_arr[$dayId][$i] ?? 0) == 1,
            ];
        }
    }
    
    if (!empty($day_items)) {
        $rundown_days[] = [
            'judul_hari' => 'DAY ' . $dayId,
            'tanggal' => sanitizeText($tanggal),
            'tipe_ket' => sanitizeText($tipe_ket_arr[$dayId] ?? 'ket'),
            'items' => $day_items
        ];
    }
}

// Ensure proper day numbering 1, 2, 3 instead of actual ID array keys if any are deleted
$formatted_days = [];
$day_counter = 1;
$total_valid_days = count($rundown_days);
foreach ($rundown_days as $day) {
    if ($total_valid_days === 1) {
        $day['judul_hari'] = strtoupper($nama_acara);
    } else {
        $day['judul_hari'] = strtoupper($nama_acara) . ' - HARI KE-' . $day_counter;
    }
    $formatted_days[] = $day;
    $day_counter++;
}

if (empty($formatted_days)) {
    die("Tidak ada data rundown untuk dicetak.");
}

$download_name = "RUNDOWN - $nama_acara - $tahun";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($download_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #525659; font-family: 'Times New Roman', Times, serif; font-size: 14px; color: #000; line-height: 1.4; }
        
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm;
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
            background: #E23C3C; color: #fff; border: none; padding: 10px 20px; font-size: 16px;
            border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin: 0 5px;
        }
        .btn-warning { background: #f39c12; }

        .lampiran-text {
            text-align: left;
            font-size: 12pt;
            font-style: italic;
            margin-bottom: 20px;
        }

        .header-pdf {
            text-align: center;
            margin-bottom: 30px;
            text-transform: uppercase;
        }
        .header-pdf h1, .header-pdf h2, .header-pdf h3 {
            font-size: 14pt;
            font-weight: bold;
            margin: 3px 0;
        }

        .table-rundown {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            page-break-inside: auto;
        }
        .table-rundown tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        .table-rundown th, .table-rundown td {
            border: 1px solid #000;
            padding: 8px 12px;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-rundown .day-header {
            background-color: #d9e2f3;
            font-weight: bold;
            font-size: 12pt;
            padding: 10px;
        }
        
        .table-rundown .col-header {
            background-color: #bfbfbf;
            font-weight: bold;
        }

        .table-rundown td.left { text-align: left; }

        @page {
            size: A4 portrait;
            margin: 0;
        }

        @media print {
            body { background: white; margin: 0; padding: 0; -webkit-print-color-adjust: exact; }
            .page { 
                margin: 0 !important; 
                padding: 15mm 20mm; 
                border: none !important; 
                border-radius: 0 !important; 
                width: 210mm; 
                min-height: 296mm;
                box-shadow: none !important; 
                background: white !important; 
                page-break-after: always;
            }
            .page:last-of-type {
                page-break-after: avoid !important;
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak Dokumen</button>
        <a href="cetak-rundown.php" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <div class="page">
        
        <div class="header-pdf">
            <h1>SUSUNAN ACARA</h1>
            <h2><?php echo htmlspecialchars($nama_acara); ?></h2>
            <h3>PERIODE <?php echo htmlspecialchars($tahun); ?></h3>
            <p style="font-weight: bold; margin-top: 5px; font-size: 12pt;"><?php echo htmlspecialchars($tanggal_utama); ?></p>
        </div>

        <table class="table-rundown">
            <?php foreach ($formatted_days as $dayIndex => $dayData): ?>
            <thead>
                <?php if ($dayIndex > 0): ?>
                <tr>
                    <td colspan="5" style="border: none; height: 30px;"></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th colspan="5" class="day-header">
                        <?php echo htmlspecialchars($dayData['judul_hari']); ?><br><br>
                        <?php echo htmlspecialchars($dayData['tanggal']); ?>
                    </th>
                </tr>
                <tr class="col-header">
                    <th style="width: 5%;">NO</th>
                    <th style="width: 15%;">WAKTU</th>
                    <th style="width: 35%;">ACARA</th>
                    <th style="width: 30%;"><?php echo ($dayData['tipe_ket'] ?? 'ket') === 'ket' ? 'KETERANGAN' : 'TEMPAT'; ?></th>
                    <th style="width: 15%;">PENANGGUNG<br>JAWAB</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $num = 1;
                for ($idx = 0; $idx < count($dayData['items']); $idx++): 
                    $item = $dayData['items'][$idx];
                    $is_parallel = !empty($item['is_parallel']);
                    
                    $rowspan = 1;
                    if (!$is_parallel) {
                        for ($j = $idx + 1; $j < count($dayData['items']); $j++) {
                            if (!empty($dayData['items'][$j]['is_parallel'])) {
                                $rowspan++;
                            } else {
                                break;
                            }
                        }
                    }
                ?>
                    <tr>
                        <?php if (!$is_parallel): ?>
                            <td <?php echo $rowspan > 1 ? 'rowspan="'.$rowspan.'"' : ''; ?>><?php echo $num++; ?>.</td>
                            <td style="white-space: nowrap;" <?php echo $rowspan > 1 ? 'rowspan="'.$rowspan.'"' : ''; ?>><?php echo htmlspecialchars($item['waktu']); ?></td>
                        <?php endif; ?>
                        <td><?php echo nl2br(htmlspecialchars($item['acara'])); ?></td>
                        <td><?php echo htmlspecialchars($item['keterangan']); ?></td>
                        <td><?php echo htmlspecialchars($item['pj']); ?></td>
                    </tr>
                <?php endfor; ?>
            </tbody>
            <?php endforeach; ?>
        </table>
        
    </div>

</body>
</html>
