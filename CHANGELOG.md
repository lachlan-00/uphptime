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
