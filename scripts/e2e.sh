#!/bin/bash

# Runs the built plugin in a real WordPress, against a stand-in for the VPNDetection API, and drives
# every area through HTTP the way a visitor would: sign-in, sign-up, password resets, comments, pages
# and their browser check, contact forms and a WooCommerce checkout, the allowlist, each way of
# reading the visitor's address, failing open and closed, and the answer cache. Then the plugin
# directory's own Plugin Check over it, and an uninstall.
#
#   ./scripts/e2e.sh [--php 8.2] [--wp 7.1.3] [--woocommerce <version>] [--cf7 <version>] [--keep]
#
# The plugins it tests beside are wordpress.org's current releases, unless a version is named: the
# oldest WordPress the plugin supports needs ones as old as itself.
#
# Visitors are named in X-Forwarded-For from a container on the same private network, which the
# plugin's automatic setting believes, as it would from a load balancer. Needs docker; the plugins it
# tests against come from wordpress.org.

set -euo pipefail

cd "$(dirname "$0")/.."

php="8.2"
wpv="7.1.3"
woocommerce=()
cf7=()
keep=n
while [ $# -gt 0 ] ; do
    case "$1" in
        --php) php="$2"; shift 2 ;;
        --wp) wpv="$2"; shift 2 ;;
        --woocommerce) woocommerce=(--version="$2"); shift 2 ;;
        --cf7) cf7=(--version="$2"); shift 2 ;;
        --keep) keep=y; shift ;;
        *) echo "usage: e2e.sh [--php 8.2] [--wp 7.1.3] [--woocommerce <version>] [--cf7 <version>] [--keep]" >&2; exit 1 ;;
    esac
done

MARIADB_IMAGE="mariadb:11.8.9"
WP_IMAGE="wordpress:${wpv}-php${php}-apache"
CLI_IMAGE="wordpress:cli-2.12.0-php${php}"

VPN=45.83.91.1
TOR=185.220.101.1
RESPROXY=5.6.7.8
GOOGLEBOT=66.249.66.1
CLEAN=81.2.69.142
MESSAGE="which this site does not allow"

./scripts/build.sh --no-zip

run="vpnd-e2e-$$"
failures=0
function cleanup() {
    if [ "$keep" = y ] ; then
        echo "kept: network ${run}, containers ${run}-{db,stub,wp,cli}, volume ${run}"
        return
    fi
    docker rm -f "${run}-db" "${run}-stub" "${run}-wp" "${run}-cli" > /dev/null 2>&1 || true
    docker volume rm "$run" > /dev/null 2>&1 || true
    docker network rm "$run" > /dev/null 2>&1 || true
}
trap cleanup EXIT

docker network create "$run" > /dev/null
docker volume create "$run" > /dev/null
docker run -d --name "${run}-db" --network "$run" --network-alias db \
    -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp \
    "$MARIADB_IMAGE" > /dev/null
docker run -d --name "${run}-stub" --network "$run" --network-alias stub -v "${PWD}/tests/e2e:/stub:ro" -w /stub \
    --user 0:0 --entrypoint php "$CLI_IMAGE" -S 0.0.0.0:80 stub.php > /dev/null
docker run -d --name "${run}-wp" --network "$run" --network-alias wp -v "${run}:/var/www/html" \
    -v "${PWD}/dist/vpndetection:/var/www/html/wp-content/plugins/vpndetection:ro" \
    -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
    "$WP_IMAGE" > /dev/null
docker run -d --name "${run}-cli" --network "$run" --user 33:33 -e HOME=/tmp -v "${run}:/var/www/html" \
    -v "${PWD}/dist/vpndetection:/var/www/html/wp-content/plugins/vpndetection:ro" \
    -e WORDPRESS_DB_HOST=db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
    --entrypoint sleep "$CLI_IMAGE" infinity > /dev/null

function wp() {
    docker exec "${run}-cli" wp --path=/var/www/html "$@"
}

# A request as a visitor at an address, printing the status line, the headers and the body.
function visit() {
    local ip="$1"
    shift
    docker exec "${run}-cli" curl -sS -i --max-time 30 -H "X-Forwarded-For: ${ip}" "$@"
}

