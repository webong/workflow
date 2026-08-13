PHP ?= php
COMPOSER ?= composer
PHPUNIT ?= vendor/bin/phpunit

.PHONY: install test test-filter types analyse lint

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
	@find src tests -type f -name '*.php' -print0 | while IFS= read -r -d '' file; do $(PHP) -l "$$file"; done
