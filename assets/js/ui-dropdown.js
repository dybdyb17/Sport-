/**
 * ui-dropdown.js — composant dropdown custom RÉUTILISABLE.
 *
 * Markup attendu (100 % générique, aucune donnée métier dedans) :
 *
 *   <div class="ui-dropdown" data-dropdown>
 *     <button type="button" class="ui-dropdown-trigger" aria-haspopup="listbox" aria-expanded="false">
 *       <span class="ui-dropdown-eyebrow">Méthode</span>
 *       <span class="ui-dropdown-current">Toutes</span>
 *       <i class="ti ti-chevron-down ui-dropdown-caret" aria-hidden="true"></i>
 *     </button>
 *     <div class="ui-dropdown-panel" role="listbox">
 *       <a href="?method=all" class="ui-dropdown-option is-active" data-value="all">
 *         <i class="ti ti-list ui-dropdown-option-icon"></i>
 *         <span class="ui-dropdown-option-label">Toutes</span>
 *         <i class="ti ti-check ui-dropdown-option-check"></i>
 *       </a>
 *       ...
 *     </div>
 *   </div>
 *
 * Comportement :
 *   - Click trigger  → toggle .is-open + aria-expanded
 *   - Click extérieur ou touche Escape → ferme tous
 *   - Click sur .ui-dropdown-option → navigation naturelle (les hrefs sont
 *     construits côté Twig avec les autres query params conservés). Aucun
 *     code métier ici.
 *   - Attribut optionnel data-dropdown-align="right" sur le conteneur pour
 *     ancrer le panneau au bord droit du trigger.
 *
 * Auto-init sur DOMContentLoaded, ré-init possible via window.uiDropdown.init()
 * (utile après injection dynamique).
 */
(function () {
  'use strict';

  function closeAll(except) {
    document.querySelectorAll('.ui-dropdown.is-open').forEach(function (el) {
      if (el === except) return;
      el.classList.remove('is-open');
      var btn = el.querySelector('.ui-dropdown-trigger');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }

  function toggleDropdown(dd) {
    var isOpen = dd.classList.contains('is-open');
    closeAll(isOpen ? null : dd);
    if (isOpen) {
      dd.classList.remove('is-open');
      var btn = dd.querySelector('.ui-dropdown-trigger');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    } else {
      dd.classList.add('is-open');
      var btn2 = dd.querySelector('.ui-dropdown-trigger');
      if (btn2) btn2.setAttribute('aria-expanded', 'true');
    }
  }

  function init() {
    document.querySelectorAll('[data-dropdown]').forEach(function (dd) {
      if (dd.dataset.dropdownInit === '1') return;
      dd.dataset.dropdownInit = '1';

      var trigger = dd.querySelector('.ui-dropdown-trigger');
      if (!trigger) return;

      trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        toggleDropdown(dd);
      });
    });
  }

  // Ferme tout au clic extérieur.
  document.addEventListener('click', function (e) {
    if (!e.target.closest('[data-dropdown]')) {
      closeAll(null);
    }
  });

  // Ferme tout à Escape + refocus le trigger du dropdown qui était ouvert.
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelector('.ui-dropdown.is-open');
    if (open) {
      open.classList.remove('is-open');
      var btn = open.querySelector('.ui-dropdown-trigger');
      if (btn) {
        btn.setAttribute('aria-expanded', 'false');
        btn.focus();
      }
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.uiDropdown = { init: init };
})();
