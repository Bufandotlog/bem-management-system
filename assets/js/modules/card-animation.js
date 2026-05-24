/**
 * CARD ANIMATION MODULE
 * Animasi kemunculan card untuk halaman kepengurusan dan berita
 * Menggunakan Intersection Observer untuk performa optimal
 */

export function initCardAnimation() {
    // Select all cards that need animation
    const cards = document.querySelectorAll('.org-card, .card, .home-news-card');
    
    if (cards.length === 0) {
        console.log('ℹ️ Tidak ada card untuk dianimasi');
        return;
    }
    
    console.log(`🎴 Menginisialisasi animasi untuk ${cards.length} card...`);
    
    // Pastikan card memiliki style awal
    cards.forEach(card => {
        // Set style awal jika belum ada
        if (!card.style.opacity) {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        }
    });
    
    // Cek apakah Intersection Observer didukung
    if ('IntersectionObserver' in window) {
        // Buat observer dengan konfigurasi optimal
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    // Tambah class visible saat card masuk viewport
                    entry.target.classList.add('visible');
                    
                    // Set style final
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    
                    // Hentikan observasi setelah animasi (hemat resource)
                    observer.unobserve(entry.target);
                    
                    // Optional: log untuk debugging (hapus di production)
                    // console.log('Card muncul:', entry.target);
                }
            });
        }, {
            threshold: 0.1,           // Trigger saat 10% card terlihat
            rootMargin: '0px 0px -20px 0px' // Trigger sedikit sebelum masuk
        });
        
        // Observasi semua card
        cards.forEach(card => {
            observer.observe(card);
        });
        
        // Fallback untuk card yang sudah terlihat saat halaman load
        setTimeout(() => {
            cards.forEach(card => {
                const rect = card.getBoundingClientRect();
                const isVisible = rect.top < window.innerHeight && rect.bottom > 0;
                
                if (isVisible && !card.classList.contains('visible')) {
                    card.classList.add('visible');
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                    observer.unobserve(card);
                }
            });
        }, 100);
        
    } else {
        // Fallback untuk browser lama (IE, dll)
        console.log('⚠️ Intersection Observer tidak didukung, menggunakan fallback...');
        
        // Gunakan requestAnimationFrame untuk performa
        function animateCardsFallback() {
            cards.forEach((card, index) => {
                setTimeout(() => {
                    card.classList.add('visible');
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100); // Delay 100ms antar card
            });
        }
        
        // Jalankan fallback
        if (document.readyState === 'complete') {
            animateCardsFallback();
        } else {
            window.addEventListener('load', animateCardsFallback);
        }
    }
}

/**
 * Fungsi untuk memicu ulang animasi (jika diperlukan)
 * Contoh: setelah filter, search, atau load more
 */
export function refreshCardAnimation() {
    const cards = document.querySelectorAll('.org-card:not(.visible), .card:not(.visible), .home-news-card:not(.visible)');
    
    if (cards.length === 0) {
        console.log('ℹ️ Tidak ada card baru untuk dianimasi');
        return;
    }
    
    console.log(`🔄 Merefresh animasi untuk ${cards.length} card baru...`);
    
    cards.forEach((card, index) => {
        setTimeout(() => {
            card.classList.add('visible');
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
}

/**
 * Fungsi untuk mereset animasi (jika diperlukan)
 */
export function resetCardAnimation() {
    const cards = document.querySelectorAll('.org-card, .card, .home-news-card');
    
    cards.forEach(card => {
        card.classList.remove('visible');
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
    });
    
    // Re-init animation
    initCardAnimation();
}