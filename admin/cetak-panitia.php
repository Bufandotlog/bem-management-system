<?php
// admin/cetak-panitia.php
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config.php';

requireLogin();
requireSekretaris();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$periode_id = getUserPeriode();

$panitia = dbFetchOne("SELECT * FROM arsip_panitia WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");
if (!$panitia) {
    die("Susunan panitia tidak ditemukan.");
}

$data = json_decode($panitia['panitia_json'], true) ?: [];

// Ambil tahun periode
$periode_data = dbFetchOne("SELECT * FROM periode_kepengurusan WHERE id = ?", [$periode_id], "i");
$tahun_mulai = $periode_data['tahun_mulai'] ?? date('Y');
$tahun_selesai = $periode_data['tahun_selesai'] ?? (date('Y') + 1);
$tahun_periode_str = $tahun_mulai . '/' . $tahun_selesai;

$nama_kegiatan = strtoupper($data['nama_kegiatan'] ?? 'KEGIATAN');
$download_name = "PANITIA - " . ($data['nama_kegiatan'] ?? 'KEGIATAN') . " - " . $tahun_periode_str;
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($download_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #525659; font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; line-height: 1.4; }
        
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm 15mm;
            margin: 10mm auto;
            border: 1px solid #D3D3D3;
            border-radius: 5px;
            background: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.2);
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
            display: flex;
            justify-content: center;
            gap: 10px;
        }
        .btn {
            background: #4A90E2; color: #fff; border: none; padding: 10px 20px; font-size: 16px;
            border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-warning { background: #f39c12; }

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

        .table-panitia {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            page-break-inside: auto;
        }
        .table-panitia tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }
        .table-panitia th, .table-panitia td {
            border: 1px solid #000;
            padding: 8px 12px;
            vertical-align: top;
        }
        
        .table-panitia td.role-title {
            width: 35%;
        }

        .table-panitia td.role-title.italic-title {
            font-style: italic;
        }

        .table-panitia td.names-list {
            width: 65%;
        }

        .table-panitia td.names-list ol {
            margin: 0;
            padding-left: 15px;
        }

        .table-panitia td.names-list ol li {
            margin-bottom: 3px;
        }

        .table-panitia .section-heading {
            text-align: center;
            font-weight: bold;
            background-color: transparent;
            padding: 8px 12px;
            text-transform: uppercase;
        }

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
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="window.print()" class="btn"><i class="fas fa-print"></i> Cetak Dokumen</button>
        <a href="arsip-panitia.php" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <div class="page">
        
        <div class="header-pdf">
            <h1>SUSUNAN PANITIA</h1>
            <h2><?php echo htmlspecialchars($nama_kegiatan); ?></h2>
            <h3>PERIODE <?php echo htmlspecialchars($tahun_periode_str); ?></h3>
        </div>

        <table class="table-panitia">
            <tbody>
                <!-- Penanggung Jawab -->
                <tr>
                    <td class="role-title">Penanggung Jawab</td>
                    <td class="names-list"><?php echo htmlspecialchars($data['penanggung_jawab'] ?? '-'); ?></td>
                </tr>

                <!-- Steering Committee -->
                <tr>
                    <td class="role-title"><span style="font-style: italic;">Steering Committee</span> (SC)</td>
                    <td class="names-list">
                        <?php if (!empty($data['sc'])): ?>
                            <ol>
                                <?php foreach ($data['sc'] as $name): ?>
                                    <li><?php echo htmlspecialchars($name); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Ketua Pelaksana -->
                <tr>
                    <td class="role-title">Ketua Pelaksana</td>
                    <td class="names-list">
                        <?php if (!empty($data['ketua_pelaksana'])): ?>
                            <ol>
                                <li><?php echo htmlspecialchars($data['ketua_pelaksana']); ?></li>
                            </ol>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Sekretaris -->
                <tr>
                    <td class="role-title">Sekretaris</td>
                    <td class="names-list">
                        <?php if (!empty($data['sekretaris'])): ?>
                            <ol>
                                <?php foreach ($data['sekretaris'] as $name): ?>
                                    <li><?php echo htmlspecialchars($name); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Bendahara -->
                <tr>
                    <td class="role-title">Bendahara</td>
                    <td class="names-list">
                        <?php if (!empty($data['bendahara'])): ?>
                            <ol>
                                <?php foreach ($data['bendahara'] as $name): ?>
                                    <li><?php echo htmlspecialchars($name); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>

                <!-- Seksi-Seksi Heading -->
                <?php if (!empty($data['seksi_seksi'])): ?>
                    <tr>
                        <td colspan="2" class="section-heading">Seksi - Seksi</td>
                    </tr>

                    <!-- Seksi-Seksi Details -->
                    <?php foreach ($data['seksi_seksi'] as $seksi): ?>
                        <tr>
                            <td class="role-title"><?php echo htmlspecialchars($seksi['nama_seksi']); ?></td>
                            <td class="names-list">
                                <?php if (!empty($seksi['anggota'])): ?>
                                    <ol>
                                        <?php foreach ($seksi['anggota'] as $name): ?>
                                            <li><?php echo htmlspecialchars($name); ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
    </div>

</body>
</html>
