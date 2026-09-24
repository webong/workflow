FROM dunglas/frankenphp:1.12.7-php8.5-bookworm@sha256:59d92e5b7616cdabf6cd9817575ed0555024bf73ac398a57df1320306bc23dd2 AS toolchain

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential cmake pkg-config libgmp-dev libmpfr-dev git unzip python3 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /opt/typephp
COPY mod/typephp/composer.json mod/typephp/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
ENV PHP_HOME=/usr/local \
    PHPX_HOME=/opt/typephp/vendor/swoole/phpx \
    LD_LIBRARY_PATH=/opt/typephp/vendor/swoole/phpx/lib:/usr/local/lib
RUN cmake -S "$PHPX_HOME" -B /opt/phpx-build \
      -DCMAKE_BUILD_TYPE=Release -DBUILD_TESTS=OFF -DBUILD_EXT=OFF -Dphp_dir="$PHP_HOME" \
    && cmake --build /opt/phpx-build --target phpx --parallel 2

COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node

WORKDIR /workspace
ENTRYPOINT []
CMD ["sh", "mod/typephp/test.sh"]

FROM php:8.3-cli AS reference
WORKDIR /workspace
COPY src ./src
COPY mod/typephp ./mod/typephp
RUN mkdir /build && php mod/typephp/tests/export-cases.php

FROM toolchain AS build
COPY src ./src
COPY mod/typephp ./mod/typephp
COPY LICENSE ./LICENSE
COPY --from=reference /build/php83-cases.json /build/php83-cases.json
RUN sh mod/typephp/test.sh
RUN python3 mod/typephp/package.py

FROM dunglas/frankenphp:1.12.7-php8.5-bookworm@sha256:59d92e5b7616cdabf6cd9817575ed0555024bf73ac398a57df1320306bc23dd2 AS runtime
HEALTHCHECK NONE
RUN apt-get update && apt-get install -y --no-install-recommends libgmpxx4ldbl libmpfr6 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /dist/*.tar.gz /tmp/workflow-native.tar.gz
RUN mkdir -p /opt/workflow \
    && tar -xzf /tmp/workflow-native.tar.gz --strip-components=1 -C /opt/workflow \
    && rm /tmp/workflow-native.tar.gz
ENV LD_LIBRARY_PATH=/opt/workflow/lib:/usr/local/lib
WORKDIR /opt/workflow
ENTRYPOINT ["/opt/workflow/bin/workflow-native"]
CMD []

# The final image depends on these checks; it cannot bypass runtime verification.
FROM runtime AS verify
RUN apt-get update && apt-get install -y --no-install-recommends python3 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=reference /build/php83-cases.json /build/php83-cases.json
COPY mod/typephp/tests /workspace/mod/typephp/tests
COPY mod/typephp/examples /workspace/mod/typephp/examples
WORKDIR /workspace
RUN ln -s /opt/workflow/bin/workflow-native /build/workflow-native \
    && ln -s /opt/workflow/lib/libworkflow_native.so /build/libworkflow_native.so \
    && python3 mod/typephp/tests/conformance.py \
    && node --test mod/typephp/tests/node_client.test.mjs \
    && touch /runtime-verified

FROM scratch AS artifacts
COPY --from=build /dist/ /
COPY --from=verify /runtime-verified /runtime-verified

FROM runtime AS release
COPY --from=verify /runtime-verified /opt/workflow/runtime-verified
USER 65532:65532