function settings() {
    wp option update vpndetection_settings "$1" --format=json > /dev/null
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

function check_not() {
    local name="$1" got="$2" unwanted="$3"
    if grep -qE -- "$unwanted" <<< "$got" ; then
        echo "  FAIL  ${name}: did not want /${unwanted}/" >&2
        head -c 1500 <<< "$got" | sed 's/^/        /' >&2
        failures=$((failures + 1))
    else
        echo "  ok    ${name}"
    fi
}

function lookups() {
    docker exec "${run}-stub" sh -c "grep -c ' /$1 ' /tmp/stub.log || true"
}

# The settings for one area on, every other off.
function areas() {
    printf '{"%s":{"action":"%s","signals":null}}' "$1" "$2"
}

echo "==> WordPress ${wpv} on PHP ${php}"
for _ in $(seq 1 60) ; do
    if docker exec "${run}-cli" test -f /var/www/html/wp-config.php && wp db check > /dev/null 2>&1 ; then
        break
    fi
    sleep 2
done
# The image's Apache rewrites REMOTE_ADDR from X-Forwarded-For itself (mod_remoteip, trusting private
# ranges), which would leave the plugin's own reading of the header untested.
docker exec "${run}-wp" sh -c 'a2disconf -q remoteip && apache2ctl -k graceful' > /dev/null 2>&1
# Before the install: it loads a page to test pretty permalinks, that page spawns WP-Cron, and cron
# would update an older WordPress to the newest in the middle of the run.
wp config set AUTOMATIC_UPDATER_DISABLED true --raw --type=constant > /dev/null
wp config set DISABLE_WP_CRON true --raw --type=constant > /dev/null
wp core install --url=http://wp --title=E2E --admin_user=admin --admin_password=correct-horse \
    --admin_email=admin@example.com --skip-email > /dev/null
wp config set VPNDETECTION_BASE_URL http://stub --type=constant > /dev/null
wp rewrite structure '/%postname%/' > /dev/null
wp option update users_can_register 1 > /dev/null
wp plugin activate vpndetection > /dev/null
# WordPress refuses a second comment from one address within 15 seconds, and every visitor here
# reaches it from the one container; and the site has no mail to send through.
docker exec "${run}-cli" sh -c "mkdir -p /var/www/html/wp-content/mu-plugins && printf '%s\\n' '<?php' \
    \"add_filter('comment_flood_filter', '__return_false');\" \"add_filter('pre_wp_mail', '__return_true');\" \
    > /var/www/html/wp-content/mu-plugins/e2e.php"

echo "==> activation"
check "the activity table exists" "$(wp db query "SHOW TABLES LIKE 'wp_vpndetection_activity'" --skip-column-names)" "wp_vpndetection_activity"
check "the purge is scheduled" "$(wp cron event list --fields=hook --format=csv)" "vpndetection_purge_activity"
check "a site with nothing set checks nothing" "$(visit "$VPN" http://wp/)" "^HTTP/1.1 200"

echo "==> sign-in, with no key"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"admins_sign_in\":true}"
out="$(visit "$VPN" http://wp/wp-login.php -d 'log=admin&pwd=wrong&wp-submit=Log+In')"
check "a VPN visitor's wrong password is refused with the message" "$out" "$MESSAGE"
out="$(visit "$CLEAN" http://wp/wp-login.php -d 'log=admin&pwd=wrong&wp-submit=Log+In')"
check_not "a clean visitor gets WordPress's own answer" "$out" "$MESSAGE"
check "  ... which says the password was wrong" "$out" "incorrect"
out="$(visit "$VPN" http://wp/wp-login.php -d 'log=admin&pwd=correct-horse&wp-submit=Log+In')"
check "an administrator signs in from a VPN" "$out" "^Location: http://wp/wp-admin/"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"admins_sign_in\":false}"
out="$(visit "$VPN" http://wp/wp-login.php -d 'log=admin&pwd=correct-horse&wp-submit=Log+In')"
check "with the exemption off, so is the administrator" "$out" "$MESSAGE"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login log),\"admins_sign_in\":false}"
out="$(visit "$VPN" http://wp/wp-login.php -d 'log=someone&pwd=wrong&wp-submit=Log+In')"
check_not "Log only refuses nobody" "$out" "$MESSAGE"
check "  ... and records the attempt" "$(wp db query "SELECT area, action, signals, detail FROM wp_vpndetection_activity WHERE action='log'" --skip-column-names)" "login.log.vpn.someone"

