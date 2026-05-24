export function initScrollReveal() {
    const elements = document.querySelectorAll('.card, .sambutan, .visi-misi, .kontak-item, .menteri-item');

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
    reveal(); // panggil sekali untuk elemen yang sudah terlihat
}