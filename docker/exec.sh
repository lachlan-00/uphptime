#!/bin/sh
# Runs a uphptime CLI command inside the container as www-data - the same user cron and Apache already
# run it as (see crontab/supervisord.conf). Running as any other user (the exec default is root) leaves
# files it creates unreadable/unlockable by the web-triggered or cron-triggered process later.
# Usage: ./exec.sh sites:list
#        ./exec.sh sites:add --url=https://example.com/

set -e

cd "$(dirname "$0")"

exec docker compose -f ../docker-compose.yml exec --user www-data primary php bin/uphptime "$@"
