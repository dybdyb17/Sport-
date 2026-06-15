<?php
// Router pour `php -S` (serveur intégré PHP utilisé sur Railway).
// PHP-S avec script router exécute CE fichier pour CHAQUE requête, y compris
// celles vers /assets/styles/...css. Symfony ne route pas /assets, donc on
// doit servir les fichiers statiques nous-mêmes ET déléguer à index.php pour
// les vraies routes.

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = __DIR__ . $uri;

// Si la requête correspond à un fichier physique dans public/ (assets compilés,
// js/, img/, favicon, robots.txt, etc.) → retourner false pour que PHP-S
// le serve directement comme fichier statique (mode rapide, sans overhead PHP).
if ($uri !== '/' && is_file($path)) {
    return false;
}

// Sinon, déléguer au front controller Symfony.
require __DIR__ . '/index.php';