echo "==> plans"
wp option update vpndetection_api_key free-key > /dev/null
settings "{\"signals\":[\"tor\"],\"areas\":$(areas login block)}"
out="$(visit "$TOR" http://wp/wp-login.php -d 'log=x&pwd=y&wp-submit=Log+In')"
check "a free key blocks Tor" "$out" "$MESSAGE"
settings "{\"signals\":[\"hosting\"],\"areas\":$(areas login block)}"
out="$(visit "$VPN" http://wp/wp-login.php -d 'log=x&pwd=y&wp-submit=Log+In')"
check "a free key blocks hosting, by its network's type" "$out" "$MESSAGE"
settings "{\"signals\":[\"resproxy\"],\"areas\":$(areas login block)}"
out="$(visit "$RESPROXY" http://wp/wp-login.php -d 'log=x&pwd=y&wp-submit=Log+In')"
check_not "a free key cannot block residential proxies" "$out" "$MESSAGE"
check "  ... and the settings say so" "$(wp option get vpndetection_diagnostics --format=json)" '"gaps":\{"signals":\["resproxy"\]'
settings "{\"signals\":[\"country\"],\"countries\":\"no, SE\",\"areas\":$(areas login block)}"
out="$(visit "$VPN" http://wp/wp-login.php -d 'log=x&pwd=y&wp-submit=Log+In')"
check "a free key blocks a listed country" "$out" "not available in your country"
check_not "  ... and lets the rest through" "$(visit "$CLEAN" http://wp/wp-login.php -d 'log=x&pwd=y')" "your country"
wp option update vpndetection_api_key max-key > /dev/null
settings "{\"signals\":[\"resproxy\"],\"areas\":$(areas login block)}"
out="$(visit "$RESPROXY" http://wp/wp-login.php -d 'log=x&pwd=y&wp-submit=Log+In')"
check "a paid key blocks residential proxies" "$out" "$MESSAGE"

echo "==> new accounts and password resets"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas register block)}"
out="$(visit "$VPN" 'http://wp/wp-login.php?action=register' -d 'user_login=newbie&user_email=newbie@example.com&wp-submit=Register')"
check "registration refuses a VPN visitor" "$out" "$MESSAGE"
check "  ... and makes no account" "$(wp user list --field=user_login)" "^admin$"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas lostpassword block)}"
out="$(visit "$VPN" 'http://wp/wp-login.php?action=lostpassword' -d 'user_login=admin&wp-submit=Get+New+Password')"
check "a password reset is refused" "$out" "$MESSAGE"

echo "==> comments"
post="$(wp post list --post_type=post --field=ID | head -1)"
function comment() {
    visit "$1" http://wp/wp-comments-post.php -d "comment_post_ID=${post}&author=Someone&email=someone@example.com&comment=Hello+from+$2"
}
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas comment hold)}"
comment "$VPN" hold > /dev/null
check "Hold for moderation holds it" "$(wp comment list --status=hold --field=comment_content)" "Hello from hold"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas comment spam)}"
comment "$VPN" spam > /dev/null
check "Mark as spam marks it" "$(wp comment list --status=spam --field=comment_content)" "Hello from spam"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas comment block)}"
out="$(comment "$VPN" block)"
check "Block refuses it with a 403" "$out" "^HTTP/1.1 403"
check "  ... and the message" "$out" "$MESSAGE"
comment "$CLEAN" clean > /dev/null
check "a clean visitor's comment is untouched" "$(wp comment list --field=comment_content)" "Hello from clean"

