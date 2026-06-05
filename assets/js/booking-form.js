/**
 * SPORT+ — Formulaire de réservation interactif (vanilla JS)
 * Gère la sélection par cards, le calcul de prix live via /api/pricing-preview.
 */
(function () {
  'use strict';

  // ── État courant ────────────────────────────────────────────────────────────
  const state = {
    format:     'solo',
    slot:       'day',
    pack:       'single',
    persons:    1,
    fullAccess: false,
  };

  // ── Helpers DOM ─────────────────────────────────────────────────────────────
  const $ = (id) => document.getElementById(id);
  const sel = (selector) => document.querySelector(selector);
  const selAll = (selector) => document.querySelectorAll(selector);

  // ── Masquer les fallback selects (graceful degradation) ─────────────────────
  document.querySelectorAll('.booking-fallback-field').forEach(el => {
    el.style.display = 'none';
  });

  // ── Sync cards ↔ select caché ───────────────────────────────────────────────
  function getSelectId(selectorType) {
    const map = { format: 'booking_format', slot: 'booking_timeslot', pack: 'booking_packtype' };
    return map[selectorType];
  }

  function setSelectValue(selectorType, value) {
    const selectEl = $(getSelectId(selectorType));
    if (selectEl) {
      selectEl.value = value;
      selectEl.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function syncCardsFromSelects() {
    [
      { type: 'format', id: 'booking_format' },
      { type: 'slot',   id: 'booking_timeslot' },
      { type: 'pack',   id: 'booking_packtype' },
    ].forEach(({ type, id }) => {
      const select = $(id);
      if (!select || !select.value) return;
      const val = select.value;
      if (type === 'format') state.format = val;
      if (type === 'slot')   state.slot   = val;
      if (type === 'pack')   state.pack   = val;
      const grid = sel(`[data-selector="${type}"]`);
      if (grid) activateCard(grid.querySelector(`[data-value="${val}"]`), grid);
    });
  }

  // ── Activer une card dans son groupe ────────────────────────────────────────
  function activateCard(card, grid) {
    if (!card || !grid) return;
    grid.querySelectorAll('.selector-card, .bk-choice').forEach(c => {
      c.classList.remove('selected');
      c.setAttribute('aria-pressed', 'false');
    });
    card.classList.add('selected');
    card.setAttribute('aria-pressed', 'true');
  }

  // ── Attacher les événements sur toutes les grilles de sélection ─────────────
  selAll('[data-selector]').forEach(grid => {
    const selectorType = grid.dataset.selector;

    grid.querySelectorAll('.selector-card, .bk-choice').forEach(card => {
      // Clic
      card.addEventListener('click', () => handleCardSelect(selectorType, card, grid));
      // Clavier (Enter / Espace)
      card.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          handleCardSelect(selectorType, card, grid);
        }
      });
    });
  });

  function handleCardSelect(type, card, grid) {
    const value = card.dataset.value;
    if (!value) return;

    activateCard(card, grid);
    setSelectValue(type, value);

    state[type === 'slot' ? 'slot' : type] = value;

    // Mise à jour personnes selon format
    if (type === 'format') {
      updatePersonsFromFormat(value);
    }

    // Synchroniser l'option FullAccess selon le pack
    if (type === 'pack') {
      syncFullAccessAvailability();
    }

    updatePricing();
  }

  // ── Gestion du champ "Nombre de participants" (GROUP uniquement) ─────────────
  function updatePersonsFromFormat(format) {
    const wrapper = $('group-persons-wrapper');
    if (!wrapper) return;
    if (format === 'group') {
      wrapper.style.display = '';
      state.persons = 4;
    } else {
      wrapper.style.display = 'none';
      state.persons = format === 'duo' ? 2 : 1;
    }
    const fullAccessPrice = $('fullaccess-price');
    if (fullAccessPrice) {
      fullAccessPrice.textContent = format === 'group' ? '25' : '30';
    }
  }

  // Écouter le changement du select de personnes (GROUP)
  const personsSelect = sel('[name="booking[personsCount]"]');
  if (personsSelect) {
    personsSelect.addEventListener('change', () => {
      state.persons = parseInt(personsSelect.value, 10) || 4;
      updatePricing();
    });
  }

  // ── FullAccess toggle ────────────────────────────────────────────────────────
  const fullAccessCheckbox = $('booking_fullAccess');
  const fullAccessToggle   = $('fullaccess-toggle');

  if (fullAccessCheckbox) {
    fullAccessCheckbox.addEventListener('change', () => {
      state.fullAccess = fullAccessCheckbox.checked;
      if (fullAccessToggle) {
        fullAccessToggle.classList.toggle('checked', state.fullAccess);
      }
      updatePricing();
    });
  }

  // FullAccess n'a de sens que sur un pack mensuel — désactivé en single.
  function syncFullAccessAvailability() {
    if (!fullAccessCheckbox || !fullAccessToggle) return;
    const isSingle = state.pack === 'single';
    if (isSingle) {
      if (fullAccessCheckbox.checked) {
        fullAccessCheckbox.checked = false;
        state.fullAccess = false;
        fullAccessToggle.classList.remove('checked');
      }
      fullAccessCheckbox.disabled = true;
      fullAccessToggle.classList.add('is-disabled');
      fullAccessToggle.setAttribute('aria-disabled', 'true');
      fullAccessToggle.title = 'Disponible uniquement avec un pack mensuel (4, 8 ou 12 séances)';
    } else {
      fullAccessCheckbox.disabled = false;
      fullAccessToggle.classList.remove('is-disabled');
      fullAccessToggle.removeAttribute('aria-disabled');
      fullAccessToggle.removeAttribute('title');
    }
  }

  // ── Appel API pricing ────────────────────────────────────────────────────────
  let debounceTimer = null;

  function updatePricing() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(fetchPricing, 120);
  }

  function fetchPricing() {
    const params = new URLSearchParams({
      format:     state.format,
      slot:       state.slot,
      pack:       state.pack,
      persons:    state.persons,
      fullAccess: state.fullAccess ? '1' : '0',
    });

    fetch(`/reservation/api/pricing-preview?${params}`)
      .then(r => r.json())
      .then(data => applyPricingToUI(data))
      .catch(() => {});
  }

  // ── Appliquer les prix à l'UI ────────────────────────────────────────────────
  function applyPricingToUI(data) {
    // Récap sidebar
    if ($('sum-format')) $('sum-format').textContent = data.format || '—';
    if ($('sum-slot'))   $('sum-slot').textContent   = data.slot || '—';
    if ($('sum-pack'))   $('sum-pack').textContent   = data.pack || 'Séance unique';

    const sumFullAccess = $('sum-fullaccess');
    if (sumFullAccess) {
      sumFullAccess.textContent = state.fullAccess
        ? `Oui (+${state.format === 'group' ? '25' : '30'} €)`
        : 'Non';
    }

    const sumPrice  = $('sum-price');
    const sumPeriod = $('sum-period');
    const sumSavings = $('sum-savings');

    // Mise à jour du prix dans le bouton submit
    const submitPrice = $('bk-submit-price');

    if (data.monthly) {
      // Pack
      if (sumPrice)    sumPrice.textContent    = data.monthly;
      if (submitPrice) submitPrice.textContent = data.monthly;
      if (sumPeriod) sumPeriod.textContent  = `/ mois · ${data.packSessions || ''} séances`;
      if (sumSavings && data.savingsRaw > 0) {
        sumSavings.style.display = '';
        sumSavings.textContent   = `Économie : ${data.savingsPerPerson} / pers. vs séances unitaires`;
      } else if (sumSavings) {
        sumSavings.style.display = 'none';
      }
    } else {
      // Séance unique
      if (sumPrice)    sumPrice.textContent    = data.singleTotal || '—';
      if (submitPrice) submitPrice.textContent = data.singleTotal || '—';
      if (sumPeriod) sumPeriod.textContent  = 'pour cette séance';
      if (sumSavings) sumSavings.style.display = 'none';
    }

    // Prix dans les cards Pack
    const packs = ['pack_4', 'pack_8', 'pack_12'];
    packs.forEach(pack => {
      const priceEl   = $(`price-pack-${pack}`);
      const economyEl = $(`economy-${pack}`);
      // Calcul local basé sur les prices du dernier appel API (non disponibles ici)
      // On met à jour lors du survol ou au prochain appel complet
    });

    // Mise à jour du prix dans la card Séance unique
    const singleCard = $('price-pack-single');
    if (singleCard && data.singleTotal) {
      singleCard.textContent = data.singleTotal;
    }

    // Déclencher un batch pour afficher les prix dans chaque card pack
    fetchPackPrices();
  }

  // ── Récupérer les prix de tous les packs pour les afficher dans les cards ────
  function fetchPackPrices() {
    ['pack_4', 'pack_8', 'pack_12'].forEach(pack => {
      const params = new URLSearchParams({
        format:     state.format,
        slot:       state.slot,
        pack:       pack,
        persons:    state.persons,
        fullAccess: state.fullAccess ? '1' : '0',
      });

      fetch(`/reservation/api/pricing-preview?${params}`)
        .then(r => r.json())
        .then(data => {
          const priceEl   = $(`price-pack-${pack}`);
          const economyEl = $(`economy-${pack}`);
          if (priceEl && data.monthly) {
            priceEl.textContent = data.monthly + '/mois';
          }
          if (economyEl && data.savingsRaw > 0) {
            economyEl.style.display = '';
            economyEl.textContent   = `Économie ${data.savingsPerPerson}`;
          } else if (economyEl) {
            economyEl.style.display = 'none';
          }
        })
        .catch(() => {});
    });
  }

  // ── Flatpickr — calendrier date/heure premium ────────────────────────────────
  function initDatePicker() {
    if (typeof flatpickr === 'undefined') return;

    const input = document.getElementById('booking_startAt');
    if (!input) return;

    flatpickr(input, {
      locale:          'fr',
      enableTime:      true,
      dateFormat:      'Y-m-d\\TH:i',   // format Symfony datetime-local
      altInput:        true,
      altFormat:       'l j F Y à H\\hi',
      minDate:         'today',
      minuteIncrement: 30,
      time_24hr:       true,
      disableMobile:   true,            // forcer le picker custom sur mobile aussi
      onChange: function(selectedDates) {
        if (!selectedDates[0]) return;
        const hour = selectedDates[0].getHours();
        // Hint visuel selon le créneau sélectionné vs heure choisie
        const hint = document.getElementById('slot-hint');
        if (!hint) return;
        const slotMap = {
          day:        hour >= 6  && hour < 20,
          night:      hour >= 20 || hour === 0,
          astreinte:  hour >= 0  && hour < 6,
        };
        const isOk = slotMap[state.slot] ?? true;
        hint.style.color = isOk ? 'var(--text-dim)' : 'var(--danger, #ff6666)';
        hint.innerHTML = isOk
          ? '<i class="ti ti-check" style="color:#7AB85A;"></i> Heure cohérente avec le créneau sélectionné'
          : '<i class="ti ti-alert-triangle" style="color:#ff6666;"></i> Attention : l\'heure ne correspond pas au créneau sélectionné (Journée 6h–20h · Night 20h–minuit · Astreinte minuit–6h)';
      },
    });
  }

  // ── Initialisation au chargement ─────────────────────────────────────────────
  syncCardsFromSelects();
  updatePersonsFromFormat(state.format);
  syncFullAccessAvailability();
  updatePricing();
  fetchPackPrices();

  // Flatpickr chargé en différé via CDN — on attend qu'il soit disponible
  if (typeof flatpickr !== 'undefined') {
    initDatePicker();
  } else {
    document.addEventListener('DOMContentLoaded', () => {
      const check = setInterval(() => {
        if (typeof flatpickr !== 'undefined') { clearInterval(check); initDatePicker(); }
      }, 100);
      setTimeout(() => clearInterval(check), 5000);
    });
  }

})();

