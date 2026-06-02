/**
 * SPORT+ — interactions globales
 * Compatible iOS 12+ / Safari / Android / tous navigateurs mobiles.
 */

// ── Oeil mot de passe ─────────────────────────────────────────────────
// Fonction globale appelée via onclick="togglePassword(this)" sur le bouton.
// Garantie de fonctionner : pas de listener, pas de timing, pas de cache JS.
window.togglePassword = function(btn) {
  var wrapper = btn.parentNode;
  while (wrapper && !wrapper.classList.contains('password-field-wrapper')) {
    wrapper = wrapper.parentNode;
    if (wrapper === document.body) return;
  }
  if (!wrapper) return;

  var input = wrapper.querySelector('input');
  if (!input) return;

  if (input.type === 'password') {
    input.type = 'text';
    btn.innerHTML = '<i class="ti ti-eye-off"></i>';
  } else {
    input.type = 'password';
    btn.innerHTML = '<i class="ti ti-eye"></i>';
  }
};

// Auto-wrap : les inputs password sans wrapper sont enveloppés automatiquement.
function autoWrapPasswords() {
  var inputs = document.querySelectorAll('input[type="password"]');
  for (var i = 0; i < inputs.length; i++) {
    var input = inputs[i];
    if (input.parentNode && input.parentNode.classList && input.parentNode.classList.contains('password-field-wrapper')) continue;

    var wrapper = document.createElement('div');
    wrapper.className = 'password-field-wrapper';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'password-toggle';
    btn.setAttribute('aria-label', 'Afficher/masquer le mot de passe');
    btn.setAttribute('onclick', 'togglePassword(this)');
    btn.innerHTML = '<i class="ti ti-eye"></i>';
    wrapper.appendChild(btn);
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', autoWrapPasswords);
} else {
  autoWrapPasswords();
}

// ── Init dropdowns + mobile menu ─────────────────────────────────────
(function init() {
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setup);
  } else {
    setup();
  }
})();

function setup() {

  // ── User dropdown ─────────────────────────────────────────────
  var avatar   = document.querySelector('.user-avatar');
  var dropdown = document.querySelector('.user-dropdown');

  if (avatar && dropdown) {
    avatar.addEventListener('click', function(e) {
      e.stopPropagation();
      var open = dropdown.classList.toggle('is-open');
      avatar.setAttribute('aria-expanded', String(open));
    });

    document.addEventListener('click', function() {
      dropdown.classList.remove('is-open');
      avatar.setAttribute('aria-expanded', 'false');
    });
  }

  // ── Mobile burger menu ────────────────────────────────────────
  var toggle     = document.querySelector('.mobile-toggle');
  var mobileMenu = document.getElementById('mobileMenu');

  if (!toggle || !mobileMenu) return;

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
    if (mobileMenu.classList.contains('is-open')) closeMenu();
    else openMenu();
  }

  toggle.addEventListener('click', function(e) {
    e.stopPropagation();
    toggleMenu();
  });

  document.addEventListener('click', function(e) {
    if (!mobileMenu.classList.contains('is-open')) return;
    if (Date.now() - lastToggleTime < 50) return;
    if (toggle.contains(e.target) || mobileMenu.contains(e.target)) return;
    closeMenu();
  });

  var menuLinks = mobileMenu.querySelectorAll('a');
  for (var i = 0; i < menuLinks.length; i++) {
    menuLinks[i].addEventListener('click', closeMenu);
  }
}
