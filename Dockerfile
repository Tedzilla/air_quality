FROM php:8.3-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev sqlite3 ca-certificates curl \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

ARG DUCKDB_VERSION=1.4.2
RUN curl -fsSL "https://github.com/duckdb/duckdb/releases/download/v${DUCKDB_VERSION}/duckdb_cli-linux-amd64.zip" -o /tmp/duckdb.zip \
    && unzip /tmp/duckdb.zip -d /usr/local/bin \
    && chmod +x /usr/local/bin/duckdb \
    && rm /tmp/duckdb.zip

WORKDIR /app
COPY . /app
RUN chmod +x /app/docker/entrypoint.sh

ENV DATA_DIR=/var/lib/air_quality \
    DUCKDB_BIN=/usr/local/bin/duckdb \
    APP_PORT=8080

VOLUME ["/var/lib/air_quality"]
EXPOSE 8080

ENTRYPOINT ["/app/docker/entrypoint.sh"]
