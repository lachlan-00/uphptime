# Test databases

Disposable MySQL and PostgreSQL instances for the SQL integration tests described in
[CONTRIBUTING.md](../../../CONTRIBUTING.md#tests-and-quality-checks). This is a separate
Compose project from the application's root [`docker-compose.yml`](../../../docker-compose.yml)
and from the other `docs/examples/` deployments - it runs only the two test databases, never
the uPHPtime node itself.

```sh
docker compose -f docs/examples/test-databases/docker-compose.yml up -d
composer tests
docker compose -f docs/examples/test-databases/docker-compose.yml down
```

The databases use host ports `3307` and `5433`. Tests skip when a database is unavailable;
check the PHPUnit output for skips. **The SQL tests delete data from the configured test
tables**: use only the disposable `uphptime_test` databases, never production databases.
`docker compose down` stops the services without deleting their named volumes; add `-v` to
also drop the volumes and start from an empty database next time.
