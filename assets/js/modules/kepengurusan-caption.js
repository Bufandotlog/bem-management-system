/**
 * MODULE: Kepengurusan Caption Fade Effect
 * Efek fade dan zoom untuk caption di halaman kepengurusan
 * DIPERLAMBAT: scroll 20-300px
 */

export function initKepengurusanCaptionFade() {
    // Cek apakah kita di halaman kepengurusan
    const heroCaption = document.querySelector('.page-kepengurusan .hero-caption');
    if (!heroCaption) return; // Hanya jalan di halaman kepengurusan
    
    console.log('✅ Kepengurusan Caption Fade diinisialisasi (scroll 20-300px) - DIPERLAMBAT');
    
    // Pastikan visibility awal = visible
    heroCaption.style.visibility = 'visible';
    heroCaption.style.opacity = '1';
    
    function updateCaptionFade() {
        const scrollY = window.scrollY;
        
        // Efek fade untuk caption (20px - 300px) - DIPERLAMBAT
        let captionOpacity = 1;
        if (scrollY > 20) {
            captionOpacity = Math.max(0, 1 - ((scrollY - 20) / 280)); // Pembagi 280 (sebelumnya 130)
        }
        
        // Efek zoom out dan geser ke atas - DIPERLAMBAT
        if (scrollY > 0 && scrollY < 300) { // Batas atas 300px (sebelumnya 150px)
            const textMove = Math.min(scrollY * 0.05, 15); // Geser lebih lambat: 0.05 (sebelumnya 0.1)
            const zoom = Math.max(0.85, 1 - (scrollY * 0.0005)); // Zoom lebih lambat: 0.0005 (sebelumnya 0.001)
            heroCaption.style.transform = `translateY(-${textMove}px) scale(${zoom})`;
        } else if (scrollY >= 300) {
            heroCaption.style.transform = 'translateY(-15px) scale(0.85)';
        } else {
            heroCaption.style.transform = 'translateY(0) scale(1)';
        }
        
        // Terapkan opacity
        heroCaption.style.opacity = captionOpacity;
        
        // Sembunyikan jika benar-benar fade total
        if (captionOpacity <= 0.01) {
            heroCaption.style.visibility = 'hidden';
        } else {
            heroCaption.style.visibility = 'visible';
        }
        
        // Pointer-events tetap none agar tidak mengganggu
        heroCaption.style.pointerEvents = 'none';
    }
    
    // Update saat scroll
    window.addEventListener('scroll', updateCaptionFade);
    
    // Panggil sekali untuk inisialisasi
    updateCaptionFade();
}