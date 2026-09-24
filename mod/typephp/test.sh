#!/bin/sh
set -eu

php /opt/typephp/vendor/bin/tpc.php mod/typephp/project.yml --no-progress --job 2
php /opt/typephp/vendor/bin/tpc.php mod/typephp/library.yml --no-progress --job 2
python3 mod/typephp/tests/conformance.py
node --test mod/typephp/tests/node_client.test.mjs
