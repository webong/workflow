PHP ?= php
COMPOSER ?= composer
PHPUNIT ?= vendor/bin/phpunit

ELEPHC_BUILD := mod/elephc/build
ELEPHC_LIB_EXT := $(if $(filter Darwin,$(shell uname -s)),dylib,so)
ELEPHC_ORACLE := $(ELEPHC_BUILD)/php83-cases.json

MANTICORE_BUILD := mod/manticore/build
MANTICORE_ORACLE := $(MANTICORE_BUILD)/php83-cases.json

.PHONY: install test test-filter types analyse lint docker-test docker-down native-test elephc-build elephc-test manticore-build manticore-test

install:
	$(COMPOSER) install --no-interaction

test:
	$(PHPUNIT) --testdox

test-filter:
	$(PHPUNIT) --testdox --filter "$(filter)"

types:
	$(PHP) tools/generate-types.php

analyse:
	vendor/bin/phpstan analyse --no-progress

lint:
	@find src ext app examples tests -type f -name '*.php' -print0 | while IFS= read -r -d '' file; do $(PHP) -l "$$file"; done

docker-test:
	docker compose -f docker-compose.test.yml up -d --build postgres mysql redis php
	docker compose -f docker-compose.test.yml run --rm php sh -lc 'composer update --no-interaction --prefer-dist && composer test && composer analyse && composer types'

docker-down:
	docker compose -f docker-compose.test.yml down

native-test:
	docker compose -f mod/typephp/compose.yml build native
	docker compose -f mod/typephp/compose.yml run --rm baseline
	docker compose -f mod/typephp/compose.yml run --rm native

# Build the elephc artifacts in Docker (nothing installed on the host).
elephc-build:
	docker compose -f mod/elephc/compose.yml build elephc
	docker compose -f mod/elephc/compose.yml run --rm baseline
	docker compose -f mod/elephc/compose.yml run --rm elephc

# Parity and ABI checks for the elephc artifacts, all in Docker.
elephc-test: elephc-build
	docker compose -f mod/elephc/compose.yml run --rm elephc sh -c 'WORKFLOW_NATIVE_LIB=/build/libworkflow_native.so python3 mod/elephc/tests/conformance.py && node --test mod/elephc/tests/node_client.test.mjs'

# Build the Manticore artifacts in Docker (nothing installed on the host).
manticore-build:
	docker compose -f mod/manticore/compose.yml build build
	docker compose -f mod/manticore/compose.yml run --rm baseline

# Parity checks for the Manticore artifacts, all in Docker.
manticore-test: manticore-build
	docker compose -f mod/manticore/compose.yml build verify

# Isolated queue concurrency and failure tests on PHP 8.3 with three SQL backends.
.PHONY: queue-test
queue-test:
	docker compose -f compose.queue-test.yml run --rm php sh -lc 'php -v && composer install --no-interaction --prefer-dist && composer test && composer analyse && composer types && git diff --exit-code -- types/'
