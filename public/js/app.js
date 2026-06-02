/**
 * SPORT+ — interactions globales + animations
 * Compatible iOS 12+ / Safari / Android.
 */

// ═══════════════════════════════════════════════════════════════════
// SYSTÈME D'ANIMATIONS SPORT+
// ═══════════════════════════════════════════════════════════════════
(function() {
  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else { fn(); }
  }

  ready(function() {

    // ── 1. Intersection Observer : reveal au scroll ────────────────
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            io.unobserve(entry.target); // une seule fois
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

      document.querySelectorAll('[data-animate], [data-stagger]').forEach(function(el) {
        io.observe(el);
      });

      // ── 2. Compteurs animés (data-counter="42") ────────────────
      function animateCounter(el, target, duration) {
        var start = 0;
        var startTs = null;
        var hasPlus     = el.dataset.suffix === '+';
        var hasSlash24  = el.textContent.indexOf('/') !== -1;
        function step(ts) {
          if (!startTs) startTs = ts;
          var p = Math.min((ts - startTs) / duration, 1);
          var eased = 1 - Math.pow(1 - p, 3);
          var val = Math.round(start + (target - start) * eased);
          if (hasSlash24) {
            el.textContent = val + '/' + val;
          } else {
            el.textContent = val + (hasPlus ? '+' : '');
          }
          if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
      }

      var counterIO = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          if (entry.isIntersecting) {
            var el = entry.target;
            var target = parseInt(el.getAttribute('data-counter'), 10);
            if (!isNaN(target)) {
              animateCounter(el, target, 1200);
            }
            counterIO.unobserve(el);
          }
        });
      }, { threshold: 0.5 });

      document.querySelectorAll('[data-counter]').forEach(function(el) {
        counterIO.observe(el);
      });
    } else {
      // fallback sans IO : tout visible immédiatement
      document.querySelectorAll('[data-animate], [data-stagger]').forEach(function(el) {
        el.classList.add('is-visible');
      });
    }

    // ── 3. Tilt 3D sur les cards coachs ─────────────────────────────
    document.querySelectorAll('.coach-pro-card').forEach(function(card) {
      var bounds;
      function onMove(e) {
        if (!bounds) bounds = card.getBoundingClientRect();
        var x = (e.clientX - bounds.left) / bounds.width;
        var y = (e.clientY - bounds.top) / bounds.height;
        var rotY = (x - 0.5) * 10; // ±5°
        var rotX = (0.5 - y) * 8;
        card.style.transform = 'perspective(1000px) rotateX(' + rotX + 'deg) rotateY(' + rotY + 'deg) translateY(-4px)';
      }
      function onEnter() {
        bounds = card.getBoundingClientRect();
        card.classList.add('is-tilting');
      }
      function onLeave() {
        card.classList.remove('is-tilting');
        card.style.transform = '';
        bounds = null;
      }
      card.addEventListener('mouseenter', onEnter);
      card.addEventListener('mousemove', onMove);
      card.addEventListener('mouseleave', onLeave);
    });

    // ── 4. Bouton scroll-to-top ─────────────────────────────────────
    var scrollTop = document.createElement('button');
    scrollTop.className = 'scroll-top';
    scrollTop.setAttribute('aria-label', 'Retour en haut');
    scrollTop.innerHTML = '<i class="ti ti-arrow-up"></i>';
    document.body.appendChild(scrollTop);

    scrollTop.addEventListener('click', function() {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    // ── 5. Indicateur de progression scroll ─────────────────────────
    var progress = document.createElement('div');
    progress.className = 'scroll-progress';
    document.body.appendChild(progress);

    var scrollTicking = false;
    function onScroll() {
      if (scrollTicking) return;
      scrollTicking = true;
      requestAnimationFrame(function() {
        var scrolled = window.pageYOffset;
        var max = document.documentElement.scrollHeight - window.innerHeight;
        var pct = max > 0 ? (scrolled / max) * 100 : 0;
        progress.style.width = pct + '%';
        scrollTop.classList.toggle('is-visible', scrolled > 400);
        scrollTicking = false;
      });
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  });
})();


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
