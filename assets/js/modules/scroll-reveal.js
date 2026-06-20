export function initScrollReveal() {
    // Detection using hero-snap-point element is 100% robust on hosting
    const isPageIndex = document.querySelector('.hero-snap-point') !== null;
    
    // Typewriter helper
    function typeElement(element, speed = 30) {
        if (!element) return;
        if (element.dataset.typed === 'true') return;
        element.dataset.typed = 'true';

        const originalHTML = element.innerHTML;
        element.innerHTML = '';
        
        const chars = [];
        let inTag = false;
        let currentTag = '';
        
        for (let i = 0; i < originalHTML.length; i++) {
            const char = originalHTML[i];
            if (char === '<') {
                inTag = true;
                currentTag = '<';
            } else if (char === '>') {
                inTag = false;
                currentTag += '>';
                chars.push(currentTag);
                currentTag = '';
            } else {
                if (inTag) {
                    currentTag += char;
                } else {
                    chars.push(char);
                }
            }
        }
        
        let index = 0;
        function type() {
            if (index < chars.length) {
                element.innerHTML += chars[index];
                index++;
                setTimeout(type, speed);
            } else {
                element.innerHTML = originalHTML;
            }
        }
        
        type();
    }

    if (isPageIndex) {
        // Manage snap class dynamically based on screen width
        function checkSnapSupport() {
            if (window.innerWidth >= 992) {
                document.documentElement.classList.add('index-html-snap');
            } else {
                document.documentElement.classList.remove('index-html-snap');
            }
        }
        
        checkSnapSupport();
        window.addEventListener('resize', checkSnapSupport);
        
        const sections = document.querySelectorAll('.home-section');
        let lastScrollTop = window.scrollY || document.documentElement.scrollTop;
        
        const observerOptions = {
            root: null,
            threshold: 0.1, // Trigger earlier (10% visibility) for immediate animation feedback
            rootMargin: '0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            const scrollTop = window.scrollY || document.documentElement.scrollTop;
            const isScrollingDown = scrollTop > lastScrollTop;
            
            entries.forEach(entry => {
                const sec = entry.target;
                
                if (document.documentElement.classList.contains('index-html-snap')) {
                    if (entry.isIntersecting) {
                        sec.classList.remove('focus-exit-up', 'focus-exit-down');
                        sec.classList.add('focus-active');
                        
                        // Trigger typewriter
                        if (sec.classList.contains('sambutan')) {
                            const heading = sec.querySelector('.sambutan-text h2');
                            if (heading) setTimeout(() => typeElement(heading, 45), 200);
                        } else if (sec.querySelector('.section-title')) {
                            const headingSpan = sec.querySelector('.section-title span');
                            if (headingSpan) setTimeout(() => typeElement(headingSpan, 55), 300);
                        }
                    } else {
                        sec.classList.remove('focus-active');
                        if (isScrollingDown) {
                            sec.classList.add('focus-exit-up');
                        } else {
                            sec.classList.add('focus-exit-down');
                        }
                    }
                } else {
                    // Mobile / fallback scroll reveal
                    if (entry.isIntersecting) {
                        sec.classList.add('visible');
                        sec.classList.remove('focus-exit-up', 'focus-exit-down', 'focus-active');
                        
                        if (sec.classList.contains('sambutan')) {
                            const heading = sec.querySelector('.sambutan-text h2');
                            if (heading) setTimeout(() => typeElement(heading, 45), 200);
                        } else if (sec.querySelector('.section-title')) {
                            const headingSpan = sec.querySelector('.section-title span');
                            if (headingSpan) setTimeout(() => typeElement(headingSpan, 55), 300);
                        }
                    }
                }
            });
            
            lastScrollTop = scrollTop;
        }, observerOptions);
        
        sections.forEach(sec => observer.observe(sec));
        
        // Observe footer for mobile fallback
        const footer = document.querySelector('footer');
        if (footer) {
            const footerObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        footer.classList.add('visible');
                    }
                });
            }, { threshold: 0.1 });
            footerObserver.observe(footer);
        }
        
    } else {
        // Fallback for non-index pages
        const elements = document.querySelectorAll('.card, .kontak-item, .menteri-item, .section-title');
        
        function reveal() {
            const triggerBottom = window.innerHeight * 0.85;
            elements.forEach(el => {
                const rect = el.getBoundingClientRect();
                if (rect.top < triggerBottom) {
                    el.classList.add('visible');
                }
            });
        }
        
        window.addEventListener('scroll', reveal);
        reveal();
    }
}