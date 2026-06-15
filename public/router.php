<?php
// Router pour `php -S` (serveur intégré PHP utilisé sur Railway).
// PHP-S avec script router exécute CE fichier pour CHAQUE requête, y compris
// celles vers /assets/styles/...css. Symfony ne route pas /assets, donc on
// doit servir les fichiers statiques nous-mêmes ET déléguer à index.php pour
// les vraies routes.
//
// Bonus perf : Cache-Control immutable sur les assets versionnés
// + compression gzip pour les contenus texte (CSS/JS/HTML) — `php -S` natif
// ne fait ni l'un ni l'autre.

$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = __DIR__ . $uri;

// Types de fichiers compressibles via gzip (gain réel, ne touche pas aux binaires).
$textExtensions = ['css', 'js', 'mjs', 'json', 'svg', 'txt', 'xml', 'html', 'webmanifest'];

if ($uri !== '/' && is_file($path)) {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // ── Compression : uniquement pour les contenus texte ─────────────
    if (in_array($ext, $textExtensions, true)) {
        ini_set('zlib.output_compression', 'On');
        ini_set('zlib.output_compression_level', '6');
    } else {
        // Images, fonts woff2, etc. : déjà compressés, ne pas re-gzipper
        ini_set('zlib.output_compression', 'Off');
    }

    // ── Headers de cache selon le type d'asset ───────────────────────
    if (str_starts_with($uri, '/assets/')) {
        // AssetMapper versionne le nom (ex: home-TVdRDpR.css) → immutable 1 an.
        header('Cache-Control: public, max-age=31536000, immutable');
    } elseif (str_starts_with($uri, '/img/') || $uri === '/favicon.svg') {
        // Images / favicon : 30 jours (révocables au prochain déploiement).
        header('Cache-Control: public, max-age=2592000');
    } elseif (in_array($ext, ['woff', 'woff2', 'ttf'], true)) {
        header('Cache-Control: public, max-age=31536000, immutable');
    } else {
        header('Cache-Control: public, max-age=3600');
    }

    // ── Mime type explicite pour les types que PHP-S ne reconnaît pas ──
    $mimes = [
        'css'         => 'text/css; charset=utf-8',
        'js'          => 'application/javascript; charset=utf-8',
        'mjs'         => 'application/javascript; charset=utf-8',
        'json'        => 'application/json; charset=utf-8',
        'svg'         => 'image/svg+xml',
        'webp'        => 'image/webp',
        'woff'        => 'font/woff',
        'woff2'       => 'font/woff2',
        'ttf'         => 'font/ttf',
        'webmanifest' => 'application/manifest+json',
    ];
    if (isset($mimes[$ext])) {
        header('Content-Type: ' . $mimes[$ext]);
    }

    readfile($path);
    return true;
}

// ── Sinon : c'est une route Symfony (HTML rendu par PHP) ─────────────
// On active la compression pour la sortie HTML/JSON de Symfony.
ini_set('zlib.output_compression', 'On');
ini_set('zlib.output_compression_level', '6');

require __DIR__ . '/index.php';
