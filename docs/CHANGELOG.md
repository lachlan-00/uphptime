# uPHPtime

## 0.0.0

* Versioned user agents for HTTP checks and peer requests.
* HTTP, HTTP keyword, TCP and ICMP monitoring with scheduled checks and uptime reports.
* Peer delegation with signed requests, failure confirmation, canary checks and peer reputation.
* Unknown state for stale or inconclusive checks. Error outcomes (including `delegated_check_failed`) leave the site state unchanged but count against uptime and are reported as `checks_error` / `error_percent`.
* Unresponsive or erroring peers are skipped for another peer, then this node checks itself; new `local_failure_counts_as_down` option.
* Configurable peer redirect handling, egress restrictions and delegated-check caching.
* Site groups, maintenance windows and optional HTTP challenge-token attestation.
* JSON, MySQL and PostgreSQL storage, with import and export.
* Report API, OpenAPI specification and Docker deployments, including a dashboard setup.
* `check:run` output reports `total_sites` and `skipped_due_to_overlap` (sites left unchecked when the exclusive lock was already held by another run).
* `storage:migrate-timezone --force` deduplicates history records and never writes more records than it read.
* Report API, `bin/uphptime report` and the dashboard support `period=range` (`start`/`end`, whole calendar days inclusive) for custom multi-day reports such as a week.
* `check_due_grace_seconds` (default 30) treats a site as due slightly ahead of its `next_due_at`, so ordinary cron/process-startup jitter never strands it a whole extra interval unchecked.
* `confirmed_uptime_percent` and `checks_confirmed_down` count only runs of two or more consecutive missed checks as downtime, alongside the raw `uptime_percent`.
* `check:recheck` CLI command (scheduled every minute) rechecks sites whose last check did not pass and restores recovered ones to up, sharing the `check:run` lock without blocking it.
* `config:check` CLI command reports any `config/node.json` key missing its value, and `--fix` fills in just those keys with their defaults without touching anything already set.
