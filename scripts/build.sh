#!/bin/bash

# Builds the plugin as WordPress installs it: dist/vpndetection/, and dist/vpndetection-<version>.zip
# for the plugin directory and the GitHub Release.
#
#   ./scripts/build.sh            # the folder and the zip
#   ./scripts/build.sh --no-zip   # the folder alone, for the test scripts
#
# composer installs the SDK and its dependencies as composer.lock pins them, PHP-Scoper moves every one
# of them under VPNDetection\WordPress\Vendor, and composer writes the autoloader over the result. Both
# run in the official composer image, so the box needs docker and zip, and no PHP.

set -euo pipefail

cd "$(dirname "$0")/.."

COMPOSER_IMAGE="composer:2.10.3"
SCOPER_VERSION="0.18.20"
SCOPER_SHA256="5e35e4a89ea53b2c70a19bfa8a856e4f9080aa12eb13a5bce650bb69d08eb122"
# The plugin's slug, also its main file's name and its text domain, and the PHP SDK's root namespace.
SLUG="vpndetection"
NAMESPACE="VPNDetection"

zip=y
case "${1:-}" in
    "") ;;
    --no-zip) zip=n ;;
    *) echo "usage: build.sh [--no-zip]" >&2; exit 1 ;;
esac

version="$(sed -n 's/^ \* Version: *//p' "${SLUG}.php")"
[ -n "$version" ] || { echo "no Version: in ${SLUG}.php" >&2; exit 1; }
constant="${SLUG^^}_VERSION"
grep -q "^const ${constant} = '${version}';$" "${SLUG}.php" ||
    { echo "${constant} in ${SLUG}.php is not ${version}, its Version: header" >&2; exit 1; }
grep -q "^Stable tag: ${version}$" readme.txt ||
    { echo "readme.txt's Stable tag is not ${version}, the plugin's Version:" >&2; exit 1; }

# PHP-Scoper, pinned by checksum and kept between builds.
cache="${XDG_CACHE_HOME:-${HOME}/.cache}/vpndetection-wordpress"
scoper="${cache}/php-scoper-${SCOPER_VERSION}.phar"
mkdir -p "$cache"
if ! echo "${SCOPER_SHA256}  ${scoper}" | sha256sum -c --quiet > /dev/null 2>&1 ; then
    curl -fsSL --max-time 120 -o "${scoper}.part" \
        "https://github.com/humbug/php-scoper/releases/download/${SCOPER_VERSION}/php-scoper.phar"
    echo "${SCOPER_SHA256}  ${scoper}.part" | sha256sum -c --quiet ||
        { echo "php-scoper ${SCOPER_VERSION} does not match its pinned checksum" >&2; rm -f "${scoper}.part"; exit 1; }
    mv "${scoper}.part" "$scoper"
fi

work="build"
out="dist/${SLUG}"
rm -rf "$work" "$out"
mkdir -p "${work}/install" "$out" dist
cp composer.json composer.lock scoper.inc.php "${work}/install/"

function in_image() {
    docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "${PWD}:/app" -v "${cache}:/cache:ro" \
        -w "/app/$1" "$COMPOSER_IMAGE" "${@:2}"
}

echo "==> composer install --no-dev"
in_image "${work}/install" composer install --no-dev --no-progress --prefer-dist --no-interaction --quiet
echo "==> php-scoper"
in_image "${work}/install" php "/cache/$(basename "$scoper")" add-prefix --output-dir=../scoped \
    --config=scoper.inc.php --force --no-interaction --quiet

echo "==> ${out}"
cp "${SLUG}.php" uninstall.php readme.txt LICENSE "$out/"
for dir in src assets languages ; do
    if [ -d "$dir" ] ; then
        cp -R "$dir" "$out/"
    fi
done
mkdir -p "${out}/vendor/composer"
cp -R "${work}/scoped/." "${out}/vendor/"
# Composer's runtime, which the scoped tree leaves out on purpose: the SDK reads its own version from
# Composer\InstalledVersions, global so every plugin's copy answers for every installed package.
cp "${work}/install/vendor/composer/installed.php" "${work}/install/vendor/composer/InstalledVersions.php" \
    "${out}/vendor/composer/"
# composer.json and its lock stay in the build: the plugin directory asks for them beside vendor/.
cp composer.json composer.lock "$out/"
# A suffix of the plugin's own, so its autoloader's class never shares a name with another's.
in_image "$out" composer config autoloader-suffix "${NAMESPACE}WordPress"
in_image "$out" composer dump-autoload --no-dev --classmap-authoritative --no-interaction --quiet

# Nothing of the scoped tree may still be named as itself.
unscoped="$(grep -rlP "^namespace (GuzzleHttp|Psr|${NAMESPACE})(;|\\\\(?!WordPress\\\\Vendor))" "${out}/vendor" \
    --include='*.php' | grep -v '/composer/' || true)"
if [ -n "$unscoped" ] ; then
    echo "these vendored files kept their own namespace:" >&2
    echo "$unscoped" >&2
    exit 1
fi

rm -rf "$work"
if [ "$zip" = y ] ; then
    rm -f "dist/${SLUG}-${version}.zip"
    (cd dist && zip -qr -X "${SLUG}-${version}.zip" "$SLUG")
    echo "dist/${SLUG}-${version}.zip: $(du -h "dist/${SLUG}-${version}.zip" | cut -f1)"
fi
