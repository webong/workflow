PHP ?= php
COMPOSER ?= composer
PHPUNIT ?= vendor/bin/phpunit

.PHONY: install test test-filter types analyse lint docker-test docker-down native-test

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
	@find src ext mod/php examples tests -type f -name '*.php' -print0 | while IFS= read -r -d '' file; do $(PHP) -l "$$file"; done

docker-test:
	docker compose -f docker-compose.test.yml up -d --build postgres redis php
	docker compose -f docker-compose.test.yml run --rm php sh -lc 'composer update --no-interaction --prefer-dist && composer test && composer analyse && composer types'

docker-down:
	docker compose -f docker-compose.test.yml down

native-test:
	docker compose -f mod/typephp/compose.yml build native
	docker compose -f mod/typephp/compose.yml run --rm baseline
	docker compose -f mod/typephp/compose.yml run --rm native
