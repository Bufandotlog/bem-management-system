<?php
// admin/berita.php - Halaman manajemen berita
// VERSI: 4.0 - SECURITY HARDENING
//   CHANGED: Query filter periode_id — admin hanya lihat berita periode sendiri
//   CHANGED: JOIN periode_kepengurusan — tampilkan nama kabinet di tiap baris
//   CHANGED: Hapus berita via POST + CSRF (bukan GET link)
//   CHANGED: Cast $berita['id'] ke (int), escape slug
//   CHANGED: rel="noopener noreferrer" pada target="_blank"
//   UNCHANGED: Seluruh struktur HTML, CSS class, layout tabel dan card mobile

require_once __DIR__ . '/header.php';

// Ambil periode aktif user — sudah filter per role di getUserPeriode()
$periode_id  = (int) getUserPeriode();
$isSuperadmin = ($_SESSION['admin_role'] ?? '') === 'superadmin'
                || !empty($_SESSION['admin_can_access_all']);

// ============================================
// PROSES HAPUS BERITA — POST + CSRF
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hapus_id'])) {
    if (!csrfVerify()) {
        redirect('admin/berita.php', 'Request tidak valid.', 'error');
        exit();
    }
    $hapusId = (int) $_POST['hapus_id'];
    if ($hapusId > 0) {
        // Ambil data berita dulu untuk hapus file gambar
        $hapusBerita = dbFetchOne(
            "SELECT gambar FROM berita WHERE id = ? AND periode_id = ?",
            [$hapusId, $periode_id], "ii"
        );
        if ($hapusBerita) {
            if (!empty($hapusBerita['gambar'])) deleteFile($hapusBerita['gambar']);
            dbQuery("DELETE FROM berita WHERE id = ? AND periode_id = ?",
                    [$hapusId, $periode_id], "ii");
            redirect('admin/berita.php', 'Berita berhasil dihapus!', 'success');
        } else {
            redirect('admin/berita.php', 'Berita tidak ditemukan atau akses ditolak.', 'error');
        }
    }
    exit();
}

// ============================================
// AMBIL BERITA SESUAI PERIODE + INFO KABINET
// ============================================
// JOIN ke periode_kepengurusan untuk tampilkan nama kabinet
$semuaBerita = dbFetchAll(
    "SELECT b.id, b.judul, b.slug, b.tanggal, b.penulis,
            b.gambar, b.status, b.periode_id,
            p.nama AS nama_periode,
            p.tahun_mulai, p.tahun_selesai
     FROM berita b
     LEFT JOIN periode_kepengurusan p ON b.periode_id = p.id
     WHERE b.periode_id = ?
     ORDER BY b.tanggal DESC",
    [$periode_id], "i"
);

$totalBerita = count($semuaBerita);
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1><i class="fas fa-newspaper"></i> Manajemen Berita</h1>
        <p>
            Total <?php echo $totalBerita; ?> berita
            — Periode: <strong><?php echo htmlspecialchars($periode_data['nama'] ?? ''); ?></strong>
            (<?php echo htmlspecialchars(($periode_data['tahun_mulai'] ?? '') . '/' . ($periode_data['tahun_selesai'] ?? '')); ?>)
        </p>
    </div>

    <div class="header-actions">
        <a href="berita-edit.php" class="btn-add">
            <i class="fas fa-plus"></i> Tambah Berita Baru
        </a>
    </div>
</div>

<?php flashMessage(); ?>

<?php if (empty($semuaBerita)): ?>
    <div class="empty-state">
        <i class="fas fa-newspaper"></i>
        <h3>Belum Ada Berita</h3>
        <p>Belum ada berita untuk periode ini</p>
        <a href="berita-edit.php" class="btn-add">
            <i class="fas fa-plus"></i> Tulis Berita Pertama
        </a>
    </div>
