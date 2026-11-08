#!/bin/sh

set -e

APP_ROOT=/var/www/html
CONFIG_FILE="$APP_ROOT/config/node.json"
PORT="${UPHPTIME_HTTP_PORT:-48484}"

# Custom uid/gid (bind-mount friendliness): lets a host user who owns ./docker/data edit it directly.
# Named PUID/PGID, not UID/GID - UID is a read-only special variable in bash, so `UID=1000 docker compose
# up` silently fails to export it; PUID/PGID (the convention linuxserver.io images use) avoids that trap.
if [ -n "$PGID" ]; then
    groupmod -o -g "$PGID" www-data
fi
if [ -n "$PUID" ]; then
    usermod -o -u "$PUID" www-data
fi

mkdir -p "$APP_ROOT/config" "$APP_ROOT/data"
chown -R www-data:www-data "$APP_ROOT/config" "$APP_ROOT/data"

# First-run bootstrap: an empty bind-mounted ./config needs a real node.json before anything can start.
if [ ! -f "$CONFIG_FILE" ]; then
    echo "=> No config found at $CONFIG_FILE, copying node.json.dist"
    cp /usr/local/share/uphptime/node.json.dist "$CONFIG_FILE"
    chown www-data:www-data "$CONFIG_FILE"
fi

# HTTPS, directly - no reverse proxy, no ACME/certbot: just serve whatever cert/key (and optional
# separate intermediate) you already have, e.g. a Cloudflare Origin CA cert. Filenames are never
# hardcoded/assumed - the three UPHPTIME_TLS_*_FILE variables point at whatever you actually have
# (a .key extension instead of privkey.pem, in any mounted path), so nothing needs renaming to fit a
# convention; the defaults below are just that, defaults, for when nothing more specific is set.
HTTPS_PORT="${UPHPTIME_HTTPS_PORT:-443}"
CERT_FILE="${UPHPTIME_TLS_CERT_FILE:-$APP_ROOT/config/ssl/cert.pem}"
KEY_FILE="${UPHPTIME_TLS_KEY_FILE:-$APP_ROOT/config/ssl/privkey.pem}"
CHAIN_FILE="${UPHPTIME_TLS_CHAIN_FILE:-$APP_ROOT/config/ssl/chain.pem}"

if [ -f "$CERT_FILE" ] && [ -f "$KEY_FILE" ]; then
    echo "=> Certificate found - serving HTTPS on port $HTTPS_PORT; HTTP ($PORT) redirects to it"
    a2enmod ssl >/dev/null

    printf 'Listen %s\nListen %s\n' "$PORT" "$HTTPS_PORT" > /etc/apache2/ports.conf
    sed "s/__HTTP_PORT__/$PORT/g; s/__HTTPS_PORT__/$HTTPS_PORT/g" \
        /usr/local/share/uphptime/apache-vhost-http-redirect.conf > /etc/apache2/sites-available/000-default.conf

    if [ -f "$CHAIN_FILE" ]; then
        CHAIN_DIRECTIVE="SSLCertificateChainFile $CHAIN_FILE"
    else
        CHAIN_DIRECTIVE=""
    fi
    sed "s|__HTTPS_PORT__|$HTTPS_PORT|g; s|__CERT_FILE__|$CERT_FILE|g; s|__KEY_FILE__|$KEY_FILE|g; s|__SSL_CHAIN_DIRECTIVE__|$CHAIN_DIRECTIVE|g" \
        /usr/local/share/uphptime/apache-vhost-https.conf > /etc/apache2/sites-available/001-uphptime-ssl.conf
    a2ensite 001-uphptime-ssl >/dev/null
else
    echo "=> No certificate configured - serving HTTP only on port $PORT"
    a2dissite 001-uphptime-ssl >/dev/null 2>&1 || true
    echo "Listen $PORT" > /etc/apache2/ports.conf
    sed "s/__HTTP_PORT__/$PORT/g" /usr/local/share/uphptime/apache-vhost-http.conf > /etc/apache2/sites-available/000-default.conf
fi

# storage:install is idempotent (CREATE TABLE IF NOT EXISTS), safe to run on every start; a no-op when
# storage_backend is "json".
STORAGE_BACKEND=$(php -r '$c = json_decode(file_get_contents($argv[1]), true); echo $c["storage_backend"] ?? "json";' "$CONFIG_FILE")
if [ "$STORAGE_BACKEND" != "json" ]; then
    echo "=> storage_backend is \"$STORAGE_BACKEND\", running storage:install"
    su -s /bin/sh www-data -c "php $APP_ROOT/bin/uphptime storage:install"
fi

exec "$@"
