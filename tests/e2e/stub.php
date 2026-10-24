<?php

// A stand-in for the VPNDetection API, answering the addresses the e2e scenarios use in the shapes the
// real API answers them, by plan: no key, a free key (`free-key`), or any other key as a paid one.
// Every request is appended to /tmp/stub.log, so a scenario can count lookups. Run with `php -S`.

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$key = trim(preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '') ?: ($_SERVER['HTTP_X_API_KEY'] ?? ''));
file_put_contents('/tmp/stub.log', sprintf("%s %s %s\n", $_SERVER['REQUEST_METHOD'] ?? '', $path, $key === '' ? '-' : $key), FILE_APPEND);
header('Content-Type: application/json');

function reply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($path === '/oauth/device_authorization') {
    @unlink('/tmp/stub-polls');
    reply(200, [
        'device_code' => 'device-code-1',
        'user_code' => 'WXYZ-1234',
        'verification_uri' => 'http://stub.test/device',
        'verification_uri_complete' => 'http://stub.test/device?user_code=WXYZ-1234',
        'expires_in' => 600,
        'interval' => 1,
    ]);
}
if ($path === '/oauth/token') {
    $polls = (int) @file_get_contents('/tmp/stub-polls') + 1;
    file_put_contents('/tmp/stub-polls', (string) $polls);
    if ($polls < 2) {
        reply(400, ['error' => 'authorization_pending']);
    }
    reply(200, [
        'access_token' => 'access-1',
        'token_type' => 'Bearer',
        'expires_in' => 3600,
        'refresh_token' => 'refresh-1',
        'scope' => 'account.read apikeys.read apikeys.reveal',
        'mslm:apikey_id' => '0ab424cc-7619-4dad-b027-afacdc2cedb0',
        'mslm:apikey' => 'max-key',
    ]);
}
if ($path === '/oauth/revoke') {
    reply(200, []);
}
if ($path === '/api/v1/entitlement') {
    if ($key === '' || $key === 'bad-key') {
        reply(401, ['error' => 'invalid API key']);
    }
    $free = $key === 'free-key';
    reply(200, [
        'org_id' => '85bb51e4-2eb6-4a31-8e4d-02ba8b98fe61',
        'apikey' => ['id' => '0ab424cc-7619-4dad-b027-afacdc2cedb0', 'expires' => null, 'allowed_cidrs' => []],
        'plan' => ['key' => $free ? 'free' : 'max', 'tier' => $free ? 'free' : 'max'],
        'usage' => [
            'requests' => 1240,
            'quota' => $free ? 50000 : 5000000,
            'hard_limit' => $free ? 50000 : null,
            'window_start' => '2026-10-04T07:00:00Z',
            'window_end' => '2026-11-04T07:00:00Z',
        ],
    ]);
}
if ($key === 'spent-key') {
    reply(429, ['error' => 'quota exceeded']);
}

$ip = ltrim($path, '/');
$location = [
    'continent' => 'EU', 'continent_name' => 'Europe', 'country' => 'DE', 'country_name' => 'Germany',
    'timezone' => 'Europe/Berlin', 'currency_code' => 'EUR', 'currency_name' => 'Euro', 'currency_symbol' => 'EUR',
    'flag_emoji' => '', 'flag_unicode' => 'U+1F1E9 U+1F1EA',
];
$asn = ['asn' => 3320, 'name' => 'Deutsche Telekom AG', 'domain' => 'telekom.de', 'type' => 'isp', 'prefix' => $ip . '/32'];
$flags = [
    'is_vpn' => false, 'is_hosting' => false, 'is_relay' => false, 'is_tor' => false, 'is_cdn' => false,
    'is_resproxy' => false, 'is_dcproxy' => false, 'is_mobproxy' => false, 'is_pubproxy' => false,
];
$details = [
    'vpn' => (object) [], 'hosting' => (object) [], 'relay' => (object) [], 'tor' => (object) [], 'cdn' => (object) [],
    'resproxy' => (object) [], 'dcproxy' => (object) [], 'mobproxy' => (object) [], 'pubproxy' => (object) [],
];
$class = ['provider' => '', 'confidence' => 'high', 'last_seen' => '2026-10-23'];
$proxy = fn (int $hits): array => [
    'provider' => 'ippeak', 'first_seen' => '2026-08-06', 'last_seen' => '2026-10-16',
    'hits' => $hits, 'hits_days_pct' => 2, 'providers_num' => 1,
];
switch ($ip) {
    case '45.83.91.1':
        $flags['is_vpn'] = $flags['is_hosting'] = true;
        $details['vpn'] = ['provider' => 'nordvpn', 'last_seen' => '2026-10-24', 'confidence' => 'high'];
        $details['hosting'] = ['provider' => 'm247'] + $class;
        $location = ['country' => 'SE', 'country_name' => 'Sweden', 'timezone' => 'Europe/Stockholm'] + $location;
        $asn = ['asn' => 9009, 'name' => 'M247 Europe SRL', 'domain' => 'm247global.com', 'type' => 'hosting', 'prefix' => '45.83.91.0/24'];
        break;
    case '185.220.101.1':
        $flags['is_tor'] = $flags['is_hosting'] = true;
        $details['tor'] = $class;
        $details['hosting'] = $class;
        $asn = ['asn' => 60729, 'name' => 'Stiftung Erneuerbare Freiheit', 'domain' => 'zwiebelfreunde.de', 'type' => 'hosting', 'prefix' => '185.220.101.0/24'];
        break;
    case '5.6.7.8':
        $flags['is_resproxy'] = true;
        $details['resproxy'] = $proxy(2);
        break;
    case '66.249.66.1':
        $flags['is_hosting'] = true;
        $details['hosting'] = ['provider' => 'gcp'] + $class;
        $asn = ['asn' => 15169, 'name' => 'Google LLC', 'domain' => 'google.com', 'type' => 'hosting', 'prefix' => '66.249.64.0/19'];
        break;
}
if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
    reply(400, ['error' => 'not a valid IP address']);
}
if ($key === '') {
    reply(200, ['ip' => $ip, 'is_vpn' => $flags['is_vpn']]);
}
if ($key === 'free-key') {
    reply(200, [
        'ip' => $ip, 'is_vpn' => $flags['is_vpn'], 'is_relay' => $flags['is_relay'], 'is_tor' => $flags['is_tor'],
        'relay' => $details['relay'], 'tor' => $details['tor'], 'location' => $location, 'asn' => $asn,
    ]);
}
reply(200, ['ip' => $ip] + $flags + $details + ['location' => $location, 'asn' => $asn]);
