# Develop and test WorkFlow

The package baseline is PHP 8.3. Use its isolated Docker test stack instead
of relying on a newer host PHP version:

```sh
make docker-test
make docker-down
```

`make docker-test` builds/starts PHP 8.3, PostgreSQL, and Redis services and
runs PHPUnit, PHPStan, and TypeScript contract generation. `make docker-down`
stops containers without deleting the named volumes. The stack does not use
CRM containers or volumes.

To run just the executable PHP example in the test container after the stack
has been started:

```sh
docker compose -f docker-compose.test.yml run --rm php php examples/order-approval.php
```

The production-like store tests use the Compose PostgreSQL and Redis
services. Outside Compose, set `WORK_FLOW_POSTGRES_DSN`,
`WORK_FLOW_POSTGRES_USER`, `WORK_FLOW_POSTGRES_PASSWORD`,
`WORK_FLOW_REDIS_HOST`, and `WORK_FLOW_REDIS_PORT` before running
`tests/Laravel/ProductionFlowStateStoreTest.php`.

For standalone JSON-RPC development, [`mod/README.md`](../mod/README.md)
shows how to build the separate Go/FrankenPHP image and exercise its API.
The image build runs the Go RPC unit tests and vet checks in its builder.
