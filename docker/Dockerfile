ARG PHPVERSION=8.3

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist
COPY . .
RUN composer dump-autoload --no-dev --optimize

FROM php:${PHPVERSION}-apache
LABEL maintainer="uPHPtime contributors"

ENV UPHPTIME_HTTP_PORT=48484

RUN apt-get update \
    &&  apt-get -q -q -y install --no-install-recommends cron supervisor libpq-dev iputils-ping \
    &&  docker-php-ext-install pdo_mysql pdo_pgsql \
    &&  a2enmod rewrite ssl \
    &&  rm -rf /var/lib/apt/lists/* /var/www/html

COPY --from=vendor /app /var/www/html
COPY docker/apache-vhost-http.conf docker/apache-vhost-http-redirect.conf docker/apache-vhost-https.conf /usr/local/share/uphptime/
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/crontab /etc/cron.d/uphptime
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
COPY docker/favicon.ico /var/www/html/public/favicon.ico

# Kept outside /var/www/html/config on purpose: that directory is a declared VOLUME, and a bind/volume
# mount there would otherwise shadow a file placed inside it at build time, hiding it from the entrypoint.
# The vhost templates above live there for the same reason - the entrypoint regenerates the real,
# in-use vhost files fresh from these pristine templates on every single start (never mutating them in
# place), so a second start never re-substitutes into an already-substituted file.
RUN mkdir -p /usr/local/share/uphptime \
    &&  cp /var/www/html/config/node.json.dist /usr/local/share/uphptime/node.json.dist \
    &&  chmod 0644 /etc/cron.d/uphptime \
    &&  chmod +x /usr/local/bin/docker-entrypoint.sh \
    &&  chown -R www-data:www-data /var/www/html

VOLUME ["/var/www/html/config", "/var/www/html/data"]
EXPOSE 48484 443

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]
