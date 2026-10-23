# Elephc build toolchain. Used by docker compose to compile the elephc artifacts.
FROM dunglas/frankenphp:1.12.7-php8.5-bookworm@sha256:59d92e5b7616cdabf6cd9817575ed0555024bf73ac398a57df1320306bc23dd2 AS toolchain

RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential cmake pkg-config libgmp-dev libmpfr-dev git unzip python3 curl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Install elephc from a specific GitHub release (prebuilt for glibc 2.35+)
# The release tarballs include elephc + bridge staticlibs (libelephc_*.a)
ARG ELEPHC_VERSION=0.27.5
RUN set -eu; \
    url="https://github.com/illegalstudio/elephc/releases/download/v${ELEPHC_VERSION}/elephc-v${ELEPHC_VERSION}-aarch64-unknown-linux-gnu.tar.gz"; \
    curl -fsSL "$url" -o /tmp/elephc.tar.gz && \
    tar -xzf /tmp/elephc.tar.gz -C /tmp && \
    install -m 755 /tmp/elephc /usr/local/bin/elephc && \
    install -m 644 /tmp/libelephc_*.a /usr/local/lib/ && \
    rm -rf /tmp/elephc /tmp/elephc.tar.gz

RUN elephc --version

WORKDIR /workspace
ENTRYPOINT []
CMD ["sh", "mod/elephc/build.sh"]

# Reference stage: generate PHP 8.3 parity oracle
FROM php:8.3-cli AS reference
WORKDIR /workspace
COPY src ./src
COPY mod/native ./mod/native
COPY mod/elephc ./mod/elephc
RUN mkdir /build && php mod/native/tests/export-cases.php

# Build stage: copy source, install project deps, compile with elephc
FROM toolchain AS build
COPY src ./src
COPY mod/native ./mod/native
COPY mod/elephc ./mod/elephc
COPY composer.json composer.lock ./
COPY LICENSE ./LICENSE
COPY --from=reference /build/php83-cases.json /build/php83-cases.json
RUN composer install --no-interaction --prefer-dist --no-scripts
RUN sh mod/elephc/build.sh
RUN python3 mod/elephc/package.py

# Runtime stage: minimal image with just the artifacts
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

# Verify stage: test the artifacts
FROM runtime AS verify
RUN apt-get update && apt-get install -y --no-install-recommends python3 \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=reference /build/php83-cases.json /build/php83-cases.json
COPY mod/elephc/tests /workspace/mod/elephc/tests
COPY mod/elephc/examples /workspace/mod/elephc/examples
WORKDIR /workspace
RUN ln -s /opt/workflow/bin/workflow-native /build/workflow-native \
    && ln -s /opt/workflow/lib/libworkflow_native.so /build/libworkflow_native.so \
    && python3 mod/elephc/tests/conformance.py \
    && node --test mod/elephc/tests/node_client.test.mjs \
    && touch /runtime-verified

FROM scratch AS artifacts
COPY --from=build /dist/ /
COPY --from=verify /runtime-verified /runtime-verified

FROM runtime AS release
COPY --from=verify /runtime-verified /opt/workflow/runtime-verified
USER 65532:65532