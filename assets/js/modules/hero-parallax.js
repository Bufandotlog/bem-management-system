export function initHeroParallax() {
    const heroImage = document.querySelector('.hero-background img');
    const heroContent = document.querySelector('.hero-content');
    
    if (!heroImage) return;
    
    // SIMPAN GAMBAR KE SESSION
    if (heroImage.src) {
        sessionStorage.setItem('heroBgImage', heroImage.src);
        
        if (window.updateGlobalBackground) {
            window.updateGlobalBackground(heroImage.src);
        } else {
            document.dispatchEvent(new CustomEvent('heroImageReady', { 
                detail: { src: heroImage.src } 
            }));
        }
    }
    
    heroImage.style.filter = 'none';

    function update() {
        const scrollY = window.scrollY;
        
        // ===== BACKGROUND EFFECTS =====
        // Zoom: scroll 0-500px, maks 1.3x
        const zoom = 1 + Math.min(scrollY / 500, 1) * 0.3;
        
        // BLUR dan BRIGHTNESS dengan 2 fase
        let blur;
        let brightness;
        
        if (scrollY <= 500) {
            // Fase 1: 0-500px - blur 0-10px, brightness normal
            blur = (scrollY / 500) * 10;
            brightness = 0.85;
        } else {
            // Fase 2: >500px - blur terus meningkat, brightness semakin gelap (TAPI TIDAK HITAM)
            const extraScroll = scrollY - 500;
            const extraFactor = Math.min(extraScroll / 500, 1); // 0-1 berdasarkan scroll tambahan
            
            // Blur: dari 10px sampai 20px (lebih halus)
            blur = 10 + (extraFactor * 10);
            
            // Brightness: dari 0.85 sampai 0.55 (gelap tapi masih terlihat)
            brightness = 0.85 - (extraFactor * 0.3);
        }
        
        // Terapkan efek
        heroImage.style.transform = `scale(${zoom})`;
        heroImage.style.filter = `blur(${blur}px) brightness(${brightness}) contrast(1.0)`;
        
        // ===== TEKS HERO NAIK KE ATAS =====
        if (heroContent) {
            const textMove = Math.min(scrollY * 0.4, 200);
            heroContent.style.transform = `translate(-50%, calc(-50% - ${textMove}px))`;
            
            let opacity = 1;
            if (scrollY > 50) {
                opacity = Math.max(0, 1 - ((scrollY - 50) / 350));
            }
            heroContent.style.opacity = opacity;
        }
    }

    window.addEventListener('scroll', update);
    update();
    
    console.log('✅ Hero Parallax diinisialisasi');
}