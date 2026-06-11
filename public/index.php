<?php

use App\Kernel;

// SPORT+ — fuseau horaire fixe Europe/Paris.
// Railway et la plupart des conteneurs prod tournent en UTC, ce qui décale
// toutes les heures de 1h ou 2h selon l'heure d'été. On fixe ici la timezone
// pour que les heures stockées et affichées soient cohérentes côté client.
date_default_timezone_set('Europe/Paris');
ini_set('date.timezone', 'Europe/Paris');

// Locale FR pour les fonctions natives PHP (date, strftime déprécié etc.)
setlocale(LC_TIME, 'fr_FR.UTF-8', 'fr_FR', 'french');

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
