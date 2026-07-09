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

const initPublicNavDetails = () => {
    const detailsItems = Array.from(document.querySelectorAll('header nav details'));
    if (detailsItems.length === 0) {
        return;
    }

    const closeSiblings = (current) => {
        const nav = current.closest('nav');
        if (!nav) {
            return;
        }

        nav.querySelectorAll('details[open]').forEach((details) => {
            if (details !== current) {
                details.removeAttribute('open');
            }
        });
    };

    detailsItems.forEach((details) => {
        details.addEventListener('toggle', () => {
            if (details.open) {
                closeSiblings(details);
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        if (event.target.closest('header nav details')) {
            return;
        }

        detailsItems.forEach((details) => details.removeAttribute('open'));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        detailsItems.forEach((details) => details.removeAttribute('open'));
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

const showPublicBlogImageFallback = (image) => {
    const media = image.closest('[data-blog-media]');
    const fallback = media?.querySelector('[data-blog-image-fallback]');

    image.classList.add('hidden');
    image.setAttribute('aria-hidden', 'true');

    if (fallback instanceof HTMLElement) {
        fallback.hidden = false;
        fallback.classList.remove('hidden');
    }
};

const initPublicBlogImages = () => {
    document.querySelectorAll('img[data-blog-image]').forEach((image) => {
        if (!(image instanceof HTMLImageElement)) {
            return;
        }

        image.addEventListener('error', () => showPublicBlogImageFallback(image), { once: true });

        if (image.complete && image.naturalWidth === 0) {
            showPublicBlogImageFallback(image);
        }
    });
};

const createLucideIcons = () => {
    window.lucide?.createIcons?.();
};

document.addEventListener('DOMContentLoaded', () => {
    initPublicMobileNav();
    initPublicNavDetails();
    initLegalNavigation();
    initPublicBlogImages();
    createLucideIcons();
});

window.addEventListener('load', createLucideIcons, { once: true });
