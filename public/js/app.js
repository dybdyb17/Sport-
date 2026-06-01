/**
 * SPORT+ — interactions globales
 * Compatible iOS 12+ / Safari / Android / tous navigateurs mobiles.
 */

// ── Oeil mot de passe — injecté sur tous les champs password ──────────────
// Fonctionne sur les formulaires Symfony (form_widget) ET les inputs manuels.
// Ne re-wrap pas si déjà dans .password-field-wrapper.
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('input[type="password"]').forEach(function(input) {
    if (input.closest('.password-field-wrapper')) return; // déjà géré

    // Créer le wrapper
    var wrapper = document.createElement('div');
    wrapper.className = 'password-field-wrapper';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    // Créer le bouton oeil
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle';
    btn.setAttribute('aria-label', 'Afficher/masquer le mot de passe');
    btn.innerHTML = '<i class="ti ti-eye"></i>';
    wrapper.appendChild(btn);

    btn.addEventListener('click', function() {
      var isText = input.type === 'text';
      input.type = isText ? 'password' : 'text';
      btn.innerHTML = isText
        ? '<i class="ti ti-eye"></i>'
        : '<i class="ti ti-eye-off"></i>';
    });
  });
});

// Sécurité : fonctionne que le DOM soit chargé ou non
(function init() {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setup);
  } else {
    setup();
  }
})();

function setup() {

  // ── User dropdown ─────────────────────────────────────────────
  const avatar   = document.querySelector('.user-avatar');
  const dropdown = document.querySelector('.user-dropdown');

  if (avatar && dropdown) {
    avatar.addEventListener('click', function(e) {
      e.stopPropagation();
      const open = dropdown.classList.toggle('is-open');
      avatar.setAttribute('aria-expanded', String(open));
    });

    document.addEventListener('click', function() {
      dropdown.classList.remove('is-open');
      avatar.setAttribute('aria-expanded', 'false');
    });
  }

  // ── Mobile burger menu ────────────────────────────────────────
  //
  // POURQUOI seulement "click" et pas "touchend" :
  //  - Sur iOS 12/13 (iPhone X/XS), appeler e.preventDefault() sur
  //    touchend empêche le click sur le bouton MAIS iOS génère quand
  //    même un ghost-click sur document → notre handler "fermer si
  //    clic ailleurs" se déclenche juste après l'ouverture → menu
  //    s'ouvre et se ferme en 1 frame, invisible pour l'utilisateur.
  //  - touch-action: manipulation (CSS) supprime le délai 300ms sur
  //    iOS sans avoir besoin de gérer touchend manuellement.
  //  - Les boutons <button> reçoivent toujours l'event click sur iOS.
  //
  const toggle     = document.querySelector('.mobile-toggle');
  const mobileMenu = document.getElementById('mobileMenu');

  if (!toggle || !mobileMenu) return;

  // Timestamp du dernier toggleMenu pour ignorer les ghost events
  var lastToggleTime = 0;

  function openMenu() {
    toggle.classList.add('is-open');
    mobileMenu.classList.add('is-open');
    mobileMenu.setAttribute('aria-hidden', 'false');
    toggle.setAttribute('aria-expanded', 'true');
    lastToggleTime = Date.now();
  }

  function closeMenu() {
    toggle.classList.remove('is-open');
    mobileMenu.classList.remove('is-open');
    mobileMenu.setAttribute('aria-hidden', 'true');
    toggle.setAttribute('aria-expanded', 'false');
    lastToggleTime = Date.now();
  }

  function toggleMenu() {
    if (mobileMenu.classList.contains('is-open')) {
      closeMenu();
    } else {
      openMenu();
    }
  }

  // Seul event listener : click (fiable sur tous les navigateurs
  // mobiles dès que touch-action:manipulation est en CSS)
  toggle.addEventListener('click', function(e) {
    e.stopPropagation(); // empêche la propagation vers document
    toggleMenu();
  });

  // Fermer si clic AILLEURS que le toggle ou le menu
  // On attend 50ms pour éviter les ghost events iOS
  document.addEventListener('click', function(e) {
    if (!mobileMenu.classList.contains('is-open')) return;
    // Ignorer si l'événement arrive dans les 50ms après un toggle
    if (Date.now() - lastToggleTime < 50) return;
    if (toggle.contains(e.target) || mobileMenu.contains(e.target)) return;
    closeMenu();
  });

  // Fermer quand on navigue vers un lien du menu
  var menuLinks = mobileMenu.querySelectorAll('a');
  for (var i = 0; i < menuLinks.length; i++) {
    menuLinks[i].addEventListener('click', closeMenu);
  }
}
