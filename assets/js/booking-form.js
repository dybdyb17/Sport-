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
    // Essaye 1 : par ID forcé dans BookingType
    let selectEl = $(getSelectId(selectorType));
    // Essaye 2 : par name="booking[timeSlot]" si l'ID a été ré-écrit par Symfony
    if (!selectEl) {
      const nameMap = { format: 'booking[format]', slot: 'booking[timeSlot]', pack: 'booking[packType]' };
      selectEl = document.querySelector(`[name="${nameMap[selectorType]}"]`);
    }
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

    // Groupe : pas de paiement en ligne possible → on affiche un avertissement
    // et on masque la carte Stripe (via attribut data-format sur le block parent).
    // Si le client avait coché Stripe, on bascule sur "cash" par défaut.
    const payBlock = document.querySelector('.bk-pay-block');
    const payWarn  = document.getElementById('bk-pay-group-warning');
    if (payBlock) {
      payBlock.dataset.format = format;
    }
    if (payWarn) {
      payWarn.hidden = format !== 'group';
    }
    if (format === 'group') {
      const stripeRadio = document.querySelector('input[name="intended_payment_method"][value="stripe"]');
      if (stripeRadio && stripeRadio.checked) {
        const cashRadio = document.querySelector('input[name="intended_payment_method"][value="cash"]');
        if (cashRadio) {
          cashRadio.checked = true;
          // Trigger un event pour que les helpers (bk-pay-info) se mettent à jour
          cashRadio.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }
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
        const date = selectedDates[0];
        const hour = date.getHours();

        // Calcul plage horaire (début → fin = +1h, à afficher en direct partout)
        const pad = (n) => String(n).padStart(2, '0');
        const startStr = pad(date.getHours()) + 'h' + pad(date.getMinutes());
        const endDate  = new Date(date.getTime() + 60 * 60 * 1000);
        const endStr   = pad(endDate.getHours()) + 'h' + pad(endDate.getMinutes());
        const range    = startStr + ' → ' + endStr;

        // Récap latéral : ligne "Horaires"
        const recapRow  = document.getElementById('sum-timerange-row');
        const recapVal  = document.getElementById('sum-timerange');
        if (recapRow && recapVal) {
          recapVal.textContent = range + ' · 1h';
          recapRow.style.display = '';
        }
        // Hint sous le champ : préview à côté du message
        const preview = document.getElementById('time-range-preview');
        if (preview) {
          preview.textContent = '· ' + range + ' (1h)';
          preview.style.display = 'inline';
        }

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
          ? '<i class="ti ti-check" style="color:#7AB85A;"></i> Heure cohérente avec le créneau · <span style="color:var(--gold);font-weight:600;">' + range + ' (1h)</span>'
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

  // La modal est imbriquée dans le form qui peut avoir un ancêtre avec
  // transform/filter/will-change → ça neutralise position:fixed et confine
  // le z-index. On la déplace en racine du body pour que le z-index 9999
  // soit réellement global et qu'elle passe devant stepper/sidebar/header.
  if (modal.parentElement !== document.body) {
    document.body.appendChild(modal);
  }

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

// ── Choix mode de paiement (étape 4) : afficher l'info contextuelle
//    + adapter le bouton de soumission + la note.
//
// L'affichage dépend de DEUX dimensions :
//   1. mode de paiement (cash / card / stripe)
//   2. séance à l'unité vs pack (packType != 'single')
//
// ⚠️ Source de vérité pour "pack sélectionné" : le SELECT caché
//    #booking_packtype (ou name booking[packType]). Le formulaire utilise
//    des div.bk-choice cliquables — PAS de radios — donc :checked ne
//    match rien. Il faut lire .value du select, qui est synchro par
//    setSelectValue() plus haut dans ce même fichier.
(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var radios     = document.querySelectorAll('.bk-pay-card input[type="radio"]');
    var infos      = document.querySelectorAll('.bk-pay-info');
    var packSelect = document.getElementById('booking_packtype')
                  || document.querySelector('[name="booking[packType]"]');
    var packChoices = document.querySelectorAll('[data-selector="pack"] .bk-choice, [data-selector="pack"] .selector-card');

    // ── Bouton + note dynamiques ───────────────────────────────────
    var labelEl = document.getElementById('bk-submit-label');
    var iconEl  = document.getElementById('bk-submit-icon');
    var noteEl  = document.getElementById('bk-submit-note-text');
    var noteIcon = document.getElementById('bk-submit-note-icon');

    // Format : [icon-classe, label bouton, icon note, texte note]
    var VARIANTS = {
      'single|null':   ['ti-send',             'Envoyer la demande au coach',
                        'ti-shield-check',     'Aucun paiement maintenant — uniquement si le coach confirme.'],
      'single|cash':   ['ti-send',             'Envoyer la demande au coach',
                        'ti-shield-check',     'Aucun paiement maintenant — tu régleras en espèces au club, une fois la séance confirmée par le coach.'],
      'single|card':   ['ti-send',             'Envoyer la demande au coach',
                        'ti-shield-check',     'Aucun paiement maintenant — tu régleras par carte au club, une fois la séance confirmée par le coach.'],
      'single|stripe': ['ti-send',             'Envoyer la demande au coach',
                        'ti-shield-check',     'Aucun paiement maintenant — tu régleras en ligne via Stripe une fois la séance confirmée par le coach.'],
      'pack|cash':     ['ti-send',             'Envoyer ma demande de pack',
                        'ti-info-circle',      'Ta demande sera enregistrée. Tu paies en espèces au club — le pack est activé dès que le coach confirme l\'encaissement.'],
      'pack|card':     ['ti-send',             'Envoyer ma demande de pack',
                        'ti-info-circle',      'Ta demande sera enregistrée. Tu paies par carte au club — le pack est activé dès que le coach confirme l\'encaissement.'],
      'pack|stripe':   ['ti-credit-card-pay',  'Payer mon pack en ligne',
                        'ti-lock',             'Tu vas être redirigé vers Stripe pour régler ton pack maintenant. Ton pack est activé dès validation du paiement.'],
      'pack|null':     ['ti-send',             'Envoyer ma demande de pack',
                        'ti-info-circle',      'Choisis un mode de paiement pour finaliser ta demande.'],
    };

    function isPackSelected() {
      // Lit la vraie source utilisée par le formulaire (select caché).
      // NE PAS utiliser :checked : les .bk-choice sont des div, pas des radios.
      var v = packSelect ? packSelect.value : null;
      return !!(v && v !== 'single');
    }

    function getMode() {
      var sel = document.querySelector('.bk-pay-card input[type="radio"]:checked');
      return sel ? sel.value : null;
    }

    function syncAll() {
      var packBit = isPackSelected() ? 'pack' : 'single';
      var mode    = getMode();
      var modeBit = mode || 'null';

      // 1) Blocs d'info explicatifs
      infos.forEach(function(info) {
        var matchMode = (info.dataset.showFor === mode);
        var matchPack = (info.dataset.showForPack === (isPackSelected() ? '1' : '0'));
        info.hidden = !(matchMode && matchPack);
      });

      // 2) Bouton + note
      var variant = VARIANTS[packBit + '|' + modeBit] || VARIANTS['single|null'];
      if (iconEl)  { iconEl.className = 'ti ' + variant[0]; }
      if (labelEl) { labelEl.textContent = variant[1]; }
      if (noteIcon){ noteIcon.className = 'ti ' + variant[2]; }
      if (noteEl)  { noteEl.textContent = variant[3]; }
    }

    // Écoute tous les changements possibles
    radios.forEach(function(radio) { radio.addEventListener('change', syncAll); });
    // Le select caché est modifié par setSelectValue() qui dispatche 'change'
    if (packSelect) { packSelect.addEventListener('change', syncAll); }
    // Ceinture : écouter aussi les clics sur les div.bk-choice au cas où
    // (setSelectValue devrait déclencher change mais safety net)
    packChoices.forEach(function(el) {
      el.addEventListener('click', function() { setTimeout(syncAll, 0); });
    });

    syncAll();
  });
})();