echo "==> pages"
settings "{\"signals\":[\"vpn\",\"hosting\"],\"areas\":$(areas pages block),\"pages_scope\":\"site\"}"
out="$(visit "$VPN" http://wp/)"
check "the whole site refuses a VPN visitor" "$out" "^HTTP/1.1 403"
check "  ... uncacheable" "$out" "^Cache-Control: no-cache, must-revalidate, max-age=0"
out="$(visit "$CLEAN" http://wp/)"
check "and serves a clean one" "$out" "^HTTP/1.1 200"
check "  ... with the browser check for a cached copy" "$out" "vpndetection-check-js"
out="$(visit "$GOOGLEBOT" http://wp/)"
check "Googlebot is never refused, though its address is hosting" "$out" "^HTTP/1.1 200"
out="$(visit "$VPN" http://wp/wp-json/vpndetection/v1/check -H 'Content-Type: application/json' -d '{"path":"/"}')"
check "the browser check refuses a VPN visitor" "$out" '"blocked":true'
check "  ... uncached" "$out" "^Cache-Control: no-store, private"
out="$(visit "$CLEAN" http://wp/wp-json/vpndetection/v1/check -H 'Content-Type: application/json' -d '{"path":"/"}')"
check "  ... and lets a clean one through" "$out" '"blocked":false'
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas pages block),\"pages_scope\":\"selected\",\"pages_paths\":\"/sample-page\"}"
check "only the chosen path is refused" "$(visit "$VPN" http://wp/sample-page/)" "^HTTP/1.1 403"
check "  ... and the rest is served" "$(visit "$VPN" http://wp/hello-world/)" "^HTTP/1.1 200"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas pages block),\"pages_scope\":\"selected\",\"pages_post_types\":[\"post\"]}"
check "a chosen post type is refused" "$(visit "$VPN" http://wp/hello-world/)" "^HTTP/1.1 403"
check "  ... and through the browser check" "$(visit "$VPN" http://wp/wp-json/vpndetection/v1/check -H 'Content-Type: application/json' -d '{"path":"/hello-world/"}')" '"blocked":true'
check "  ... where a page is not" "$(visit "$VPN" http://wp/sample-page/)" "^HTTP/1.1 200"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas pages block),\"pages_scope\":\"site\",\"pages_redirect\":\"http://wp/sample-page/\"}"
out="$(visit "$VPN" http://wp/hello-world/)"
check "a refusal can redirect" "$out" "^Location: http://wp/sample-page/"
check "  ... to a page that is itself never refused" "$(visit "$VPN" http://wp/sample-page/)" "^HTTP/1.1 200"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas pages block),\"pages_scope\":\"site\",\"pages_uncached\":true}"
out="$(visit "$CLEAN" http://wp/)"
check "kept out of the cache, a page is served uncached" "$out" "^Cache-Control: no-cache"
check_not "  ... with no browser check" "$out" "vpndetection-check-js"

echo "==> the allowlist"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"allowlist\":\"45.83.91.0/24 # office\"}"
check_not "a block on the allowlist is let through" "$(visit "$VPN" http://wp/wp-login.php -d 'log=x&pwd=y')" "$MESSAGE"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"allowlist\":\"AS9009\"}"
check_not "so is a network by its AS number" "$(visit "$VPN" http://wp/wp-login.php -d 'log=x&pwd=y')" "$MESSAGE"

echo "==> the visitor's address"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"proxy\":\"direct\"}"
check_not "Direct ignores X-Forwarded-For" "$(visit "$VPN" http://wp/wp-login.php -d 'log=x&pwd=y')" "$MESSAGE"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"proxy\":\"cloudflare\"}"
out="$(docker exec "${run}-cli" curl -sS -i -H "CF-Connecting-IP: ${VPN}" http://wp/wp-login.php -d 'log=x&pwd=y')"
check "Cloudflare reads CF-Connecting-IP" "$out" "$MESSAGE"
check_not "  ... and not X-Forwarded-For" "$(visit "$VPN" http://wp/wp-login.php -d 'log=x&pwd=y')" "$MESSAGE"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"proxy\":\"custom\",\"proxy_header\":\"X-Real-IP\",\"proxy_trusted\":\"\"}"
out="$(docker exec "${run}-cli" curl -sS -i -H "X-Real-IP: ${VPN}" http://wp/wp-login.php -d 'log=x&pwd=y')"
check "another proxy's header is believed from the private network" "$out" "$MESSAGE"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block)}"
out="$(docker exec "${run}-cli" curl -sS -i -H "X-Forwarded-For: ${VPN}, ${CLEAN}" http://wp/wp-login.php -d 'log=x&pwd=y')"
check_not "X-Forwarded-For is read from the right" "$out" "$MESSAGE"

