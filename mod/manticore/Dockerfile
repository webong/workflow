# Manticore build toolchain. Used by docker compose to compile the Manticore artifacts.
#
# The toolchain stage uses the published ghcr.io/manticorephp/compiler image,
# which ships a prebuilt Manticore compiler plus clang (LLVM >= 15), cc, pcre2,
# OpenSSL, zlib, and pkg-config — everything Manticore needs to emit native
# binaries. No PHP runtime is needed to build or to run the output.

ARG MANTICORE_IMAGE=ghcr.io/manticorephp/compiler:latest
FROM ${MANTICORE_IMAGE} AS toolchain

RUN apt-get update && apt-get install -y --no-install-recommends python3 \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspace
ENTRYPOINT []
CMD ["sh", "mod/manticore/build.sh"]

# Reference stage: generate PHP 8.3 parity oracle
FROM php:8.3-cli AS reference
WORKDIR /workspace
COPY src ./src
COPY mod/native ./mod/native
RUN mkdir /build && php mod/native/tests/export-cases.php

# Build stage: copy source, compile with Manticore
FROM toolchain AS build
COPY src ./src
COPY mod/native ./mod/native
COPY mod/manticore ./mod/manticore
COPY LICENSE ./LICENSE
COPY --from=reference /build/php83-cases.json /build/php83-cases.json
RUN sh mod/manticore/build.sh
RUN python3 mod/manticore/package.py

# Runtime stage: minimal image with just the compiled executable
FROM debian:bookworm-slim AS runtime
HEALTHCHECK NONE
RUN apt-get update && apt-get install -y --no-install-recommends \
    libc6 libpcre2-8-0 libssl3 zlib1g \
    && rm -rf /var/lib/apt/lists/*
COPY --from=build /dist/*.tar.gz /tmp/workflow-native.tar.gz
RUN mkdir -p /opt/workflow \
    && tar -xzf /tmp/workflow-native.tar.gz --strip-components=1 -C /opt/workflow \
    && rm /tmp/workflow-native.tar.gz
WORKDIR /opt/workflow
ENTRYPOINT ["/opt/workflow/bin/workflow-native"]
CMD []

# Verify stage: test the artifacts against the PHP 8.3 oracle
FROM runtime AS verify
RUN apt-get update && apt-get install -y --no-install-recommends python3 nodejs \
    && rm -rf /var/lib/apt/lists/*
COPY --from=reference /build/php83-cases.json /build/php83-cases.json
COPY mod/manticore/tests /workspace/mod/manticore/tests
COPY mod/manticore/examples /workspace/mod/manticore/examples
WORKDIR /workspace
RUN mkdir -p /build \
    && WORKFLOW_NATIVE_BIN=/opt/workflow/bin/workflow-native \
       WORKFLOW_ORACLE=/build/php83-cases.json \
       python3 mod/manticore/tests/conformance.py \
    && WORKFLOW_NATIVE_BIN=/opt/workflow/bin/workflow-native \
       node --test mod/manticore/tests/node_client.test.mjs \
    && touch /runtime-verified

FROM scratch AS artifacts
COPY --from=build /dist/ /
COPY --from=verify /runtime-verified /runtime-verified

FROM runtime AS release
COPY --from=verify /runtime-verified /opt/workflow/runtime-verified
USER 65532:65532
