# Testing the peer API

The peer API lets one uPHPtime node ask another node to check a site. It is not the
dashboard's report API: it uses a **peer key ID and secret** to sign each request, not
an API key sent directly in a header.

The example below tests `POST /peer/v1/check-site` directly. You need access to the
receiving node's CLI to create an inbound key. You do not need a second uPHPtime node.
The machine sending the request needs Bash, PHP CLI, and curl.

## 1. Create a test key on the receiving node

Run one of these commands on the node that will receive the API request:

```sh
# Standalone PHP installation, from the uPHPtime directory:
php bin/uphptime peers:add --id=api-test --label="API test" --key-direction=inbound
```

```sh
# Docker with dashboard, from docker/with-dashboard:
sh exec.sh peers:add --id=api-test --label="API test" --key-direction=inbound
```

For the standalone Docker deployment, use the same `sh exec.sh` command from the
`docker` directory instead. The command must use the same configuration and data
directory as the web server; Docker's `exec.sh` runs it as the web server user.

The output includes:

```json
{
  "id": "api-test",
  "label": "API test",
  "key_id": "example-key-id",
  "secret_to_relay": "example-base64-secret"
}
```

These are placeholders, not usable credentials. Copy your actual `key_id` and
`secret_to_relay` from the command output. The secret is base64-encoded and is only
printed when it is generated; `peers:list` shows key IDs and status, but not secrets.
Keep the secret private and never paste it into a ticket, chat, source code, or
Swagger's signature field.

If `api-test` already exists, create another inbound key for it instead:

```sh
# Substitute "sh exec.sh" for "php bin/uphptime" in Docker.
php bin/uphptime peers:keys:add --peer=api-test --key-direction=inbound
```

## 2. Send a signed request

In a **Bash** terminal on the machine making the request, paste and run this entire block.
At the prompts, enter the receiving node's base URL (for example
`https://node.example.com`), the `key_id`, and the `secret_to_relay` from step 1.
The secret is hidden while you type. Use HTTPS for a remote node.

```bash
(
  read -r -p "Node base URL: " NODE_URL
  read -r -p "Peer key ID: " KEY_ID
  read -r -s -p "Peer secret (base64): " SECRET
  printf '\n'

  BODY='{"check_type":"http","url":"https://example.com/","timeout_seconds":5}'
  TIMESTAMP=$(date +%s)
  NONCE=$(php -r 'echo bin2hex(random_bytes(16));') || exit 1
  REQUEST_ID=$(php -r 'echo bin2hex(random_bytes(16));') || exit 1
  export KEY_ID SECRET BODY TIMESTAMP NONCE REQUEST_ID

  SIGNATURE=$(php -r '
    $secret = base64_decode(getenv("SECRET"), true);
    if ($secret === false || $secret === "") {
        fwrite(STDERR, "Invalid base64 peer secret\n");
        exit(1);
    }
    $canonical = implode("\n", [
        "POST",
        "/peer/v1/check-site",
        getenv("KEY_ID"),
        getenv("TIMESTAMP"),
        getenv("NONCE"),
        getenv("REQUEST_ID"),
        hash("sha256", getenv("BODY")),
    ]);
    echo hash_hmac("sha256", $canonical, $secret);
  ') || exit 1
  unset SECRET

  curl -i --request POST "${NODE_URL%/}/peer/v1/check-site" \
    --header "Content-Type: application/json" \
    --header "Accept: application/json" \
    --header "X-Uphptime-Key-Id: $KEY_ID" \
    --header "X-Uphptime-Timestamp: $TIMESTAMP" \
    --header "X-Uphptime-Nonce: $NONCE" \
    --header "X-Uphptime-Request-Id: $REQUEST_ID" \
    --header "X-Uphptime-Signature: $SIGNATURE" \
    --data-binary "$BODY"
)
```

The command prints the response headers and JSON body. An HTTP 200 means authentication
succeeded and the check ran; inspect `outcome` (`pass`, `fail`, or `error`) to see the
check result. A 200 does not necessarily mean the target is up. To check a different URL,
edit `BODY` before running the block. The receiving node must be able to reach that URL
and its egress policy must allow it.

Run the whole block again for each request: the timestamp, nonce, and signature must be
fresh. Do not copy and rerun just the printed curl request; a reused nonce is rejected.
The secret is temporarily present in the signing process's environment, so use a trusted
machine and avoid shell tracing (`set -x`).

## How signing works

The receiving node looks up an enabled **inbound** key by `X-Uphptime-Key-Id`. The
client base64-decodes the shared secret into raw bytes and uses those bytes as the
HMAC-SHA256 key. The signed message is these seven values joined with a single newline,
with no newline after the last value:

1. Uppercase HTTP method, `POST`.
2. Request path, `/peer/v1/check-site` (without the query string).
3. Key ID.
4. Current Unix timestamp in seconds.
5. A new nonce for the request.
6. Request ID.
7. Lowercase hex SHA-256 hash of the exact request body bytes.

Send the resulting HMAC as lowercase hex in `X-Uphptime-Signature`, together with the
four other `X-Uphptime-*` headers in the example. Any change to the body, even whitespace,
requires a new signature. The request ID is echoed in signed responses. To verify a
response, compute HMAC-SHA256 using the same raw secret over the newline-joined request
ID, `X-Uphptime-Response-Timestamp`, HTTP status code, and lowercase hex SHA-256 of the
exact response body, in that order.

The [OpenAPI spec](openapi.json) lists the headers, but Swagger UI does **not** create
the signature. Its **Authorize** field expects the calculated signature, not the secret.
You must also supply the four operation headers and sign the exact body Swagger sends;
the curl example is simpler for an initial test.

## Troubleshooting

- **401 `unauthorized`:** The response intentionally does not identify which
  authentication check failed. Confirm the key exists and is enabled and inbound on the
  receiving node (`peers:list`), the ID and decoded secret match, all five headers are
  present, the signed body and path match what was sent, and the nonce was not reused.
  Ensure both machines' clocks are synchronized; the default `clock_skew_seconds` is 60.
- **404:** Check the URL and that `pinger_enabled` is true on the receiving node.
- **422:** Authentication succeeded, but the JSON or required fields are invalid.
  For an HTTP check, supply a real `url` and a positive `timeout_seconds`.
- **429:** The peer key exceeded its rate limit; wait for the `Retry-After` period.
- **200 with `outcome: fail` or `error`:** Authentication worked. Investigate the
  target, DNS, network access, and the receiving node's egress policy. Private address
  ranges are blocked by default.

If a secret is exposed, retire the affected key and create a replacement:

```sh
# Substitute "sh exec.sh" for "php bin/uphptime" in Docker.
php bin/uphptime peers:keys:retire --peer=api-test --key-id=OLD_KEY_ID
php bin/uphptime peers:keys:add --peer=api-test --key-direction=inbound
```

Retiring a key immediately stops every client using it; coordinate replacement with them.
