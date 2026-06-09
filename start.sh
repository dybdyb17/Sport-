#!/bin/bash
set -e

echo "=== SPORT+ — démarrage Railway ==="
echo "PHP version: $(php -v | head -1)"
echo "PORT: ${PORT:-8080}"
echo "APP_ENV: ${APP_ENV:-prod}"

# Étape 1 : Vérifier la connexion BDD avec retry (Railway parfois lent à provisionner)
echo "[1/6] Vérification connexion BDD..."
for i in 1 2 3 4 5; do
    if php bin/console doctrine:query:sql "SELECT 1" --env=prod --no-debug > /dev/null 2>&1; then
        echo "✓ BDD accessible"
        break
    fi
    echo "  Tentative $i/5 échouée, retry dans 3s..."
    sleep 3
    if [ $i -eq 5 ]; then
        echo "✗ BDD inaccessible après 5 tentatives, on continue quand même"
    fi
done

# Étape 2 : Schema update depuis les entités (compatible MySQL local + Postgres prod)
echo "[2/6] Mise à jour du schéma BDD..."
php bin/console doctrine:schema:update --force --env=prod --no-debug 2>&1 || {
    echo "WARN schema:update a échoué, on continue"
}

# Étape 3 : Cache prod
echo "[3/6] Cache prod..."
php bin/console cache:clear --env=prod --no-debug 2>&1 || echo "WARN cache:clear failed"
php bin/console cache:warmup --env=prod --no-debug 2>&1 || echo "WARN cache:warmup failed"

# Filet de sécurité : si public/assets/ est manquant ou incomplet, on recompile au runtime.
# Au build Docker on a déjà compilé, mais en cas de problème (volume monté, build raté en silence
# avant le hotfix, etc.) on garantit que les CSS/JS soient présents avant le démarrage du serveur.
if [ ! -d "public/assets" ] || [ -z "$(ls -A public/assets 2>/dev/null)" ]; then
    echo "→ public/assets/ vide ou absent, compilation runtime..."
    APP_ENV=prod APP_DEBUG=0 php bin/console asset-map:compile --no-debug 2>&1 || {
        echo "✗ ERREUR : asset-map:compile a échoué au runtime. CSS/JS ne seront pas servis."
    }
else
    echo "✓ public/assets/ présent ($(ls public/assets | wc -l) entrées top-level)"
fi

# Étape 4 : Valider le template réellement utilisé par la route /tarifs.
# Cette vérification est volontairement bloquante : mieux vaut arrêter un déploiement
# avec un message précis que démarrer Railway avec une page publique en erreur 500.
echo "[4/6] Validation du template Tarifs..."
php bin/console lint:twig \
    templates/public/tarifs_v2.html.twig \
    templates/public/_founding_offer.html.twig \
    --env=prod --no-debug

# Étape 5 : Créer admin par défaut s'il n'existe pas
echo "[5/6] Vérification admin..."
ADMIN_EXISTS=$(php bin/console doctrine:query:sql 'SELECT COUNT(*) FROM "user" WHERE roles LIKE '"'"'%ROLE_ADMIN%'"'"'' --env=prod --no-debug 2>/dev/null | grep -oE '[0-9]+' | tail -1 || echo "0")

if [ "$ADMIN_EXISTS" = "0" ]; then
    echo "Pas d'admin → création admin par défaut..."
    php bin/console app:create-admin \
        "${ADMIN_EMAIL:-admin@sportplus-marseille.fr}" \
        "${ADMIN_PASSWORD:-SportPlus2026!}" \
        "Admin SPORT+" \
        "0491422207" \
        --env=prod --no-debug || echo "WARN admin creation failed"
else
    echo "✓ Admin déjà présent"
fi

# Étape 6 : Seed Founding Offer si absente
echo "[6/6] Vérification offre Founding..."
FOUNDING_EXISTS=$(php bin/console doctrine:query:sql 'SELECT COUNT(*) FROM founding_offer' --env=prod --no-debug 2>/dev/null | grep -oE '[0-9]+' | tail -1 || echo "0")

if [ "$FOUNDING_EXISTS" = "0" ]; then
    echo "Pas d'offre Founding → seed..."
    php bin/console app:seed-founding-offer --env=prod --no-debug || echo "WARN founding seed failed"
else
    echo "✓ Offre Founding déjà présente"
fi

# Lancement du serveur PHP intégré sur le port fourni par Railway.
# On utilise public/router.php (et NON public/index.php directement) :
# - router.php sert les fichiers statiques (assets/, img/, js/, favicon...) en priorité
# - et délègue à index.php (Symfony) pour les vraies routes.
# Sans router.php, toutes les requêtes /assets/...css partiraient à Symfony → 404 → CSS manquants.
echo "=== Démarrage du serveur HTTP sur 0.0.0.0:${PORT:-8080} (router=public/router.php) ==="
exec php -S 0.0.0.0:${PORT:-8080} -t public/ public/router.php
