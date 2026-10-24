=== VPNDetection ===
Contributors: vpndetection
Tags: vpn, proxy, tor, woocommerce, spam
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Block or hold sign-ins, sign-ups, comments, forms, WooCommerce orders and pages from VPNs, proxies, Tor, hosting networks and countries.

== Description ==

VPNDetection tells you when a visitor connects through a VPN, a proxy, Tor or a hosting network, and lets you decide what happens next, area by area:

* Sign-in: the login page, front-end login forms such as WooCommerce's, and XML-RPC. Administrators can always sign in, so you can't lock yourself out.
* New accounts: WordPress's registration page, a network's sign-up and WooCommerce's My Account.
* Password resets, on WordPress and WooCommerce.
* Comments: block them, hold them for moderation or mark them as spam.
* Contact forms: Contact Form 7 and WPForms.
* WooCommerce checkout, classic and block: refuse the order, or take it and hold it for review, with a note on the order saying what the connection was.
* Pages: the whole site, or the post types and paths you choose, with a message or a redirect.

Start each area on Log only and the Activity tab lists who it would have caught before it blocks anyone. One click there puts an address on the allowlist, which also takes blocks and whole networks by their AS number.

= What it can tell apart =

VPNs, Tor, public proxies, residential proxies, mobile proxies, datacenter proxies, hosting and cloud servers, privacy relays such as iCloud Private Relay, and the countries you list. Every area uses the same list unless you give it one of its own, so comments can catch hosting servers while sign-in leaves them alone.

Without an account the plugin checks for VPNs only. A free account adds Tor, relays, countries and hosting, judged on the free plan by the network an address belongs to, with 50,000 checks a month of your own, and paid plans answer every signal. Connecting takes one click: sign in on vpndetection.io in the tab that opens and pick a key.

= Built to stay out of the way =

* It checks only the areas you turn on, and asks about each address at most once every six hours.
* If VPNDetection can't be reached within 2.5 seconds, the visitor is let through. You can choose to refuse them instead.
* It works with page caches. A cached page is checked from the visitor's browser, or you can keep protected pages out of the cache so the server checks every visit. A refusal is never cached.
* Google's, Bing's, Apple's and DuckDuckGo's crawlers are never refused from your pages, though they crawl from cloud addresses.
* It finds the visitor's real address behind Cloudflare, a load balancer or another proxy, and believes a forwarding header only from the proxy that writes it.
* It sends its requests through WordPress's own HTTP API, so your proxy settings and debugging tools see them.

= For developers =

`vpndetection_visitor()` returns the API's answer for the current visitor as an array. The `vpndetection_condition` filter changes the rule an area blocks on, in the PHP SDK's condition format, `vpndetection_verdict` can let a caught visitor through, and the `vpndetection_flagged` action fires for every visitor an area catches. Set `VPNDETECTION_API_KEY` in wp-config.php to keep the key out of the database. The source is at https://github.com/vpndetection-io/wordpress-plugin.

== Installation ==

1. Install VPNDetection from Plugins > Add New, and activate it.
2. Open Settings > VPNDetection and click Connect with VPNDetection, or paste an API key from https://app.vpndetection.io.
3. Tick what to block, set each area you want checked to Log only, and watch the Activity tab for a few days.
4. Switch the areas you're happy with to Block, Hold or Spam.

== Frequently Asked Questions ==

= Will I lock myself out? =

Not through the sign-in area while "Administrators can always sign in" is ticked, as it is by default. The settings page also shows what your own connection looks like, so you can add your address to the allowlist before you turn anything on. If you ever need to, deleting the plugin's folder from wp-content/plugins turns it off.

= My whole site sits behind Cloudflare or a load balancer. Does that matter? =

Cloudflare and a proxy on your server's private network are recognized automatically. For anything else, set Visitor address under Advanced to the proxy in front of you, or every visitor looks like the proxy. The "Your connection" box on the settings page shows which address the plugin sees.

= Can it block countries too? =

Yes. Tick Countries under What to block and list them by their two-letter codes, such as CN, RU. It works on the free plan, reads where the visitor's address is rather than where their browser says they are, and tells a refused visitor the site isn't available in their country. VPNs and proxies are how visitors get round a country block, so it works best beside them.

= How many checks will my site use? =

One per address per six hours, only in the areas you turn on. Sign-in, sign-up and comments use few; protecting every page uses one for each new visitor.

= Does it slow pages down? =

An address already looked up costs no request at all, and a new one waits at most 2.5 seconds for VPNDetection before the visitor is let through. Areas you leave off cost nothing.

= Is it GDPR friendly? =

It sends visitors' IP addresses to VPNDetection for the areas you turn on, keeps the answers for six hours, and records the visitors it acts on, with their address, for 30 days by default. It adds a suggested paragraph to your privacy policy under Settings > Privacy.

== Screenshots ==

1. What to block, and where.
2. The Activity tab.
3. A held WooCommerce order and its note.

== Changelog ==

= 1.0.0 =
* First release.

== External services ==

This plugin uses the VPNDetection API, run by VPNDetection (https://vpndetection.io), to tell what kind of network an IP address belongs to.

* When a visitor uses an area you turned on (signs in, creates an account, asks for a password reset, comments, sends a contact form, places an order or opens a protected page), the plugin sends the visitor's IP address, with your API key if you added one, to https://api.vpndetection.io. The answer is kept on your site for six hours, so the same address is not sent again within that time.
* When you open the plugin's settings page, it sends your own IP address the same way, and reads your plan and usage with your API key.
* When you click Connect with VPNDetection, the plugin asks https://api.vpndetection.io for a sign-in code and opens https://app.vpndetection.io in your browser, where you sign in and pick an API key, which is then sent back to your site.

VPNDetection's terms of service: https://vpndetection.io/terms. Its privacy policy: https://vpndetection.io/privacy.
