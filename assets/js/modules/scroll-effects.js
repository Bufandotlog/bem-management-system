/**
 * MODULE: Scroll Effects
 * Fungsi: Smooth scroll, scroll hint, dan animasi scroll
 */

export function initScrollEffects() {
    // Smooth scroll untuk anchor links
    initSmoothScroll();
    
    // Scroll hint (gulir ke bawah)
    initScrollHint();
    
    // Animasi fade in saat scroll
    initAnimateOnScroll();
}

function initSmoothScroll() {
    const smoothScrollLinks = document.querySelectorAll('a[href^="#"]:not([href="#"])');
    
    smoothScrollLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            const targetId = this.getAttribute('href');
            const targetElement = document.querySelector(targetId);
            
            if (targetElement) {
                targetElement.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
}

function initScrollHint() {
    const scrollHint = document.querySelector('.scroll-hint');
    
    if (!scrollHint) return;

    scrollHint.addEventListener('click', function() {
        // Cari section sambutan atau section berikutnya
        const nextSection = document.querySelector('#sambutan') || 
                            document.querySelector('section:not(.hero)') || 
                            document.querySelector('.container');
        
        if (nextSection) {
            nextSection.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });

    // Sembunyikan scroll hint setelah user scroll
    window.addEventListener('scroll', function() {
        if (window.scrollY > 100) {
            scrollHint.style.opacity = '0';
            scrollHint.style.visibility = 'hidden';
            scrollHint.style.transition = 'all 0.3s ease';
        } else {
            scrollHint.style.opacity = '1';
            scrollHint.style.visibility = 'visible';
        }
    });
}

function initAnimateOnScroll() {
    const elements = document.querySelectorAll('.sambutan, .visi-misi, .card, .kontak-item, .menteri-item');
    
    // Set initial style
    elements.forEach(element => {
        element.style.opacity = '0';
        element.style.transform = 'translateY(30px)';
        element.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
    });

    function animateOnScroll() {
        elements.forEach(element => {
            const elementPosition = element.getBoundingClientRect().top;
            const windowHeight = window.innerHeight;
            
            if (elementPosition < windowHeight - 100) {
                element.style.opacity = '1';
                element.style.transform = 'translateY(0)';
            }
        });
    }

    // Trigger sekali saat load
    setTimeout(animateOnScroll, 100);
    
    // Trigger saat scroll
    window.addEventListener('scroll', animateOnScroll);
}