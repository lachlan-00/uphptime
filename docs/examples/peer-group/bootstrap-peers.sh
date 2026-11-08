#!/bin/sh
# Wires all three local nodes into one flat, fully-meshed pool: every pair gets a single shared
# "both-direction" key, so any node can delegate a check to any other node and any node can verify a
# delegated request from any other - there's no primary/sub-agent distinction in the trust graph itself,
# only in which node happens to hold sites. Safe to re-run (skips any pair that's already wired). Adds
# a demo site on primary and runs one check so there is something to see once peers are wired up.
# Requires: docker compose, and a `php` CLI on the host (used only to parse the CLI's JSON output, same
# as ../with-dashboard/bootstrap.sh) - the peer and config setup itself runs inside the containers as
# www-data, not on the host.
#
# To fold in nodes from another location into the same flat pool, use mesh-remote.sh instead - it does
# the same one-shared-key wiring, but across two separately-run `docker compose` deployments that can't
# exec into each other directly.

set -e

cd "$(dirname "$0")"

COMPOSE="docker compose"
KEY_FILE=$(mktemp)
trap 'rm -f "$KEY_FILE"' EXIT

exec_node() {
    node="$1"
    shift
    $COMPOSE exec -T --user www-data "$node" "$@"
}

wait_ready() {
    node="$1"
    echo "=> Waiting for $node to be ready..."
    i=0
    while [ "$i" -lt 30 ]; do
        if exec_node "$node" php bin/uphptime sites:list >/dev/null 2>&1; then
            return 0
        fi
        i=$((i + 1))
        sleep 2
    done

    echo "Timed out waiting for $node to start." >&2
    exit 1
}

peer_exists() {
    node="$1"
    peer_id="$2"
    exec_node "$node" php bin/uphptime peers:list | php -r '
        $peers = json_decode(stream_get_contents(STDIN), true);
        foreach ($peers as $peer) {
            if ($peer["id"] === $argv[1]) {
                exit(0);
            }
        }
        exit(1);
    ' "$peer_id"
}

# Generates one both-direction (outbound+inbound) key on $node_a for $node_b, then relays that same
# key_id/secret onto $node_b registered as $node_a, also both-direction - one shared secret, used
# symmetrically, is enough for each side to both sign its own outbound requests and verify the other's.
wire_pair() {
    node_a="$1"
    node_b="$2"
    base_url_a_to_b="$3"
    base_url_b_to_a="$4"

    if peer_exists "$node_a" "$node_b" && peer_exists "$node_b" "$node_a"; then
        echo "=> $node_a and $node_b already peered, leaving as-is"
        return 0
    fi
    if peer_exists "$node_a" "$node_b" || peer_exists "$node_b" "$node_a"; then
        echo "$node_a and $node_b have a one-sided peer entry already - this script only creates matched" >&2
        echo "pairs. Remove the existing entry (peers:remove) and re-run, or finish the other side by hand." >&2
        exit 1
    fi

    echo "=> creating shared key for $node_a <-> $node_b"
    exec_node "$node_a" php bin/uphptime peers:add --id="$node_b" --label="$node_b" \
        --base-url="$base_url_a_to_b" --key-direction=both >"$KEY_FILE"

    KEY_ID=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["key_id"];' "$KEY_FILE")
    SECRET=$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["secret_to_relay"];' "$KEY_FILE")
    exec_node "$node_b" php bin/uphptime peers:add --id="$node_a" --label="$node_a" \
        --base-url="$base_url_b_to_a" --key-direction=both --key-id="$KEY_ID" --secret="$SECRET" >/dev/null
}

wait_ready primary
wait_ready peer1
wait_ready peer2

wire_pair primary peer1 \
    "http://peer1:${PEER1_HTTP_PORT:-48489}" "http://primary:${PRIMARY_HTTP_PORT:-48488}"
wire_pair primary peer2 \
    "http://peer2:${PEER2_HTTP_PORT:-48490}" "http://primary:${PRIMARY_HTTP_PORT:-48488}"
wire_pair peer1 peer2 \
    "http://peer2:${PEER2_HTTP_PORT:-48490}" "http://peer1:${PEER1_HTTP_PORT:-48489}"

site_count=$(exec_node primary php bin/uphptime sites:list | php -r '
    $data = json_decode(stream_get_contents(STDIN), true);
    echo count($data["sites"] ?? []);
')
if [ "$site_count" -eq 0 ]; then
    echo "=> adding a demo site on primary"
    exec_node primary php bin/uphptime sites:add --url=https://example.com/ --name="Demo site" --groups=prod >/dev/null
else
    echo "=> primary already has sites, leaving them as-is"
fi

echo "=> running one check pass so delegated checks have a chance to run"
exec_node primary php bin/uphptime check:run >/dev/null

echo "=> Done. Peers configured on primary:"
exec_node primary php bin/uphptime peers:list
