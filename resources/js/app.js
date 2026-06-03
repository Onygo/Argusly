const root = document.documentElement;

const openMobile = () => root.classList.add('mobile-sidebar-open');
const closeMobile = () => root.classList.remove('mobile-sidebar-open');

document.querySelectorAll('[data-mobile-sidebar-open]').forEach((button) => button.addEventListener('click', openMobile));
document.querySelectorAll('[data-mobile-sidebar-close], [data-mobile-backdrop]').forEach((element) => element.addEventListener('click', closeMobile));

document.querySelectorAll('[data-workspace]').forEach((link) => {
    link.addEventListener('click', () => {
        localStorage.setItem('argusly.nav.lastWorkspace', link.dataset.workspace || '');
    });
});
