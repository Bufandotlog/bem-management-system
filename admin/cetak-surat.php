<?php
// admin/cetak-surat.php
// Force No-Cache (Critical for InfinityFree)
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config.php';

requireLogin();
requireSekretaris();

$id = (int)($_GET['id'] ?? 0);
$periode_id = getUserPeriode();

$surat = dbFetchOne("SELECT * FROM arsip_surat WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");

if (!$surat) {
    die("Surat tidak ditemukan atau Anda tidak memiliki akses ke periode ini.");
}

$konten = json_decode($surat['konten_surat'], true) ?? [];

// Helper untuk format teks HTML supaya tebal otomatis menangani yang digarisbawahi oleh user (jika perlu)
$tujuan_html = nl2br(htmlspecialchars($surat['tujuan']));

// Mengambil Ketua BEM yg aktif untuk fallback TTD bawah
if (isset($BULK_KETUA)) {
    $ketua_bem = $BULK_KETUA;
} else {
    $ketua_bem = getKetua($periode_id);
}
$nama_ketua_bem = $ketua_bem['nama_lengkap'] ?? 'DEDE ANGGI MUHYIDIN';

// Ambil Pengaturan Tabel Tanda Tangan Tetap
if (isset($BULK_PENGATURAN)) {
    $pengaturan = $BULK_PENGATURAN;
} else {
    $db_pengaturan = dbFetchAll("SELECT kunci, nilai FROM pengaturan");
    $pengaturan = [];
    foreach($db_pengaturan as $p) {
        if(trim($p['nilai']) !== '') $pengaturan[$p['kunci']] = $p['nilai'];
    }
}

