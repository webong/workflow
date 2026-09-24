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
COPY composer.json ./
COPY src ./src
COPY ext ./ext
COPY mod/php ./mod/php
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --optimize-autoloader

COPY --from=go-builder /usr/local/bin/workflowd /usr/local/bin/workflowd

ENV WORKFLOW_PHP_PUBLIC=/app/mod/php/public \
    WORKFLOW_HEALTH_URL=http://127.0.0.1:8080/healthz
HEALTHCHECK --interval=5s --timeout=3s --start-period=10s --retries=6 \
    CMD curl --fail --silent --max-time 2 "$WORKFLOW_HEALTH_URL" || exit 1
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/workflowd"]
