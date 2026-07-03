<?php
// admin/cetak-lpj.php
// Force No-Cache
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config.php';

requireLogin();

$id = (int)($_GET['id'] ?? 0);
$periode_id = getUserPeriode();
$user_role = $_SESSION['user_role'] ?? '';
$user_kementerian_id = $_SESSION['kementerian_id'] ?? 0;

if ($user_role === 'menteri') {
    $lpj = dbFetchOne("SELECT l.*, k.nama as nama_kementerian FROM lpj_dokumen l JOIN kementerian k ON l.kementerian_id = k.id WHERE l.id = ? AND l.periode_id = ? AND l.kementerian_id = ?", [$id, $periode_id, $user_kementerian_id], "iii");
} else {
    $lpj = dbFetchOne("SELECT l.*, k.nama as nama_kementerian FROM lpj_dokumen l JOIN kementerian k ON l.kementerian_id = k.id WHERE l.id = ? AND l.periode_id = ?", [$id, $periode_id], "ii");
}

if (!$lpj) {
    die("LPJ tidak ditemukan atau Anda tidak memiliki akses ke LPJ ini.");
}

$k_name = $lpj['nama_kementerian'];
$triwulan = strtoupper($lpj['triwulan']);
$is_mubesma = ($triwulan === 'MUBESMA');

$keanggotaan = json_decode($lpj['keanggotaan'] ?? '', true) ?: [];
$proker_terlaksana = json_decode($lpj['proker_terlaksana'] ?? '', true) ?: [];
$proker_belum_terlaksana = json_decode($lpj['proker_belum_terlaksana'] ?? '', true) ?: [];
$keadaan_objektif = $lpj['keadaan_objektif'] ?? '';

// Sort by date (parse Indonesian month names)
$id_months = ['januari'=>1,'februari'=>2,'maret'=>3,'april'=>4,'mei'=>5,'juni'=>6,'juli'=>7,'agustus'=>8,'september'=>9,'oktober'=>10,'november'=>11,'desember'=>12];
$parse_id_date = function($d) use ($id_months) {
    preg_match('/(\d+)(?:\s*[-–]\s*\d+)?\s+(\w+)\s+(\d{4})/i', strtolower(trim($d)), $m);
    if (!empty($m)) return mktime(0, 0, 0, $id_months[$m[2]] ?? 1, (int)$m[1], (int)$m[3]);
    return 0;
};
$sorted_proker = $proker_terlaksana;
usort($sorted_proker, function($a, $b) use ($parse_id_date) {
    return $parse_id_date($a['Tanggal Kegiatan'] ?? '') - $parse_id_date($b['Tanggal Kegiatan'] ?? '');
});

// Fetch kementerian details for Tugas Pokok and Fungsi
$k_row = dbFetchOne("SELECT tugas, fungsi FROM kementerian WHERE id = ?", [$lpj['kementerian_id']], "i");
$k_tugas = $k_row && !empty($k_row['tugas']) ? json_decode($k_row['tugas'], true) : [];
$k_fungsi = $k_row && !empty($k_row['fungsi']) ? json_decode($k_row['fungsi'], true) : [];

// Fetch Visi and Misi
$visi_misi_row = dbFetchOne("SELECT visi, misi FROM visi_misi WHERE id = 1");
$visi = $visi_misi_row ? ($visi_misi_row['visi'] ?? '') : '';
$misi = $visi_misi_row && !empty($visi_misi_row['misi']) ? json_decode($visi_misi_row['misi'], true) : [];

$periode_row = dbFetchOne("SELECT nama, tahun_mulai, tahun_selesai FROM periode_kepengurusan WHERE id = ?", [$periode_id], "i");
$years_str = $periode_row ? ($periode_row['tahun_mulai'] . '/' . $periode_row['tahun_selesai']) : '2025/2026';

$download_name = "LPJ_".str_replace(' ', '_', $k_name)."_Triwulan_".$triwulan;

