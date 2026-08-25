<?php $__env->startSection('title', 'Kepengurusan - BEM Kabinet Astawidya'); ?>
<?php $__env->startSection('page_class', 'page-kepengurusan'); ?>

<?php $__env->startSection('content'); ?>

<!-- ===== HERO CAPTION ===== -->
<div class="hero-caption">
    <div class="caption-content">
        <h1 class="caption-title"><span>KEPENGURUSAN</span></h1>

        <!-- DROPDOWN PILIH PERIODE -->
        <?php if($semuaPeriode->count() > 1): ?>
        <label for="custom-periode-trigger" class="periode-label">
            <i class="fas fa-calendar-alt"></i> Lihat Periode:
        </label>
        <div class="periode-selector custom-dropdown-container">
            <div class="custom-dropdown" id="customPeriodeDropdown">
                <button type="button" class="custom-dropdown-trigger" id="custom-periode-trigger" aria-haspopup="listbox" aria-expanded="false">
                    <span class="trigger-text">
                        <?php echo e($periodeTerpilih->nama); ?> (<?php echo e($periodeTerpilih->tahun_mulai); ?>/<?php echo e($periodeTerpilih->tahun_selesai); ?>)
                        <?php if($periodeTerpilih->is_active): ?> • Periode Aktif <?php endif; ?>
                    </span>
                    <i class="fas fa-chevron-down dropdown-arrow"></i>
                </button>
                <div class="custom-dropdown-menu" role="listbox" aria-labelledby="custom-periode-trigger" tabindex="-1">
                    <?php $__currentLoopData = $semuaPeriode; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <?php $isSelected = ($p->id === $periodeTerpilih->id); ?>
                        <div class="custom-dropdown-item <?php echo e($isSelected ? 'selected' : ''); ?>"
                             data-value="<?php echo e($p->id); ?>"
                             data-text="<?php echo e($p->nama); ?> (<?php echo e($p->tahun_mulai); ?>/<?php echo e($p->tahun_selesai); ?>) <?php echo e($p->is_active ? '• Periode Aktif' : ''); ?>"
                             role="option"
                             aria-selected="<?php echo e($isSelected ? 'true' : 'false'); ?>"
                             tabindex="-1">
                            <div class="item-content">
                                <span class="item-nama"><?php echo e($p->nama); ?></span>
                                <span class="item-tahun">(<?php echo e($p->tahun_mulai); ?>/<?php echo e($p->tahun_selesai); ?>)</span>
                                <?php if($p->is_active): ?> <span class="item-badge">Periode Aktif</span> <?php endif; ?>
                            </div>
                            <?php if($isSelected): ?> <i class="fas fa-check selected-icon"></i> <?php endif; ?>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
            </div>
            <form method="GET" action="<?php echo e(route('kepengurusan.index')); ?>" id="customPeriodeForm" style="display:none;">
                <input type="hidden" name="periode" id="customPeriodeInput" value="<?php echo e($periodeTerpilih->id); ?>">
            </form>
        </div>

        <?php if(!$periodeTerpilih->is_active): ?>
        <div class="periode-badge arsip">
            <i class="fas fa-archive"></i>
            Menampilkan Arsip Periode <?php echo e($periodeTerpilih->tahun_mulai); ?>/<?php echo e($periodeTerpilih->tahun_selesai); ?>

        </div>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Gambar Struktur Organisasi -->
        <div class="caption-image-container">
            <?php if($struktur && !empty($struktur->gambar)): ?>
                <img src="<?php echo e(asset('uploads/' . $struktur->gambar)); ?>"
                     alt="<?php echo e($struktur->judul ?? 'Struktur Organisasi BEM'); ?>"
                     class="caption-image"
                     loading="lazy">
                <?php if(!empty($struktur->deskripsi)): ?>
                <div class="image-caption">
                    <i class="fas fa-info-circle"></i>
                    <?php echo e($struktur->deskripsi); ?>

                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-image-placeholder">
                    <div class="placeholder-content">
                        <i class="fas fa-image"></i>
                        <p>Gambar Struktur Organisasi</p>
                        <span>Periode <?php echo e($periodeTerpilih->tahun_mulai ?? ''); ?>/<?php echo e($periodeTerpilih->tahun_selesai ?? ''); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <p class="caption-narasi">
            Struktur organisasi BEM Institut Teknologi dan Bisnis Universitas Nasional
            <?php if($periodeTerpilih): ?>
                Kabinet <?php echo e($periodeTerpilih->nama); ?> periode <?php echo e($periodeTerpilih->tahun_mulai); ?>/<?php echo e($periodeTerpilih->tahun_selesai); ?>

            <?php else: ?>
                Kabinet Astawidya
            <?php endif; ?>
            yang terdiri dari Badan Pengurus Harian (BPH) dan jajaran kementerian, bekerja bersama untuk mewujudkan visi dan misi kabinet.
        </p>
        <div class="caption-scroll">
            <span class="scroll-text">jelajahi struktur</span>
            <i class="fas fa-chevron-down"></i>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- KONTEN UTAMA KEPENGURUSAN                 -->
