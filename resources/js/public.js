const initPublicMobileNav = () => {
    const toggle = document.querySelector('[data-mobile-nav-toggle]');
    const menu = document.querySelector('[data-mobile-nav-menu]');
    if (!toggle || !menu) {
        return;
    }

    const openIcon = toggle.querySelector('[data-mobile-nav-icon-open]');
    const closeIcon = toggle.querySelector('[data-mobile-nav-icon-close]');

    const setOpen = (isOpen) => {
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        menu.classList.toggle('hidden', !isOpen);
        document.body.classList.toggle('overflow-hidden', isOpen);
        openIcon?.classList.toggle('hidden', isOpen);
        closeIcon?.classList.toggle('hidden', !isOpen);
    };

    setOpen(false);

    toggle.addEventListener('click', () => {
        setOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    menu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => setOpen(false));
    });
};

const initLegalNavigation = () => {
    const picker = document.querySelector('[data-legal-nav-select]');
    if (!picker) {
        return;
    }

    picker.addEventListener('change', () => {
        const target = String(picker.value || '').trim();
        if (target !== '') {
            window.location.href = target;
        }
    });
};

const createLucideIcons = () => {
    window.lucide?.createIcons?.();
};

document.addEventListener('DOMContentLoaded', () => {
    initPublicMobileNav();
    initLegalNavigation();
    createLucideIcons();
});

window.addEventListener('load', createLucideIcons, { once: true });