// ── MODAL "VOUS ÊTES COMBIEN ?" pour Group ──
(function() {
  const modal = document.getElementById('group-modal');
  if (!modal) return;

  const personsSel = document.querySelector('[name="booking[personsCount]"]');
  const groupCard  = document.querySelector('[data-selector="format"] [data-value="group"]');

  function openModal() {
    modal.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeModal() {
    modal.hidden = true;
    document.body.style.overflow = '';
  }

  // Ouvre le modal quand on clique sur la card Group
  if (groupCard) {
    groupCard.addEventListener('click', () => {
      setTimeout(openModal, 150);
    });
  }

  // Clic sur une option → set personsCount + close
  modal.querySelectorAll('.group-modal-option').forEach(btn => {
    btn.addEventListener('click', () => {
      const n = btn.dataset.persons;
      if (personsSel) {
        personsSel.value = n;
        personsSel.dispatchEvent(new Event('change', { bubbles: true }));
      }
      modal.querySelectorAll('.group-modal-option').forEach(b => b.classList.remove('is-selected'));
      btn.classList.add('is-selected');
      closeModal();
    });
  });

  // Fermer
  modal.querySelectorAll('[data-close]').forEach(el => {
    el.addEventListener('click', closeModal);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && !modal.hidden) closeModal();
  });
})();
