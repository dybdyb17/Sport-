document.addEventListener('DOMContentLoaded', () => {

  // ── User dropdown ──
  const avatar   = document.querySelector('.user-avatar');
  const dropdown = document.querySelector('.user-dropdown');
  if (avatar && dropdown) {
    avatar.addEventListener('click', e => {
      e.stopPropagation();
      const open = dropdown.classList.toggle('is-open');
      avatar.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', () => {
      dropdown.classList.remove('is-open');
      avatar.setAttribute('aria-expanded', 'false');
    });
  }

  // ── Mobile menu ──
  // Utilise click + touchend pour couvrir tous les navigateurs mobiles.
  // Le flag `_touchFired` évite le double-déclenchement sur iOS/Android
  // où touchend ET click se déclenchent l'un après l'autre sur le même tap.
  const toggle     = document.querySelector('.mobile-toggle');
  const mobileMenu = document.getElementById('mobileMenu');

  if (toggle && mobileMenu) {
    let touchFired = false;

    function toggleMenu() {
      const open = toggle.classList.toggle('is-open');
      mobileMenu.classList.toggle('is-open', open);
      mobileMenu.setAttribute('aria-hidden', String(!open));
      toggle.setAttribute('aria-expanded', String(open));
    }

    toggle.addEventListener('touchend', e => {
      e.preventDefault(); // empêche le ghost click qui suit sur Android
      touchFired = true;
      toggleMenu();
      setTimeout(() => { touchFired = false; }, 300);
    }, { passive: false });

    toggle.addEventListener('click', () => {
      if (touchFired) return; // déjà traité par touchend
      toggleMenu();
    });

    // Fermer le menu si on clique ailleurs
    document.addEventListener('click', e => {
      if (mobileMenu.classList.contains('is-open') && !mobileMenu.contains(e.target) && !toggle.contains(e.target)) {
        toggle.classList.remove('is-open');
        mobileMenu.classList.remove('is-open');
        mobileMenu.setAttribute('aria-hidden', 'true');
        toggle.setAttribute('aria-expanded', 'false');
      }
    });

    // Fermer le menu quand on clique sur un lien à l'intérieur
    mobileMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => {
        toggle.classList.remove('is-open');
        mobileMenu.classList.remove('is-open');
        mobileMenu.setAttribute('aria-hidden', 'true');
        toggle.setAttribute('aria-expanded', 'false');
      });
    });
  }

});
