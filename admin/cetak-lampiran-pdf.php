<?php
// admin/cetak-lampiran-pdf.php
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

$tanggal = sanitizeText($_POST['tanggal'] ?? '');
$acara   = sanitizeText($_POST['acara'] ?? '');
$tahun   = (int)($_POST['tahun'] ?? date('Y'));
$qtys    = $_POST['qty'] ?? [];
$item_names = $_POST['item_name'] ?? [];

// Filter dan pisahkan barang dan tempat
$list_barang = [];
$list_tempat = [];

foreach ($qtys as $id => $qty) {
    if ((int)$qty > 0) {
        $nama_asli = $item_names[$id] ?? 'Tidak dikenal';
        
        if (strpos($id, 'b_') === 0) {
            // Ini adalah barang
            $real_id = (int)str_replace('b_', '', $id);
            $satuan = 'pcs';
            $nama = $nama_asli;
            
            // Extract original name and unit from POST string as fallback
            if (preg_match('/(.*)\s+\(([^)]+)\)$/', $nama_asli, $matches)) {
                $nama = trim($matches[1]);
                $satuan = trim($matches[2]);
            }

            // Get LATEST name and unit from DB
            $master = dbFetchOne("SELECT nama_barang, satuan FROM barang_master WHERE id = ?", [$real_id], "i");
            if ($master) {
                $nama = $master['nama_barang'];
                $satuan = $master['satuan'];
            }
            
            $list_barang[] = [
                'nama' => $nama,
                'qty'  => (int)$qty,
                'satuan' => $satuan
            ];
        } else if (strpos($id, 't_') === 0) {
            // Ini adalah tempat
            $real_id = (int)str_replace('t_', '', $id);
            $nama = $nama_asli;

            // Get LATEST name from DB
            $master = dbFetchOne("SELECT nama_tempat FROM tempat_master WHERE id = ?", [$real_id], "i");
            if ($master) {
                $nama = $master['nama_tempat'];
            }

            $list_tempat[] = [
                'nama' => $nama
            ];
        } else {
            // Fallback for malformed IDs
            $satuan = 'pcs';
            $nama = $nama_asli;
            if (preg_match('/(.*)\s+\(([^)]+)\)$/', $nama_asli, $matches)) {
                $nama = trim($matches[1]);
                $satuan = trim($matches[2]);
            }
            $list_barang[] = [
                'nama' => $nama,
                'qty'  => (int)$qty,
                'satuan' => $satuan
            ];
        }
    }
}

if (empty($list_barang) && empty($list_tempat)) {
    die("Tidak ada barang atau tempat yang dipilih untuk dicetak.");
}

$download_name = "LAMPIRAN PINJAM BARANG - $acara - $tahun";
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

        .header-pdf {
            text-align: center;
            margin-bottom: 30px;
        }
        .header-pdf h1 {
            font-size: 20px;
            text-decoration: underline;
            margin-bottom: 10px;
        }
        .header-pdf h2 {
            font-size: 16px;
            font-weight: normal;
        }

        .table-items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .table-items th, .table-items td {
            border: 1px solid #000;
            padding: 8px 12px;
            text-align: left;
        }
        .table-items th {
            background-color: #f2f2f2;
            text-align: center;
            font-weight: bold;
        }
        .table-items td.center { text-align: center; }

        .footer-pdf {
            margin-top: 50px;
            text-align: right;
            font-size: 12px;
            color: #555;
            font-style: italic;
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
                min-height: 296mm; /* Mengurangi 1mm untuk toleransi PDF driver */
                box-shadow: none !important; 
                background: white !important; 
                page-break-after: always; 
                page-break-inside: avoid;
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
        <a href="cetak-lampiran.php" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Kembali</a>
    </div>

    <div class="page">
        <div style="text-align: left; font-size: 12pt; margin-bottom: 20px; font-style: italic;">Lampiran 1</div>
        
        <div class="header-pdf">
            <h1 style="font-size: 14pt; text-decoration: none; font-weight: bold; margin-bottom: 5px; text-transform: uppercase;">Daftar Barang & Tempat Yang Akan Dipinjam</h1>
            <h2 style="font-size: 14pt; font-weight: bold; text-transform: uppercase;">Pada Tanggal <?php echo htmlspecialchars($tanggal); ?> Untuk Acara <?php echo htmlspecialchars($acara); ?> <?php echo $tahun; ?></h2>
        </div>

        <?php if (!empty($list_barang)): ?>
        <div style="page-break-inside: avoid;">
            <h3 style="margin-bottom:10px; font-size:12pt; text-transform:uppercase;">Daftar Barang</h3>
            <table class="table-items">
                <thead>
                    <tr>
                        <th style="width: 50px;">No.</th>
                        <th>Nama Barang</th>
                        <th style="width: 150px;">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list_barang as $idx => $item): ?>
                        <tr>
                            <td class="center"><?php echo $idx + 1; ?>.</td>
                            <td><?php echo htmlspecialchars($item['nama']); ?></td>
                            <td><?php echo $item['qty'] . ' (' . htmlspecialchars($item['satuan']) . ')'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($list_tempat)): ?>
        <div style="page-break-inside: avoid;">
            <h3 style="margin-bottom:10px; font-size:12pt; text-transform:uppercase; margin-top:40px;">Daftar Tempat</h3>
            <table class="table-items">
                <thead>
                    <tr>
                        <th style="width: 50px;">No.</th>
                        <th>Nama Tempat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list_tempat as $idx => $item): ?>
                        <tr>
                            <td class="center"><?php echo $idx + 1; ?>.</td>
                            <td><?php echo htmlspecialchars($item['nama']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="footer-pdf">
            Dicetak pada: <?php echo formatTanggal(date('Y-m-d H:i:s'), true); ?>
        </div>
    </div>

</body>
</html>
