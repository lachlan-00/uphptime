# uPHPtime with dashboard

This Compose setup runs a uPHPtime node and the static
[uPHPtime dashboard](https://github.com/lachlan-00/uphptime-dashboard).

## Requirements

- Git and Docker with the Compose plugin
- PHP CLI on the host for `bootstrap.sh`

## Setup

From `docs/examples/with-dashboard`:

```sh
sh setup.sh
docker compose up -d --build
sh bootstrap.sh
```

`setup.sh` clones the server and dashboard into `docker/uphptime` and
`docker/uphptime-dashboard` (relative to this directory). It leaves existing directories
unchanged; it does not update them.
If an existing checkout is incorrect, move it aside or correct it before running setup again.
Compose builds images from these checkouts, not from the surrounding repository.

`bootstrap.sh` reuses or creates a report API key, adds a demo site if there are no sites, and
runs a check. Enter the printed node URL and key in the dashboard's **Settings**. For remote
access, use a node URL reachable from the browser rather than `localhost`. Treat the printed
key as a secret.

| Service   | Default host port | Override              |
|-----------|------------------:|-----------------------|
| Node      |             48488 | `UPHPTIME_HTTP_PORT`  |
| Dashboard |             48480 | `DASHBOARD_HTTP_PORT` |

Set overrides in a `.env` file next to `docker-compose.yml`. The bootstrap script's printed
URLs use shell environment variables; adjust them to match any Compose `.env` overrides.

## Operations

```sh
sh exec.sh sites:add --url=https://example.com/ --name="Example" --groups=prod
sh exec.sh sites:list
sh exec.sh check:run
sh exec.sh report --id=SITE_ID --period=overall
docker compose logs -f primary
```

`exec.sh` runs commands as `www-data`, matching Apache and cron. The node runs scheduled
checks and cache refreshes inside the container.

Node configuration is stored in `data/primary/config/node.json` and monitoring data in
`data/primary/data`. Set `report_cors_allowed_origin` to the dashboard's actual origin instead
of `*`. Configuration is read on each request. Preserve these directories for backups.

To update, update each checkout in `docker/` using your normal Git workflow, then run
`docker compose up -d --build`. Rebuilding does not overwrite the bind-mounted configuration or
data. Update the checking peer separately when changing peer-side behavior.

To stop the services without deleting data:

```sh
docker compose down
```

## Backups

`primary` has a third bind mount, `./backup` on the host mapped to `/var/www/html/backup` in
the container, alongside the existing `config`/`data` mounts. It's where `backup.sh` writes
`storage:export` output - see "Storage" in the main README for exactly what that command
captures (sites, peers, peer keys, peer reputations, history, rollups).

```sh
sh backup.sh                # exports primary, keeps the 14 most recent backups
sh backup.sh --keep=30      # keep more history
```

Each run adds a timestamped subdirectory, e.g. `backup/20260311T020000Z/`, and prunes older
ones beyond `--keep`. To restore one, point `storage:import` at it:

```sh
sh exec.sh storage:import --dir=/var/www/html/backup/20260311T020000Z --force
```

`backup.sh` only exports; it never calls `storage:import` itself, so a bad backup can't
clobber a running node by accident.

Run it on a schedule from the host (not from inside a container, since it shells out to
`docker compose exec`). Either a host crontab entry:

```cron
0 2 * * * cd /path/to/docs/examples/with-dashboard && sh backup.sh >> backup.log 2>&1
```

or a systemd timer, `/etc/systemd/system/uphptime-dashboard-backup.service`:

```ini
[Service]
Type=oneshot
WorkingDirectory=/path/to/docs/examples/with-dashboard
ExecStart=/bin/sh backup.sh
```

and `/etc/systemd/system/uphptime-dashboard-backup.timer`:

```ini
[Timer]
OnCalendar=*-*-* 02:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

enabled with `systemctl enable --now uphptime-dashboard-backup.timer`.

See the [main README](../../README.md) for configuration, peers, scheduling, and TLS.
For a signed peer API test, see the [peer API walkthrough](../../docs/peer-api.md).
