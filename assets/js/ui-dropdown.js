/**
 * ui-dropdown.js — composant dropdown custom RÉUTILISABLE (2 modes).
 *
 * ═══════════════════════════════════════════════════════════════════════
 * MODE 1 — NAVIGATION (existant, admin/paiements)
 * ═══════════════════════════════════════════════════════════════════════
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
 *     </div>
 *   </div>
 *
 * Comportement : clic sur <a> → navigation naturelle. Aucune synchro.
 *
 * ═══════════════════════════════════════════════════════════════════════
 * MODE 2 — FORMULAIRE (nouveau, pour les <select> Symfony/HTML)
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   <div class="ui-dropdown ui-dropdown--form" data-dropdown
 *        data-dropdown-mode="form" data-dropdown-target="booking_coach">
 *     <select id="booking_coach" name="booking[coach]"
 *             class="ui-dropdown-hidden-select" tabindex="-1" aria-hidden="true">
 *       <option value="">Sélectionner un coach</option>
 *       <option value="1" selected>Karim Coacheee</option>
 *     </select>
 *     <button type="button" class="ui-dropdown-trigger" aria-haspopup="listbox" aria-expanded="false">
 *       <span class="ui-dropdown-eyebrow">Coach</span>
 *       <span class="ui-dropdown-current">— rempli par JS —</span>
 *       <i class="ti ti-chevron-down ui-dropdown-caret" aria-hidden="true"></i>
 *     </button>
 *     <div class="ui-dropdown-panel" role="listbox">
 *       <!-- Généré par JS depuis les <option> du select -->
 *     </div>
 *   </div>
 *
 * Le select réel reste dans le DOM (validation Symfony, form_errors, prefill,
 * dégradation gracieuse si JS échoue). Le JS le pilote :
 *   1. Au init : lit les <option>, remplit le panneau, affiche l'option
 *      sélectionnée dans .ui-dropdown-current
 *   2. Clic sur une option ui-dropdown : sync select.value + dispatch un
 *      event 'change' (bubbles) pour que tout JS parent qui écoute
 *      continue de fonctionner (booking-form.js prix live, etc.)
 *
 * ═══════════════════════════════════════════════════════════════════════
 * COMMUN (les deux modes)
 * ═══════════════════════════════════════════════════════════════════════
 *   - Click trigger              → toggle .is-open + aria-expanded
 *   - Click extérieur ou Escape  → ferme tous
 *   - Flèches ↑ ↓                → ouvrir + naviguer options
 *   - Enter / Space              → sélectionner option focused
 *   - data-dropdown-align="right" → panneau ancré à droite
 *
 * Auto-init sur DOMContentLoaded, ré-init via window.uiDropdown.init().
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

  function openDropdown(dd) {
    closeAll(dd);
    dd.classList.add('is-open');
    var btn = dd.querySelector('.ui-dropdown-trigger');
    if (btn) btn.setAttribute('aria-expanded', 'true');
  }

  function closeDropdown(dd) {
    dd.classList.remove('is-open');
    var btn = dd.querySelector('.ui-dropdown-trigger');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  function toggleDropdown(dd) {
    if (dd.classList.contains('is-open')) closeDropdown(dd);
    else openDropdown(dd);
  }

  // ─────────────────────────────────────────────────────────────────────
  // MODE FORMULAIRE — pilotage d'un <select> masqué
  // ─────────────────────────────────────────────────────────────────────

  /**
   * Génère les options ui-dropdown dans le panneau depuis les <option> du
   * <select> cible, et met à jour .ui-dropdown-current avec la valeur
   * actuellement sélectionnée.
   */
  function syncFromSelect(dd) {
    var targetId = dd.dataset.dropdownTarget;
    if (!targetId) return null;
    var select = document.getElementById(targetId);
    if (!select) return null;

    var panel = dd.querySelector('.ui-dropdown-panel');
    var current = dd.querySelector('.ui-dropdown-current');
    if (!panel || !current) return select;

    // Générer les boutons ui-dropdown-option depuis les <option> du select.
    // Boutons type="button" pour ne pas submit le form au clic.
    // Une entrée par option, marquée is-active si sélectionnée.
    var html = '';
    var selectedLabel = '';
    var options = select.querySelectorAll('option');
    for (var i = 0; i < options.length; i++) {
      var o = options[i];
      var val = o.value;
      var label = o.textContent.trim();
      var isSelected = o.selected;
      if (isSelected) selectedLabel = label;
      html += '<button type="button" class="ui-dropdown-option'
        + (isSelected ? ' is-active' : '')
        + '" data-value="' + val.replace(/"/g, '&quot;')
        + '" role="option"'
        + ' aria-selected="' + (isSelected ? 'true' : 'false') + '">'
        + '<span class="ui-dropdown-option-label">'
        + (label ? label : '<span style="color:var(--text-dim);">—</span>')
        + '</span>'
        + '<i class="ti ti-check ui-dropdown-option-check" aria-hidden="true"></i>'
        + '</button>';
    }
    panel.innerHTML = html;

    // Placeholder si rien de sélectionné : premier label vide ou "—"
    if (!selectedLabel && options.length) {
      selectedLabel = options[0].textContent.trim() || '—';
    }
    current.textContent = selectedLabel;

    return select;
  }

  /**
   * Handler de clic sur une option en mode form :
   *  - met à jour select.value + option.selected
   *  - dispatch event 'change' pour listeners tiers (booking-form.js, etc.)
   *  - synchronise l'affichage
   *  - ferme le panneau
   */
  function handleFormOptionClick(dd, select, optionEl) {
    var val = optionEl.dataset.value;

    // Mettre à jour select.value + marquer la bonne <option> selected
    select.value = val;
    var opts = select.querySelectorAll('option');
    for (var i = 0; i < opts.length; i++) {
      opts[i].selected = (opts[i].value === val);
    }

    // Dispatch 'change' bubbling pour que le JS parent capture (prix live,
    // toggle chips paiement, etc.). Attention : new Event('change') avec
    // bubbles: true, sinon les listeners sur document/window ne captent pas.
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.dispatchEvent(new Event('input', { bubbles: true }));

    // Refresh UI dropdown
    var current = dd.querySelector('.ui-dropdown-current');
    if (current) current.textContent = optionEl.querySelector('.ui-dropdown-option-label').textContent;
    var allOpts = dd.querySelectorAll('.ui-dropdown-option');
    for (var j = 0; j < allOpts.length; j++) {
      var isMatch = (allOpts[j] === optionEl);
      allOpts[j].classList.toggle('is-active', isMatch);
      allOpts[j].setAttribute('aria-selected', isMatch ? 'true' : 'false');
    }

    closeDropdown(dd);
    var trigger = dd.querySelector('.ui-dropdown-trigger');
    if (trigger) trigger.focus();
  }

  // ─────────────────────────────────────────────────────────────────────
  // NAVIGATION CLAVIER dans le panneau ouvert
  // ─────────────────────────────────────────────────────────────────────

  function focusableOptions(dd) {
    return dd.querySelectorAll('.ui-dropdown-option');
  }

  function focusOptionByIndex(dd, index) {
    var opts = focusableOptions(dd);
    if (!opts.length) return;
    var i = Math.max(0, Math.min(opts.length - 1, index));
    opts[i].focus();
    opts[i].scrollIntoView({ block: 'nearest' });
  }

  // ─────────────────────────────────────────────────────────────────────
  // INIT
  // ─────────────────────────────────────────────────────────────────────

  function init() {
    document.querySelectorAll('[data-dropdown]').forEach(function (dd) {
      if (dd.dataset.dropdownInit === '1') return;
      dd.dataset.dropdownInit = '1';

      var trigger = dd.querySelector('.ui-dropdown-trigger');
      if (!trigger) return;

      var isFormMode = dd.dataset.dropdownMode === 'form';
      var select = null;
      if (isFormMode) {
        select = syncFromSelect(dd);
        if (!select) return;

        // Si le select natif change de valeur par autre chose (JS externe,
        // reset form), reflète-le côté ui-dropdown.
        select.addEventListener('change', function () {
          syncFromSelect(dd);
        });
      }

      // Toggle au clic du trigger
      trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        toggleDropdown(dd);
      });

      // Navigation clavier depuis le trigger
      trigger.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
          e.preventDefault();
          if (!dd.classList.contains('is-open')) openDropdown(dd);
          focusOptionByIndex(dd, e.key === 'ArrowDown' ? 0 : focusableOptions(dd).length - 1);
        } else if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          toggleDropdown(dd);
        }
      });

      // Clic sur les options en mode form
      if (isFormMode) {
        dd.querySelector('.ui-dropdown-panel').addEventListener('click', function (e) {
          var optionEl = e.target.closest('.ui-dropdown-option');
          if (!optionEl) return;
          e.preventDefault();
          e.stopPropagation();
          handleFormOptionClick(dd, select, optionEl);
        });

        // Navigation clavier dans le panneau
        dd.addEventListener('keydown', function (e) {
          if (!dd.classList.contains('is-open')) return;
          var opts = focusableOptions(dd);
          var currentIndex = -1;
          for (var i = 0; i < opts.length; i++) {
            if (opts[i] === document.activeElement) { currentIndex = i; break; }
          }
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            focusOptionByIndex(dd, currentIndex + 1);
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            focusOptionByIndex(dd, currentIndex - 1);
          } else if (e.key === 'Enter' || e.key === ' ') {
            if (document.activeElement && document.activeElement.classList.contains('ui-dropdown-option')) {
              e.preventDefault();
              handleFormOptionClick(dd, select, document.activeElement);
            }
          }
        });
      }
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
      closeDropdown(open);
      var btn = open.querySelector('.ui-dropdown-trigger');
      if (btn) btn.focus();
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.uiDropdown = { init: init };
})();
