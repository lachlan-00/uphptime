#!/bin/sh
# Wires one local node into the same flat pool as a node at another location, reachable over the
# network but not something this script can `docker compose exec` into directly (it's a separate
# deployment - possibly not even uPHPtime running in Docker). Both sides get a matched both-direction
# key, exactly like bootstrap-peers.sh's local wiring, except the secret has to be relayed by a human
# instead of this script doing both sides itself.
#
# Run it once per node pair, once on EACH side (that's 2 runs per pair - e.g. 9 runs total to fully
# mesh two 3-node pools). On the first run for a pair, omit --key-id/--secret: this generates a new
# shared key on the local node and prints the exact command to run on the other side. Run that printed
# command there (give the operator the key_id and secret - treat the secret as a secret, same as any
# other credential), then that side's output includes a key_id/secret too, which goes back to this
# script as --key-id/--secret so this side's own peer entry also carries the pair's single shared
# secret. Either side can go first.
#
# Usage:
#   sh mesh-remote.sh <local-node> <remote-id> <remote-base-url> \
#       --local-base-url=<how the OTHER side reaches this node>
#
#   sh mesh-remote.sh <local-node> <remote-id> <remote-base-url> \
#       --local-base-url=<...> --key-id=<from the other side's run> --secret=<from the other side's run>
#
# Example, linking this location's peer1 to a remote location's peer3 (remote reachable at
# https://siteb.example.com:48491, this node reachable from there at https://sitea.example.com:48489):
#   sh mesh-remote.sh peer1 siteb-peer3 https://siteb.example.com:48491 \
#       --local-base-url=https://sitea.example.com:48489

set -e

cd "$(dirname "$0")"

LOCAL_NODE="$1"
REMOTE_ID="$2"
REMOTE_BASE_URL="$3"
shift 3 2>/dev/null || {
    echo "Usage: $0 <local-node> <remote-id> <remote-base-url> --local-base-url=... [--key-id=... --secret=...]" >&2
    exit 1
}

case "$LOCAL_NODE" in
    primary|peer1|peer2) ;;
    *)
        echo "local-node must be primary, peer1, or peer2 (got: $LOCAL_NODE)" >&2
        exit 1
        ;;
esac
if [ -z "$REMOTE_ID" ] || [ -z "$REMOTE_BASE_URL" ]; then
    echo "Usage: $0 <local-node> <remote-id> <remote-base-url> --local-base-url=... [--key-id=... --secret=...]" >&2
    exit 1
fi

LOCAL_BASE_URL=""
KEY_ID=""
SECRET=""
for arg in "$@"; do
    case "$arg" in
        --local-base-url=*) LOCAL_BASE_URL="${arg#--local-base-url=}" ;;
        --key-id=*) KEY_ID="${arg#--key-id=}" ;;
        --secret=*) SECRET="${arg#--secret=}" ;;
        *)
            echo "Unrecognized argument: $arg" >&2
            exit 1
            ;;
    esac
done
if [ -z "$LOCAL_BASE_URL" ]; then
    echo "--local-base-url is required: it's how the OTHER side reaches this node (e.g. a public hostname)." >&2
    exit 1
fi
if [ -n "$KEY_ID" ] && [ -z "$SECRET" ] || [ -z "$KEY_ID" ] && [ -n "$SECRET" ]; then
    echo "--key-id and --secret must be given together." >&2
    exit 1
fi

exec_node() {
    docker compose exec -T --user www-data "$LOCAL_NODE" php bin/uphptime "$@"
}

if exec_node peers:list | php -r '
    $peers = json_decode(stream_get_contents(STDIN), true);
    foreach ($peers as $peer) {
        if ($peer["id"] === $argv[1]) {
            exit(0);
        }
    }
    exit(1);
' "$REMOTE_ID"; then
    echo "$LOCAL_NODE already has a peer entry for \"$REMOTE_ID\" - nothing to do. Use peers:set/peers:remove to change it." >&2
    exit 1
fi

if [ -n "$KEY_ID" ]; then
    echo "=> registering $REMOTE_ID on $LOCAL_NODE using the shared key from the other side"
    exec_node peers:add --id="$REMOTE_ID" --label="$REMOTE_ID" \
        --base-url="$REMOTE_BASE_URL" --key-direction=both --key-id="$KEY_ID" --secret="$SECRET" >/dev/null
    echo "=> Done. $LOCAL_NODE and $REMOTE_ID are now peered both ways."
else
    echo "=> generating a new shared key for $LOCAL_NODE <-> $REMOTE_ID"
    RESULT=$(exec_node peers:add --id="$REMOTE_ID" --label="$REMOTE_ID" \
        --base-url="$REMOTE_BASE_URL" --key-direction=both)
    GENERATED_KEY_ID=$(echo "$RESULT" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["key_id"];')
    GENERATED_SECRET=$(echo "$RESULT" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["secret_to_relay"];')

    echo
    echo "=> Done on this side. On the OTHER location, run:"
    echo
    echo "   sh mesh-remote.sh <their-node> <a-label-for-$LOCAL_NODE> $LOCAL_BASE_URL \\"
    echo "       --local-base-url=$REMOTE_BASE_URL \\"
    echo "       --key-id=$GENERATED_KEY_ID --secret=$GENERATED_SECRET"
    echo
    echo "   (substitute their own node name for <their-node>, and any label for <a-label-for-$LOCAL_NODE>."
    echo "   Keep the base-url and key-id/secret exactly as printed above.) Treat the secret as a credential"
    echo "   in transit."
fi
