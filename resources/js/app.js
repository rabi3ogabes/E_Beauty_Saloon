import Alpine from 'alpinejs';

/*
 * Public website runtime (platform + salon themes). Livewire pages bring their own Alpine,
 * so we only boot ours when it is not already present.
 */
window.luxe = {
    money(minor, currency = 'QAR', locale = document.documentElement.lang || 'en') {
        const value = (minor / 100).toLocaleString(locale === 'ar' ? 'ar-QA' : 'en-QA', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
        return locale === 'ar' ? `${value} ر.ق` : `${currency} ${value}`;
    },
};

// Reveal-on-scroll for elements with [data-reveal]
const io = 'IntersectionObserver' in window
    ? new IntersectionObserver((entries) => entries.forEach((e) => {
        if (e.isIntersecting) {
            e.target.classList.add('animate-fade-up');
            e.target.style.opacity = '';
            io.unobserve(e.target);
        }
    }), { rootMargin: '0px 0px -10% 0px' })
    : null;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-reveal]').forEach((el) => {
        if (io && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            el.style.opacity = '0';
            io.observe(el);
        }
    });
});

if (!window.Alpine) {
    window.Alpine = Alpine;
    Alpine.start();
}
