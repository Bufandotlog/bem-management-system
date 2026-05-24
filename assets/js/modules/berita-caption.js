/**
 * MODULE: Berita Caption Fade Effect
 * Efek fade untuk caption di halaman berita
 */

export function initBeritaCaptionFade() {
    // Cek apakah kita di halaman berita
    const heroCaption = document.querySelector('.page-berita .hero-caption');
    if (!heroCaption) return;
    
    console.log('✅ Berita Caption Fade diinisialisasi (scroll 20-150px)');
    
    // Pastikan visibility awal = visible
    heroCaption.style.visibility = 'visible';
    heroCaption.style.opacity = '1';
    
    function updateCaptionFade() {
        const scrollY = window.scrollY;
        
        // Efek fade untuk caption
        let captionOpacity = 1;
        if (scrollY > 20) {
            captionOpacity = Math.max(0, 1 - ((scrollY - 20) / 130));
        }
        
        // Terapkan opacity
        heroCaption.style.opacity = captionOpacity;
        
        // Efek geser ke atas
        if (captionOpacity > 0 && scrollY < 150) {
            const textMove = Math.min(scrollY * 0.1, 15);
            heroCaption.style.transform = `translateY(-${textMove}px)`;
        } else {
            heroCaption.style.transform = 'translateY(0)';
        }
        
        // PERBAIKAN: JANGAN UBAH pointer-events SAMA SEKALI
        // Biarkan CSS yang mengatur dengan !important
        
        // Hanya sembunyikan visibility jika benar-benar fade total
        if (captionOpacity <= 0.01) {
            heroCaption.style.visibility = 'hidden';
        } else {
            heroCaption.style.visibility = 'visible';
        }
    }
    
    // Update saat scroll
    window.addEventListener('scroll', updateCaptionFade);
    
    // Panggil sekali untuk inisialisasi
    updateCaptionFade();
}