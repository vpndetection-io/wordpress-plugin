# [<img src="https://s3.vpndetection.io/vpndetection-public/brand/mark.svg" alt="VPNDetection" height="28"/>](https://vpndetection.io/) VPNDetection WordPress Plugin

[![release](https://img.shields.io/github/v/release/vpndetection-io/wordpress-plugin)](CHANGELOG.md)
[![license](https://img.shields.io/github/license/vpndetection-io/wordpress-plugin)](LICENSE)

The official [VPNDetection](https://vpndetection.io) plugin for [WordPress](https://wordpress.org).

## Getting Started

Download `vpndetection-<version>.zip` from the [latest release](https://github.com/vpndetection-io/wordpress-plugin/releases/latest) and upload it under Plugins > Add New > Upload Plugin, then activate it. It needs WordPress 6.4 or newer and PHP 8.2 or newer.

## What it sends

The plugin calls the VPNDetection API at `https://api.vpndetection.io`. Our terms are at https://vpndetection.io/terms and our privacy policy at https://vpndetection.io/privacy.

## Building from source

The plugin vendors the [PHP SDK](https://github.com/vpndetection-io/sdk-php) and its dependencies under its own namespace, so another plugin's copy of Guzzle can never be the one it loads. Building needs Docker and nothing else:

```bash
./scripts/build.sh      # dist/vpndetection-<version>.zip
./scripts/test.sh       # the unit suite
./scripts/e2e.sh        # a real WordPress, and wordpress.org's Plugin Check
```

## About VPNDetection

VPN Detection API: Accurate anonymity detection identifying VPNs, residential proxies, hosting servers, Tor nodes, CDNs, relays and more.

[<img src="https://s3.vpndetection.io/vpndetection-public/brand/mark.svg" alt="VPNDetection" height="64"/>](https://vpndetection.io/)

## License

This project is licensed under the [MIT License](LICENSE).
