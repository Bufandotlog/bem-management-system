/**
 * MODULE: Social Media Tooltip
 * Fungsi: Menampilkan tooltip pada ikon sosial media
 */

export function initSocialTooltip() {
    const socialLinks = document.querySelectorAll('.social-links a');
    
    socialLinks.forEach(link => {
        link.addEventListener('mouseenter', function() {
            const platform = getPlatformName(this.getAttribute('href'));
            
            // Buat tooltip
            const tooltip = document.createElement('span');
            tooltip.className = 'social-tooltip';
            tooltip.textContent = platform;
            tooltip.style.cssText = `
                position: absolute;
                bottom: -30px;
                left: 50%;
                transform: translateX(-50%);
                background: #1e3a8a;
                color: white;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 12px;
                white-space: nowrap;
                z-index: 100;
            `;
            
            this.style.position = 'relative';
            this.appendChild(tooltip);
            
            // Hapus tooltip saat mouse leave
            this.addEventListener('mouseleave', function() {
                const tooltip = this.querySelector('.social-tooltip');
                if (tooltip) tooltip.remove();
            }, { once: true });
        });
    });
}

function getPlatformName(url) {
    if (url.includes('instagram')) return 'Instagram';
    if (url.includes('tiktok')) return 'TikTok';
    if (url.includes('twitter')) return 'Twitter';
    if (url.includes('youtube')) return 'YouTube';
    if (url.includes('facebook')) return 'Facebook';
    if (url.includes('whatsapp')) return 'WhatsApp';
    if (url.includes('telegram')) return 'Telegram';
    return 'Social Media';
}