// Helper to resolve absolute upload paths to public web URLs
if (!function_exists('getLpjImageUrl')) {
    function getLpjImageUrl($filePath) {
        if (empty($filePath)) return '';
        if (strpos($filePath, 'http') === 0 || strpos($filePath, 'data:') === 0) {
            return $filePath;
        }
        $uploadsPos = strpos($filePath, 'uploads/');
        if ($uploadsPos !== false) {
            $relPath = substr($filePath, $uploadsPos + 8);
            return baseUrl('uploads/' . $relPath);
        }
        return uploadUrl($filePath);
    }
}

// Helper to parse points string or array
if (!function_exists('parsePoints')) {
    function parsePoints($val) {
        if (empty($val)) return [];
        if (is_array($val)) return $val;
        return array_filter(array_map('trim', explode("\n", $val)));
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Preview LPJ - <?php echo htmlspecialchars($k_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #323639; font-family: 'Times New Roman', Times, serif; font-size: 16px; color: #000; line-height: 1.5; }
        
        .no-print {
            text-align: center;
            padding: 15px;
            background: #222;
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .btn {
            background: #4A90E2; color: #fff; border: none; padding: 10px 20px; font-size: 14px;
            border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-family: Arial, sans-serif;
            font-weight: bold; transition: background 0.2s;
        }
        .btn:hover { background: #357ABD; }
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #219653; }
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #d68010; }

        .page-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 20px 0;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 20mm 20mm 25mm; /* Margin standar docx: kiri agak lebar untuk jilid */
            margin-bottom: 20px;
            background: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            position: relative;
            page-break-after: always;
        }

        .cover-page {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            height: 257mm; /* 297 - 40mm padding */
            text-align: center;
        }

        .cover-title {
            margin-top: 1.5in;
            font-size: 14pt;
            font-weight: bold;
            line-height: 1.3;
        }

        .cover-logo {
            margin: 1.2in 0;
        }
        .cover-logo img {
            width: 6.5cm;
            height: auto;
        }

        .cover-footer {
            margin-bottom: 0.5in;
            font-size: 14pt;
            font-weight: bold;
            line-height: 1.3;
        }

        /* Section Headings */
        .section-header {
            font-size: 12pt;
            font-weight: bold;
            margin-top: 15px;
            margin-bottom: 8px;
            text-align: left;
        }
        .subsection-header {
            font-size: 12pt;
            font-weight: bold;
            margin-left: 0.5cm;
            margin-top: 10px;
            margin-bottom: 6px;
        }

        /* Lists and Paragraphs */
        .narrative-p {
            text-align: justify;
            text-indent: 1cm;
            line-height: 1.5;
            margin-bottom: 12px;
            margin-left: 0.5cm;
        }

        .hanging-list {
            margin-left: 1.5cm;
            text-indent: -0.5cm;
            margin-bottom: 10px;
        }
        .hanging-list p {
            margin-bottom: 6px;
            text-align: justify;
            line-height: 1.15;
        }

        /* Tables */
        .borderless-table {
            width: 100%;
            border-collapse: collapse;
            margin-left: 0.5cm;
            width: calc(100% - 0.5cm);
            margin-bottom: 15px;
        }
        .borderless-table td {
            border: none;
            vertical-align: top;
            padding: 4px 6px;
            line-height: 1.2;
        }
        .borderless-table td.col-label {
            width: 4.8cm;
        }
        .borderless-table td.col-colon {
            width: 0.5cm;
            text-align: center;
        }

        .budget-table {
            width: 100%;
            border-collapse: collapse;
            margin-left: 1cm;
            width: calc(100% - 1cm);
            margin-top: 8px;
            margin-bottom: 15px;
            page-break-inside: avoid;
        }
        .budget-table th, .budget-table td {
            border: 1px solid #000;
            padding: 5px 8px;
            font-size: 11pt;
            vertical-align: middle;
        }
        .budget-table th {
            background-color: #D3D3D3;
            text-align: center;
            font-weight: bold;
        }
        .budget-table td.right-align {
            text-align: right;
        }
        .budget-table tr.total-row td {
            background-color: #EAEAEA;
            font-weight: bold;
        }

        .photo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-left: 1cm;
            width: calc(100% - 1cm);
            margin-top: 8px;
            margin-bottom: 15px;
            page-break-inside: avoid;
        }
        .photo-item {
            text-align: center;
            border: 1px solid #ddd;
            padding: 8px;
            background: #fafafa;
            border-radius: 4px;
        }
        .photo-item img {
            max-width: 100%;
            height: 120px;
            object-fit: contain;
            background: #fff;
            border: 1px solid #eee;
            margin-bottom: 5px;
        }
        .photo-caption {
            font-size: 10pt;
            font-style: italic;
            color: #555;
        }

        .page-footer-label {
            position: absolute;
            bottom: 15mm;
            right: 20mm;
            font-size: 10pt;
            font-style: italic;
            font-family: 'Times New Roman', Times, serif;
            color: #555;
        }

        /* Proker block formatting to prevent breaking midway */
        .proker-block {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }

        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .page-container { padding: 0; }
            .page {
                box-shadow: none;
                margin: 0;
                padding: 20mm 20mm 20mm 25mm;
                width: 210mm;
                height: 297mm;
                page-break-after: always;
            }
            .page:last-child {
                page-break-after: avoid;
            }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak PDF</button>
        <?php if (!empty($lpj['file_path'])): ?>
            <a href="<?php echo uploadUrl($lpj['file_path']); ?>" class="btn btn-success" download><i class="fas fa-file-word"></i> Download Word</a>
        <?php endif; ?>
        <a href="arsip-lpj.php" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Kembali ke Arsip</a>
    </div>

    <div class="page-container">

        <!-- 1. COVER PAGE (Only for MUBESMA) -->
        <?php if ($is_mubesma): ?>
        <div class="page">
            <div class="cover-page">
                <div class="cover-title">
                    LAPORAN PERTANGGUNGJAWABAN<br>
                    MENTERI <?php echo strtoupper($k_name); ?><br>
                    BADAN EKSEKUTIF MAHASISWA<br>
                    INSTITUT BUDI UTOMO NASIONAL
                </div>
                <div class="cover-logo">
                    <img src="<?php echo baseUrl('assets/images/favicon/apple-touch-icon.png'); ?>" alt="Logo BEM">
                </div>
                <div class="cover-footer">
                    BADAN EKSEKUTIF MAHASISWA<br>
                    INSTITUT BUDI UTOMO NASIONAL MAJALENGKA<br>
                    PERIODE <?php echo htmlspecialchars($years_str); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 2. MAIN REPORT PAGE -->
        <div class="page">
            <!-- Inline Header for non-Mubesma -->
            <?php if (!$is_mubesma): ?>
                <div style="text-align: center; margin-bottom: 25px;">
                    <h2 style="font-size: 12pt; font-weight: bold; text-transform: uppercase;">LAPORAN PERTANGGUNG JAWABAN TRIWULAN <?php echo htmlspecialchars($triwulan); ?></h2>
                    <h2 style="font-size: 12pt; font-weight: bold; text-transform: uppercase;">MENTERI <?php echo htmlspecialchars($k_name); ?></h2>
                    <h2 style="font-size: 12pt; font-weight: bold; text-transform: uppercase;">BEM INSTBUNAS MAJALENGKA <?php echo htmlspecialchars($years_str); ?></h2>
                </div>
            <?php endif; ?>

            <!-- A. / I. PENDAHULUAN / KEADAAN OBJEKTIF -->
            <div class="section-header">
                <?php echo $is_mubesma ? "I. PENDAHULUAN" : "A. KEADAAN OBJEKTIF MENTERI"; ?>
            </div>
            <?php 
            $lines = array_filter(array_map('trim', explode("\n", $keadaan_objektif)));
            if (empty($lines)) {
                $lines = ["(Belum diisi)"];
            }
            foreach ($lines as $line): 
            ?>
                <p class="narrative-p"><?php echo htmlspecialchars($line); ?></p>
            <?php endforeach; ?>

            <!-- B. / II. SUSUNAN KEANGGOTAAN -->
            <div class="section-header">
                <?php echo $is_mubesma ? "II. SUSUNAN KEANGGOTAAN" : "B. SUSUNAN KEANGGOTAAN"; ?>
            </div>
            <table class="borderless-table">
                <tr>
                    <td class="col-label">Ketua Menteri</td>
                    <td class="col-colon">:</td>
                    <td><?php echo htmlspecialchars($keanggotaan['ketua'] ?? '—'); ?></td>
                </tr>
                <tr>
                    <td class="col-label">Sekretaris</td>
                    <td class="col-colon">:</td>
                    <td><?php echo htmlspecialchars($keanggotaan['sekretaris'] ?? '—'); ?></td>
                </tr>
                <tr>
                    <td class="col-label">Bendahara</td>
                    <td class="col-colon">:</td>
                    <td><?php echo htmlspecialchars($keanggotaan['bendahara'] ?? '—'); ?></td>
                </tr>
                <?php 
                $anggota_list = parsePoints($keanggotaan['anggota'] ?? '');
                if (!empty($anggota_list)): 
                ?>
                    <tr>
                        <td class="col-label">Anggota</td>
                        <td class="col-colon">:</td>
                        <td><?php echo implode(', ', array_map('htmlspecialchars', $anggota_list)); ?></td>
                    </tr>
                <?php endif; ?>
            </table>

            <!-- III. TUGAS POKOK DAN FUNGSI (Mubesma Only) -->
            <?php if ($is_mubesma): ?>
                <div class="section-header">III. TUGAS POKOK DAN FUNGSI</div>
                
                <div class="subsection-header">A. Tugas Pokok</div>
                <div class="hanging-list">
                    <?php if (!empty($k_tugas)): ?>
                        <?php foreach ($k_tugas as $idx => $t_item): ?>
                            <p><?php echo ($idx + 1); ?>.&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($t_item); ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-style: italic; text-indent: 0;">(Tidak ada tugas pokok)</p>
                    <?php endif; ?>
                </div>

                <div class="subsection-header">B. Fungsi</div>
                <div class="hanging-list">
                    <?php if (!empty($k_fungsi)): ?>
                        <?php foreach ($k_fungsi as $idx => $f_item): ?>
                            <p><?php echo ($idx + 1); ?>.&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($f_item); ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-style: italic; text-indent: 0;">(Tidak ada fungsi)</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- IV. EVALUASI PENCAPAIAN VISI DAN MISI (Mubesma Only) -->
            <?php if ($is_mubesma): ?>
                <div class="section-header">IV. EVALUASI PENCAPAIAN VISI DAN MISI</div>
                <div class="subsection-header" style="margin-left: 0.5cm;">Visi dan Misi Badan Eksekutif Mahasiswa INSTBUNAS Majalengka</div>
                
                <div class="subsection-header">A. Visi</div>
                <p class="narrative-p" style="text-indent: 0; margin-left: 1cm;"><?php echo htmlspecialchars($visi ?: '(Belum diatur)'); ?></p>

                <div class="subsection-header">B. Misi</div>
                <div class="hanging-list">
                    <?php if (!empty($misi)): ?>
                        <?php foreach ($misi as $idx => $m_item): ?>
                            <p><?php echo ($idx + 1); ?>.&nbsp;&nbsp;&nbsp;&nbsp;<?php echo htmlspecialchars($m_item); ?></p>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="font-style: italic; text-indent: 0;">(Belum diatur)</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="page-footer-label">
                Laporan Pertanggungjawaban <?php echo $is_mubesma ? "Mubesma" : "Triwulan " . htmlspecialchars($triwulan); ?>
            </div>
        </div>

        <!-- 3. PROGRAM KERJA RINGKASAN (Mubesma Only) -->
        <?php if ($is_mubesma): ?>
        <div class="page">
            <div class="section-header">V. PROGRAM KERJA</div>

            <?php if (empty($proker_terlaksana)): ?>
                <p class="narrative-p" style="font-style: italic; text-indent: 0;">(Tidak ada data program kerja)</p>
            <?php else: ?>
                <?php
                // Clean program name helper
                $clean_prog_name = function($name) {
                    if (empty($name)) return '';
                    return trim(preg_replace('/^\d+[\.\s-]*/', '', $name));
                };

                // Group consecutive same-named programs for rowspan
                $proker_groups_v = [];
                $prev_prog = null;
                $current_no = 1;
                foreach ($sorted_proker as $pk_row) {
                    $prog_name = $clean_prog_name($pk_row['Nama Program Kerja'] ?? '—');
                    if ($prev_prog === $prog_name && !empty($proker_groups_v)) {
                        $proker_groups_v[count($proker_groups_v)-1]['rows'][] = $pk_row;
                    } else {
                        $proker_groups_v[] = ['name' => $pk_row['Nama Program Kerja'] ?? '—', 'start_no' => $current_no, 'rows' => [$pk_row]];
                        $prev_prog = $prog_name;
                    }
                    $current_no++;
                }
                ?>
                <table class="budget-table" style="margin-left: 0; width: 100%; margin-top: 10px;">
                    <thead>
                        <tr>
                            <th style="width: 7%; text-align: center;">NO</th>
                            <th style="width: 22%; text-align: center;">WAKTU</th>
                            <th style="width: 25%; text-align: center;">PROGRAM KERJA</th>
                            <th style="width: 28%; text-align: center;">NAMA KEGIATAN</th>
                            <th style="width: 18%; text-align: center;">TEMPAT</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proker_groups_v as $grp): ?>
                            <?php $span = count($grp['rows']); ?>
                            <?php foreach ($grp['rows'] as $r_idx => $r_row): ?>
                            <tr>
                                <?php if ($r_idx === 0): ?>
                                <td style="text-align: center; vertical-align: top;" rowspan="<?php echo $span; ?>"><?php echo $grp['start_no']; ?></td>
                                <?php endif; ?>
                                <td style="text-align: justify; vertical-align: top;"><?php echo htmlspecialchars($r_row['Tanggal Kegiatan'] ?? '—'); ?></td>
                                <?php if ($r_idx === 0): ?>
                                <td style="text-align: justify; vertical-align: top;" rowspan="<?php echo $span; ?>"><?php echo htmlspecialchars($grp['name']); ?></td>
                                <?php endif; ?>
                                <td style="text-align: justify; vertical-align: top;"><?php echo htmlspecialchars($r_row['Nama Kegiatan'] ?? $r_row['Nama Program Kerja'] ?? '—'); ?></td>
                                <td style="text-align: justify; vertical-align: top;"><?php echo htmlspecialchars($r_row['Tempat Kegiatan'] ?? $r_row['Tempat'] ?? '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top: 8px; font-size: 10pt; font-style: italic; text-align: center; color: #555;">
                    *disesuaikan dengan program kerja selama menjabat
                </p>
            <?php endif; ?>

            <div class="page-footer-label">
                Laporan Pertanggungjawaban Mubesma
            </div>
        </div>
        <?php endif; ?>

        <!-- 4. REALISASI PROGRAM KERJA -->
        <div class="page">
            <div class="section-header">
                <?php echo $is_mubesma ? "VI. REALISASI PROGRAM KERJA YANG SUDAH DILAKSANAKAN" : "C. REALISASI PROGRAM KERJA YANG SUDAH DILAKSANAKAN"; ?>
            </div>

            <?php if (empty($proker_terlaksana)): ?>
                <p class="narrative-p" style="font-style: italic; text-indent: 0;">(Tidak ada program kerja terlaksana)</p>
            <?php else: ?>
                <?php foreach ($sorted_proker as $idx => $pk): ?>
                    <div class="proker-block">
                        <div class="subsection-header" style="margin-left: 0.5cm; margin-bottom: 10px;">
                            <?php echo ($idx + 1) . '. ' . htmlspecialchars($pk['Nama Program Kerja'] ?? 'Program Kerja'); ?>
                        </div>
                        <table class="borderless-table" style="margin-left: 1cm; width: calc(100% - 1cm);">
                            <tr>
                                <td class="col-label">a.&nbsp;&nbsp;Nama Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Nama Kegiatan'] ?? $pk['Nama Program Kerja'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">b.&nbsp;&nbsp;Tempat Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Tempat Kegiatan'] ?? $pk['Tempat'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">c.&nbsp;&nbsp;Sifat Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Sifat'] ?? 'Internal'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">d.&nbsp;&nbsp;Tema Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Tema Kegiatan'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">e.&nbsp;&nbsp;Tujuan Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td>
                                    <?php 
                                    $tujuans = parsePoints($pk['Tujuan'] ?? '');
                                    if (count($tujuans) === 1) {
                                        echo htmlspecialchars($tujuans[0]);
                                    } else {
                                        foreach ($tujuans as $t_idx => $t_val) {
                                            echo ($t_idx + 1) . '. ' . htmlspecialchars($t_val) . '<br>';
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-label">f.&nbsp;&nbsp;Tanggal Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Tanggal Kegiatan'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">g.&nbsp;&nbsp;Penanggung Jawab</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Penanggung Jawab'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">h.&nbsp;&nbsp;Peserta Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td>
                                    <?php 
                                    $pesertas = parsePoints($pk['Peserta Kegiatan'] ?? '');
                                    if (count($pesertas) === 1) {
                                        echo htmlspecialchars($pesertas[0]);
                                    } else {
                                        foreach ($pesertas as $p_idx => $p_val) {
                                            echo ($p_idx + 1) . '. ' . htmlspecialchars($p_val) . '<br>';
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-label">i.&nbsp;&nbsp;Evaluasi &amp; Saran</td>
                                <td class="col-colon">:</td>
                                <td>
                                    <?php 
                                    $evals = parsePoints($pk['Evaluasi'] ?? '');
                                    if (count($evals) === 1) {
                                        echo htmlspecialchars($evals[0]);
                                    } else {
                                        foreach ($evals as $e_idx => $e_val) {
                                            echo ($e_idx + 1) . '. ' . htmlspecialchars($e_val) . '<br>';
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                        </table>

                        <!-- Sub-bagian j: Realisasi Anggaran -->
                        <div class="subsection-header" style="margin-left: 1cm; font-weight: normal; font-size: 11pt;">j.&nbsp;&nbsp;Realisasi Anggaran</div>
                        <?php 
                        $no_budget = !empty($pk['tidak_menggunakan_anggaran']);
                        $anggaran = $pk['anggaran'] ?? [];
                        if ($no_budget || empty($anggaran)): 
                        ?>
                            <p style="margin-left: 1.5cm; font-style: italic; font-size: 11pt;">(Tidak ada realisasi anggaran)</p>
                        <?php else: ?>
                            <table class="budget-table">
                                <thead>
                                    <tr>
                                        <th style="width: 12%;">Tanggal</th>
                                        <th style="width: 20%;">Keterangan</th>
                                        <th style="width: 25%;">Uraian</th>
                                        <th style="width: 14%;">Debet</th>
                                        <th style="width: 14%;">Kredit</th>
                                        <th style="width: 15%;">Saldo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $balance = 0;
                                    $total_d = 0;
                                    $total_k = 0;
                                    foreach ($anggaran as $tx): 
                                        $d = (float)($tx['debet'] ?? 0);
                                        $k = (float)($tx['kredit'] ?? 0);
                                        $total_d += $d;
                                        $total_k += $k;
                                        $balance += ($d - $k);
                                    ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($tx['tanggal'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($tx['keterangan'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($tx['uraian'] ?? ''); ?></td>
                                            <td class="right-align"><?php echo $d > 0 ? formatRupiah($d) : ''; ?></td>
                                            <td class="right-align"><?php echo $k > 0 ? formatRupiah($k) : ''; ?></td>
                                            <td class="right-align"><?php echo formatRupiah($balance); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr class="total-row">
                                        <td colspan="3" style="text-align: center;">TOTAL</td>
                                        <td class="right-align"><?php echo formatRupiah($total_d); ?></td>
                                        <td class="right-align"><?php echo formatRupiah($total_k); ?></td>
                                        <td class="right-align"><?php echo formatRupiah($balance); ?></td>
                                    </tr>
                                </tbody>
                             </table>
                        <?php endif; ?>

                        <!-- Sub-bagian k: Dokumentasi Kegiatan -->
                        <div class="subsection-header" style="margin-left: 1cm; font-weight: normal; font-size: 11pt; margin-top: 15px;">k.&nbsp;&nbsp;Dokumentasi Kegiatan</div>
                        <?php 
                        $doc_list = $pk['dokumentasi'] ?? [];
                        if (empty($doc_list)): 
                        ?>
                            <p style="margin-left: 1.5cm; font-style: italic; font-size: 11pt;">(Dokumentasi tidak tersedia)</p>
                        <?php else: ?>
                            <div class="photo-grid">
                                <?php foreach ($doc_list as $photo): ?>
                                    <div class="photo-item">
                                        <img src="<?php echo htmlspecialchars(getLpjImageUrl($photo['file_path'])); ?>" alt="Dokumentasi">
                                        <div class="photo-caption"><?php echo htmlspecialchars($photo['caption'] ?? 'Dokumentasi'); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div class="page-footer-label">
                Laporan Pertanggungjawaban <?php echo $is_mubesma ? "Mubesma" : "Triwulan " . htmlspecialchars($triwulan); ?>
            </div>
        </div>

        <!-- 4. PROGRAM KERJA BELUM TEREALISASI -->
        <div class="page">
            <div class="section-header">
                <?php echo $is_mubesma ? "VII. PROGRAM KERJA YANG BELUM TERLAKSANA" : "D. PROGRAM KERJA YANG BELUM TERLAKSANA"; ?>
            </div>

            <?php if (empty($proker_belum_terlaksana)): ?>
                <p class="narrative-p" style="font-style: italic; text-indent: 0;">(Tidak ada program kerja belum terlaksana)</p>
            <?php else: ?>
                <?php foreach ($proker_belum_terlaksana as $idx => $pk): ?>
                    <div class="proker-block">
                        <div class="subsection-header" style="margin-left: 0.5cm; margin-bottom: 10px;">
                            <?php echo ($idx + 1) . '. ' . htmlspecialchars($pk['Nama Program Kerja'] ?? 'Program Kerja'); ?>
                        </div>
                        <table class="borderless-table" style="margin-left: 1cm; width: calc(100% - 1cm);">
                            <tr>
                                <td class="col-label">a.&nbsp;&nbsp;Nama Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Nama Kegiatan'] ?? $pk['Nama Program Kerja'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">b.&nbsp;&nbsp;Sifat Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Sifat'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">c.&nbsp;&nbsp;Tema Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Tema Kegiatan'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">d.&nbsp;&nbsp;Tujuan Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td>
                                    <?php 
                                    $tujuans = parsePoints($pk['Tujuan Kegiatan'] ?? $pk['Tujuan'] ?? '');
                                    if (count($tujuans) === 1) {
                                        echo htmlspecialchars($tujuans[0]);
                                    } else {
                                        foreach ($tujuans as $t_idx => $t_val) {
                                            echo ($t_idx + 1) . '. ' . htmlspecialchars($t_val) . '<br>';
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-label">e.&nbsp;&nbsp;Tanggal Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Tanggal Kegiatan'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">f.&nbsp;&nbsp;Penanggung Jawab</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Penanggung Jawab'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">g.&nbsp;&nbsp;Peserta Kegiatan</td>
                                <td class="col-colon">:</td>
                                <td>
                                    <?php 
                                    $pesertas = parsePoints($pk['Peserta Kegiatan'] ?? '');
                                    if (count($pesertas) === 1) {
                                        echo htmlspecialchars($pesertas[0]);
                                    } else {
                                        foreach ($pesertas as $p_idx => $p_val) {
                                            echo ($p_idx + 1) . '. ' . htmlspecialchars($p_val) . '<br>';
                                        }
                                    }
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="col-label">h.&nbsp;&nbsp;Anggaran</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Anggaran'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <td class="col-label">i.&nbsp;&nbsp;Dokumentasi</td>
                                <td class="col-colon">:</td>
                                <td><?php echo htmlspecialchars($pk['Dokumentasi'] ?? '—'); ?></td>
                            </tr>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div class="page-footer-label">
                Laporan Pertanggungjawaban <?php echo $is_mubesma ? "Mubesma" : "Triwulan " . htmlspecialchars($triwulan); ?>
            </div>
        </div>

    </div>

</body>
</html>
