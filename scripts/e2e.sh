#!/bin/bash

# Runs the built plugin in a real WordPress: activates it, holds it to wordpress.org's Plugin Check,
# and uninstalls it. Each thing the plugin does gets its scenarios here as it grows.
#
#   ./scripts/e2e.sh [--php 8.2] [--wp 7.1.3] [--keep]

set -euo pipefail

cd "$(dirname "$0")/.."

php="8.2"
wpv="7.1.3"
keep=n
while [ $# -gt 0 ] ; do
    case "$1" in
        --php) php="$2"; shift 2 ;;
        --wp) wpv="$2"; shift 2 ;;
        --keep) keep=y; shift ;;
        *) echo "usage: e2e.sh [--php 8.2] [--wp 7.1.3] [--keep]" >&2; exit 1 ;;
    esac
done

SLUG="vpndetection"
MARIADB_IMAGE="mariadb:11.8.9"
WP_IMAGE="wordpress:${wpv}-php${php}-apache"
CLI_IMAGE="wordpress:cli-2.12.0-php${php}"

./scripts/build.sh --no-zip

run="${SLUG}-e2e-$$"
failures=0
function cleanup() {
    if [ "$keep" = y ] ; then
        echo "kept: network ${run}, containers ${run}-{db,wp,cli}, volume ${run}"
        return
    fi
    docker rm -f "${run}-db" "${run}-wp" "${run}-cli" > /dev/null 2>&1 || true
    docker volume rm "$run" > /dev/null 2>&1 || true
    docker network rm "$run" > /dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$run" > /dev/null
docker volume create "$run" > /dev/null
docker run -d --name "${run}-db" --network "$run" --network-alias db \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp \
    "$MARIADB_IMAGE" > /dev/null
for container in wp cli ; do
    args=(-d --name "${run}-${container}" --network "$run" -v "${run}:/var/www/html"
        -v "${PWD}/dist/${SLUG}:/var/www/html/wp-content/plugins/${SLUG}:ro"
        -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp)
    if [ "$container" = wp ] ; then
        docker run "${args[@]}" --network-alias wp "$WP_IMAGE" > /dev/null
    else
        docker run "${args[@]}" --user 33:33 -e HOME=/tmp --entrypoint sleep "$CLI_IMAGE" infinity > /dev/null
    fi
done

function wp() {
    docker exec "${run}-cli" wp --path=/var/www/html "$@"
}

function check() {
    local name="$1" got="$2" want="$3"
    if grep -qE -- "$want" <<< "$got" ; then
        echo "  ok    ${name}"
    else
        echo "  FAIL  ${name}: wanted /${want}/" >&2
        head -c 1500 <<< "$got" | sed 's/^/        /' >&2
        failures=$((failures + 1))
    fi
}

echo "==> WordPress ${wpv} on PHP ${php}"
for _ in $(seq 1 60) ; do
    if docker exec "${run}-cli" test -f /var/www/html/wp-config.php && wp db check > /dev/null 2>&1 ; then
        break
    fi
    sleep 2
done
wp core install --url=http://wp --title=E2E --admin_user=admin --admin_password=correct-horse \
    --admin_email=admin@example.com --skip-email > /dev/null
# An older WordPress would otherwise update itself in the middle of the run.
wp config set AUTOMATIC_UPDATER_DISABLED true --raw --type=constant > /dev/null
wp config set DISABLE_WP_CRON true --raw --type=constant > /dev/null
wp plugin activate "$SLUG" > /dev/null

echo "==> activation"
check "the plugin is active" "$(wp plugin list --status=active --field=name)" "^${SLUG}$"
check "the site still serves" "$(docker exec "${run}-cli" curl -sS -i http://wp/)" "^HTTP/1.1 200"

echo "==> Plugin Check"
wp plugin install plugin-check --activate > /dev/null
report="$(wp plugin check "$SLUG" --format=json 2>&1 || true)"
errors="$({ grep -o '"type":"ERROR"' <<< "$report" || true; } | wc -l)"
warnings="$({ grep -o '"type":"WARNING"' <<< "$report" || true; } | wc -l)"
echo "  Plugin Check: ${errors} errors, ${warnings} warnings"
if [ "$errors" -gt 0 ] ; then
    echo "  FAIL  Plugin Check found errors:" >&2
    sed 's/},{/},\n{/g' <<< "$report" | grep '"type":"ERROR"' | head -40 >&2
    failures=$((failures + 1))
fi

echo "==> uninstall"
wp plugin deactivate "$SLUG" > /dev/null
wp plugin uninstall "$SLUG" --skip-delete > /dev/null
check "the plugin's settings are gone" "$(wp option list --search="${SLUG}*" --field=option_name)" "^$"

if [ "$failures" -gt 0 ] ; then
    echo "${failures} failed" >&2
    exit 1
fi
echo "all passed"
