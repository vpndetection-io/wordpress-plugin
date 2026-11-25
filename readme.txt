=== VPNDetection ===
Contributors: vpndetection
Tags: vpn, proxy, tor, woocommerce, spam
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.3.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Block or hold sign-ins, sign-ups, comments, forms, WooCommerce orders and pages from VPNs, proxies, Tor, hosting networks and countries.

== Description ==

🔎 Is that sign-up, comment or order coming through a VPN? VPNDetection tells you when a visitor connects through a VPN, a proxy, Tor or a hosting network, and lets you decide what happens next, area by area.

= 🚪 Where it checks =

* Sign-in: the login page, front-end login forms such as WooCommerce's, and XML-RPC. Administrators can always sign in, so you can't lock yourself out.
* New accounts: WordPress's registration page, a network's sign-up and WooCommerce's My Account.
* Password resets, on WordPress and WooCommerce.
* Comments: block them, hold them for moderation or mark them as spam.
* Contact forms: Contact Form 7 and WPForms.
* WooCommerce checkout, classic and block: refuse the order, or take it and hold it for review, with a note on the order saying what the connection was.
* Pages: the whole site, or the post types and paths you choose, with a message or a redirect.

= 👀 Watch before it blocks =

Start each area on Log only and the Activity tab lists who it would have caught before it blocks anyone. One click there puts an address on the allowlist, which also takes blocks and whole networks by their AS number.

= 🎯 What it can tell apart =

VPNs, Tor, public proxies, residential proxies, mobile proxies, datacenter proxies, hosting and cloud servers, privacy relays such as iCloud Private Relay, addresses that run a mail server, forged crawlers, and the countries you list. Every area uses the same list unless you give it one of its own, so comments can catch hosting servers while sign-in leaves them alone. A privacy relay counts as a relay alone, even where it runs on a hosting network, so iCloud Private Relay visitors aren't refused as hosting.

= 🎁 Free to start =

* No account: VPNDetection's free plan, Unlimited, checks for VPNs, Tor, privacy relays, countries and hosting on every visit, with no cap.
* Free account: try every signal, public, residential, mobile and datacenter proxies included.
* Paid plan: every signal on every visit, with how often each proxy was seen.

Connecting takes one click: sign in on vpndetection.io in the tab that opens and pick a key.

= 🚀 Built to stay out of the way =

* It checks only the areas you turn on, and asks about each address at most once every six hours.
* If VPNDetection can't be reached within 2.5 seconds, the visitor is let through. You can choose to refuse them instead.
* It works with page caches. A cached page is checked from the visitor's browser, or you can keep protected pages out of the cache so the server checks every visit. A refusal is never cached.
* Google's, Bing's, Apple's and DuckDuckGo's crawlers are never refused from your pages, though they crawl from cloud addresses. On a paid plan or the trial, neither is any other search engine's crawler whose operator vouches for its address.
* It finds the visitor's real address behind Cloudflare, a load balancer or another proxy, and believes a forwarding header only from the proxy that writes it.
* It sends its requests through WordPress's own HTTP API, so your proxy settings and debugging tools see them.

= 🔧 For developers =

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

Yes. Tick Countries under What to block and list them by their two-letter codes, such as CN, RU. It works without an account, reads where the visitor's address is rather than where their browser says they are, and tells a refused visitor the site isn't available in their country. VPNs and proxies are how visitors get round a country block, so it works best beside them.

= How many checks will my site use? =

One per address per six hours, only in the areas you turn on. Sign-in, sign-up and comments use few; protecting every page uses one for each new visitor.

= Does it slow pages down? =

An address already looked up costs no request at all, and a new one waits at most 2.5 seconds for VPNDetection before the visitor is let through. Areas you leave off cost nothing.

= Is it GDPR friendly? =

It sends visitors' IP addresses to VPNDetection for the areas you turn on, and their user agents where an area blocks forged crawlers, keeps the answers for six hours, and records the visitors it acts on, with their address, for 30 days by default. It adds a suggested paragraph to your privacy policy under Settings > Privacy.

== Screenshots ==

1. What to block, and where.
2. The Activity tab.
3. A held WooCommerce order and its note.

== Changelog ==

= 1.3.1 =
* The bundled VPNDetection PHP library is now 4.20.1. Nothing about how the plugin works changes.

= 1.3.0 =
* New signal, Forged crawlers, on a paid plan or the trial: refuse a client whose user agent claims a crawler, such as Googlebot, from an address that crawler doesn't use. While an area blocks it, each check sends the visitor's user agent with their address. It's off until you tick it.

= 1.2.3 =
* The settings page says a paid plan checks every signal on every visit: VPNDetection sells one, at the quota you pick.

= 1.2.2 =
* The account card counts the free plan's trial as VPNDetection now runs it: every signal for 14 days from your first check, up to 250,000 checks, and the day it ends. Once it has ended, it says so.

