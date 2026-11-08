# uPHPtime

uPHPtime is an uptime monitor written in PHP. Run it as a standalone node, or connect
independently operated nodes to delegate checks and confirm failures from other networks.
It supports HTTP, keyword, TCP and ICMP checks, scheduled reporting, maintenance windows,
and JSON or SQL storage.

## Why uPHPtime exists

Uptime monitoring should not have to depend on a single hosted service, company, or platform
remaining available. A monitor built around hosted CI/repository infrastructure can be affected
if those services change, become unavailable, or no longer fit your needs.
uPHPtime is designed to run on infrastructure you control, so you can keep running independently.

It also aims to make monitoring a community-shared resource. In a workplace, spread checks
across multiple devices or networks to get independent perspectives instead of relying on
one machine or connection. For people self-hosting at home, trusted peers can share nodes
to check one another's services from outside their home networks. That can help distinguish
a local connectivity problem from an outage and make monitoring more resilient.

Each node remains independently operated: you choose which peers to trust and configure,
and which checks to delegate. Sharing nodes can add useful vantage points, but it does not
remove common points of failure such as a shared power supply, ISP, or hosting provider.

## Get started

### Docker

Requires Docker and the Compose plugin. The root `docker-compose.yml` is the default,
single-node setup. From this checkout:

```sh
docker compose up -d --build
sh docker/exec.sh sites:add --url=https://example.com/ --name="Example"
sh docker/exec.sh check:run
sh docker/exec.sh sites:list
```

