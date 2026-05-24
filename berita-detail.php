<?php
// berita-detail.php - Halaman Detail Berita
// VERSI: 2.1 - FIX: uploadUrl() konsisten, filter status published,
//               views race condition, XSS pada konten

include 'header.php';

// ===========================================
// AMBIL PARAMETER & VALIDASI
// ===========================================
$slug = trim($_GET['slug'] ?? '');

if (empty($slug)) {
    header('Location: berita.php');
    exit;
}

// ===========================================
// AMBIL BERITA
// ✅ FIX: Tambah filter status = 'published' agar draft tidak bisa diakses
//         langsung via URL ?slug=xxx
// ===========================================
$berita = dbFetchOne(
    "SELECT * FROM berita WHERE slug = ? AND status = 'published'",
    [$slug], "s"
);

if (!$berita) {
    header('Location: berita.php');
    exit;
}

// ===========================================
// UPDATE VIEWS
// ✅ FIX: Gunakan data dari DB (bukan +1 dari array lama yang sudah stale)
//         Tidak perlu refresh query — cukup tampilkan views + 1
// ===========================================
dbQuery(
    "UPDATE berita SET views = views + 1 WHERE id = ?",
    [$berita['id']], "i"
);
$views_display = ($berita['views'] ?? 0) + 1;

// ===========================================
// SET JUDUL HALAMAN
// ===========================================
$page_title = $berita['judul'];
?>

<article class="berita-detail">

    <h1><?php echo htmlspecialchars($berita['judul']); ?></h1>

    <div class="berita-meta">
        <span>
            <i class="far fa-calendar-alt"></i>
            <?php echo formatTanggal($berita['tanggal']); ?>
        </span>
        <span>
            <i class="far fa-user"></i>
            <?php echo htmlspecialchars($berita['penulis'] ?? 'Admin'); ?>
        </span>
        <span>
            <i class="far fa-eye"></i>
            <?php echo number_format($views_display); ?> dilihat
        </span>
    </div>

    <?php if (!empty($berita['gambar'])): ?>
    <div class="berita-featured-image">
        <!-- ✅ FIX: uploadUrl() bukan BASE_URL . 'uploads/' . $path -->
        <img src="<?php echo uploadUrl($berita['gambar']); ?>"
             alt="<?php echo htmlspecialchars($berita['judul']); ?>"
             loading="lazy"
             onerror="this.parentElement.style.display='none'">
    </div>
    <?php endif; ?>

    <div class="berita-content">
        <?php
        // ✅ FIX: Konten berita kemungkinan mengandung HTML (dari rich text editor)
        // Jika konten adalah plain text → pakai nl2br + htmlspecialchars (aman)
        // Jika konten adalah HTML → pakai langsung (pastikan input sudah di-sanitize saat simpan)
        // Karena tidak ada info editor, kita pakai nl2br + htmlspecialchars sebagai default aman
        echo nl2br(htmlspecialchars($berita['konten'] ?? ''));
        ?>
    </div>

    <div class="berita-footer">
        <a href="berita.php" class="btn btn-kembali">
            <i class="fas fa-arrow-left"></i> Kembali ke Berita
        </a>
    </div>

</article>

<?php include 'footer.php'; ?>