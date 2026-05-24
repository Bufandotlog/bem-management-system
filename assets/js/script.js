/**
 * MAIN SCRIPT - JavaScript untuk website BEM Kabinet Astawidya
 */

import { initMobileMenu } from './modules/mobile-menu.js';
import { initNavigation } from './modules/navigation.js';
import { initScrollEffects } from './modules/scroll-effects.js';
import { initSocialTooltip } from './modules/social-tooltip.js';
import { initFormValidation } from './modules/form-validation.js';
import { initBackToTop } from './modules/back-to-top.js';
import { initCardAnimation } from './modules/card-animation.js';
import { initHeroParallax } from './modules/hero-parallax.js';
import { initScrollReveal } from './modules/scroll-reveal.js';
import { initTypingAnimation } from './modules/typing-animation.js';
import { initBeritaCaptionFade } from './modules/berita-caption.js';
import { initKepengurusanCaptionFade } from './modules/kepengurusan-caption.js';
import { initKepengurusanDropdown } from './modules/kepengurusan-dropdown.js'; // BARU: Import dropdown module

import * as utilities from './modules/utilities.js';

document.addEventListener('DOMContentLoaded', function() {
    
    // Module lainnya
    initMobileMenu();
    initNavigation();
    initScrollEffects();
    initSocialTooltip();
    initFormValidation();
    initBackToTop();
    initCardAnimation();
    initHeroParallax();
    initScrollReveal();
    initTypingAnimation();
    initBeritaCaptionFade();
    initKepengurusanCaptionFade();
    initKepengurusanDropdown(); // BARU: Inisialisasi custom dropdown
    
    console.log('✅ Semua modul JavaScript berhasil diinisialisasi');
    
});

// Export utilities untuk penggunaan global
window.confirmAction = utilities.confirmAction;
window.formatTanggalIndonesia = utilities.formatTanggalIndonesia;
window.copyToClipboard = utilities.copyToClipboard;