The node listens on port `48488` by default (override with `UPHPTIME_HTTP_PORT`; see
[`docker/.env.example`](https://github.com/lachlan-00/uphptime/blob/main/docker/.env.example)). Configuration and data persist in
`docker/data/primary/config` and `docker/data/primary/data`. The container creates `node.json`
on first startup and runs scheduled checks automatically. Use `sh docker/exec.sh` for CLI
commands so files are created by the same user as the web server and scheduler.

For other Docker layouts - a node with a browser dashboard, or a meshed multi-node peer
group - see [docs/examples/with-dashboard/README.md](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/with-dashboard/README.md)
and [docs/examples/peer-group/README.md](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/peer-group/README.md). Each is a
separate, self-contained Compose project with its own checkout, not an extension of the root one.

### PHP

Requires PHP 8.1 or later with JSON, cURL and OpenSSL extensions, plus Composer. ICMP
checks shell out to the system `ping` (Linux/macOS or Windows); MySQL or PostgreSQL storage also requires PDO and the
appropriate driver.

```sh
composer install
cp config/node.json.dist config/node.json
php bin/uphptime sites:add --url=https://example.com/ --name="Example"
php bin/uphptime check:run
php bin/uphptime sites:list
```

Schedule `check:run` for ongoing monitoring as described below. To serve the API, expose
the application through a PHP-capable web server with `public/` as its document root and
route requests to `public/index.php`. The web server and scheduled CLI user need read/write
access to the configured data directory.

## Monitor sites

| Type             | Required options                                                  | Check                       |
|------------------|-------------------------------------------------------------------|-----------------------------|
| `http` (default) | `--url`                                                           | HTTP response status        |
| `http_keyword`   | `--url`, `--keyword`, `--keyword-mode=contains` or `not_contains` | HTTP response and body text |
| `tcp`            | `--host`, `--port`                                                | TCP connection              |
| `icmp`           | `--host`                                                          | ICMP ping                   |

```sh
php bin/uphptime sites:add --url=https://example.com/ --name="Example" --groups=prod,web
php bin/uphptime sites:add --url=https://example.com/ --type=http_keyword --keyword=Welcome --keyword-mode=contains
php bin/uphptime sites:add --host=mail.example.com --port=25 --type=tcp
php bin/uphptime sites:add --host=8.8.8.8 --type=icmp
php bin/uphptime sites:list
php bin/uphptime report --id=SITE_ID --period=overall
php bin/uphptime report --group=prod --month=2026-01
php bin/uphptime sites:remove --id=SITE_ID
```

Replace `SITE_ID` with the ID returned by `sites:add`. Use `--purge-history` when removing a
site to also remove its history and rollups. `report` supports `day`, `month` and `overall`
periods. Run `php bin/uphptime help` for all commands.

New sites start as `unknown` and are immediately due for a check. `sites:list` displays
`up`, `suspect`, `down`, or `unknown`. A stale check or an inconclusive result is shown as
unknown; the last confirmed state is retained internally. Unconfirmed peer failures and
local network failures without independent confirmation are excluded from uptime calculations
rather than counted as downtime. In particular, TCP and ICMP failures without a reachable
peer cannot independently confirm downtime. HTTP error responses and keyword mismatches can
still count as failures.

Maintenance windows exclude checks during the window from uptime reporting:

```sh
php bin/uphptime maintenance:add --site=SITE_ID --start=2026-01-01T02:00:00+00:00 --end=2026-01-01T04:00:00+00:00 --note="Planned maintenance"
php bin/uphptime maintenance:list --site=SITE_ID
php bin/uphptime maintenance:remove --site=SITE_ID --window-id=WINDOW_ID
```

## Configuration and checks

Edit `config/node.json`; the available settings and defaults are in
[`config/node.json.dist`](https://github.com/lachlan-00/uphptime/blob/main/config/node.json.dist). In Docker, edit the bind-mounted copy
instead. Configuration is read per web request; set peer-side options on the node actually
performing the delegated check.

- `check_interval_seconds`, `request_timeout_seconds`, `run_budget_seconds` and
  `stale_after_seconds` control scheduling and freshness.
- `max_redirects` defaults to `0` for delegated HTTP checks. A plain HTTP check accepts the
  initial 2xx or 3xx response; a keyword check requires a 2xx response and a matching body.
  A positive limit follows redirects; an exhausted limit or missing redirect location fails
  the check. Local HTTP checks do not follow redirects.
- `pinger_enabled`, timeout, cache, TCP port and egress settings control delegated checks.
  Private and other restricted address ranges are blocked by default; configure
  `egress_allowed_ranges` deliberately when private targets must be reachable.
- HTTP requests identify the checking node as `uPHPtime/<version>`. Set `http_user_agent`
  to append custom text; an empty or omitted value uses only the default identification.
  Update the checking peer to change the version it reports.

Delegated cURL failures are logged on the **checking peer** as `pinger_http_curl_failure`.
The entry includes the host, path, redirect count and cURL error, but omits URL credentials,
query contents and fragments. Paths can still contain sensitive data. The peer cache can
delay a fresh probe until the cached result expires.

History records contain `source` (`self` or `peer:<key_id>`) and, where available,
`peer_id` and `peer_label`. With no configured peers, checks run locally.

### Troubleshooting

Check `sites:list` for current state, then inspect today's history for the outcome and
source. From the repository root, for the default Docker setup:

```sh
tail -n 10 docker/data/primary/data/history/SITE_ID/$(date -u +%F).jsonl
docker compose logs --since=15m primary | grep pinger_http_curl_failure
```

For the `with-dashboard` or `peer-group` examples, run these from that example's own
directory instead, using `data/primary/data/history/...` (or `data/<node>/data/...` for
`peer-group`) and the matching service name. `source: self` means a local check;
`source: peer:...` identifies the delegated key. `unknown` and error outcomes are
inconclusive, not confirmed downtime. A cached result can delay a fresh peer probe by up to
`pinger_cache_ttl_seconds`.

## Peers

Peers use pairwise HMAC keys; there is no automatic discovery. For mutual delegation, each
node must be reachable at the base URL configured by the other node. Use HTTPS and exchange
the generated secret over a secure channel.

To test the peer API directly, follow the [peer API walkthrough](https://github.com/lachlan-00/uphptime/blob/main/docs/peer-api.md) for
key creation, a signed curl request, and troubleshooting.

`--key-direction` is relative to the node where the command runs:

| Direction        | This node's use of the key                        |
|------------------|---------------------------------------------------|
| `outbound`       | Ask the peer to check this node's sites.          |
| `inbound`        | Accept requests from the peer to check its sites. |
| `both` (default) | Allow both uses.                                  |

```sh
# On the first node:
php bin/uphptime peers:add --id=second-first --label="Second <-> First" --base-url=https://second.example.com/ --key-direction=both

# On the second node, use key_id and secret_to_relay from the first command:
php bin/uphptime peers:add --id=first-second --label="First <-> Second" --base-url=https://first.example.com/ --key-id=KEY_ID --secret=SECRET --key-direction=both
```

Run `peers:list` on each node. A `peer:<key_id>` history source identifies a delegated
check. Peer availability, scheduling and cached results affect which node performs a given
check. Avoid exposing keys in logs, shell history or version control.

Use `peers:set` to enable or disable delegation, `peers:keys:rotate` and `peers:keys:retire`
to manage keys, and `peers:allowlist:add` to restrict the targets a peer may ask this node to
check. An empty target allowlist imposes no peer-specific restriction; the node's egress
policy still applies. Peer reputation, canary checks and confirmation are local to the owner.

### One-way delegation

To let `owner` ask `checker` to probe sites without allowing `checker` to delegate back,
create a key on the owner and relay its ID and secret securely:

```sh
# Owner:
php bin/uphptime peers:add --id=checker --label="Remote checker" --base-url=https://checker.example/ --key-direction=outbound

# Checker: use key_id and secret_to_relay from the owner's output.
php bin/uphptime peers:add --id=owner --label="Monitoring owner" --key-id=KEY_ID --secret=SECRET --key-direction=inbound
```

The checker URL must be reachable from the owner. The owner's URL need not be reachable
from the checker unless it also delegates checks to the owner.

### Key rotation

Rotate one direction at a time. Add the received key as inbound-only, rotate the other
direction, then retire old keys after confirming the new keys work:

```sh
# Owner: relay the new key ID and secret to checker.
php bin/uphptime peers:keys:rotate --peer=checker

# Checker: accept the owner's key, rotate its own, then relay its new key to owner.
php bin/uphptime peers:keys:add --peer=owner --key-id=NEW_OWNER_KEY_ID --secret=NEW_OWNER_SECRET --key-direction=inbound
php bin/uphptime peers:keys:rotate --peer=owner

# Owner: accept checker's new key.
php bin/uphptime peers:keys:add --peer=checker --key-id=NEW_CHECKER_KEY_ID --secret=NEW_CHECKER_SECRET --key-direction=inbound
```

Use `peers:list` to find the old IDs, then run `peers:keys:retire --peer=PEER_ID --key-id=OLD_KEY_ID`
on each node. Keep generated secrets out of tickets and version control.

### Result attestation

For HTTP(S) sites, `sites:add --challenge-delivery=header` or `--challenge-delivery=well_known`
enables challenge-token attestation. The monitored site must obtain the JSON token from the
owner node's `GET /challenge/v1/{site-id}` endpoint and serve the token itself either in the
`X-Uphptime-Challenge` response header or as the plain-text body of `/.well-known/uphptime`,
respectively. This requires site-side integration; proxying the JSON endpoint unchanged is
not sufficient. For a PHP site, route `/.well-known/uphptime` to
[`docs/examples/challenge-well-known.php`](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/challenge-well-known.php) and set
the node URL and site ID there. Header delivery requires adding the fetched token to the
site's normal responses as `X-Uphptime-Challenge`.

### Sharing and joining peers

Connecting with someone is a short, explicit exchange: add their node using its reachable
HTTPS base URL, then send them the generated `key_id` and `secret_to_relay` over a private
channel. They add your node with those values and their own node's reachable URL:

```sh
# On your node; share key_id and secret_to_relay with your peer.
php bin/uphptime peers:add --id=peer --label="My peer <=> Your peer" --base-url=https://peer.example.com/ --key-direction=both

# On your peer's node; they use the key_id and secret_to_relay you sent.
php bin/uphptime peers:add --id=you --label="Your node <=> My node" --base-url=https://you.example.com/ --key-id=KEY_ID --secret=SECRET --key-direction=both
```

Both nodes must be reachable at the URLs their peer configures. Treat the generated secret
like a password: share it privately, never in a public post or repository. For Docker, use
`sh docker/exec.sh` instead of `php bin/uphptime` in these commands.

## Scheduling

`check:run` checks due sites and uses an exclusive lock to prevent overlapping runs.
`pinger:refresh-cache` is optional and refreshes the delegated-check cache. The Docker image
already runs these jobs every five minutes and every minute respectively; it does not need
an additional host scheduler.

For a non-Docker installation, choose cron or systemd timers below. Run the jobs as a user
with read/write access to the configured data directory.

### Cron

Edit [`docs/examples/uphptime.cron`](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/uphptime.cron) to use the absolute PHP
and installation paths and a writable log location, then install its entries in that user's
crontab with `crontab -e`. Remove the cache refresh entry if the node does not need it.

### systemd timers

Copy the example [check service](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/uphptime-check.service) and
[timer](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/uphptime-check.timer) to `/etc/systemd/system/`. Edit the service's
`User` and `ExecStart` for your installation. To refresh the pinger cache, also copy the
[cache service](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/uphptime-pinger-refresh.service) and
[timer](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/uphptime-pinger-refresh.timer), and edit its `User` and `ExecStart`.

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now uphptime-check.timer
# If using the cache refresh:
sudo systemctl enable --now uphptime-pinger-refresh.timer
```

Inspect output with `journalctl -u uphptime-check.service` or
`journalctl -u uphptime-pinger-refresh.service`.

## Storage

JSON is the default backend. To use MySQL or PostgreSQL, set `storage_backend` to `mysql` or
`pgsql` and configure `sql_host`, `sql_port`, `sql_database`, `sql_username` and
`sql_password` in `node.json`. Port `0` selects the backend's default port. Install the schema:

```sh
php bin/uphptime storage:install
```

The SQL backend can be exported to or imported from a JSON-layout directory:

```sh
php bin/uphptime storage:export --dir=/path/to/backup
php bin/uphptime storage:import --dir=/path/to/backup
```

Both commands refuse a non-empty destination unless `--force` is supplied; `--force` clears
the destination first.

### Backups

For the default Docker setup (root `docker-compose.yml`), and the
[with-dashboard](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/with-dashboard/README.md) and
[peer-group](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/peer-group/README.md) examples, each node's Compose file
bind-mounts `./backup` (or `./backup/<node>` where there's more than one node) to
`/var/www/html/backup`, alongside the existing `config`/`data` mounts, and each of those
directories has its own `backup.sh` that runs `storage:export` into a timestamped
subdirectory there and prunes old ones - see "Backups" in the example READMEs (or, for the
default setup, [`docker/backup.sh`](https://github.com/lachlan-00/uphptime/blob/main/docker/backup.sh)'s own comment) for usage, a restore
example, and a cron/systemd timer to run it on a schedule.

For a standalone (non-Docker) install, run `storage:export`/`storage:import` directly against
a directory of your choosing, on whatever schedule your own cron or systemd timer provides -
see "Scheduling" above for the equivalent pattern with `check:run`.

## Deployment and API

When the pinger is enabled, `GET /` returns an empty HTTP 200 response; it does not check
the node's dependencies or confirm that scheduled checks are running.

Set `UPHPTIME_HTTP_PORT`, `PUID` and `PGID` in `docker/.env` if needed; see
[`docker/.env.example`](https://github.com/lachlan-00/uphptime/blob/main/docker/.env.example). Protect the node with TLS when exposing it:
use a reverse proxy, or place a certificate and key in
`docker/data/primary/config/ssl/cert.pem` and `privkey.pem` and uncomment the HTTPS port
mapping in the root `docker-compose.yml`.
`UPHPTIME_HTTPS_PORT` and `UPHPTIME_TLS_*_FILE` can override the port and container paths;
see `.env.example`. The container does not obtain certificates automatically. For a minimal
nginx TLS reverse proxy, see [`docs/examples/nginx-uphptime.conf`](https://github.com/lachlan-00/uphptime/blob/main/docs/examples/nginx-uphptime.conf);
bind the container port to `127.0.0.1` in Compose so external traffic uses the proxy.

The report endpoint is disabled until `report_api_key` is set in `node.json`. Set
`report_cors_allowed_origin` to the dashboard's exact browser origin, and protect the key:

```json
{
  "report_api_key": "64-hex-character-secret",
  "report_cors_allowed_origin": "https://status.example.com"
}
```

`report_api_key` is a bearer secret; CORS is not authentication. Serve both node and
dashboard over HTTPS and do not put the key in public code. To allow private targets, set
the network CIDRs and TCP ports the pinger needs, for example:

```json
{
  "egress_allowed_ranges": ["10.20.0.0/16"],
  "tcp_port_allowlist": [443, 8443]
}
```

Only allow trusted ranges and required ports. A peer can also be restricted to a domain
suffix or CIDR:

```sh
php bin/uphptime peers:allowlist:add --id=PEER_ID --pattern=internal.example.com
```

Generate a key value with `openssl rand -hex 32`, then set it in `node.json` before making
the report request below.

```sh
curl -H "Authorization: Bearer $REPORT_API_KEY" \
  "https://node.example.com/report/v1/overview?period=month&group=prod"
```

The endpoint supports `day`, `month` and `overall` periods and optional group filtering.
The [dashboard](https://github.com/lachlan-00/uphptime-dashboard) uses this API. See
[`docs/openapi.json`](https://github.com/lachlan-00/uphptime/blob/main/docs/openapi.json) for the peer, challenge and report endpoints.

For scripts, set `REPORT_API_KEY` to the configured key and send it as a bearer token:

```sh
curl -H "Authorization: Bearer ${REPORT_API_KEY}" \
  "https://node.example.com/report/v1/overview?period=month&group=prod"
```

## Development

See [CONTRIBUTING.md](https://github.com/lachlan-00/uphptime/blob/main/CONTRIBUTING.md) for the architecture, development setup and contribution guidelines.

```sh
composer qa              # syntax, style, static analysis, API spec and tests
composer api:docs        # regenerate docs/openapi.json
composer api:docs:check  # check the generated spec
```

## License

AGPL-3.0-or-later; see [LICENSE](https://github.com/lachlan-00/uphptime/blob/main/LICENSE).
