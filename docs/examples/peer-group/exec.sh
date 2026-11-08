#!/bin/sh
# Runs a uphptime CLI command on one of the three nodes, as www-data - the same user cron and Apache
# already run it as (see ../crontab, ../supervisord.conf). Running as any other user (the exec default
# is root) leaves files it creates unreadable/unlockable by the web-triggered or cron-triggered process
# later.
# Usage: ./exec.sh primary sites:list
#        ./exec.sh peer1 peers:list

set -e

cd "$(dirname "$0")"

NODE="$1"
case "$NODE" in
    primary|peer1|peer2) ;;
    *)
        echo "Usage: $0 {primary|peer1|peer2} <uphptime-command> [args...]" >&2
        exit 1
        ;;
esac
shift

exec docker compose exec --user www-data "$NODE" php bin/uphptime "$@"