<!-- ========================================= -->
<div class="kepengurusan-content-wrapper">
    <div class="kepengurusan-container">

        <!-- ===== BADAN PENGURUS HARIAN ===== -->
        <div class="bph-section">
            <h2 class="section-title-bph">BADAN PENGURUS HARIAN</h2>

            <?php if($bphList->isEmpty()): ?>
            <div class="empty-state">
                <i class="fas fa-users-slash"></i>
                <p>Belum ada data BPH untuk periode ini.</p>
            </div>
            <?php else: ?>
            <div class="bph-grid">

                <?php $__currentLoopData = $bphList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $bph): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e(route('kepengurusan.detailMenteri', ['id' => $bph->id, 'type' => 'bph', 'periode' => $periodeTerpilih->id])); ?>" class="org-card dept-card logo-card">
                    <div class="card-logo-container">
                        <img src="<?php echo e(asset('assets/images/default-logo.png')); ?>" class="org-logo" alt="<?php echo e($bph->nama_divisi); ?>" loading="lazy">
                    </div>
                    <div class="org-card-info">
                        <h3><?php echo e($bph->nama_divisi); ?></h3>
                        <p class="org-jabatan"><?php echo e($bph->anggota_periode->count()); ?> Anggota</p>
                        <?php if($bph->anggota_periode->count() > 0): ?>
                        <div class="org-preview">
                            <?php echo e($bph->anggota_periode->take(2)->pluck('nama')->implode(' & ')); ?>

                            <?php if($bph->anggota_periode->count() > 2): ?>
                                &amp; <?php echo e($bph->anggota_periode->count() - 2); ?> lainnya
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

            </div>
            <?php endif; ?>
        </div>

        <!-- ===== PEMISAH ===== -->
        <div class="section-divider"><span>KEMENTERIAN</span></div>

        <!-- ===== KEMENTERIAN ===== -->
        <?php if($kementerianList->count() > 0): ?>
        <div class="menteri-section">
            <div class="menteri-grid">
                <?php $__currentLoopData = $kementerianList; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $menteri): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $namaAnggota = $menteri->anggota_periode->pluck('nama')->toArray();
                        $preview = array_slice($namaAnggota, 0, 2);
                        $sisa = count($namaAnggota) - 2;
                    ?>
                <a href="<?php echo e(route('kepengurusan.detailMenteri', ['id' => $menteri->id, 'type' => 'kementerian', 'periode' => $periodeTerpilih->id])); ?>" class="org-card menteri-card logo-card">
                    <div class="card-logo-container">
                        <img src="<?php echo e(asset('assets/images/default-logo.png')); ?>" class="org-logo" alt="<?php echo e($menteri->nama_kementerian); ?>" loading="lazy">
                    </div>
                    <div class="org-card-info">
                        <h3><?php echo e($menteri->nama_kementerian); ?></h3>
                        <p class="org-jabatan"><?php echo e(count($namaAnggota)); ?> Anggota</p>
                        <?php if(count($preview) > 0): ?>
                        <div class="org-preview">
                            <?php echo e(implode(', ', $preview)); ?>

                            <?php if($sisa > 0): ?> &amp; <?php echo e($sisa); ?> lainnya <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-building"></i>
            <p>Belum ada data kementerian untuk periode ini.</p>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php $__env->startPush('js'); ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const trigger = document.getElementById('custom-periode-trigger');
    const dropdown = document.getElementById('customPeriodeDropdown');
    const menu = dropdown ? dropdown.querySelector('.custom-dropdown-menu') : null;
    const form = document.getElementById('customPeriodeForm');
    const input = document.getElementById('customPeriodeInput');

    if(trigger && menu) {
        trigger.addEventListener('click', function(e) {
            e.stopPropagation();
            const expanded = this.getAttribute('aria-expanded') === 'true';
            this.setAttribute('aria-expanded', !expanded);
            dropdown.classList.toggle('open');
        });

        document.addEventListener('click', function(e) {
            if (!dropdown.contains(e.target)) {
                trigger.setAttribute('aria-expanded', 'false');
                dropdown.classList.remove('open');
            }
        });

        const items = menu.querySelectorAll('.custom-dropdown-item');
        items.forEach(item => {
            item.addEventListener('click', function() {
                const value = this.getAttribute('data-value');
                input.value = value;
                form.submit();
            });
        });
    }
});
</script>
<?php $__env->stopPush(); ?>

<?php $__env->startPush('css'); ?>
<style>
.kepengurusan-content-wrapper {
    position: relative;
    z-index: 10;
    background: transparent;
    margin-top: 100vh;
    min-height: 100vh;
    padding-top: 1rem;
}
.empty-state { text-align: center; padding: 4rem; color: #888; }
.empty-state i { font-size: 4rem; margin-bottom: 1rem; color: #333; display: block; }

/* Styles untuk custom dropdown sederhana */
.custom-dropdown-container { position: relative; display: inline-block; }
.custom-dropdown-trigger { background: #111; color: #fff; border: 1px solid #333; padding: 10px 15px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: space-between; min-width: 200px; }
.custom-dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: #111; border: 1px solid #333; border-radius: 8px; width: 100%; z-index: 100; margin-top: 5px; max-height: 300px; overflow-y: auto; }
.custom-dropdown.open .custom-dropdown-menu { display: block; }
.custom-dropdown-item { padding: 10px 15px; cursor: pointer; color: #ccc; border-bottom: 1px solid #222; }
.custom-dropdown-item:hover { background: #222; color: #4A90E2; }
.custom-dropdown-item.selected { background: #222; color: #4A90E2; font-weight: bold; }
.item-badge { background: #4caf50; color: white; padding: 2px 6px; border-radius: 4px; font-size: 0.7rem; margin-left: 8px; }
.periode-badge { display: inline-block; background: #333; color: #ccc; padding: 5px 10px; border-radius: 15px; font-size: 0.8rem; margin-top: 10px; }
</style>
<?php $__env->stopPush(); ?>

<?php $__env->stopSection(); ?>

<?php echo $__env->make('public.layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /var/www/html/bem/resources/views/public/kepengurusan/index.blade.php ENDPATH**/ ?>