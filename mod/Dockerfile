FROM dunglas/frankenphp:1.12.7-builder-php8.3-bookworm AS go-builder

WORKDIR /src/mod
COPY mod/go.mod mod/go.sum ./
RUN go mod download
COPY mod/ ./
RUN CGO_ENABLED=0 go test ./internal/rpc && \
    CGO_ENABLED=0 go vet ./internal/rpc
RUN CGO_ENABLED=1 \
    CGO_CFLAGS="$(php-config --includes)" \
    CGO_LDFLAGS="$(php-config --ldflags) $(php-config --libs)" \
    go build -tags=nowatcher,nobrotli,nomercure -o /usr/local/bin/workflowd ./cmd/workflowd

FROM dunglas/frankenphp:1.12.7-php8.3-bookworm

RUN install-php-extensions pdo_pgsql
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
COPY ext ./ext
COPY mod/php ./mod/php
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader

COPY --from=go-builder /usr/local/bin/workflowd /usr/local/bin/workflowd

ENV WORKFLOW_PHP_PUBLIC=/app/mod/php/public
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/workflowd"]