// Ambil Data Lampiran Internal (Peminjaman Barang) jika ada
$internal_data = [];
if (!empty($konten['lampiran_internal_ids'])) {
    $ids = (array)$konten['lampiran_internal_ids'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $internal_data = dbFetchAll("SELECT * FROM lampiran_pinjam WHERE id IN ($placeholders) AND periode_id = ?", array_merge($ids, [$periode_id]));
}

// Ambil Data Rundown Internal (Susunan Acara) jika ada
$rundown_data = [];
if (!empty($konten['rundown_internal_ids'])) {
    $r_ids = (array)$konten['rundown_internal_ids'];
    $r_placeholders = implode(',', array_fill(0, count($r_ids), '?'));
    $rundown_data = dbFetchAll("SELECT * FROM arsip_rundown WHERE id IN ($r_placeholders) AND periode_id = ?", array_merge($r_ids, [$periode_id]));
}

// Generate Dynamic Filename
$f_perihal = strtoupper($surat['perihal']);
$parts = explode('/', $surat['nomor_surat']);
$f_kode = strtoupper($parts[2] ?? '');
$f_tujuan = strtoupper(trim(explode("\n", $surat['tujuan'])[0]));
$f_tahun = end($parts) ?: date('Y');
$download_name = "SURAT $f_perihal $f_kode UNTUK $f_tujuan $f_tahun";
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($download_name); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Reset & Setup Kertas A4 */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #525659; font-family: 'Times New Roman', Times, serif; font-size: 16px; color: #000; line-height: 1.5; }
        
        <?php if (isset($_GET['bulk'])): ?>
        body { background: white !important; }
        .page { margin: 0 !important; border: none !important; box-shadow: none !important; width: 100% !important; padding: 0 !important; }
        <?php endif; ?>

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

        /* Non-Printable Elements (Tombol Cetak) */
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
            background: #4A90E2; color: #fff; border: none; padding: 10px 20px; font-size: 16px;
            border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; margin: 0 5px;
        }
        .btn-warning { background: #f39c12; }
        
        /* Kop Surat Custom */
        .kop-surat {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 8px solid #1c3687; /* Garis tebal biru tua */
            padding-bottom: 5px;
            margin-bottom: 25px;
            position: relative;
        }
        .kop-surat::after {
            content: '';
            position: absolute;
            bottom: -15px; /* Sesuaikan jarak pita biru muda dr garis tebal */
            left: 0;
            width: 100%;
            height: 10px; /* Lebar pita biru muda */
            background-color: #688cc2;
            display: none; /* Matikan jika tak perlu */
        }
        .kop-logo {
            width: 120px;
            height: auto;
            margin-right: 15px;
        }
        .kop-teks {
            text-align: center;
            flex-grow: 1;
            padding: 0 10px;
            color: #000;
        }
        .kop-teks h1 { font-size: 26px; font-weight: 900; margin: 0; font-family: Arial, sans-serif; letter-spacing: 1px; }
        .kop-teks h2 { font-size: 34px; font-weight: 900; margin: 5px 0; font-family: 'Times New Roman', Times, serif; color: #1c3687; letter-spacing: 4px;}
        .kop-teks h4 { font-size: 16px; font-weight: bold; margin: 5px 0 0 0; color: #000; }
        .kop-teks .kop-alamat {
            background-color: #1c3687;
            color: white;
            padding: 4px 10px;
            font-size: 11px;
            margin-top: 5px;
            border-top-right-radius: 15px;
            border-bottom-right-radius: 15px;
            display: inline-block;
            font-family: Arial, sans-serif;
            font-weight: bold;
        }
        
        .kop-extra { 
            width: 130px; 
            text-align: right; 
            font-family: Arial, sans-serif; 
            font-size: 10px;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
        }
        .kop-extra img { width: 80px; height: 80px; margin-bottom: 2px;}
        .kop-extra .contact-item { display: flex; align-items: center; justify-content: flex-end; gap: 5px; font-weight: bold;}
        .kop-extra .contact-item i { font-size: 12px; }
        .kop-extra .contact-item.wa i { color: #25D366; }
        .kop-extra .contact-item.email i { color: #EA4335; }

        /* Meta Surat (Nomor, Hal, Tujuan) */
        .meta-surat { width: 100%; margin-bottom: 5px; line-height: 1.3; }
        .meta-surat td { vertical-align: top; }
        .col-label { width: 75px; }
        .col-titik { width: 15px; text-align: center; }

        /* Isi Surat */
        .isi-surat {
            text-align: justify;
            margin-bottom: 10px;
        }
        .indent { text-indent: 40px; margin-top: 5px; }
        
        /* Waktu Pelaksanaan Table */
        .waktu-pelaksanaan { width: 100%; margin-top: 10px; margin-bottom: 10px; border-collapse: collapse; }
        .waktu-pelaksanaan td { vertical-align: top; padding: 4px 10px; border: none; }
        .waktu-pelaksanaan td:first-child { padding-left: 0; }
        
        /* TTD Area */
        .ttd-area { width: 100%; margin-top: 15px; text-align: center; }
        .ttd-area .ttd-title { font-weight: bold; margin-bottom: 5px; }
        .ttd-table { width: 100%; margin-bottom: 5px; border-collapse: collapse; border: none !important; }
        .ttd-table td { width: 50%; vertical-align: top; padding-bottom: 5px; border: none !important; }
        .ttd-name { font-weight: bold; text-decoration: underline; margin-top: 55px; }
        .ttd-jabatan { font-size: 14px; }

        @page {
            size: A4 portrait;
            margin: 0;
        }

        @media print {
            body { background: white; margin: 0; padding: 0; -webkit-print-color-adjust: exact; }
            .page { 
                margin: 0 !important; 
                padding: 10mm 15mm; 
                border: none !important; 
                border-radius: 0 !important; 
                width: 210mm; 
                min-height: 295mm; /* Mengurangi toleransi PDF driver */
                box-shadow: none !important; 
                outline: none !important;
                background: white !important; 
                page-break-after: always; 
            }
            * { 
                box-shadow: none !important; 
                outline: none !important; 
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
            img { 
                border-style: none !important; 
                border: 0 !important; 
                outline: none !important; 
            }
            table {
                border-collapse: collapse;
            }
            .lampiran-table, .lampiran-table th, .lampiran-table td {
                border: 1px solid #000 !important;
                border-color: #000 !important;
            }
            .pdf-page-canvas { border: none !important; }
            .page:last-of-type {
                page-break-after: avoid !important;
            }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    <?php if (!isset($_GET['bulk'])): ?>
    <div class="no-print">
        <button onclick="safePrint()" class="btn"><i class="fas fa-print"></i> Cetak Dokumen</button>
        <button onclick="exportWord()" class="btn" style="background:#27ae60;"><i class="fas fa-file-word"></i> Download Word</button>
        <a href="arsip-surat.php" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Kembali ke Arsip</a>
    </div>
    <?php endif; ?>

    <div class="page">
        <!-- 1. KOP SURAT -->
        <?php 
        $kop_path = rtrim(UPLOAD_PATH, '/\\') . '/kop_surat.png';
        if (file_exists($kop_path)): 
            $kop_url = baseUrl('uploads/kop_surat.png') . '?v=' . filemtime($kop_path);
        ?>
            <div style="margin: -10mm -15mm -5px -15mm; text-align: center;">
                <img src="<?php echo htmlspecialchars($kop_url); ?>" style="width:100%; height:auto; display:block;" alt="Kop Surat">
            </div>
        <?php else: ?>
            <div class="kop-surat">
                <img src="<?php echo assetUrl('images/favicon/android-chrome-192x192.png'); ?>" class="kop-logo" alt="Logo">
                <div class="kop-teks">
                    <h1>BADAN EKSEKUTIF MAHASISWA</h1>
                    <h2>INSTBUNAS</h2>
                    <h4>SK No. 610/VIII/SK-BEM/INSTBUNAS/2024</h4>
                    <div class="kop-alamat">Jl. Siliwangi No. 121 (Jl. Raya Kadipaten - Majalengka) Heuleut - Kadipaten - Majalengka</div>
                </div>
                <div class="kop-extra">
                    <!-- QR Placeholder -->
                    <div style="width: 80px; height: 80px; background: white; border: 1px solid #000; display:flex; align-items:center; justify-content:center; padding:2px;">
                        <i class="fas fa-qrcode" style="font-size: 60px;"></i>
                    </div>
                    <div class="contact-item wa">
                        <i class="fab fa-whatsapp"></i> <span>083869304199</span>
                    </div>
                    <div class="contact-item email">
                        <i class="fas fa-envelope"></i> <span>beminstbunas@gmail.com</span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- 2. META SURAT -->
        <!-- 
            Kolom ke-4 (kanan): width:1% + nowrap pada baris titi mangsa.
            Ini memaksa kolom menyusut PAS selebar teks titi mangsa.
            Karena kolom pas selebar teks, maka text-align:left pun sudah rata kanan ke margin.
            Tujuan di baris bawah memakai white-space:normal agar wrap di dalam 
            lebar yang sama, sehingga tepi kiri tujuan = tepi kiri titi mangsa.
        -->
        <table class="meta-surat" style="width: 100%; border-collapse: collapse;">
            <tr>
                <td class="col-label" style="width: 75px;">Nomor</td>
                <td class="col-titik" style="width: 15px;">:</td>
                <td style="vertical-align: top;"><?php echo htmlspecialchars($surat['nomor_surat']); ?></td>
                <td style="width: 1%; white-space: nowrap; text-align: left; vertical-align: top;">
                    <?php echo htmlspecialchars(convertBulanKeIndonesia($surat['tempat_tanggal'])); ?>
                </td>
            </tr>
            <tr>
                <td class="col-label">Lampiran</td>
                <td class="col-titik">:</td>
                <td style="vertical-align: top;">
                    <?php 
                    $cnt_pdf = !empty($konten['lampiran_files']) ? count($konten['lampiran_files']) : 0;
                    $cnt_int = !empty($konten['lampiran_internal_ids']) ? count($konten['lampiran_internal_ids']) : 0;
                    $cnt_rundown = !empty($konten['rundown_internal_ids']) ? count($konten['rundown_internal_ids']) : 0;
                    $jml_lampiran = $cnt_pdf + $cnt_int + $cnt_rundown;
                    echo $jml_lampiran > 0 ? $jml_lampiran : '-';
                    ?>
                </td>
                <td></td>
            </tr>
            <tr>
                <td class="col-label" style="vertical-align: top;">Perihal</td>
                <td class="col-titik" style="vertical-align: top;">:</td>
                <td style="vertical-align: top;">
                    <div style="font-weight: bold; text-decoration: underline; line-height: 1.4;">
                        <?php echo htmlspecialchars($surat['perihal']); ?>
                    </div>
                </td>
                <td></td>
            </tr>
            <tr>
                <td colspan="3"></td>
                <td style="vertical-align: top; padding-top: 15px; white-space: normal; text-align: left;">
                    Yth,<br>
                    <b><?php echo nl2br(htmlspecialchars($surat['tujuan'])); ?></b><br>
                    Di Tempat
                </td>
            </tr>
        </table>

        <!-- 3. ISI SURAT -->
        <div class="isi-surat">
            <p><b><i>Assalamu'alaikum Wr. Wb.</i></b></p>
            
            <p class="indent">
                Puji syukur kita panjatkan kehadirat Allah SWT karena atas rahmat hidayah-Nya kita masih diberikan kesehatan dan selalu mendapatkan perlindungannya.
                <?php
                // Deteksi mode paragraf pembuka
                $nama_keg  = trim($konten['nama_kegiatan'] ?? '');
                $tema_keg  = trim($konten['tema'] ?? '');
                $custom    = trim($konten['tema_kegiatan'] ?? '');

                // Ambil tahun dari nomor surat (bagian terakhir: 001/L/BEMCUP/BEM/IV/2026)
                $parts_nomor = explode('/', $surat['nomor_surat']);
                $tahun_surat = end($parts_nomor) ?: date('Y');

                if (!empty($nama_keg)) {
                    // Mode template: generate dari nama_kegiatan + tema
                    $pembuka = 'Sehubungan akan diadakannya kegiatan <b>'
                        . $nama_keg . '</b> Tahun ' . htmlspecialchars($tahun_surat)
                        . (!empty($tema_keg) ? ' dengan tema "<b>' . $tema_keg . '</b>"' : '')
                        . ' yang akan dilaksanakan pada :';
                } elseif (!empty($custom)) {
                    // Mode custom
                    $pembuka = strip_tags($custom, '<b><strong><i><em><u>');
                } else {
                    $pembuka = '';
                }
                echo $pembuka;
                ?>
            </p>

            <table class="waktu-pelaksanaan">
                <tr>
                    <td style="width: 120px;">Hari, tanggal</td>
                    <td style="width: 15px;">:</td>
                    <td><?php echo htmlspecialchars($konten['pelaksanaan_hari_tanggal'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td>Waktu</td>
                    <td>:</td>
                    <td><?php echo htmlspecialchars($konten['pelaksanaan_waktu'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td>Tempat</td>
                    <td>:</td>
                    <td><?php echo htmlspecialchars($konten['pelaksanaan_tempat'] ?? ''); ?></td>
                </tr>
            </table>

            <?php
            // Paragraf Permohonan: generate dinamis dengan akhiran cerdas
            // Gabungkan semua baris tujuan dengan spasi agar tidak ada kata yang terlewat
            $tujuan_baris_pertama = trim(str_replace(["\r\n", "\r", "\n"], ' ', $surat['tujuan']));
            $konteks_text         = trim($konten['konteks'] ?? '');
            
            // Tentukan "buntut" kalimat (suffix) secara pintar
            if (!empty($konteks_text)) {
                // Konteks menggantikan kalimat akhiran sepenuhnya
                $suffix = ' ' . $konteks_text . '.';
            } else {
                // Suffix otomatis berdasarkan Perihal
                $perihal_lower = strtolower($surat['perihal']);
                if (strpos($perihal_lower, 'undangan') !== false) {
                    $suffix = ' agar dapat menghadiri kegiatan tersebut.';
                } else if (strpos($perihal_lower, 'peminjaman') !== false || strpos($perihal_lower, 'permohonan tempat') !== false) {
                    $suffix = ' untuk dapat menggunakan fasilitas tersebut.';
                } else if (strpos($perihal_lower, 'delegasi') !== false || strpos($perihal_lower, 'utusan') !== false) {
                    $suffix = ' untuk mendelegasikan perwakilannya pada kegiatan tersebut.';
                } else if (strpos($perihal_lower, 'pemberitahuan') !== false) {
                    $suffix = ' terkait kegiatan tersebut.';
                } else {
                    $suffix = ' demi mendukung terselenggaranya acara tersebut.';
                }
            }

            $sapaan = !empty($konten['sapaan_tujuan']) ? htmlspecialchars($konten['sapaan_tujuan']) . ' ' : '';
            
            // Hindari redundansi untuk berbagai jenis surat di paragraf pertama
            $perihal_paragraf_1 = mb_strtolower($surat['perihal']);
            
            $suffix_word = '';
            if (strpos($perihal_paragraf_1, 'podcast') !== false) {
                $suffix_word = ' podcast';
            } elseif (strpos($perihal_paragraf_1, 'poadcast') !== false) {
                $suffix_word = ' poadcast';
            }

            if (strpos($perihal_paragraf_1, 'pemberitahuan') !== false) {
                if (strpos($perihal_paragraf_1, 'kegiatan') !== false) {
                    $perihal_paragraf_1 = 'pemberitahuan kegiatan' . $suffix_word;
                } else {
                    $perihal_paragraf_1 = 'pemberitahuan' . $suffix_word;
                }
            } elseif (strpos($perihal_paragraf_1, 'undangan') !== false) {
                $perihal_paragraf_1 = 'undangan' . $suffix_word;
            } elseif (strpos($perihal_paragraf_1, 'delegasi') !== false) {
                $perihal_paragraf_1 = 'delegasi' . $suffix_word;
            } elseif (strpos($perihal_paragraf_1, 'utusan') !== false) {
                $perihal_paragraf_1 = 'utusan' . $suffix_word;
            } elseif (strpos($perihal_paragraf_1, 'peminjaman') !== false) {
                $perihal_paragraf_1 = 'permohonan peminjaman' . $suffix_word;
            } elseif (strpos($perihal_paragraf_1, 'permohonan') !== false) {
                $perihal_paragraf_1 = 'permohonan' . $suffix_word;
            }

            $paragraf_permohonan  = 'Dengan ini kami menyampaikan '
                . htmlspecialchars($perihal_paragraf_1)
                . ' kepada '
                . $sapaan
                . htmlspecialchars($tujuan_baris_pertama)
                . $suffix;
            ?>
            <p class="indent"><?php echo $paragraf_permohonan; ?></p>
            
            <?php
            // Paragraf Penutup: dinamis (mengikuti perihal)
            $perihal_penutup = mb_strtolower($surat['perihal']);
            $perihal_penutup = str_replace(['podcast', 'poadcast'], '', $perihal_penutup);
            $perihal_penutup = preg_replace('/\s+/', ' ', trim($perihal_penutup));
            $paragraf_penutup = 'Demikian surat ' . $perihal_penutup . ' ini kami sampaikan, atas perhatian dan kerjasamanya kami ucapkan terimakasih.';
            ?>
            <p class="indent"><?php echo htmlspecialchars($paragraf_penutup); ?></p>
            
            <p style="margin-top: 15px;"><b><i>Wassalamu'alaikum Wr. Wb.</i></b></p>
        </div>

        <!-- 4. TANDA TANGAN -->
        <div class="ttd-area">
            <?php 
            $kode_keg = "";
            $parts = explode('/', $surat['nomor_surat']);
            if (isset($parts[2])) $kode_keg = $parts[2];
            // Prioritas: label_panitia custom > nama_kegiatan > kode_kegiatan
            if (!empty($konten['label_panitia'])) {
                $nama_panitia = $konten['label_panitia']; // Sudah uppercase dari form
            } else {
                $nama_panitia = !empty($konten['nama_kegiatan']) ? $konten['nama_kegiatan'] : $kode_keg;
            }

            // Helper untuk deteksi TTD (Base64 vs File)
            function renderTTD($val) {
                if (empty($val)) return '';
                if (strpos($val, 'data:image') !== false) return htmlspecialchars($val);
                return uploadUrl($val);
            }
            ?>
            <div class="ttd-title">PANITIA PELAKSANA <?php echo strtoupper(htmlspecialchars($nama_panitia)); ?> <?php echo end($parts) ?: date('Y'); ?></div>
            
            <table class="ttd-table" style="margin-bottom: 5px;">
                <tr>
                    <td style="position:relative;">
                        Ketua Pelaksana
                        <?php if(!empty($pengaturan['cap_panitia_image']) && ($konten['use_cap_panitia'] ?? '1') === '1'): ?>
                            <img src="<?php echo uploadUrl($pengaturan['cap_panitia_image']); ?>" style="position:absolute; top:20px; left:100%; transform:translateX(-50%); max-width:190px; max-height:95px; mix-blend-mode:multiply; pointer-events:none; opacity:0.85; z-index:2;">
                        <?php endif; ?>
                        <?php if(!empty($konten['panitia_ketua_ttd'])): ?>
                            <img src="<?php echo renderTTD($konten['panitia_ketua_ttd']); ?>" style="position:absolute; bottom:15px; left:50%; transform:translateX(-50%); max-height:85px; mix-blend-mode:multiply; pointer-events:none;">
                        <?php endif; ?>
                        <div class="ttd-name"><?php echo htmlspecialchars($konten['panitia_ketua'] ?? ''); ?></div>
                    </td>
                    <td style="position:relative;">
                        Sekretaris
                        <?php if(!empty($konten['panitia_sekretaris_ttd'])): ?>
                            <img src="<?php echo renderTTD($konten['panitia_sekretaris_ttd']); ?>" style="position:absolute; bottom:15px; left:50%; transform:translateX(-50%); max-height:85px; mix-blend-mode:multiply; pointer-events:none;">
                        <?php endif; ?>
                        <div class="ttd-name"><?php echo htmlspecialchars($konten['panitia_sekretaris'] ?? ''); ?></div>
                    </td>
                </tr>
            </table>

            <div style="margin-top: -10px; margin-bottom: 10px;">Mengetahui,</div>

            <table class="ttd-table">
                <tr>
                    <td style="position:relative;">
                        a.n Rektor INSTBUNAS Majalengka<br>
                        <span class="ttd-jabatan"><?php echo htmlspecialchars($pengaturan['ttd_warek_jabatan'] ?? 'WAREK III Bid. Kemahasiswaan'); ?></span>
                        <?php if(!empty($pengaturan['cap_warek_image']) && ($konten['use_cap_warek'] ?? '1') === '1'): ?>
                            <img src="<?php echo uploadUrl($pengaturan['cap_warek_image']); ?>" style="position:absolute; bottom:0px; left:0; max-width:180px; max-height:130px; mix-blend-mode:multiply; pointer-events:none; opacity:0.85; z-index:2;">
                        <?php endif; ?>
                        <?php if(!empty($pengaturan['ttd_warek_image']) && ($konten['use_ttd_warek'] ?? '1') === '1'): ?>
                            <img src="<?php echo uploadUrl($pengaturan['ttd_warek_image']); ?>" style="position:absolute; bottom:20px; left:50%; transform:translateX(-50%); max-height:85px; mix-blend-mode:multiply; pointer-events:none;">
                        <?php endif; ?>
                        <div class="ttd-name"><?php echo htmlspecialchars($pengaturan['ttd_warek_name'] ?? 'II MUHAMAD MISBAH, S.Pd.I., SE., MM.'); ?></div>
                    </td>
                    <td style="position:relative;">
                        Ketua BEM<br>
                        <span class="ttd-jabatan"><?php echo htmlspecialchars(trim(str_ireplace('Ketua BEM', '', $pengaturan['ttd_presma_jabatan'] ?? 'INSTBUNAS Majalengka'))); ?></span>
                        <?php if(!empty($pengaturan['cap_presma_image']) && ($konten['use_cap_presma'] ?? '1') === '1'): ?>
                            <img src="<?php echo uploadUrl($pengaturan['cap_presma_image']); ?>" style="position:absolute; bottom:0px; left:10%; max-width:180px; max-height:130px; mix-blend-mode:multiply; pointer-events:none; opacity:0.85; z-index:2;">
                        <?php endif; ?>
                        <?php if(!empty($pengaturan['ttd_presma_image']) && ($konten['use_ttd_presma'] ?? '1') === '1'): ?>
                            <img src="<?php echo uploadUrl($pengaturan['ttd_presma_image']); ?>" style="position:absolute; bottom:20px; left:50%; transform:translateX(-50%); max-height:85px; mix-blend-mode:multiply; pointer-events:none;">
                        <?php endif; ?>
                        <div class="ttd-name"><?php echo htmlspecialchars($pengaturan['ttd_presma_name'] ?? $nama_ketua_bem); ?></div>
                    </td>
                </tr>
            </table>
        </div>

    </div>

    <!-- RENDER LAMPIRAN INTERNAL (DATA DARI DATABASE) -->
    <?php if (!empty($internal_data)): ?>
        <?php foreach($internal_data as $idx_int => $data_int): 
            $barang_list = json_decode($data_int['barang_json'], true) ?: [];
        ?>
        <div class="page" style="margin-top: 10mm; page-break-before: always; position: relative;">
            <div style="text-align: left; font-size: 12pt; margin-bottom: 20px; font-style: italic;">Lampiran <?php echo ($idx_int + 1); ?></div>
            
            <div style="text-align: center; margin-bottom: 30px;">
                <h1 style="font-size: 14pt; text-decoration: none; font-weight: bold; margin-bottom: 5px; text-transform: uppercase;">Daftar Barang & Tempat Yang Akan Dipinjam</h1>
                <h2 style="font-size: 14pt; font-weight: bold; text-transform: uppercase;">Pada Tanggal <?php echo htmlspecialchars($data_int['tanggal_kegiatan']); ?> Untuk Acara <?php echo htmlspecialchars($data_int['nama_acara']); ?> <?php echo htmlspecialchars($data_int['tahun']); ?></h2>
            </div>

            <?php 
                $list_barang = [];
                $list_tempat = [];
                foreach($barang_list as $b) {
                    $id = $b['id'] ?? '';
                    $qty = $b['qty'] ?? 0;
                    $nama_asli = $b['nama'] ?? 'Tidak dikenal';
                    
                    if (strpos($id, 'b_') === 0) {
                        $real_id = (int)str_replace('b_', '', $id);
                        $satuan = 'pcs';
                        $nama = $nama_asli;
                        
                        // Extract original name and unit from archived string as fallback
                        if (preg_match('/(.*)\s+\(([^)]+)\)$/', $nama_asli, $matches)) {
                            $nama = trim($matches[1]);
                            $satuan = trim($matches[2]);
                        }
                        
                        // Try to get LATEST name and unit from Master
                        $master = dbFetchOne("SELECT nama_barang, satuan FROM barang_master WHERE id = ?", [$real_id], "i");
                        if ($master) {
                            $nama = $master['nama_barang'];
                            $satuan = $master['satuan'];
                        }

                        $list_barang[] = ['nama' => $nama, 'qty' => $qty, 'satuan' => $satuan];
                    } else if (strpos($id, 't_') === 0) {
                        $real_id = (int)str_replace('t_', '', $id);
                        $nama = $nama_asli;
                        
                        // Try to get LATEST name from Master
                        $master = dbFetchOne("SELECT nama_tempat FROM tempat_master WHERE id = ?", [$real_id], "i");
                        if ($master) {
                            $nama = $master['nama_tempat'];
                        }
                        $list_tempat[] = ['nama' => $nama];
                    } else {
                        // fallback for old/malformed data
                        $satuan = 'pcs';
                        $nama = $nama_asli;
                        if (preg_match('/(.*)\s+\(([^)]+)\)$/', $nama_asli, $matches)) {
                            $nama = trim($matches[1]);
                            $satuan = trim($matches[2]);
                        }
                        $list_barang[] = ['nama' => $nama, 'qty' => $qty, 'satuan' => $satuan];
                    }
                }
            ?>
            
            <?php if (!empty($list_barang)): ?>
            <div style="page-break-inside: avoid;">
                <h3 style="margin-bottom:10px; font-size:12pt; text-transform:uppercase;">Daftar Barang</h3>
                <table class="lampiran-table" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="border: 1px solid #000; padding: 8px; text-align: center; width: 50px;">No.</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: left;">Nama Barang</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: left; width: 100px;">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list_barang as $b_idx => $item): ?>
                        <tr>
                            <td style="border: 1px solid #000; padding: 8px; text-align: center;"><?php echo $b_idx + 1; ?>.</td>
                            <td style="border: 1px solid #000; padding: 8px;"><?php echo htmlspecialchars($item['nama']); ?></td>
                            <td style="border: 1px solid #000; padding: 8px; text-align: left;"><?php echo $item['qty'] . ' (' . htmlspecialchars($item['satuan']) . ')'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if (!empty($list_tempat)): ?>
            <div style="page-break-inside: avoid;">
                <h3 style="margin-bottom:10px; font-size:12pt; text-transform:uppercase; margin-top:40px;">Daftar Tempat</h3>
                <table class="lampiran-table" style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                    <thead>
                        <tr style="background: #f5f5f5;">
                            <th style="border: 1px solid #000; padding: 8px; text-align: center; width: 50px;">No.</th>
                            <th style="border: 1px solid #000; padding: 8px; text-align: left;">Nama Tempat</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list_tempat as $b_idx => $item): ?>
                        <tr>
                            <td style="border: 1px solid #000; padding: 8px; text-align: center;"><?php echo $b_idx + 1; ?>.</td>
                            <td style="border: 1px solid #000; padding: 8px;"><?php echo htmlspecialchars($item['nama']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <p style="font-size: 11pt; margin-top: 20px;">Demikian daftar barang ini kami buat untuk dapat dipergunakan sebagaimana mestinya.</p>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- RENDER RUNDOWN INTERNAL (DATA DARI DATABASE) -->
    <?php if (!empty($rundown_data)): ?>
        <?php 
        $bulan_id_r = [
            'January' => 'Januari', 'February' => 'Februari', 'March' => 'Maret',
            'April' => 'April', 'May' => 'Mei', 'June' => 'Juni', 'July' => 'Juli',
            'August' => 'Agustus', 'September' => 'September', 'October' => 'Oktober',
            'November' => 'November', 'December' => 'Desember'
        ];
        $hari_id_r = [
            'Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jum\'at', 'Saturday' => 'Sabtu'
        ];
        
        $lampiran_offset = count($internal_data);
        foreach($rundown_data as $idx_rd => $data_rd):
            $rd_nama = $data_rd['nama_acara'];
            $rd_tahun = $data_rd['tahun'];
            $rd_tanggal_mulai = $data_rd['tanggal_mulai'];
            $rd_durasi = (int)$data_rd['durasi_hari'];
            $rd_json = json_decode($data_rd['rundown_json'], true) ?: [];
            
            $start_ts_r = strtotime($rd_tanggal_mulai);
            $end_ts_r = strtotime($rd_tanggal_mulai . " + " . ($rd_durasi - 1) . " days");
            
            // Build tanggal utama string
            $s_d = date('d', $start_ts_r);
            $s_m = $bulan_id_r[date('F', $start_ts_r)] ?? date('F', $start_ts_r);
            $s_y = date('Y', $start_ts_r);
            
            if ($rd_durasi > 1) {
                $e_d = date('d', $end_ts_r);
                $e_m = $bulan_id_r[date('F', $end_ts_r)] ?? date('F', $end_ts_r);
                $e_y = date('Y', $end_ts_r);
                if ($s_y !== $e_y) {
                    $tanggal_utama_r = "$s_d $s_m $s_y - $e_d $e_m $e_y";
                } elseif ($s_m !== $e_m) {
                    $tanggal_utama_r = "$s_d $s_m - $e_d $e_m $s_y";
                } else {
                    $tanggal_utama_r = "$s_d - $e_d $s_m $s_y";
                }
            } else {
                $tanggal_utama_r = "$s_d $s_m $s_y";
            }
            
            $total_days_r = count($rd_json);
        ?>
        <div class="page" style="margin-top: 10mm; page-break-before: always; position: relative;">
            <div style="text-align: left; font-size: 12pt; margin-bottom: 20px; font-style: italic;">Lampiran <?php echo ($lampiran_offset + $idx_rd + 1); ?></div>
            
            <div style="text-align: center; margin-bottom: 30px; text-transform: uppercase;">
                <h1 style="font-size: 14pt; font-weight: bold; margin: 3px 0;">SUSUNAN ACARA</h1>
                <h2 style="font-size: 14pt; font-weight: bold; margin: 3px 0;"><?php echo htmlspecialchars($rd_nama); ?></h2>
                <h3 style="font-size: 14pt; font-weight: bold; margin: 3px 0;">PERIODE <?php echo htmlspecialchars($rd_tahun); ?></h3>
                <p style="font-weight: bold; margin-top: 5px; font-size: 12pt;"><?php echo htmlspecialchars($tanggal_utama_r); ?></p>
            </div>

            <table style="width: 100%; border-collapse: collapse; margin-bottom: 30px; page-break-inside: auto;">
            <?php 
            $day_counter = 1;
            foreach($rd_json as $dayIdx => $dayData):
                $day_ts = strtotime($rd_tanggal_mulai . " + $dayIdx days");
                $hari_nama = $hari_id_r[date('l', $day_ts)] ?? date('l', $day_ts);
                $tgl_d = date('d', $day_ts);
                $tgl_m = $bulan_id_r[date('F', $day_ts)] ?? date('F', $day_ts);
                $tgl_y = date('Y', $day_ts);
                
                if ($total_days_r === 1) {
                    $judul_hari = strtoupper($rd_nama);
                } else {
                    $judul_hari = strtoupper($rd_nama) . ' - HARI KE-' . $day_counter;
                }
                
                $items = $dayData['items'] ?? [];
                // Skip if no items to avoid "nanggung" tables
                if (empty($items)) continue;
            ?>
                <thead>
                    <?php if ($day_counter > 1): ?>
                    <tr>
                        <td colspan="5" style="border: none; height: 30px;"></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <th colspan="5" style="background-color: #d9e2f3; font-weight: bold; font-size: 12pt; padding: 10px; border: 1px solid #000; text-align: center; -webkit-print-color-adjust: exact;">
                            <?php echo htmlspecialchars($judul_hari); ?><br><br>
                            <?php echo htmlspecialchars("$hari_nama, $tgl_d $tgl_m $tgl_y"); ?>
                        </th>
                    </tr>
                    <tr>
                        <th style="background-color: #bfbfbf; font-weight: bold; border: 1px solid #000; padding: 8px 12px; text-align: center; width: 5%; -webkit-print-color-adjust: exact;">NO</th>
                        <th style="background-color: #bfbfbf; font-weight: bold; border: 1px solid #000; padding: 8px 12px; text-align: center; width: 15%; -webkit-print-color-adjust: exact;">WAKTU</th>
                        <th style="background-color: #bfbfbf; font-weight: bold; border: 1px solid #000; padding: 8px 12px; text-align: center; width: 35%; -webkit-print-color-adjust: exact;">ACARA</th>
                        <th style="background-color: #bfbfbf; font-weight: bold; border: 1px solid #000; padding: 8px 12px; text-align: center; width: 30%; -webkit-print-color-adjust: exact;"><?php echo ($dayData['tipe_ket'] ?? 'ket') === 'ket' ? 'KETERANGAN' : 'TEMPAT'; ?></th>
                        <th style="background-color: #bfbfbf; font-weight: bold; border: 1px solid #000; padding: 8px 12px; text-align: center; width: 15%; -webkit-print-color-adjust: exact;">PENANGGUNG<br>JAWAB</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $num = 1;
                    for ($idx = 0; $idx < count($items); $idx++): 
                        $item = $items[$idx];
                        $is_par = !empty($item['is_parallel']);
                        
                        $rowspan = 1;
                        if (!$is_par) {
                            for ($j = $idx + 1; $j < count($items); $j++) {
                                if (!empty($items[$j]['is_parallel'])) {
                                    $rowspan++;
                                } else {
                                    break;
                                }
                            }
                        }
                    ?>
                        <tr style="page-break-inside: avoid;">
                            <?php if (!$is_par): ?>
                                <td style="border: 1px solid #000; padding: 8px 12px; text-align: center; vertical-align: middle;" <?php echo $rowspan > 1 ? 'rowspan="'.$rowspan.'"' : ''; ?>><?php echo $num++; ?>.</td>
                                <td style="border: 1px solid #000; padding: 8px 12px; text-align: center; vertical-align: middle; white-space: nowrap;" <?php echo $rowspan > 1 ? 'rowspan="'.$rowspan.'"' : ''; ?>><?php echo htmlspecialchars($item['waktu']); ?></td>
                            <?php endif; ?>
                            <td style="border: 1px solid #000; padding: 8px 12px; text-align: center; vertical-align: middle;"><?php echo nl2br(htmlspecialchars($item['acara'])); ?></td>
                            <td style="border: 1px solid #000; padding: 8px 12px; text-align: center; vertical-align: middle;"><?php echo htmlspecialchars($item['keterangan']); ?></td>
                            <td style="border: 1px solid #000; padding: 8px 12px; text-align: center; vertical-align: middle;"><?php echo htmlspecialchars($item['pj']); ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            <?php 
                $day_counter++;
            endforeach; 
            ?>
            </table>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Container untuk render Lampiran PDF (EXTERNAL) -->
    <div id="lampiran-render-container"></div>
    
    <!-- Indikator Loading Lampiran -->
    <div id="pdf-loading" class="no-print" style="display:none; text-align:center; padding:10px; color:#4facfe;">
        <i class="fas fa-spinner fa-spin"></i> Memproses lampiran PDF...
    </div>

    <!-- Script Print Viewport -->
    <!-- Script PDF.js & Print Logic -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

        async function renderLampiran() {
            const lampiranFiles = <?php echo json_encode($konten['lampiran_files'] ?? []); ?>;
            const container = document.getElementById('lampiran-render-container');
            const loader = document.getElementById('pdf-loading');
            
            if (!lampiranFiles || lampiranFiles.length === 0) {
                window.isLampiranReady = true;
                return;
            }

            loader.style.display = 'block';
            window.isLampiranReady = false;

            for (const fileUrl of lampiranFiles) {
                try {
                    // Gunakan path relatif agar lebih aman dari masalah CORS/Protokol
                    const fullUrl = '../uploads/' + fileUrl; 
                    const loadingTask = pdfjsLib.getDocument({
                        url: fullUrl,
                        withCredentials: true // Penting untuk beberapa hosting dengan security cookies
                    });
                    
                    const pdf = await loadingTask.promise;
                    
                    for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                        const page = await pdf.getPage(pageNum);
                        const viewport = page.getViewport({ scale: 1.5 }); // Turunkan skala sedikit jika terlalu berat
                        
                        const canvas = document.createElement('canvas');
                        const context = canvas.getContext('2d');
                        canvas.height = viewport.height;
                        canvas.width = viewport.width;
                        canvas.className = 'pdf-page-canvas';
                        
                        const pageWrapper = document.createElement('div');
                        pageWrapper.className = 'page';
                        pageWrapper.style.padding = '0';
                        pageWrapper.style.margin = '10mm auto';
                        pageWrapper.style.overflow = 'hidden';
                        pageWrapper.appendChild(canvas);
                        
                        container.appendChild(pageWrapper);

                        const renderContext = {
                            canvasContext: context,
                            viewport: viewport
                        };
                        await page.render(renderContext).promise;
                    }
                } catch (err) {
                    console.error('Gagal memuat PDF:', fileUrl, err);
                    const errorBox = document.createElement('div');
                    errorBox.style = "background:#fee; border:1px solid #fcc; color:#c33; padding:20px; margin:20px auto; width:210mm; border-radius:10px;";
                    errorBox.innerHTML = `<strong>Gagal Memuat Lampiran:</strong> ${fileUrl.split('/').pop()}<br><small>${err.message}</small>`;
                    container.appendChild(errorBox);
                }
            }
            loader.style.display = 'none';
            window.isLampiranReady = true;
        }

        // Fungsi Cetak yang lebih aman
        function safePrint() {
            if (!window.isLampiranReady) {
                alert('Mohon tunggu, lampiran sedang diproses...');
                return;
            }
            window.print();
        }

        // Inisialisasi
        window.addEventListener('load', renderLampiran);

        // Otomatis buka print jika ada parameter
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('print')) {
            window.addEventListener('load', async () => {
                await renderLampiran();
                window.print();
            });
        }

        // Export ke Word (.doc)
        function exportWord() {
            var clone = document.querySelector('.page').cloneNode(true);
            var css = `
                <style>
                    body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; }
                    table { border-collapse: collapse; }
                    .indent { text-indent: 40px; margin-top: 8px; }
                    .ttd-table td { width: 50%; vertical-align: top; }
                    .meta-surat { width: 100%; margin-bottom: 20px; line-height: 1.4; }
                    .meta-surat td { vertical-align: top; }
                    .col-label { width: 70px; }
                    .col-titik { width: 15px; text-align: center; }
                    .isi-surat { text-align: justify; }
                </style>
            `;
            var preHtml = "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><title>Surat Export</title>" + css + "</head><body>";
            var postHtml = "</body></html>";
            var html = preHtml + clone.outerHTML + postHtml;
            var blob = new Blob(['\ufeff', html], { type: 'application/msword' });
            var filename = '<?php echo addslashes($download_name); ?>' + '.doc';
            var url = URL.createObjectURL(blob);
            var downloadLink = document.createElement("a");
            document.body.appendChild(downloadLink);
            downloadLink.href = url;
            downloadLink.download = filename;
            downloadLink.click();
            document.body.removeChild(downloadLink);
        }
    </script>
    <style>
        @media print {
            .pdf-page-canvas {
                width: 100% !important;
                height: 100% !important;
                object-fit: contain;
                display: block;
            }
            .page {
                margin: 0 !important;
                box-shadow: none !important;
                border: none !important;
                page-break-after: always;
            }
            .no-print { display: none !important; }
        }
        .pdf-page-canvas {
            width: 100%;
            height: auto;
            display: block;
            background: white;
        }
    </style>
</body>
</html>
