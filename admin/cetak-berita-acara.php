<?php
// admin/cetak-berita-acara.php
header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: Wed, 11 Jan 1984 05:00:00 GMT");

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/config.php';

requireLogin();
requireSekretaris();

$id = (int)($_GET['id'] ?? 0);
$periode_id = getUserPeriode();

$ba = dbFetchOne("SELECT * FROM arsip_berita_acara WHERE id = ? AND periode_id = ?", [$id, $periode_id], "ii");

if (!$ba) {
    die("Berita Acara tidak ditemukan atau Anda tidak memiliki akses ke periode ini.");
}

$konten = json_decode($ba['konten_json'], true) ?: [];

// Ambil Pengaturan Tanda Tangan
$db_pengaturan = dbFetchAll("SELECT kunci, nilai FROM pengaturan");
$pengaturan = [];
foreach($db_pengaturan as $p) {
    $pengaturan[$p['kunci']] = $p['nilai'];
}

// Generate dynamic download name
$download_name = "BERITA_ACARA_" . strtoupper(str_replace(' ', '_', $ba['nama_kegiatan'])) . "_" . date('Y');

function format_paragraphs($text) {
    if (strpos($text, '<p>') !== false || strpos($text, '<br>') !== false) {
        return $text;
    }
    $paragraphs = explode("\n\n", str_replace("\r", "", $text));
    $html = '';
    foreach ($paragraphs as $p) {
        $p = trim($p);
        if ($p !== '') {
            $html .= '<p>' . nl2br(htmlspecialchars($p)) . '</p>';
        }
    }
    return $html;
}
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
            page-break-after: always;
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
            border-bottom: 8px solid #1c3687;
            padding-bottom: 5px;
            margin-bottom: 25px;
            position: relative;
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
        .kop-teks h1 { font-size: 24px; font-weight: 900; margin: 0; font-family: Arial, sans-serif; letter-spacing: 1px; }
        .kop-teks h2 { font-size: 32px; font-weight: 900; margin: 5px 0; font-family: Arial, sans-serif; color: #1c3687; letter-spacing: 4px;}
        .kop-teks h4 { font-size: 14px; font-weight: bold; margin: 5px 0 0 0; color: #000; font-family: Arial, sans-serif; }
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
        .kop-extra .contact-item { display: flex; align-items: center; justify-content: flex-end; gap: 5px; font-weight: bold;}
        .kop-extra .contact-item i { font-size: 12px; }
        .kop-extra .contact-item.wa i { color: #25D366; }
        .kop-extra .contact-item.email i { color: #EA4335; }

        /* Typography & Layout */
        .doc-title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            text-transform: uppercase;
            text-decoration: underline;
            margin-bottom: 5px;
        }
        .doc-number {
            text-align: center;
            font-size: 14px;
            margin-bottom: 25px;
        }
        .doc-body {
            text-align: justify;
            font-size: 16px;
            line-height: 1.5;
            margin-bottom: 20px;
        }
        .doc-body p {
            margin-bottom: 15px;
            text-indent: 40px;
        }
        .doc-body p.no-indent {
            text-indent: 0;
        }

        /* Lists */
        ol.list-kegiatan {
            margin-left: 40px;
            margin-bottom: 20px;
        }
        ol.list-kegiatan li {
            margin-bottom: 5px;
            padding-left: 5px;
        }

        /* Signatures block */
        .date-creation {
            text-align: right;
            margin-bottom: 15px;
            padding-right: 20px;
        }
        .sig-block {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
            border: none !important;
        }
        .sig-block td {
            border: none !important;
            vertical-align: top;
            width: 50%;
            text-align: center;
            padding: 10px 0;
            position: relative;
        }
        .sig-stamp {
            position: absolute;
            mix-blend-mode: multiply;
            pointer-events: none;
            opacity: 0.85;
            z-index: 1;
        }
        .sig-title {
            font-weight: normal;
            line-height: 1.3;
            margin-bottom: 60px;
        }
        .sig-name {
            font-weight: bold;
            text-decoration: underline;
        }
        .sig-image-wrap {
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: -50px 0 -5px 0;
        }
        .sig-image-wrap img {
            max-height: 100%;
            max-width: 150px;
            object-fit: contain;
        }
        .warek-sig-wrap {
            height: 160px;
            margin: -100px 0 -15px 0;
        }
        .warek-sig-wrap img {
            max-height: 100%;
            max-width: 320px;
        }

        /* Metadata Table Page 2 */
        .meta-table {
            width: 100%;
            margin-bottom: 25px;
            border-collapse: collapse;
            font-size: 16px;
            line-height: 1.5;
        }
        .meta-table td {
            padding: 0 10px 0 0;
            vertical-align: top;
            border: none;
            line-height: 1.5;
        }
        .meta-table td.label-col {
            width: 180px;
            font-weight: normal;
        }
        .meta-table td.colon-col {
            width: 20px;
        }

        .section-header {
            font-weight: bold;
            font-size: 16px;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        
        ol.section-list {
            margin-left: 30px;
            margin-bottom: 15px;
        }
        ol.section-list li {
            margin-bottom: 5px;
            text-align: justify;
        }

        /* Documentation grid */
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 20px;
        }
        .doc-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #fff;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 8px;
        }
        .doc-photo-wrap {
            width: 100%;
            height: 180px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            background: #f7f9fa;
            border: 1px solid #eee;
            margin-bottom: 10px;
        }
        .doc-photo-wrap img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }
        .doc-caption {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            font-family: 'Times New Roman', Times, serif;
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
                min-height: 297mm;
                box-shadow: none !important; 
                outline: none !important;
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
        <button onclick="exportWord()" class="btn" style="background:#27ae60;"><i class="fas fa-file-word"></i> Download Word</button>
        <a href="arsip-berita-acara.php" class="btn btn-warning"><i class="fas fa-arrow-left"></i> Kembali ke Arsip</a>
    </div>

    <!-- HELPER KOP SURAT RENDERER -->
    <?php
    function renderKop() {
        $kop_path = rtrim(UPLOAD_PATH, '/\\') . '/kop_surat.png';
        if (file_exists($kop_path)): 
            $kop_url = baseUrl('uploads/kop_surat.png') . '?v=' . filemtime($kop_path);
        ?>
            <div style="margin: -10mm -15mm 10px -15mm; text-align: center;">
                <img src="<?php echo htmlspecialchars($kop_url); ?>" style="width:100%; height:auto; display:block;" alt="Kop Surat">
            </div>
        <?php else: ?>
            <div class="kop-surat">
                <img src="<?php echo assetUrl('images/favicon/android-chrome-192x192.png'); ?>" class="kop-logo" alt="Logo">
                <div class="kop-teks">
                    <h1>BADAN EKSEKUTIF MAHASISWA</h1>
                    <h2>I N S T B U N A S</h2>
                    <h4>SK No. 547/SK-BEM/INSTBUNAS/VII/2025</h4>
                    <div class="kop-alamat">JL. Siliwangi No. 121 (Jl. Raya Kadipaten - Majalengka) Heuleut - Kadipaten - Majalengka</div>
                </div>
                <div class="kop-extra">
                    <div style="width: 80px; height: 80px; background: white; border: 1px solid #000; display:flex; align-items:center; justify-content:center; padding:2px; margin-bottom:2px;">
                        <i class="fas fa-qrcode" style="font-size: 60px;"></i>
                    </div>
                    <div class="contact-item wa">
                        <i class="fab fa-whatsapp"></i> <span>083865855545</span>
                    </div>
                    <div class="contact-item email">
                        <i class="fas fa-envelope"></i> <span>beminstbunasmajalengka@gmail.com</span>
                    </div>
                </div>
            </div>
        <?php endif;
    }
    ?>

    <!-- PAGE 1: BERITA ACARA KEGIATAN -->
    <div class="page">
        <?php renderKop(); ?>
        
        <div class="doc-title">BERITA ACARA KEGIATAN</div>
        <div class="doc-number">Nomor: <?php echo htmlspecialchars($ba['nomor_berita']); ?></div>

        <div class="doc-body">
            <p>
                Pada hari ini <?php echo htmlspecialchars($konten['hari_kegiatan'] ?? ''); ?> 
                tanggal <?php echo htmlspecialchars($ba['tanggal_kegiatan'] ?? ''); ?>, 
                bertempat di <?php echo htmlspecialchars($ba['tempat'] ?? ''); ?>, 
                telah dilaksanakan kegiatan <?php echo htmlspecialchars($ba['nama_kegiatan'] ?? ''); ?> 
                dengan tema <i>"<?php echo htmlspecialchars($konten['tema_kegiatan'] ?? ''); ?>"</i>, 
                yang berlangsung dari pukul <?php echo htmlspecialchars($konten['waktu_mulai'] ?? ''); ?> WIB hingga selesai. 
                Kegiatan ini diselenggarakan oleh Badan Eksekutif Mahasiswa (BEM) bekerja sama dengan pihak rektorat Institut Budi Utomo Nasional Majalengka. 
                Adapun rincian kegiatan yang telah dilaksanakan adalah:
            </p>

            <ol class="list-kegiatan">
                <?php 
                $rincian = $konten['rincian_kegiatan'] ?? [];
                foreach ($rincian as $item): 
                ?>
                    <li><?php echo htmlspecialchars($item); ?></li>
                <?php endforeach; ?>
            </ol>

            <p class="no-indent">
                Kegiatan berjalan dengan lancar dan mendapat respon positif dari seluruh civitas akademika INSTBUNAS Majalengka serta Mahasiswa INSTBUNAS Majalengka.
            </p>

            <p class="no-indent">
                Demikian berita acara ini dibuat dengan sebenarnya untuk digunakan sebagaimana mestinya.
            </p>
        </div>

        <div class="date-creation">
            <?php echo htmlspecialchars($konten['tempat_pembuatan'] ?? 'Majalengka'); ?>, <?php echo htmlspecialchars($konten['tanggal_pembuatan'] ?? ''); ?>
        </div>

        <!-- Yang Membuat Berita Acara (Signatures) -->
        <div style="text-align: center; font-size: 16px; margin-bottom: 10px;">Yang Membuat Berita Acara,</div>
        
        <table class="sig-block">
            <tr>
                <td>
                    <div class="sig-title">
                        Ketua BEM<br>
                        INSTBUNAS Majalengka
                    </div>
                    
                    <?php if (!empty($pengaturan['cap_presma_image']) && ($konten['use_cap_presma'] ?? '1') === '1'): ?>
                        <img src="<?php echo uploadUrl($pengaturan['cap_presma_image']); ?>" class="sig-stamp" style="bottom: 30px; left: 2%; max-width: 140px; max-height: 100px;">
                    <?php endif; ?>
                    
                    <?php if (($konten['use_ttd_presma'] ?? '1') === '1'): ?>
                        <?php if (!empty($pengaturan['ttd_presma_image'])): ?>
                            <div class="sig-image-wrap">
                                <img src="<?php echo uploadUrl($pengaturan['ttd_presma_image']); ?>" alt="TTD Ketua BEM">
                            </div>
                        <?php elseif (!empty($konten['ketua_bem_ttd'])): ?>
                            <div class="sig-image-wrap">
                                <img src="<?php echo uploadUrl($konten['ketua_bem_ttd']); ?>" alt="TTD Ketua BEM">
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <div class="sig-name"><?php echo htmlspecialchars($konten['ketua_bem_nama'] ?: ($pengaturan['ttd_presma_name'] ?? 'Dede Anggi Muhyidin')); ?></div>
                </td>
                <td>
                    <div class="sig-title">
                        Sekretaris BEM<br>
                        INSTBUNAS Majalengka
                    </div>
                    
                    <?php if (($konten['use_ttd_sekretaris'] ?? '1') === '1'): ?>
                        <?php if (!empty($pengaturan['ttd_sekretaris_image'])): ?>
                            <div class="sig-image-wrap">
                                <img src="<?php echo uploadUrl($pengaturan['ttd_sekretaris_image']); ?>" alt="TTD Sekretaris BEM">
                            </div>
                        <?php elseif (!empty($konten['sekretaris_bem_ttd'])): ?>
                            <div class="sig-image-wrap">
                                <img src="<?php echo uploadUrl($konten['sekretaris_bem_ttd']); ?>" alt="TTD Sekretaris BEM">
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <div class="sig-name"><?php echo htmlspecialchars($konten['sekretaris_bem_nama'] ?: ($pengaturan['ttd_sekretaris_name'] ?? 'Mela Agustin')); ?></div>
                </td>
            </tr>
            <tr>
                <td colspan="2" style="text-align: center; padding-top: 20px;">
                    <div class="sig-title" style="margin-bottom: 60px;">
                        Mengetahui,<br>
                        a.n. Rektor INSTBUNAS Majalengka<br>
                        WAREK III Bid. Kemahasiswaan
                    </div>
                    
                    <?php if (!empty($pengaturan['cap_warek_image']) && ($konten['use_cap_warek'] ?? '1') === '1'): ?>
                        <img src="<?php echo uploadUrl($pengaturan['cap_warek_image']); ?>" class="sig-stamp" style="bottom: 60px; left: 25%; max-width: 240px; max-height: 180px;">
                    <?php endif; ?>
                    
                    <?php if (($konten['use_ttd_warek'] ?? '1') === '1'): ?>
                        <?php if (!empty($pengaturan['ttd_warek_image'])): ?>
                            <div class="sig-image-wrap warek-sig-wrap">
                                <img src="<?php echo uploadUrl($pengaturan['ttd_warek_image']); ?>" alt="TTD & Cap Warek III">
                            </div>
                        <?php elseif (!empty($konten['warek_ttd'])): ?>
                            <div class="sig-image-wrap warek-sig-wrap">
                                <img src="<?php echo uploadUrl($konten['warek_ttd']); ?>" alt="TTD & Cap Warek III">
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <div class="sig-name"><?php echo htmlspecialchars($konten['warek_nama'] ?: ($pengaturan['ttd_warek_name'] ?? 'Ir Muhammad Misbah, S.Pd.I., SE., MM.')); ?></div>
                    <div style="font-size: 14px;">NUPTK. <?php echo htmlspecialchars($konten['warek_nuptk'] ?? '7756762662200002'); ?></div>
                </td>
            </tr>
        </table>
    </div>

    <!-- PAGE 2: LAPORAN KEGIATAN -->
    <div class="page">
        <?php renderKop(); ?>

        <div class="doc-title" style="text-decoration:none; margin-bottom: 30px;">LAPORAN KEGIATAN <?php echo strtoupper(htmlspecialchars($ba['nama_kegiatan'])); ?></div>

        <table class="meta-table">
            <tr>
                <td class="label-col">Nama Kegiatan</td>
                <td class="colon-col">:</td>
                <td><?php echo htmlspecialchars($ba['nama_kegiatan']); ?></td>
            </tr>
            <?php if (!empty($konten['program_kerja'])): ?>
            <tr>
                <td class="label-col">Program Kerja</td>
                <td class="colon-col">:</td>
                <td><?php echo htmlspecialchars($konten['program_kerja']); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="label-col">Tema Kegiatan</td>
                <td class="colon-col">:</td>
                <td>"<?php echo htmlspecialchars($konten['tema_kegiatan'] ?? ''); ?>"</td>
            </tr>
            <tr>
                <td class="label-col">Hari, Tanggal</td>
                <td class="colon-col">:</td>
                <td><?php echo htmlspecialchars($konten['hari_kegiatan'] ?? ''); ?>, <?php echo htmlspecialchars($ba['tanggal_kegiatan']); ?></td>
            </tr>
            <tr>
                <td class="label-col">Waktu</td>
                <td class="colon-col">:</td>
                <td><?php echo htmlspecialchars($ba['waktu']); ?></td>
            </tr>
            <tr>
                <td class="label-col">Pelaksana Kegiatan</td>
                <td class="colon-col">:</td>
                <td><?php echo htmlspecialchars($konten['pelaksana_kegiatan'] ?? ''); ?></td>
            </tr>
            <?php if (!empty($konten['penanggung_jawab'])): ?>
            <tr>
                <td class="label-col">Penanggung Jawab</td>
                <td class="colon-col">:</td>
                <td><?php echo htmlspecialchars($konten['penanggung_jawab']); ?></td>
            </tr>
            <?php endif; ?>
        </table>

        <!-- A. TUJUAN -->
        <div class="section-header">A. Tujuan :</div>
        <ol class="section-list">
            <?php 
            $tujuan = $konten['tujuan'] ?? [];
            foreach ($tujuan as $item): 
            ?>
                <li><?php echo htmlspecialchars($item); ?></li>
            <?php endforeach; ?>
        </ol>

        <!-- B. MANFAAT -->
        <div class="section-header">B. Manfaat :</div>
        <ol class="section-list">
            <?php 
            $manfaat = $konten['manfaat'] ?? [];
            foreach ($manfaat as $item): 
            ?>
                <li><?php echo htmlspecialchars($item); ?></li>
            <?php endforeach; ?>
        </ol>

        <!-- C. BENTUK KEGIATAN -->
        <div class="section-header">C. Bentuk Kegiatan :</div>
        <div class="doc-body" style="text-indent:0;">
            <?php echo format_paragraphs($konten['bentuk_kegiatan'] ?? ''); ?>
            <p style="text-indent: 0; margin-top:20px;">
                Demikian Laporan Kegiatan ini kami buat, semoga dapat dipergunakan sebagaimana mestinya.
            </p>
        </div>
    </div>

    <!-- PAGE 3: DOKUMENTASI -->
    <div class="page">
        <?php renderKop(); ?>

        <div class="section-header" style="font-size: 18px; margin-bottom: 20px;">D. Dokumentasi :</div>

        <?php if (!empty($konten['dokumentasi'])): ?>
            <div class="doc-grid">
                <?php foreach ($konten['dokumentasi'] as $doc): ?>
                    <div class="doc-item">
                        <div class="doc-photo-wrap">
                            <img src="<?php echo uploadUrl($doc['image']); ?>" alt="Foto Kegiatan">
                        </div>
                        <div class="doc-caption"><?php echo htmlspecialchars($doc['caption']); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="font-style: italic; color: #555; text-align: center; margin-top: 30px;">
                (tidak ada dokumentasi dalam kegiatan ini)
            </p>
        <?php endif; ?>
    </div>

    <script>
    function exportWord() {
        // Clone all page contents
        const pages = document.querySelectorAll('.page');
        let pagesHtml = '';
        
        pages.forEach((page, idx) => {
            let clone = page.cloneNode(true);
            
            // Adjust any dimensions or paths for Word compatibility
            const images = clone.querySelectorAll('img');
            images.forEach(img => {
                // Ensure absolute url for images
                const src = img.getAttribute('src');
                if (src && !src.startsWith('http')) {
                    img.setAttribute('src', window.location.origin + '/' + src.replace(/^\/+/, ''));
                }
                img.style.maxWidth = '100%';
            });
            
            pagesHtml += clone.outerHTML;
            if (idx < pages.length - 1) {
                // Add page break for Word
                pagesHtml += '<br clear="all" style="page-break-before:always" />';
            }
        });

        const css = `
            <style>
                body { font-family: 'Times New Roman', Times, serif; font-size: 12pt; color: #000; }
                table { border-collapse: collapse; width: 100%; }
                .kop-surat { display: table; width: 100%; border-bottom: 4pt solid #1c3687; padding-bottom: 10px; margin-bottom: 20px; }
                .kop-logo { width: 100px; }
                .kop-teks { text-align: center; }
                .kop-teks h1 { font-family: Arial, sans-serif; font-size: 18pt; margin: 0; font-weight: bold; }
                .kop-teks h2 { font-family: Arial, sans-serif; font-size: 24pt; color: #1c3687; margin: 0; font-weight: bold; }
                .kop-teks h4 { font-family: Arial, sans-serif; font-size: 11pt; margin: 0; }
                .kop-alamat { background-color: #1c3687; color: white; padding: 4px; font-size: 9pt; font-family: Arial, sans-serif; font-weight: bold; }
                .doc-title { text-align: center; font-size: 14pt; font-weight: bold; text-decoration: underline; text-transform: uppercase; margin-bottom: 5px; }
                .doc-number { text-align: center; font-size: 11pt; margin-bottom: 20px; }
                .doc-body { text-align: justify; font-size: 12pt; line-height: 1.5; }
                .doc-body p { text-indent: 0.5in; margin-bottom: 10pt; }
                ol.list-kegiatan { margin-left: 0.5in; }
                ol.section-list { margin-left: 0.5in; }
                ol.section-list li { text-align: justify; margin-bottom: 5pt; }
                .sig-block { width: 100%; margin-top: 20px; }
                .sig-block td { width: 50%; text-align: center; vertical-align: top; position: relative; }
                .sig-stamp { position: absolute; mix-blend-mode: multiply; pointer-events: none; opacity: 0.85; z-index: 1; }
                .sig-title { font-weight: normal; margin-bottom: 40pt; }
                .sig-name { font-weight: bold; text-decoration: underline; }
                .sig-image-wrap { height: 70px; margin-top: -50px; margin-bottom: -5px; }
                .sig-image-wrap img { max-height: 70px; max-width: 150px; }
                .warek-sig-wrap { height: 160px; margin-top: -100px; margin-bottom: -15px; }
                .warek-sig-wrap img { max-height: 160px; max-width: 320px; }
                .meta-table { width: 100%; margin-bottom: 20px; line-height: 1.5; }
                .meta-table td { padding: 0pt 4pt 0pt 0pt; vertical-align: top; line-height: 1.5; }
                .meta-table td.label-col { font-weight: normal; }
                .section-header { font-weight: bold; font-size: 12pt; margin-top: 15pt; }
                .doc-grid { width: 100%; }
                .doc-item { width: 45%; display: inline-block; margin: 2%; text-align: center; vertical-align: top; }
                .doc-photo-wrap img { max-width: 100%; height: auto; }
                .doc-caption { font-size: 10pt; font-weight: bold; margin-top: 5pt; }
            </style>
        `;
        
        const preHtml = "<html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'><head><meta charset='utf-8'><title>Berita Acara Export</title>" + css + "</head><body>";
        const postHtml = "</body></html>";
        const html = preHtml + pagesHtml + postHtml;
        
        const blob = new Blob(['\ufeff', html], { type: 'application/msword' });
        const filename = '<?php echo addslashes($download_name); ?>' + '.doc';
        const url = URL.createObjectURL(blob);
        const downloadLink = document.createElement("a");
        document.body.appendChild(downloadLink);
        downloadLink.href = url;
        downloadLink.download = filename;
        downloadLink.click();
        document.body.removeChild(downloadLink);
    }
    </script>
</body>
</html>
