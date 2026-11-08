# Contributing to uPHPtime

Contributions to code, tests and documentation are welcome. For a substantial change,
describe the problem and proposed approach in an issue before investing in an implementation.
For setup and user-facing commands, see the [README](README.md).

## Project intentions

uPHPtime is intended to be a small, self-hosted monitoring node that can operate alone or
cooperate with independently administered peers. It uses short-lived scheduled commands
and an HTTP API rather than requiring a dedicated application daemon. The
[dashboard](https://github.com/lachlan-00/uphptime-dashboard) is a separate project.

Changes should preserve the distinction between an observed site failure and an inability
to obtain a trustworthy observation. A peer communication failure or an unconfirmed local
network failure must not by itself become reported downtime. Inconclusive or stale results
are shown as unknown, while the last confirmed state is retained. Consider how a change
affects history, rollups and reports as well as the current state.

Peers are independently administered and not assumed to be infallible. Authentication,
confirmation, reputation, canary checks and optional attestation support this model. Keep
network access bounded by the existing egress policy, timeouts and response limits; do not
log peer secrets, authorization headers or full URLs containing sensitive query data.

## Development setup

Use PHP 8.1 or later with JSON, cURL and OpenSSL extensions and Composer. Install the
development dependencies and create a local configuration:

```sh
composer install
cp config/node.json.dist config/node.json
php bin/uphptime help
```

`config/node.json`, `data/`, and `vendor/` are local and ignored by Git. Both entry points
accept `UPHPTIME_CONFIG_PATH` to select an alternative configuration file. Use isolated
configuration and data for experiments rather than a production node's files.

## Code layout

Classes use the `Uphptime\` namespace, mapped to `src/Uphptime/` by Composer. The test
namespace maps to `tests/Uphptime/`, which generally mirrors the source tree.

| Path                                     | Responsibility                                                                                              |
|------------------------------------------|-------------------------------------------------------------------------------------------------------------|
| `bin/uphptime`                           | CLI entry point                                                                                             |
| `public/index.php`                       | Slim HTTP routes and middleware                                                                             |
| `src/Uphptime/DicBuilder.php`            | Combines domain service definitions into the dependency injection container                                 |
| `src/Uphptime/Api/`                      | API actions, authentication, signing, peer client, challenge tokens and OpenAPI specification               |
| `src/Uphptime/Check/`                    | Local checkers and check-run orchestration, peer result acquisition, confirmation, canaries and attestation |
| `src/Uphptime/Pinger/`                   | Execution of delegated checks, DNS and egress policy, HTTP/TCP/ICMP probes and caching                      |
| `src/Uphptime/Site/`, `History/`         | Site configuration, runtime state and state transitions; history records and rollups                        |
| `src/Uphptime/Peer/`                     | Peer configuration, selection and reputation                                                                |
| `src/Uphptime/Reporting/`, `Scheduling/` | Reports, rollups and retention; run budgets and locks                                                       |
| `src/Uphptime/Storage/`                  | Repository interfaces, JSON and PDO implementations, and import/export                                      |
| `src/Uphptime/Config/`, `Support/`       | Configuration loading and shared infrastructure such as clock and filesystem helpers                        |
| `src/Uphptime/Cli/`                      | Command implementations, option parsing and command registration                                            |
| `config/`, `resources/sql/`              | Example configuration and MySQL/PostgreSQL schemas                                                          |
| `docker/`, `docker-compose.yml`          | Application deployment (the root `docker-compose.yml` is the default, single-node setup)                    |
| `docs/examples/test-databases/`          | Disposable MySQL/PostgreSQL services for the SQL integration tests                                          |
| `docs/openapi.json`                      | Generated API specification                                                                                 |

### Following a check

`check:run` enters through `Cli/Commands/CheckRunCommand` and invokes `Check/CheckRunner`
under a lock. The runner closes rollups, prunes retained data, selects due sites within the
run budget and either delegates to a usable peer or checks locally. Delegated results can
require confirmation or canary verification. The resulting outcome is applied through
`Site/SiteStateMachine`, and history and site state are persisted through repository
interfaces. Reporting reads history and rollups, including maintenance and inconclusive
outcome handling.

On the receiving node, `POST /peer/v1/check-site` passes through HMAC middleware and an API
action to the pinger executor. Pinger-side checks apply egress policy and may use a cache.
The owner's state machine decides how to use the result; the pinger does not update the
owner's site directly.

## Making changes

- Follow the existing PHP style, strict types and PHP 8.1 compatibility. Prefer the existing
  interfaces and injected collaborators for storage, time, randomness and network behavior.
  Register services in the relevant `service_definitions.php` when necessary.
- Add tests in the corresponding `tests/Uphptime/` area. Cover failure and inconclusive paths,
  not just successful checks, and use existing test doubles where practical.
- For a CLI command, add a `CommandInterface` implementation, register it in
  `Cli/service_definitions.php`, and add its help text in `Cli/CommandDescriptions.php`.
  Commands normally emit JSON; help is the human-readable exception.
- For a configuration setting, keep `ConfigKey`, `Config`, `ConfigLoader`, the example
  config and tests consistent. Account for existing configurations that omit the new key.
- Update `src/Uphptime/Version.php` when the application version changes; HTTP checks and
  peer requests use it in their user agent.
- For persisted data, consider JSON and both SQL backends, existing records, schema
  installation, and import/export. Keep storage behavior consistent across backends.
- For API changes, update the routes, actions and authentication as appropriate, then
  update `Api/OpenApi/OpenApiSpecBuilder.php` and regenerate the specification. Keep
  request and response signing compatible across communicating nodes.
- Update the relevant README and the unreleased section of [CHANGELOG.md](CHANGELOG.md)
  when behavior or setup changes.

## Tests and quality checks

Run a focused test while developing, then the quality gate before submitting:

```sh
vendor/bin/phpunit tests/Uphptime/Check/CheckRunnerTest.php
composer qa
```

`composer qa` runs syntax checks, PHP-CS-Fixer in check mode, PHPStan, the OpenAPI
consistency check and PHPUnit. `composer cs:fix` applies formatting; `composer api:docs`
regenerates `docs/openapi.json` after an API specification change. The suite includes HTTP
integration tests that start local PHP servers.

SQL integration tests require the corresponding PDO drivers and the test databases defined
in [`docs/examples/test-databases/docker-compose.yml`](docs/examples/test-databases/docker-compose.yml):

```sh
docker compose -f docs/examples/test-databases/docker-compose.yml up -d
composer tests
docker compose -f docs/examples/test-databases/docker-compose.yml down
```

The databases use host ports `3307` and `5433`. Tests skip when a database is unavailable;
check the PHPUnit output for skips. **The SQL tests delete data from the configured test
tables**: use only the disposable `uphptime_test` databases, never production databases.
`docker compose down` stops the services without deleting their named volumes.

## Submitting a change

Keep pull requests focused and explain the problem, the approach, any behavior or
configuration changes, and how the change was checked. Note skipped tests and any
compatibility or migration considerations. Do not include real credentials, production
data or private URLs in examples or test fixtures.

CI ([`.github/workflows/qa.yml`](.github/workflows/qa.yml)) runs `composer qa` with real MySQL
and PostgreSQL service containers, so the SQL integration tests run for real there rather than
skip. Locally, without the test databases running, they skip cleanly - see "Tests and quality
checks" above.

Contributions are made under the project's [AGPL-3.0-or-later license](LICENSE).