echo "==> failing open and closed"
wp option update vpndetection_api_key spent-key > /dev/null
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"fail_closed\":false}"
check_not "a spent allowance lets visitors through" "$(visit "$CLEAN" http://wp/wp-login.php -d 'log=x&pwd=y')" "$MESSAGE"
check "  ... and tells the site owner" "$(wp option get vpndetection_diagnostics --format=json)" '"kind":"quota_exceeded"'
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas login block),\"fail_closed\":true}"
check "failing closed refuses them" "$(visit "$RESPROXY" http://wp/wp-login.php -d 'log=x&pwd=y')" "$MESSAGE"
wp option update vpndetection_api_key max-key > /dev/null

echo "==> the answer cache"
settings "{\"signals\":[\"resproxy\"],\"areas\":$(areas login block),\"min_hits\":3}"
check_not "a residential proxy seen fewer times than asked passes" "$(visit "$RESPROXY" http://wp/wp-login.php -d 'log=x&pwd=y')" "$MESSAGE"
before="$(lookups "$RESPROXY")"
visit "$RESPROXY" http://wp/wp-login.php -d 'log=x&pwd=y' > /dev/null
check "a second visit from the same address asks the API nothing" "$(lookups "$RESPROXY")" "^${before}$"

echo "==> contact forms"
# Contact Form 7 needs PHP 8.3 from 6.2, so a leg below it tests 6.1's last release.
if [ ${#cf7[@]} -eq 0 ] && [ "$(printf '%s\n' 8.3 "$php" | sort -V | head -1)" != 8.3 ] ; then
    cf7=(--version=6.1.7)
fi
wp plugin install contact-form-7 "${cf7[@]}" --activate > /dev/null
form="$(wp post list --post_type=wpcf7_contact_form --field=ID | head -1)"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas forms block)}"
out="$(visit "$VPN" "http://wp/wp-json/contact-form-7/v1/contact-forms/${form}/feedback" \
    -F "_wpcf7=${form}" -F "_wpcf7_unit_tag=wpcf7-f${form}-o1" -F 'your-name=Someone' \
    -F 'your-email=someone@example.com' -F 'your-subject=Hi' -F 'your-message=Hello')"
check "Contact Form 7 refuses a VPN visitor as spam" "$out" '"status":"spam"'
out="$(visit "$CLEAN" "http://wp/wp-json/contact-form-7/v1/contact-forms/${form}/feedback" \
    -F "_wpcf7=${form}" -F "_wpcf7_unit_tag=wpcf7-f${form}-o1" -F 'your-name=Someone' \
    -F 'your-email=someone@example.com' -F 'your-subject=Hi' -F 'your-message=Hello')"
check_not "  ... and not a clean one" "$out" '"status":"spam"'
wp plugin install wpforms-lite --activate > /dev/null
check "WPForms still applies the filter the plugin hooks" \
    "$(docker exec "${run}-cli" grep -rh "wpforms_process_initial_errors" /var/www/html/wp-content/plugins/wpforms-lite/includes/class-process.php)" \
    "apply_filters\( 'wpforms_process_initial_errors', \\\$this->errors, \\\$this->form_data \)"
out="$(docker exec "${run}-cli" sh -c "HTTP_X_FORWARDED_FOR=${VPN} REMOTE_ADDR=10.0.0.2 wp --path=/var/www/html eval 'echo wp_json_encode(apply_filters(\"wpforms_process_initial_errors\", [], [\"id\" => 7, \"settings\" => [\"form_title\" => \"Contact\"]]));'")"
check "  ... and WPForms then shows the form's error" "$out" '"7":\{"header":".*'"$MESSAGE"

