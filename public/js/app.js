document.addEventListener('DOMContentLoaded', () => {
  // ── User dropdown ──
  const avatar = document.querySelector('.user-avatar');
  const dropdown = document.querySelector('.user-dropdown');
  if (avatar && dropdown) {
    avatar.addEventListener('click', e => {
      e.stopPropagation();
      const open = dropdown.classList.toggle('is-open');
      avatar.setAttribute('aria-expanded', open);
    });
    document.addEventListener('click', () => {
      dropdown.classList.remove('is-open');
      avatar.setAttribute('aria-expanded', 'false');
    });
  }

  // ── Mobile menu ──
  const toggle = document.querySelector('.mobile-toggle');
  const mobileMenu = document.getElementById('mobileMenu');
  if (toggle && mobileMenu) {
    toggle.addEventListener('click', () => {
      const open = toggle.classList.toggle('is-open');
      mobileMenu.classList.toggle('is-open', open);
      mobileMenu.setAttribute('aria-hidden', !open);
      toggle.setAttribute('aria-expanded', open);
    });
  }
});
