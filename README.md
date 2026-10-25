# [<img src="https://s3.vpndetection.io/vpndetection-public/brand/mark.svg" alt="VPNDetection" height="28"/>](https://vpndetection.io/) VPNDetection WordPress Plugin

[![release](https://img.shields.io/github/v/release/vpndetection-io/wordpress-plugin)](CHANGELOG.md)
[![license](https://img.shields.io/github/license/vpndetection-io/wordpress-plugin)](LICENSE)

The official [VPNDetection](https://vpndetection.io) plugin for [WordPress](https://wordpress.org).

It blocks, holds or flags visitors who come through VPNs, proxies, Tor and hosting networks, where you choose: sign-in, sign-ups, password resets, comments, contact forms, WooCommerce checkout and pages.

## Getting Started

Download `vpndetection-<version>.zip` from the [latest release](https://github.com/vpndetection-io/wordpress-plugin/releases/latest) and upload it under Plugins > Add New > Upload Plugin, then activate it. It needs WordPress 6.4 or newer and PHP 8.2 or newer.

Open Settings > VPNDetection and click Connect with VPNDetection. A tab opens on vpndetection.io where you sign in, or sign up for free, and pick one of your API keys; the plugin is handed that key, with nothing to copy. If you'd rather paste a key, the same box takes one from the [console](https://app.vpndetection.io), and a site that keeps secrets out of its database can set it in `wp-config.php` instead:

```php
define('VPNDETECTION_API_KEY', 'your-api-key');
```

Without a key the plugin checks for VPNs only, and shares 1,000 checks a day with everything else on your server's address. A free key adds Tor, privacy relays, countries and hosting, judged on the free plan by the network an address belongs to, with 50,000 checks a month of your own. Every paid plan answers every signal.

Nothing is checked until you turn an area on.

## What it checks

| Area | Covers | Can |
|---|---|---|
| Sign-in | wp-login.php, front-end login forms that sign in through WordPress (WooCommerce's My Account among them), XML-RPC | log, block |
| New accounts | WordPress's registration page, a network's sign-up, WooCommerce's My Account | log, block |
| Password resets | WordPress's and WooCommerce's | log, block |
| Comments | comments from visitors who aren't signed in | log, hold for moderation, mark as spam, block |
| Contact forms | Contact Form 7 and WPForms | log, block |
| Checkout | WooCommerce, classic and block checkout | log, hold the order for review, refuse the order |
| Pages | the whole site, or the post types and path prefixes you choose, for visitors who aren't signed in | log, block with a message or a redirect |

Start an area on Log only: the Activity tab then lists who it would have caught, and one click there puts an address on the allowlist. A held order is paid as usual and then stays On hold instead of going to Processing, with a note saying what the connection was. Administrators can always sign in, whatever their connection, unless you untick that.

## What to block

VPNs, Tor, public proxies, residential proxies, mobile proxies, datacenter proxies, hosting and cloud servers, privacy relays such as iCloud Private Relay, and the countries you list by their two-letter codes. Every area blocks on the same list unless you give it one of its own, so comments can catch hosting servers while sign-in leaves them alone. The settings mark each signal your plan doesn't answer, since an absent answer can never match. A visitor refused for their country alone is told the site isn't available there, rather than to turn off a VPN.

Residential, mobile and datacenter proxies can also be held to a number of sightings: at 3, an address counts only once it has been seen in a proxy pool three times, which spares a home connection that was rented out once.

The allowlist takes addresses, CIDR blocks and whole networks by their AS number (`AS64500`), one per line. An address or block on it is never looked up at all.

## The visitor's address

Behind Cloudflare, the plugin believes `CF-Connecting-IP` from Cloudflare's own addresses. Behind a proxy on your server's private network, such as a load balancer or a local nginx, it reads `X-Forwarded-For` from the right, taking the first address that isn't one of your own hops. Anything else in front of the site, with public addresses of its own, has to be named under Advanced > Visitor address, with the header it writes. Until it is, every visitor looks like that proxy.

The settings page shows the address the plugin sees for you, and says so when your own request carried a forwarding header it didn't believe.

## Page caches

A page cache answers without running WordPress, so a page checked only on the server would reach whoever the cache serves. A protected page therefore carries a small script that asks the site, from the visitor's browser, whether this visitor may see it; it works with any cache, but not for a visitor who turns scripts off. For pages that must never reach a refused visitor, tick "Keep these pages out of the page cache" and the server checks every visit. A refusal is never cached either way.

Search engines crawl from cloud addresses, so Google's, Bing's, Apple's and DuckDuckGo's published crawler addresses are never refused from your pages.

## Costs and failures

Each address is looked up at most once every six hours, in the object cache if the site has one and in transients if not, and only for areas that are on. A lookup waits at most 2.5 seconds. If VPNDetection can't be reached in that time, or the site's allowance is spent, the visitor is let through and the settings say why; Advanced can switch that to refusing them.

## For developers

`vpndetection_visitor()` returns the API's answer for the current visitor as an array, keyed as the [API reference](https://docs.vpndetection.io/api) names it, or `null` when there is none:

```php
$visitor = vpndetection_visitor();
if (($visitor['is_vpn'] ?? false) && ($visitor['location']['country'] ?? '') !== 'DE') {
    // ...
}
```

It is looked up once a request and counts against the site's allowance like any check.

| Hook | Does |
|---|---|
| `vpndetection_condition` (filter) | The rule an area blocks on, in the [PHP SDK](https://docs.vpndetection.io/integrations/php)'s condition format, with the area and its signals. Return your own to reach the evidence, such as `[['isVpn' => true, 'vpn' => ['provider' => ['nordvpn', 'expressvpn']]]]` |
| `vpndetection_verdict` (filter) | A caught visitor, before the area acts. Return `null` to let them through |
| `vpndetection_flagged` (action) | Every visitor an area caught, as an array: the area, what it did, the signals, the address and the answer |
| `vpndetection_client_ip` (filter) | The visitor's address, for an edge that names it some other way |
| `vpndetection_cache_ttl` (filter) | Seconds an answer is kept, 21600 by default |

## What it sends

The plugin calls the VPNDetection API at `https://api.vpndetection.io`. For each area you turn on, it sends the visitor's IP address, and your API key if you added one, when a visitor uses that area, at most once per address every six hours. The settings page also sends your own address the same way and reads your plan and usage. Connect with VPNDetection asks the API for a sign-in code and opens `https://app.vpndetection.io` in your browser. Nothing else leaves your site. Our terms are at https://vpndetection.io/terms and our privacy policy at https://vpndetection.io/privacy, and the plugin adds a suggested paragraph to your own privacy policy under Settings > Privacy.

## Building from source

The plugin vendors the [PHP SDK](https://github.com/vpndetection-io/sdk-php) and its dependencies under its own namespace, so another plugin's copy of Guzzle can never be the one it loads. Building needs Docker and nothing else:

```bash
./scripts/build.sh      # dist/vpndetection-<version>.zip
./scripts/test.sh       # the unit suite
./scripts/e2e.sh        # a real WordPress, every area driven over HTTP, and wordpress.org's Plugin Check
```

## Other Libraries

There are official VPNDetection client libraries available for many languages including PHP, Python, Go, Java, Ruby, and many popular frameworks such as Django, Rails, and Laravel. See our GitHub at https://github.com/vpndetection-io for more.

## About VPNDetection

VPN Detection API: Accurate anonymity detection identifying VPNs, residential proxies, hosting servers, Tor nodes, CDNs, relays and more.

[<img src="https://s3.vpndetection.io/vpndetection-public/brand/mark.svg" alt="VPNDetection" height="64"/>](https://vpndetection.io/)

## License

This project is licensed under the [MIT License](LICENSE).
