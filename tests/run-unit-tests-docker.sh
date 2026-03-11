#!/usr/bin/env bash
#
# Run PHPUnit unit tests inside a Docker container.
# No local PHP installation required — only Docker.
#
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"

docker run --rm \
    -v "$PROJECT_ROOT":/app \
    -w /app \
    php:8.1-cli \
    bash -c '
        apt-get update -qq && apt-get install -y -qq git unzip > /dev/null 2>&1

        curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer > /dev/null 2>&1

        composer install --no-interaction --prefer-dist --quiet

        cd vendor-bin/phpunit && composer install --no-interaction --prefer-dist --quiet && cd /app

        vendor-bin/phpunit/vendor/bin/phpunit --configuration tests/php/unit/phpunit.xml tests/php/unit
    '
