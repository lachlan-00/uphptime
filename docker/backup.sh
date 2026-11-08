#!/bin/sh
# Exports this node's storage (sites, peers, peer reputations, history, rollups - see "Storage"
# in the main README) into a timestamped subdirectory under ./backup/, a bind-mounted directory
# alongside the existing config/data mounts, then prunes old ones. Meant to be run on a schedule
# from the host - see "Backups" in the main README for a cron and a systemd timer example.
#
# Usage: sh backup.sh [--keep=N]
#   --keep=N   how many timestamped backups to retain (default 14; oldest are removed).

set -e

cd "$(dirname "$0")"

KEEP=14
for arg in "$@"; do
    case "$arg" in
        --keep=*) KEEP="${arg#--keep=}" ;;
        *)
            echo "Unrecognized argument: $arg" >&2
            exit 1
            ;;
    esac
done

STAMP=$(date -u +%Y%m%dT%H%M%SZ)

echo "=> backing up to ./backup/primary/$STAMP"
docker compose -f ../docker-compose.yml exec -T --user www-data primary \
    php bin/uphptime storage:export --dir="/var/www/html/backup/$STAMP"

# Oldest-first list of existing backup directories, beyond the --keep most recent.
ls -1 ./backup/primary 2>/dev/null | sort | head -n -"$KEEP" | while IFS= read -r old; do
    [ -n "$old" ] || continue
    echo "=> pruning old backup $old"
    rm -rf "./backup/primary/$old"
done

echo "=> Done."
