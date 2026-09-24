FROM dunglas/frankenphp:1.12.7-php8.5-bookworm@sha256:59d92e5b7616cdabf6cd9817575ed0555024bf73ac398a57df1320306bc23dd2

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
