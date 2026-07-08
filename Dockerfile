FROM php:8.4-cli-alpine

# Dépendances système + extensions PHP nécessaires.
# GD (avec support JPEG + WebP) est indispensable pour AvatarUploader qui
# resize + recompresse les photos de profil user. WebP réduit ~30% le poids
# vs JPG à qualité équivalente et supporte la transparence.
RUN apk add --no-cache \
    bash \
    git \
    unzip \
    icu-dev \
    libzip-dev \
    postgresql-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install \
        gd \
        pdo \
        pdo_pgsql \
        pdo_mysql \
        intl \
        opcache \
        zip \
        mbstring

# Composer depuis l'image officielle
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Optimisation Docker layer cache : composer files d'abord
COPY composer.json composer.lock symfony.lock* ./

# Install dépendances prod sans scripts, sans autoload (généré après)
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --prefer-dist

# Copie du reste du projet
COPY . .

# Autoload optimisé + scripts post-install
RUN COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev \
    && COMPOSER_ALLOW_SUPERUSER=1 composer run-script post-install-cmd --no-dev || true

# Dossiers var/ avec permissions correctes
RUN mkdir -p var/cache var/log && chmod -R 777 var

# Warmup cache prod au build (plus rapide au démarrage)
RUN APP_ENV=prod APP_DEBUG=0 php bin/console cache:warmup --no-debug || echo "warmup failed, continuing"

# AssetMapper : compile les assets en prod.
# Si la compilation foire ici, on N'IGNORE PLUS l'erreur — sans assets, le site est inutilisable.
RUN APP_ENV=prod APP_DEBUG=0 php bin/console asset-map:compile

# start.sh exécutable
RUN chmod +x start.sh

EXPOSE 8080

CMD ["bash", "start.sh"]