<?php else: ?>

    <!-- DESKTOP: Tabel -->
    <div class="table-container">
        <table class="modern-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Judul</th>
                    <th>Periode / Kabinet</th>
                    <th>Tanggal</th>
                    <th>Penulis</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($semuaBerita as $berita):
                    $beritaId   = (int) $berita['id'];
                    $beritaSlug = htmlspecialchars($berita['slug'], ENT_QUOTES, 'UTF-8');
                    $namaPeriode = htmlspecialchars(
                        ($berita['nama_periode'] ?? 'Unknown')
                        . ' ' . ($berita['tahun_mulai'] ?? '')
                        . '/' . ($berita['tahun_selesai'] ?? ''),
                        ENT_QUOTES, 'UTF-8'
                    );
                ?>
                <tr>
                    <td class="id-cell">#<?php echo str_pad($beritaId, 3, '0', STR_PAD_LEFT); ?></td>

                    <td class="title-cell">
                        <div class="title-wrapper">
                            <?php if (!empty($berita['gambar'])): ?>
                                <img src="<?php echo uploadUrl($berita['gambar']); ?>"
                                     class="news-thumbnail" alt="Thumbnail"
                                     onerror="this.style.display='none'">
                            <?php endif; ?>
                            <span><?php echo htmlspecialchars($berita['judul'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </td>

                    <td>
                        <span style="font-size:.85rem;">
                            <i class="fas fa-calendar-alt" style="color:#4A90E2;margin-right:4px;"></i>
                            <?php echo $namaPeriode; ?>
                        </span>
                    </td>

                    <td class="date-cell">
                        <i class="far fa-calendar-alt"></i>
                        <?php echo date('d/m/Y', strtotime($berita['tanggal'])); ?>
                    </td>

                    <td class="author-cell">
                        <i class="far fa-user"></i>
                        <?php echo htmlspecialchars($berita['penulis'], ENT_QUOTES, 'UTF-8'); ?>
                    </td>

                    <td>
                        <?php if (($berita['status'] ?? 'published') === 'published'): ?>
                            <span class="status-badge published">Published</span>
                        <?php else: ?>
                            <span class="status-badge draft">Draft</span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="action-buttons">
                            <a href="berita-edit.php?id=<?php echo $beritaId; ?>" class="btn-edit">
                                <i class="fas fa-edit"></i> Edit
                            </a>

                            <?php /* Hapus via POST form + CSRF — bukan GET link */ ?>
                            <form method="POST" style="display:inline"
                                  onsubmit="return confirm('Yakin ingin menghapus berita ini?')">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="hapus_id" value="<?php echo $beritaId; ?>">
                                <button type="submit" class="btn-delete">
                                    <i class="fas fa-trash"></i> Hapus
                                </button>
                            </form>

                            <a href="../berita-detail.php?slug=<?php echo $beritaSlug; ?>"
                               target="_blank" rel="noopener noreferrer" class="btn-view">
                                <i class="fas fa-eye"></i> Lihat
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- MOBILE: Card Layout -->
    <div class="news-cards">
        <?php foreach ($semuaBerita as $berita):
            $beritaId   = (int) $berita['id'];
            $beritaSlug = htmlspecialchars($berita['slug'], ENT_QUOTES, 'UTF-8');
            $namaPeriode = htmlspecialchars(
                ($berita['nama_periode'] ?? 'Unknown')
                . ' ' . ($berita['tahun_mulai'] ?? '')
                . '/' . ($berita['tahun_selesai'] ?? ''),
                ENT_QUOTES, 'UTF-8'
            );
        ?>
        <div class="news-card">
            <div class="news-card-header">
                <?php if (!empty($berita['gambar'])): ?>
                    <img src="<?php echo uploadUrl($berita['gambar']); ?>"
                         class="news-card-thumb" alt="Thumbnail"
                         onerror="this.style.display='none'">
                <?php endif; ?>
                <div class="news-card-info">
                    <div class="news-card-title">
                        <?php echo htmlspecialchars($berita['judul'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="news-card-id">
                        #<?php echo str_pad($beritaId, 3, '0', STR_PAD_LEFT); ?>
                    </div>
                </div>
                <?php if (($berita['status'] ?? 'published') === 'published'): ?>
                    <span class="status-badge published">Published</span>
                <?php else: ?>
                    <span class="status-badge draft">Draft</span>
                <?php endif; ?>
            </div>

            <!-- Info periode di card mobile -->
            <div class="news-card-meta">
                <div class="news-card-meta-item">
                    <i class="fas fa-crown" style="color:#4A90E2;"></i>
                    <?php echo $namaPeriode; ?>
                </div>
                <div class="news-card-meta-item">
                    <i class="far fa-calendar-alt"></i>
                    <?php echo date('d/m/Y', strtotime($berita['tanggal'])); ?>
                </div>
                <div class="news-card-meta-item">
                    <i class="far fa-user"></i>
                    <?php echo htmlspecialchars($berita['penulis'], ENT_QUOTES, 'UTF-8'); ?>
                </div>
            </div>

            <div class="news-card-actions">
                <a href="berita-edit.php?id=<?php echo $beritaId; ?>" class="btn-edit">
                    <i class="fas fa-edit"></i><span class="btn-label"> Edit</span>
                </a>

                <form method="POST" style="display:inline"
                      onsubmit="return confirm('Yakin ingin menghapus berita ini?')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="hapus_id" value="<?php echo $beritaId; ?>">
                    <button type="submit" class="btn-delete">
                        <i class="fas fa-trash"></i><span class="btn-label"> Hapus</span>
                    </button>
                </form>

                <a href="../berita-detail.php?slug=<?php echo $beritaSlug; ?>"
                   target="_blank" rel="noopener noreferrer" class="btn-view">
                    <i class="fas fa-eye"></i><span class="btn-label"> Lihat</span>
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($totalBerita > 10): ?>
    <div class="pagination">
        <a href="#" class="pagination-item"><i class="fas fa-chevron-left"></i></a>
        <a href="#" class="pagination-item active">1</a>
        <a href="#" class="pagination-item">2</a>
        <a href="#" class="pagination-item">3</a>
        <a href="#" class="pagination-item">4</a>
        <a href="#" class="pagination-item">5</a>
        <a href="#" class="pagination-item"><i class="fas fa-chevron-right"></i></a>
    </div>
    <?php endif; ?>

<?php endif; ?>

<link rel="stylesheet" href="css/berita.css">

<?php require_once __DIR__ . '/footer.php'; ?>