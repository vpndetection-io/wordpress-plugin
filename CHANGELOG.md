# Changelog

What each release changed for you, newest first. Each line is a commit's summary, linked to its full description and diff.

## 1.2.3 - 2026-11-25

### Fixes

- Settings: a paid plan checks every signal, not paid plans ([`8d4f80f`](https://github.com/vpndetection-io/wordpress-plugin/commit/8d4f80fe114a9aa51ae53763f797223d7c54ea30))
  - The settings page says a paid plan checks every signal on every visit: VPNDetection sells one, at the quota you pick.

## 1.2.2 - 2026-11-24

### Fixes

- Count a free key's trial as its 14 days, not a month that resets ([`13fd5c4`](https://github.com/vpndetection-io/wordpress-plugin/commit/13fd5c4d02852c052db487132f0d1bf4a212ebf0))
  - The free plan tries every signal for 14 days from your first check, up to 250,000 checks. The account card counts them until the day the trial ends, and once it has ended, says so.

## 1.2.1 - 2026-11-24

### Fixes

- Bundle vpndetection 4.20.0: a filtered rule on a list matches any element ([`6e91a21`](https://github.com/vpndetection-io/wordpress-plugin/commit/6e91a21074a8130adf85ba25791f4a42647c3173))
  - A rule you return from the `vpndetection_condition` filter may name a member the answer holds as a list, such as a public proxy's protocols; it now matches when any element does, where it never matched before.

## 1.2.0 - 2026-11-23

### Features

- Never refuse a vouched-for search engine crawler from your pages ([`a0a89c5`](https://github.com/vpndetection-io/wordpress-plugin/commit/a0a89c512930506af49f65fcd7a35dd71034618a))
  - On a paid plan or the trial, the answer says whose bot an address is and whether its operator vouches for it. Google's, Bing's, Apple's and DuckDuckGo's published ranges still pass on every plan.

### Fixes

- Bundle vpndetection 4.19.0, which changes no call the plugin makes ([`31e8f3d`](https://github.com/vpndetection-io/wordpress-plugin/commit/31e8f3d114a767e6ab5d77cda0100ace2a7c8cb4))

## 1.1.1 - 2026-11-22

### Fixes

- Count a free key's trial by the month, with the day it resets ([`a490db1`](https://github.com/vpndetection-io/wordpress-plugin/commit/a490db1ecb795a31b104f81bd72a4c5be67ecc98))
- Bundle vpndetection 4.18.0, which changes no call the plugin makes ([`4e459f5`](https://github.com/vpndetection-io/wordpress-plugin/commit/4e459f55d23904d137659a5f3d90007ed8346238))

## 1.1.0 - 2026-11-21

### Features

- Block addresses that run a mail server, on a paid plan or the trial ([`6914b36`](https://github.com/vpndetection-io/wordpress-plugin/commit/6914b3636d22e9623bbf4af2331e1ca1673da94b))
  - Of the services, networks and routes a paid answer now names, only a mail server is a signal. SSH, web and DNS servers also answer from home routers, carrier, satellite and BitTorrent addresses are ordinary people's, and the relay and hosting signals already judge anycast space.

## 1.0.15 - 2026-11-20

### Fixes

- Settings and readme: the free trial without a count ([`ebc2295`](https://github.com/vpndetection-io/wordpress-plugin/commit/ebc2295566ccf2ef84dcfcc7930e209d6d22214a))
- Bundle vpndetection 4.17.0: a spent trial's answer is kept a minute, not six hours ([`cddb1bf`](https://github.com/vpndetection-io/wordpress-plugin/commit/cddb1bfdadad028720dc0b60071fcbd37a550ef2))

## 1.0.14 - 2026-11-19

### Fixes

- Bundle vpndetection 4.16.1, which changes no call the plugin makes ([`b4039f8`](https://github.com/vpndetection-io/wordpress-plugin/commit/b4039f8ca78bbdbf6078f90299bb67b13888a473))

## 1.0.13 - 2026-11-18

### Fixes

- Bundle vpndetection 4.16.0: the plan's own name, and the overage cap ([`6106cd2`](https://github.com/vpndetection-io/wordpress-plugin/commit/6106cd2f8df406ee15c72664d78132b8d9c84009))

## 1.0.12 - 2026-11-15

### Fixes

- Bundle vpndetection 4.15.0, which changes no call the plugin makes ([`fd4b87a`](https://github.com/vpndetection-io/wordpress-plugin/commit/fd4b87a3ef5634cdbab919f1537b60c83778a170))

## 1.0.11 - 2026-11-14

### Fixes

- Keep an Activity row's provider in step with its verdict ([`545265b`](https://github.com/vpndetection-io/wordpress-plugin/commit/545265b47f50c2aef976bb3a39dbb9f83bf294b9))

## 1.0.10 - 2026-11-14

### Fixes

- Judge a privacy relay by relay alone, even on a hosting network ([`6dcd218`](https://github.com/vpndetection-io/wordpress-plugin/commit/6dcd21822d428015986033eb66b5c302ac991aa0))

## 1.0.9 - 2026-11-12

### Fixes

- Bundle vpndetection 4.14.1, which changes no call the plugin makes ([`6ea72df`](https://github.com/vpndetection-io/wordpress-plugin/commit/6ea72dff15c4f0bb24f0186a89341d054c33bbd6))
- Declare WooCommerce 11.3 tested ([`8054a89`](https://github.com/vpndetection-io/wordpress-plugin/commit/8054a89517b5d8bd4c492f08d942807f59ccd699))

## 1.0.8 - 2026-11-11

### Fixes

- Name the free plan Unlimited on the account card, and count its daily trial ([`91da11e`](https://github.com/vpndetection-io/wordpress-plugin/commit/91da11e7d78ab32317eab5b3ba33cc1e9da9616b))

## 1.0.7 - 2026-11-11

### Features

- Gate the proxies on a paid plan, and name the Trial an account tries ([`a9b0963`](https://github.com/vpndetection-io/wordpress-plugin/commit/a9b09631458eb0d675cd98004864e682ef475cb7))

## 1.0.6 - 2026-11-11

### Features

- Check Tor, relays, countries and hosting without a key, proxies with a free one ([`ec87abc`](https://github.com/vpndetection-io/wordpress-plugin/commit/ec87abcfcabed84ab37fbd256d3ba0843c652346))
- Say that checking without an account is for non-commercial sites ([`17dd50f`](https://github.com/vpndetection-io/wordpress-plugin/commit/17dd50f4023ffc9bae9cbab07f2e9c1978b07b35))

## 1.0.5 - 2026-11-08

### Fixes

- Bundle vpndetection 4.12.0, which changes no call the plugin makes ([`0090269`](https://github.com/vpndetection-io/wordpress-plugin/commit/0090269f5038e4989a3053e58b51a13ebe35d6ab))

## 1.0.4 - 2026-11-06

### Fixes

- Never refuse Bingbot from 52.130.224.112/28 ([`3897a50`](https://github.com/vpndetection-io/wordpress-plugin/commit/3897a50438ee66e3525a6cf2311ca5bc1679bcc0))

## 1.0.3 - 2026-11-02

### Fixes

- Bundle vpndetection 4.11.3: a bad per-call retry count is refused ([`a72068d`](https://github.com/vpndetection-io/wordpress-plugin/commit/a72068d239a8516af72dfd6900d411cbbec68f55))

## 1.0.2 - 2026-10-30

### Fixes

- Bundle vpndetection 4.11.2: a lowercase t or z in a date-time is read ([`7f27300`](https://github.com/vpndetection-io/wordpress-plugin/commit/7f27300b86f551e86c34fe632d819f4b89b2c5b8))

## 1.0.1 - 2026-10-26

### Fixes

- Bundle vpndetection 4.11.1: re-pinned to the 2026.10.25 spec ([`de78bbe`](https://github.com/vpndetection-io/wordpress-plugin/commit/de78bbe99113a3f4c1697caf38dab901cdffff41))

## 1.0.0 - 2026-10-25

### Features

- Block VPNs, proxies, Tor and hosting networks across WordPress ([`3ed0afe`](https://github.com/vpndetection-io/wordpress-plugin/commit/3ed0afea4fd3b5ee0181d65efb46a5c324eed411))
- Countries, and hosting on the free plan by the network's type ([`acc75ba`](https://github.com/vpndetection-io/wordpress-plugin/commit/acc75ba43139bdeac823cb489efc073eedefdcd1))
