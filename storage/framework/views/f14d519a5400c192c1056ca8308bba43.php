<?php $__env->startSection('title', 'Beranda - BEM Kabinet ' . ($kabinet->nama ?? 'ASTAWIDYA')); ?>
<?php $__env->startSection('page_class', 'page-index'); ?>

<?php $__env->startSection('content'); ?>

<!-- Absolute scroll snap target untuk Hero Section di paling atas -->
<div class="hero-snap-point"></div>

<!-- Sambutan Presiden Mahasiswa -->
<section id="sambutan" class="sambutan home-section">
    <div class="sambutan-container">
        <div class="sambutan-foto">
            <?php if($ketuaAnggota && $ketuaAnggota->foto): ?>
                <img src="<?php echo e(asset('uploads/' . $ketuaAnggota->foto)); ?>" alt="Presiden Mahasiswa">
            <?php else: ?>
                <img src="<?php echo e(asset('assets/images/default-avatar.jpg')); ?>" alt="Default Avatar">
            <?php endif; ?>
        </div>
        <div class="sambutan-text">
            <h2>Sambutan<br><span class="text-merah">Presiden Mahasiswa</span></h2>
            <div class="jabatan">
                <?php echo e($ketuaAnggota->nama ?? 'Dede Anggi Muhyidin'); ?> • 
                Ketua BEM 
                <?php if($periodeAktif): ?>
                    <?php echo e($periodeAktif->nama . ' ' . $periodeAktif->tahun_mulai); ?>

                <?php else: ?>
                    <?php echo e($kabinet->tahun_mulai ?? '2026'); ?>

                <?php endif; ?>
            </div>
            
            <p><?php echo nl2br(e($sambutan['pembuka'])); ?></p>
            <p><?php echo nl2br(e($sambutan['paragraf1'])); ?></p>
            <p><?php echo nl2br(e($sambutan['paragraf2'])); ?></p>
            
            <div class="ttd">
                <strong><?php echo e($ketuaAnggota->nama ?? 'Dede Anggi Muhyidin'); ?></strong><br>
                Presiden Mahasiswa 
                <?php if($periodeAktif): ?>
                    <?php echo e($periodeAktif->nama . ' ' . $periodeAktif->tahun_mulai); ?>

                <?php else: ?>
                    <?php echo e($kabinet->tahun_mulai ?? '2026'); ?>

                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Visi Misi -->
<section id="visi-misi-section" class="home-section visi-misi-section">
    <div class="container">
        <h2 class="section-title"><span>VISI & MISI</span></h2>
        <div class="visi-misi">
            <div class="visi">
                <h3>Visi</h3>
                <p><?php echo nl2br(e($visiMisi['visi'])); ?></p>
            </div>
            <div class="misi">
                <h3>Misi</h3>
                <ul>
                    <?php $__currentLoopData = $visiMisi['misi']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $misi): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><?php echo e($misi); ?></li>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- Berita Terkini -->
<section id="berita-section" class="home-section berita-section">
    <div class="container news-section">
        <h2 class="section-title"><span>BERITA TERKINI</span></h2>
        
        <?php if($beritaTerbaru->isEmpty()): ?>
            <div style="text-align: center; padding: 3rem; color: #888;">
                <i class="far fa-newspaper" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                <p>Belum ada berita saat ini.</p>
            </div>
        <?php else: ?>
            <div class="card-grid home-news-grid">
                <?php $__currentLoopData = $beritaTerbaru; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $b): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <div class="card home-news-card">
                    <div class="card-image home-news-image">
                        <?php if($b->gambar): ?>
                            <img src="<?php echo e(asset('uploads/' . $b->gambar)); ?>" alt="<?php echo e($b->judul); ?>">
                        <?php else: ?>
                            <img src="<?php echo e(asset('assets/images/default-news.jpg')); ?>" alt="Default News">
                        <?php endif; ?>
                    </div>
                    <div class="card-content home-news-content">
                        <h3><?php echo e($b->judul); ?></h3>
                        <div class="card-meta home-news-meta">
                            <span><i class="far fa-calendar-alt"></i> <?php echo e(\Carbon\Carbon::parse($b->tanggal)->translatedFormat('d F Y')); ?></span>
                            <span><i class="far fa-user"></i> <?php echo e($b->penulis); ?></span>
                        </div>
                        <p><?php echo e(Str::limit(strip_tags($b->konten), 150)); ?></p>
                        <a href="<?php echo e(route('berita.show', $b->slug)); ?>" class="btn btn-small">Baca Selengkapnya</a>
                    </div>
                </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
            <div style="text-align: center; margin-top: 3rem;">
                <a href="<?php echo e(route('berita.index')); ?>" class="btn">Lihat Semua Berita</a>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php $__env->startPush('css'); ?>
<style>
/* Beri jarak antara section berita dengan footer */
.news-section {
    margin-bottom: 6rem !important;  
    padding-bottom: 2rem;
}
@media (max-width: 768px) {
    .news-section {
        margin-bottom: 4rem !important;
    }
}
footer {
    margin-top: 0 !important;
    clear: both;
}
</style>
<?php $__env->stopPush(); ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('public.layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/bem/resources/views/public/home.blade.php ENDPATH**/ ?>