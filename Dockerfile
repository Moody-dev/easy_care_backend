FROM php:8.4-apache-bookworm

ENV PORT=10000 HOME=/var/www
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpq-dev supervisor unzip util-linux \
    && docker-php-ext-install -j2 pdo_mysql pdo_pgsql bcmath pcntl intl zip \
    && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini \
    && a2dismod -f mpm_event mpm_worker \
    && a2enmod mpm_prefork rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

COPY app/ app/
COPY bootstrap/ bootstrap/
COPY config/ config/
COPY database/migrations/ database/migrations/
COPY database/postgresql/ database/postgresql/
COPY database/schema/ database/schema/
COPY database/seeders/ database/seeders/
COPY public/ public/
COPY resources/views/ resources/views/
COPY routes/ routes/
COPY artisan ./artisan

RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && composer check-platform-reqs --no-dev \
    && cp bootstrap/render-apache.conf /etc/apache2/sites-available/000-default.conf \
    && printf 'Listen ${PORT}\n' > /etc/apache2/ports.conf \
    && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-enabled/server-security.conf \
    && printf 'memory_limit=128M\nupload_max_filesize=12M\npost_max_size=16M\nexpose_php=Off\nopcache.enable=1\nopcache.memory_consumption=64\n' > /usr/local/etc/php/conf.d/runtime.ini \
    && chown -R www-data:www-data bootstrap/cache storage \
    && apache2ctl -t \
    && (apache2ctl -M | awk '/mpm_.*_module/ { count++; prefork += ($1 == "mpm_prefork_module") } END { exit !(count == 1 && prefork == 1) }')

EXPOSE 10000
STOPSIGNAL SIGTERM
CMD ["/bin/sh", "/var/www/html/bootstrap/render-start.sh"]
