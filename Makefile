PHP ?= php
COMPOSER ?= composer
PHPUNIT ?= vendor/bin/phpunit

ELEPHC_BUILD := mod/elephc/build
ELEPHC_LIB_EXT := $(if $(filter Darwin,$(shell uname -s)),dylib,so)
ELEPHC_ORACLE := $(ELEPHC_BUILD)/php83-cases.json

.PHONY: install test test-filter types analyse lint docker-test docker-down native-test elephc-build elephc-test

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

# Build the elephc artifacts on the host; needs elephc on PATH. See mod/elephc/README.md.
elephc-build:
	sh mod/elephc/build.sh

# Parity and ABI checks for the elephc artifacts. Needs a PHP 8.3 interpreter for the oracle.
elephc-test: elephc-build
	$(PHP) mod/native/tests/export-cases.php $(ELEPHC_ORACLE)
	WORKFLOW_NATIVE_BIN=$(ELEPHC_BUILD)/workflow-native \
	WORKFLOW_NATIVE_LIB=$(ELEPHC_BUILD)/libworkflow_native.$(ELEPHC_LIB_EXT) \
	WORKFLOW_ORACLE=$(ELEPHC_ORACLE) \
		python3 mod/elephc/tests/conformance.py
	WORKFLOW_NATIVE_BIN=$(ELEPHC_BUILD)/workflow-native \
		node --test mod/elephc/tests/node_client.test.mjs
