# Plugin sandbox image: PHP 8.4-FPM + nginx + supervisord in one container.
#
# This repo is a CakePHP 5 plugin, not an app. The container exists so a
# developer can exec in to run composer install, vendor/bin/phpunit, and
# code edits against a live MariaDB + Redis. nginx serves a tiny placeholder
# page so an HTTP health check passes.
#
# Dev dependencies (phpunit, codesniffer, bake) ship in the image — this is
# a sandbox, not a production deploy.

FROM php:8.4-fpm-bookworm

# ---- System dependencies + PHP extensions ----
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx supervisor curl ca-certificates gnupg \
        git unzip jq \
        libicu-dev libonig-dev libzip-dev libpq-dev \
        libpng-dev libjpeg-dev libfreetype6-dev libxml2-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        intl mbstring pdo pdo_mysql pdo_pgsql mysqli zip opcache bcmath gd \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# ---- Composer ----
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ---- nginx + supervisord configs ----
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/php/supervisord.conf /etc/supervisor/conf.d/app.conf
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

WORKDIR /var/www/html

# Install vendor deps first for layer caching. WITH dev deps — sandbox needs
# phpunit / codesniffer / bake.
COPY composer.json composer.lock* ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction \
    || echo "composer install failed at build — entrypoint will retry"

# Copy the rest of the plugin
COPY . .

# Finalize autoload + perms
RUN composer dump-autoload --no-interaction 2>/dev/null || true \
    && chown -R www-data:www-data /var/www/html \
    && mkdir -p /var/www/html/tmp /var/www/html/logs \
    && chmod -R 775 /var/www/html/tmp /var/www/html/logs

COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/app.conf", "-n"]
