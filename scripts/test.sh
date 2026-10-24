#!/bin/bash

# The unit suite, on the PHP composer.json declares as the floor unless another is named, against the
# built plugin's scoped autoloader. The end-to-end suite in a real WordPress is scripts/e2e.sh.
#
#   ./scripts/test.sh                      # PHP 8.2
#   PHP_VERSION=8.5 ./scripts/test.sh

set -euo pipefail

cd "$(dirname "$0")/.."

PHP_VERSION="${PHP_VERSION:-8.2}"
declare -A PHP_IMAGES=([8.2]=php:8.2.35-cli [8.3]=php:8.3.36-cli [8.4]=php:8.4.27-cli [8.5]=php:8.5.12-cli)
image="${PHP_IMAGES[$PHP_VERSION]:-}"
[ -n "$image" ] || { echo "no image pinned for PHP ${PHP_VERSION}" >&2; exit 1; }

./scripts/build.sh --no-zip
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "${PWD}:/app" -w /app composer:2.10.3 \
    install --no-progress --no-interaction --quiet
docker run --rm -u "$(id -u):$(id -g)" -v "${PWD}:/app" -w /app "$image" php vendor/bin/phpunit "$@"
