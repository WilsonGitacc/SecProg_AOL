(function () {
    'use strict';

    // 1. Neo-Brutalist Dark Mode Toggle
    const root = document.documentElement;
    const savedTheme = localStorage.getItem('secure_lms_theme') || 'light';
    if (savedTheme === 'dark') {
        root.setAttribute('data-theme', 'dark');
    }

    const themeBtn = document.getElementById('nb-theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            const current = root.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
            const next = current === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            localStorage.setItem('secure_lms_theme', next);
        });
    }

    // 2. Avatar Profile/Security Popover Menu
    const avatarBtn = document.getElementById('nb-avatar-btn');
    const avatarPopover = document.getElementById('nb-avatar-popover');
    if (avatarBtn && avatarPopover) {
        avatarBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            const isOpen = avatarPopover.classList.toggle('open');
            avatarBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
        document.addEventListener('click', function (e) {
            if (!avatarPopover.contains(e.target) && !avatarBtn.contains(e.target)) {
                avatarPopover.classList.remove('open');
                avatarBtn.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                avatarPopover.classList.remove('open');
                avatarBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }
})();
