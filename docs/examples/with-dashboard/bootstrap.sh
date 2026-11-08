#!/bin/sh
# Generates a report API key, wires it into the primary node's config, adds a demo site, and runs one
# check so the dashboard has real data to show immediately. Safe to re-run (skips what's already done).
# Requires: docker compose, and a `php` CLI on the host (used only to parse the CLI's JSON output, same
# as ../peer-group/bootstrap-peers.sh) - the config patch itself runs inside the container as www-data,
# not on the host, so it needs no host-side write access to the bind-mounted ./data/primary/config.

set -e

cd "$(dirname "$0")"

COMPOSE="docker compose"

exec_node() {
    $COMPOSE exec -T --user www-data primary "$@"
}

wait_ready() {
    echo "=> Waiting for primary to be ready..."
    i=0
    while [ "$i" -lt 30 ]; do
        if exec_node php bin/uphptime sites:list >/dev/null 2>&1; then
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done

    echo "Timed out waiting for primary to start." >&2
    exit 1
}

wait_ready

# Idempotent: if a key is already configured (e.g. a re-run, or set by hand), reuse it rather than
# rotating it out from under an already-configured dashboard.
REPORT_API_KEY=$(exec_node php -r '
    $path = "/var/www/html/config/node.json";
    $config = json_decode(file_get_contents($path), true);
    if (($config["report_api_key"] ?? "") !== "") {
        echo $config["report_api_key"];
        exit;
    }
    $key = bin2hex(random_bytes(32));
    $config["report_api_key"] = $key;
    $config["report_cors_allowed_origin"] = "*";
    file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT) . "\n");
    echo $key;
')

site_count=$(exec_node php bin/uphptime sites:list | php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    echo count($data["sites"] ?? []);
')
if [ "$site_count" -eq 0 ]; then
    echo "=> adding a demo site on primary"
    exec_node php bin/uphptime sites:add --url=https://example.com/ --name="Demo site" --groups=prod >/dev/null
else
    echo "=> primary already has sites, leaving them as-is"
fi

echo "=> running one check pass so the dashboard has data to show"
exec_node php bin/uphptime check:run >/dev/null

echo "=> Done. Open the dashboard at http://localhost:${DASHBOARD_HTTP_PORT:-48480}, click Settings, and enter:"
echo "     Node base URL:   http://localhost:${UPHPTIME_HTTP_PORT:-48488}"
echo "     Report API key:  $REPORT_API_KEY"