// ── Stepper visuel : marque les étapes complétées au scroll
(function() {
  document.addEventListener('DOMContentLoaded', function() {
    var steps = document.querySelectorAll('.bk-step-dot');
    var sections = ['step-1', 'step-2', 'step-3', 'step-4'];
    var cards = sections.map(function(id) { return document.getElementById(id); });
    var progressFill = document.getElementById('bk-step-progress-fill');
    var recapStep = document.getElementById('bk-recap-step');
    var mobilePrice = document.getElementById('bk-mobile-price');
    var mobileAction = document.getElementById('bk-mobile-action');
    var currentStep = 0;

    // Active une étape au clic
    steps.forEach(function(dot) {
      function goToStep() {
        var target = document.getElementById(dot.dataset.target);
        if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      dot.addEventListener('click', goToStep);
      dot.addEventListener('keydown', function(event) {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          goToStep();
        }
      });
    });

    // Marque les étapes au scroll
    function updateStepper() {
      var scrollY = window.scrollY + window.innerHeight * 0.4;
      var lastVisible = 0;
      sections.forEach(function(id, idx) {
        var el = document.getElementById(id);
        if (el && el.offsetTop <= scrollY) lastVisible = idx;
      });
      currentStep = lastVisible;
      steps.forEach(function(dot, idx) {
        dot.classList.toggle('is-active', idx === lastVisible);
        dot.classList.toggle('is-done', idx < lastVisible);
        dot.setAttribute('aria-current', idx === lastVisible ? 'step' : 'false');
      });
      cards.forEach(function(card, idx) {
        if (!card) return;
        card.classList.toggle('is-current', idx === lastVisible);
        card.classList.toggle('is-complete', idx < lastVisible);
      });
      if (progressFill) progressFill.style.width = (lastVisible / (sections.length - 1) * 100) + '%';
      if (recapStep) recapStep.textContent = 'Étape ' + (lastVisible + 1) + '/' + sections.length;
      if (mobileAction) {
        mobileAction.innerHTML = lastVisible === sections.length - 1
          ? 'Finaliser <i class="ti ti-arrow-right"></i>'
          : 'Continuer <i class="ti ti-arrow-down"></i>';
      }
    }

    window.addEventListener('scroll', updateStepper, { passive: true });
    updateStepper();

    // Pulse le récap quand une valeur change
    var recap = document.getElementById('bk-recap');
    var sumPrice = document.getElementById('sum-price');
    var observer = new MutationObserver(function() {
      if (recap) {
        recap.classList.remove('is-pulse');
        void recap.offsetWidth;
        recap.classList.add('is-pulse');
      }
      if (mobilePrice && sumPrice) mobilePrice.textContent = sumPrice.textContent;
    });
    document.querySelectorAll('#sum-format,#sum-slot,#sum-pack,#sum-fullaccess,#sum-price').forEach(function(el) {
      observer.observe(el, { childList: true, characterData: true, subtree: true });
    });

    if (mobilePrice && sumPrice) mobilePrice.textContent = sumPrice.textContent;
    if (mobileAction) {
      mobileAction.addEventListener('click', function() {
        if (currentStep < sections.length - 1) {
          var next = cards[currentStep + 1];
          if (next) next.scrollIntoView({ behavior: 'smooth', block: 'start' });
          return;
        }
        var submit = document.querySelector('#booking-form .bk-submit');
        if (submit) submit.click();
      });
    }
  });
})();
