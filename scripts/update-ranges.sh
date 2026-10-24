#!/bin/bash

# Rewrites src/Ranges.php from the lists their operators publish: Cloudflare's edge addresses, which
# the plugin trusts to name the visitor in CF-Connecting-IP, and the search engines' crawler
# addresses, which the page gate never blocks. Run before a release; a diff is a change to ship.
#
#   ./scripts/update-ranges.sh

set -euo pipefail

cd "$(dirname "$0")/.."

for tool in curl jq ; do
    command -v "$tool" > /dev/null || { echo "update-ranges.sh needs ${tool}" >&2; exit 1; }
done

scratch="$(mktemp -d)"
trap 'rm -rf "$scratch"' EXIT

function fetch() {
    curl -fsSL --max-time 30 -A "Mozilla/5.0 (compatible; vpndetection-wordpress ranges)" -o "${scratch}/$1" "$2"
}

fetch cf4.txt https://www.cloudflare.com/ips-v4
fetch cf6.txt https://www.cloudflare.com/ips-v6
fetch google-common.json https://developers.google.com/static/crawling/ipranges/common-crawlers.json
fetch google-special.json https://developers.google.com/static/crawling/ipranges/special-crawlers.json
fetch bing.json https://www.bing.com/toolbox/bingbot.json
fetch apple.json https://search.developer.apple.com/applebot.json
fetch duckduckgo.json https://duckduckgo.com/duckduckbot.json

# One PHP string literal per line, eight spaces in.
function cidrs() {
    grep -vE '^\s*$' | sed -E "s/^\s*(.*)\s*$/        '\1',/"
}

function prefixes() {
    for f in "$@" ; do
        jq -r '.prefixes[] | .ipv4Prefix // .ipv6Prefix' "${scratch}/${f}"
    done
}

# Cloudflare ends each file without a newline.
cloudflare="$({ cat "${scratch}/cf4.txt"; echo; cat "${scratch}/cf6.txt"; echo; } | cidrs)"
google="$(prefixes google-common.json google-special.json | cidrs)"
bing="$(prefixes bing.json | cidrs)"
apple="$(prefixes apple.json | cidrs)"
duckduckgo="$(prefixes duckduckgo.json | cidrs)"
for list in cloudflare google bing apple duckduckgo ; do
    [ "$(grep -c "'" <<< "${!list}")" -gt 0 ] || { echo "no ${list} ranges came back" >&2; exit 1; }
done

cat > src/Ranges.php <<EOF
<?php

declare(strict_types=1);

namespace VPNDetection\\WordPress;

defined('ABSPATH') || exit;

/**
 * Address blocks their operators publish. Written by scripts/update-ranges.sh on $(date -u +%Y-%m-%d); edit that,
 * never this.
 */
final class Ranges
{
    /** Cloudflare's edge, from cloudflare.com/ips. */
    public const CLOUDFLARE = [
${cloudflare}
    ];

    /** Googlebot and Google's special crawlers. */
    public const GOOGLE = [
${google}
    ];

    /** Bingbot. */
    public const BING = [
${bing}
    ];

    /** Applebot. */
    public const APPLE = [
${apple}
    ];

    /** DuckDuckBot. */
    public const DUCKDUCKGO = [
${duckduckgo}
    ];
}
EOF
echo "src/Ranges.php: $(grep -c "^        '" src/Ranges.php) blocks"
