#!/usr/bin/env bash
#
# Run all tests (unit, integration, behat) inside a Docker container.
# Bootstraps a Nextcloud instance with SQLite, installs the Music app,
# downloads test audio, scans the library, and runs all test suites.
#
# No local PHP or Nextcloud required — only Docker.
#
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
NC_VERSION="${NC_VERSION:-31.0.12}"

echo "==> Starting test container (Nextcloud $NC_VERSION, SQLite)"

docker run --rm \
    -v "$PROJECT_ROOT":/music-src:ro \
    -e NC_VERSION="$NC_VERSION" \
    php:8.2-cli \
    bash -c '
set -euo pipefail

echo "--- Installing system packages ---"
apt-get update -qq > /dev/null 2>&1
apt-get install -y -qq git unzip wget sqlite3 \
    libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libicu-dev libgmp-dev > /dev/null 2>&1
docker-php-ext-configure gd --with-freetype --with-jpeg > /dev/null 2>&1
docker-php-ext-install zip gd intl gmp bcmath pcntl > /dev/null 2>&1
echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory.ini

echo "--- Installing Composer ---"
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer > /dev/null 2>&1

# ── Download Nextcloud ──
echo "--- Downloading Nextcloud $NC_VERSION ---"
mkdir -p /tmp/oc_music_ci && cd /tmp/oc_music_ci
wget -q "https://download.nextcloud.com/server/releases/nextcloud-${NC_VERSION}.zip"
unzip -q "nextcloud-${NC_VERSION}.zip"
mv nextcloud server

# ── Copy Music app into Nextcloud ──
echo "--- Installing Music app ---"
cp -r /music-src server/apps/music
cd server/apps/music

# Install composer deps for the app
composer install --no-interaction --prefer-dist --quiet
cd vendor-bin/phpunit && composer install --no-interaction --prefer-dist --quiet && cd /tmp/oc_music_ci

# ── Install Nextcloud with SQLite ──
echo "--- Installing Nextcloud ---"
cd server
mkdir -p /tmp/oc_music_ci/data
touch config/CAN_INSTALL
php occ maintenance:install \
    --database-name owncloud \
    --database-user oc_autotest \
    --admin-user admin \
    --admin-pass admin123 \
    --database sqlite \
    --database-pass=oc_autotest \
    --data-dir=/tmp/oc_music_ci/data

# Create test user
OC_PASS=ampache123456 php occ user:add ampache --password-from-env

# Set log level
php occ config:system:set loglevel --type=integer --value=1

# Remove ownCloud-specific files
rm -f apps/music/appinfo/database.xml apps/music/appinfo/app.php

# Enable Music app
php occ app:enable music --force

# ── Download and scan test content ──
echo "--- Downloading test audio ---"
apt-get install -y -qq python3-mutagen > /dev/null 2>&1
./apps/music/tests/scripts/downloadTestData.sh /tmp/oc_music_ci/data/ampache

echo "--- Scanning files ---"
php occ files:scan ampache
php occ music:scan ampache

# ── Setup API key for behat tests ──
sqlite3 /tmp/oc_music_ci/data/owncloud.db \
    "INSERT INTO oc_music_ampache_users (user_id, hash) VALUES ('"'"'ampache'"'"', '"'"'3e60b24e84cfa047e41b6867efc3239149c54696844fd3a77731d6d8bb105f18'"'"');"

# ── Run unit tests ──
echo ""
echo "==============================="
echo "   UNIT TESTS"
echo "==============================="
cd /tmp/oc_music_ci/server/apps/music
vendor-bin/phpunit/vendor/bin/phpunit \
    --configuration tests/php/unit/phpunit.xml tests/php/unit

# ── Run integration tests ──
echo ""
echo "==============================="
echo "   INTEGRATION TESTS"
echo "==============================="
vendor-bin/phpunit/vendor/bin/phpunit \
    --configuration tests/php/integration/phpunit.xml tests/php/integration

# ── Run behat tests ──
echo ""
echo "==============================="
echo "   BEHAT (acceptance) TESTS"
echo "==============================="
cd /tmp/oc_music_ci/server
php -S localhost:8888 -t . > /dev/null 2>&1 &
sleep 2

cd apps/music
cp tests/behat.yml.ci_tests tests/behat.yml
vendor/bin/behat -c tests/behat.yml --format=progress

echo ""
echo "==============================="
echo "   ALL TESTS PASSED"
echo "==============================="
'
