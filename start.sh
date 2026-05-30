#!/bin/bash
set -e

echo "=== SPORT+ — démarrage Railway ==="
echo "PHP version: $(php -v | head -1)"
echo "PORT: ${PORT:-8080}"
echo "APP_ENV: ${APP_ENV:-prod}"

# Étape 1 : Vérifier la connexion BDD avec retry (Railway parfois lent à provisionner)
echo "[1/5] Vérification connexion BDD..."
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
echo "[2/5] Mise à jour du schéma BDD..."
php bin/console doctrine:schema:update --force --env=prod --no-debug 2>&1 || {
    echo "WARN schema:update a échoué, on continue"
}

# Étape 3 : Cache prod
echo "[3/5] Cache prod..."
php bin/console cache:clear --env=prod --no-debug 2>&1 || echo "WARN cache:clear failed"
php bin/console cache:warmup --env=prod --no-debug 2>&1 || echo "WARN cache:warmup failed"

# Étape 4 : Créer admin par défaut s'il n'existe pas
echo "[4/5] Vérification admin..."
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

# Étape 5 : Seed Founding Offer si absente
echo "[5/5] Vérification offre Founding..."
FOUNDING_EXISTS=$(php bin/console doctrine:query:sql 'SELECT COUNT(*) FROM founding_offer' --env=prod --no-debug 2>/dev/null | grep -oE '[0-9]+' | tail -1 || echo "0")

if [ "$FOUNDING_EXISTS" = "0" ]; then
    echo "Pas d'offre Founding → seed..."
    php bin/console app:seed-founding-offer --env=prod --no-debug || echo "WARN founding seed failed"
else
    echo "✓ Offre Founding déjà présente"
fi

# Lancement du serveur PHP intégré sur le port fourni par Railway
echo "=== Démarrage du serveur HTTP sur 0.0.0.0:${PORT:-8080} ==="
exec php -S 0.0.0.0:${PORT:-8080} -t public/
