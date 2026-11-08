# uPHPtime peer group

This Compose setup runs three uPHPtime nodes: `primary`, `peer1`, and `peer2`, fully meshed
into one flat pool using the peer API (see [the peer API walkthrough](../../docs/peer-api.md)
for how the signing works). Every node trusts every other node both ways, so any of the three
can delegate a check to, or be delegated a check from, any of the others - there's no
primary/sub-agent distinction in the trust graph itself. The only thing that makes `primary`
special here is that it's the one node this setup adds sites to; `peer1` and `peer2` never
hold sites and are only ever asked to run a check on `primary`'s behalf.

Trust is pairwise and **not transitive** - a node only delegates to peers it has an explicit
key for, never to "a peer of a peer". See `mesh-remote.sh` below for folding in nodes from
another location.

## Requirements

- Git and Docker with the Compose plugin
- PHP CLI on the host for `bootstrap-peers.sh`

## Setup

From `docs/examples/peer-group`:

```sh
sh setup.sh
docker compose up -d --build
sh bootstrap-peers.sh
```

`setup.sh` clones the server once into `docker/uphptime` (relative to this directory), shared
by all three containers. It leaves an existing checkout unchanged; it does not update it. Compose builds images from this
checkout, not from the surrounding repository.

`bootstrap-peers.sh` gives each of the three pairs (`primary`↔`peer1`, `primary`↔`peer2`,
`peer1`↔`peer2`) one shared both-direction key, adds a demo site on `primary` if it has none,
and runs one check. Safe to re-run.

| Service | Default host port | Override           |
|---------|-------------------:|---------------------|
| primary |               48488 | `PRIMARY_HTTP_PORT` |
| peer1   |               48489 | `PEER1_HTTP_PORT`   |
| peer2   |               48490 | `PEER2_HTTP_PORT`   |

Set overrides in a `.env` file next to `docker-compose.yml`. `bootstrap-peers.sh` uses the same
variables to build each peer's internal base URL (`http://peer1:$PEER1_HTTP_PORT`, reachable by
container name on the Compose network), so keep them consistent if you change them.

## Operations

```sh
sh exec.sh primary sites:list
sh exec.sh primary peers:list
sh exec.sh primary check:run
sh exec.sh peer1 peers:list
docker compose logs -f primary
```

`exec.sh` takes the node name (`primary`, `peer1`, or `peer2`) as its first argument and runs
the rest as a `bin/uphptime` command, as `www-data`, matching Apache and cron on that node.

Each node's configuration is stored in `data/<node>/config/node.json` and monitoring data in
`data/<node>/data`. Only `primary` needs sites added (`sites:add`) - peers just need to trust
`primary` and respond to delegated check requests; they don't need sites of their own unless
you also want to check sites directly through them.

To change which peers primary is willing to delegate to, or their rate limits and target
allowlists, use `peers:set` and `peers:allowlist:*` via `exec.sh primary ...` - see the main
README and `docs/peer-api.md` for the full command set.

To add a fourth local peer by hand (outside `bootstrap-peers.sh`), add a service to
`docker-compose.yml` following the `peer1`/`peer2` pattern, then repeat the two-sided
`peers:add` steps from `bootstrap-peers.sh` for it against each existing node, to keep the
pool fully meshed.

## Joining another location's pool

`bootstrap-peers.sh` only wires nodes this one `docker compose` project can `exec` into. To
fold in a pool running at another location (its own separate `docker/peer-group` deployment,
or anything else speaking the peer API), use `mesh-remote.sh` instead - see the comment at the
top of that script for the full usage and an example. In short: run it once per node pair,
once on each side (9 runs total to fully mesh two 3-node pools); the first run generates a
shared key and prints the exact command to run on the other side to complete that pair.
Remote-local doesn't matter once wired: a node just sees a flat list of peers it trusts,
whether they're in the same Compose project or on the other side of the world.

To update, update the shared checkout in `docker/uphptime` using your normal Git workflow,
then run `docker compose up -d --build`. Rebuilding does not overwrite the bind-mounted
configuration or data.

To stop the services without deleting data:

```sh
docker compose down
```

## Backups

Each node has a third bind mount, `./backup/<node>` on the host mapped to
`/var/www/html/backup` in the container, alongside the existing `config`/`data` mounts. It's
where `backup.sh` writes `storage:export` output - see "Storage" in the main README for
exactly what that command captures (sites, peers, peer keys, peer reputations, history,
rollups). Backing up all three nodes, not just `primary`, matters here even though
`peer1`/`peer2` never hold sites: without their config, a lost `peer1`/`peer2` means
re-running the two-sided key exchange with every peer that trusted it.

```sh
sh backup.sh                # exports all three nodes, keeps the 14 most recent per node
sh backup.sh --keep=30      # keep more history
```

Each run adds a timestamped subdirectory, e.g. `backup/primary/20260311T020000Z/`, and prunes
older ones beyond `--keep`. To restore one, point `storage:import` at it:

```sh
sh exec.sh primary storage:import --dir=/var/www/html/backup/20260311T020000Z --force
```

`backup.sh` only exports; it never calls `storage:import` itself, so a bad backup can't
clobber a running node by accident.

Run it on a schedule from the host (not from inside a container, since it shells out to
`docker compose exec`). Either a host crontab entry:

```cron
0 2 * * * cd /path/to/docs/examples/peer-group && sh backup.sh >> backup.log 2>&1
```

or a systemd timer, `/etc/systemd/system/uphptime-peer-group-backup.service`:

```ini
[Service]
Type=oneshot
WorkingDirectory=/path/to/docs/examples/peer-group
ExecStart=/bin/sh backup.sh
```

and `/etc/systemd/system/uphptime-peer-group-backup.timer`:

```ini
[Timer]
OnCalendar=*-*-* 02:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

enabled with `systemctl enable --now uphptime-peer-group-backup.timer`.

See the [main README](../../README.md) for configuration, peers, scheduling, and TLS.