echo "==> WooCommerce checkout"
wp plugin install woocommerce "${woocommerce[@]}" --activate > /dev/null
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json > /dev/null
wp option update woocommerce_coming_soon no > /dev/null
product="$(wp wc product create --name=Widget --regular_price=10 --user=admin --porcelain)"
# A block checkout through the Store API, which keeps the cart by its Cart-Token header.
function checkout() {
    docker exec "${run}-cli" sh -c "
        headers=\$(curl -sS -D - -o /dev/null -H 'X-Forwarded-For: $1' http://wp/wp-json/wc/store/v1/cart)
        nonce=\$(echo \"\$headers\" | sed -n 's/^[Nn]once: //p' | tr -d '\\r')
        token=\$(echo \"\$headers\" | sed -n 's/^[Cc]art-[Tt]oken: //p' | tr -d '\\r')
        curl -sS -o /dev/null -H \"Nonce: \$nonce\" -H \"Cart-Token: \$token\" -H 'X-Forwarded-For: $1' \\
            -H 'Content-Type: application/json' -d '{\"id\":${product},\"quantity\":1}' http://wp/wp-json/wc/store/v1/cart/add-item
        curl -sS -i -H \"Nonce: \$nonce\" -H \"Cart-Token: \$token\" -H 'X-Forwarded-For: $1' -H 'Content-Type: application/json' \\
            -d '{\"billing_address\":{\"first_name\":\"A\",\"last_name\":\"B\",\"address_1\":\"1 Main St\",\"city\":\"Berlin\",\"postcode\":\"10115\",\"country\":\"DE\",\"email\":\"buyer@example.com\"},\"payment_method\":\"cod\"}' \\
            http://wp/wp-json/wc/store/v1/checkout"
}
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas checkout block)}"
out="$(checkout "$VPN")"
check "Refuse the order refuses a VPN buyer's checkout" "$out" "vpndetection_blocked"
check "  ... with the message" "$out" "$MESSAGE"
settings "{\"signals\":[\"vpn\"],\"areas\":$(areas checkout hold)}"
out="$(checkout "$VPN")"
order="$(sed -n 's/.*"order_id":\([0-9]*\).*/\1/p' <<< "$out" | head -1)"
held="$order"
check "Hold takes the order" "$out" '"order_id":[0-9]+'
check "  ... and it waits On hold" "$(wp wc shop_order get "${order:-0}" --field=status --user=admin 2>&1)" "^on-hold$"
check "  ... with a note saying why" "$(wp wc order_note list "${order:-0}" --field=note --user=admin 2>&1)" "VPNDetection: placed through VPNs \(nordvpn\)"
out="$(checkout "$CLEAN")"
order="$(sed -n 's/.*"order_id":\([0-9]*\).*/\1/p' <<< "$out" | head -1)"
check "a clean buyer's order goes to Processing" "$(wp wc shop_order get "${order:-0}" --field=status --user=admin 2>&1)" "^processing$"

echo "==> the activity log"
check "rows count repeats rather than growing" "$(wp db query "SELECT MAX(hits) FROM wp_vpndetection_activity" --skip-column-names)" "^[2-9]|^[1-9][0-9]+"

echo "==> the admin screens, in a browser"
if command -v node > /dev/null && node -e "require(process.env.PLAYWRIGHT_MODULE || 'playwright')" > /dev/null 2>&1 ; then
    wp option delete vpndetection_api_key > /dev/null
    # The screenshots are the listing's: nothing the scenarios above left behind belongs on them.
    wp option delete vpndetection_diagnostics > /dev/null
    settings '{"signals":["vpn","tor","hosting"]}'
    address="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "${run}-wp")"
    mkdir -p dist/screenshots
    if ! node tests/e2e/admin.mjs "$address" "${held:-}" dist/screenshots ; then
        failures=$((failures + 1))
    fi
else
    echo "  skipped: no node with playwright here"
fi

echo "==> Plugin Check"
wp plugin install plugin-check --activate > /dev/null
report="$(wp plugin check vpndetection --format=json 2>&1 || true)"
errors="$({ grep -o '"type":"ERROR"' <<< "$report" || true; } | wc -l)"
warnings="$({ grep -o '"type":"WARNING"' <<< "$report" || true; } | wc -l)"
echo "  Plugin Check: ${errors} errors, ${warnings} warnings"
if [ "$errors" -gt 0 ] ; then
    echo "  FAIL  Plugin Check found errors:" >&2
    sed 's/},{/},\n{/g' <<< "$report" | grep '"type":"ERROR"' | head -40 >&2
    failures=$((failures + 1))
fi
if [ -n "${E2E_REPORT:-}" ] ; then
    printf '%s\n' "$report" > "$E2E_REPORT"
fi

echo "==> uninstall"
wp plugin deactivate vpndetection > /dev/null
wp plugin uninstall vpndetection --skip-delete > /dev/null
check "the activity table is gone" "$(wp db query "SHOW TABLES LIKE 'wp_vpndetection_activity'" --skip-column-names)" "^$"
check "and so are the settings" "$(wp option list --search='vpndetection*' --field=option_name)" "^$"

if [ "$failures" -gt 0 ] ; then
    echo "${failures} failed" >&2
    exit 1
fi
echo "all passed"
