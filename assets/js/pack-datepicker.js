/**
 * SPORT+ — Flatpickr pour la page /mon-espace/mes-packs/{id}/reserver
 *
 * Reprend la MÊME config que booking-form.js (locale FR, format Symfony
 * datetime-local, thème custom via booking.css .flatpickr-*), et ajoute
 * les bornes horaires minTime/maxTime selon le créneau du pack.
 *
 * Les bornes sont passées par le template via data-attributes sur l'input :
 *   data-slot        : 'day' | 'night' | 'astreinte'
 *   data-min-time    : "06:00" / "20:00" / "00:00"  (inclusive)
 *   data-max-time    : "19:59" / "23:59" / "05:59"  (inclusive)
 *
 * ⚠️ Ces bornes sont un CONFORT UX. La vérification serveur
 * (TimeSlot::fromDateTime dans ClientController::packBookingNew) est la
 * source de vérité et RESTE en place — le JS peut être contourné, pas
 * le serveur.
 *
 * Approche : script dédié, PAS de refactor du booking-form.js existant.
 * Justification : la page réservation est stable, on ne prend pas le
 * risque de la casser pour factoriser une config de ~15 lignes.
 */
(function () {
  'use strict';

  function init() {
    if (typeof flatpickr === 'undefined') return false;

    const input = document.getElementById('pack_booking_startAt');
    if (!input) return true; // pas sur cette page — ok

    const minTime = input.dataset.minTime || null;
    const maxTime = input.dataset.maxTime || null;

    flatpickr(input, {
      locale:          'fr',
      enableTime:      true,
      dateFormat:      'Y-m-d\\TH:i',   // format Symfony datetime-local
      altInput:        true,
      altFormat:       'l j F Y à H\\hi',
      minDate:         'today',
      minuteIncrement: 15,
      time_24hr:       true,
      disableMobile:   true,
      minTime:         minTime,
      maxTime:         maxTime,
    });

    return true;
  }

  // Flatpickr est chargé via CDN avec defer — on attend qu'il soit dispo.
  if (init() !== true) {
    document.addEventListener('DOMContentLoaded', function () {
      if (init()) return;
      const timer = setInterval(function () {
        if (init()) clearInterval(timer);
      }, 50);
      // Filet : arrêter la boucle après 5s si Flatpickr n'arrive jamais
      setTimeout(function () { clearInterval(timer); }, 5000);
    });
  }
})();