= 1.2.1 =
* A rule returned from the `vpndetection_condition` filter that names a list in the answer, such as a public proxy's protocols, now matches when any item in it does. The bundled VPNDetection PHP library is now 4.20.0.

= 1.2.0 =
* On a paid plan or the trial, any search engine's crawler whose operator vouches for its address is never refused from your pages, beside Google's, Bing's, Apple's and DuckDuckGo's published addresses, which pass on every plan.
* The bundled VPNDetection PHP library is now 4.19.0. Nothing about how the plugin works changes.

= 1.1.1 =
* The account card counts the free plan's trial of every signal by the month, as VPNDetection now does, and says the day it resets.
* The bundled VPNDetection PHP library is now 4.18.0. Nothing about how the plugin works changes.

= 1.1.0 =
* New signal, Mail servers, on a paid plan or the trial: refuse visitors whose address runs a mail server. Home and mobile connections can't, so such a visit comes from a server, a script or a hijacked machine. It's off until you tick it.

= 1.0.15 =
* The settings screen no longer states how many checks a connected free account tries each day. The pricing page says what the trial includes.
* Once your account's trial resets or its overage cap is raised, checks see every signal again within a minute, where they could wait up to six hours. The bundled VPNDetection PHP library is now 4.17.0.

= 1.0.14 =
* The bundled VPNDetection PHP library is now 4.16.1, which documents one more refusal from the database downloads, a service the plugin doesn't use. Nothing about how the plugin works changes.

= 1.0.13 =
* The account card names your plan as VPNDetection names it.
* Past your organization's overage cap, checks get the free plan's signals, uncharged, until the period resets or the cap is raised in Billing. The plugin now says so on its settings, the Dashboard and the Plugins screen, instead of reporting those signals as missing from your plan.

= 1.0.12 =
* The bundled VPNDetection PHP library is now 4.15.0, which says on every answer whether the address is private or reserved, and names each provider beside its ID. Nothing about how the plugin works changes.

= 1.0.11 =
* The Activity tab names the provider of a visitor's latest verdict when their signals change within the hour, rather than the first one's.

= 1.0.10 =
* A visitor on iCloud Private Relay or another privacy relay is no longer refused as hosting where an area blocks hosting networks: a relay counts as a relay alone, and relays are let in by default.

= 1.0.9 =
* Tested with WooCommerce 11.3.
* The bundled VPNDetection PHP library is now 4.14.1, which says what a lookup without a key answers and marks an answer once the free plan's daily trial is spent. Nothing about how the plugin works changes.

= 1.0.8 =
* The account card names your plan Unlimited, VPNDetection's free plan, and counts today's checks with every signal out of its 100 a day.

= 1.0.7 =
* Without an account the plugin checks for VPNs, Tor, privacy relays, countries and hosting with no cap; a connected account tries every signal, the proxies included, on its first 100 checks each day, and a paid plan checks every signal on every visit.

= 1.0.6 =
* Without an account the plugin now checks for Tor, privacy relays, countries and hosting as well as VPNs, and a free account adds every proxy. The settings say which signals need an account, and that counting a proxy's sightings needs a paid plan.
* Checking without an account is for non-commercial sites: a store or any other business connects a free account.

= 1.0.5 =
* The bundled VPNDetection PHP library is now 4.12.0, which lets a database download name a build. The plugin downloads nothing, so nothing about how it works changes.

= 1.0.4 =
* Bing's crawler is never refused from its newest published address block, 52.130.224.112/28.

= 1.0.3 =
* The bundled VPNDetection PHP library is now 4.11.3, which refuses a bad retry count or concurrency set on a single call. Nothing about how the plugin works changes.

= 1.0.2 =
* The bundled VPNDetection PHP library is now 4.11.2, which reads a date and time written in lowercase. Nothing about how the plugin works changes.

= 1.0.1 =
* The bundled VPNDetection PHP library is now 4.11.1. Nothing about how the plugin works changes.

= 1.0.0 =
* First release.

== External services ==

This plugin uses the VPNDetection API, run by VPNDetection (https://vpndetection.io), to tell what kind of network an IP address belongs to.

* When a visitor uses an area you turned on (signs in, creates an account, asks for a password reset, comments, sends a contact form, places an order or opens a protected page), the plugin sends the visitor's IP address, with your API key if you added one, to https://api.vpndetection.io. The answer is kept on your site for six hours, so the same address is not sent again within that time. While an area blocks forged crawlers, the visitor's user agent is sent with the address, and the answer is kept for that address and user agent.
* When you open the plugin's settings page, it sends your own IP address the same way, and reads your plan and usage with your API key.
* When you click Connect with VPNDetection, the plugin asks https://api.vpndetection.io for a sign-in code and opens https://app.vpndetection.io in your browser, where you sign in and pick an API key, which is then sent back to your site.

VPNDetection's terms of service: https://vpndetection.io/terms. Its privacy policy: https://vpndetection.io/privacy.
