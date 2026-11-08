#!/bin/sh
# Exports every node's storage (sites, peers, peer reputations, history, rollups - see
# `storage:export` in the main README) into its own bind-mounted ./backup/<node>/ directory, one
# timestamped subdirectory per run, then prunes old ones. Meant to be run on a schedule from the
# host - see "Backups" in README.md for a cron and a systemd timer example.
#
# Usage: sh backup.sh [--keep=N]
#   --keep=N   how many timestamped backups to retain per node (default 14; oldest are removed).

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

backup_node() {
    node="$1"

    echo "=> backing up $node to ./backup/$node/$STAMP"
    docker compose exec -T --user www-data "$node" \
        php bin/uphptime storage:export --dir="/var/www/html/backup/$STAMP"

    # Oldest-first list of this node's existing backup directories, beyond the --keep most recent.
    ls -1 "./backup/$node" 2>/dev/null | sort | head -n -"$KEEP" | while IFS= read -r old; do
        [ -n "$old" ] || continue
        echo "=> pruning old backup $node/$old"
        rm -rf "./backup/$node/$old"
    done
}

backup_node primary
backup_node peer1
backup_node peer2

echo "=> Done."
