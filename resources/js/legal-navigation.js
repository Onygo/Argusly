document.addEventListener('DOMContentLoaded', () => {
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
});
