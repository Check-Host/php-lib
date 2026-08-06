<?php

/**
 * Live smoke test against the production API.
 *
 *   php tests/test_all.php
 *
 * Exits non-zero on the first failure so CI actually gates on it. The
 * deterministic, offline suite is tests/test_unit.php.
 */

require_once __DIR__ . '/../src/Exceptions/CheckHostException.php';
require_once __DIR__ . '/../src/CheckHost.php';

use CheckHostCc\CheckHostApi\CheckHost;

// CI exposes CHECK_HOST_API_TOKEN as a masked GitLab CI variable (higher
// rate limits). Locally we fall back to anonymous limits, where the
// per-target bucket needs ~5s between monitoring calls.
$token = getenv('CHECK_HOST_API_TOKEN') ?: (getenv('CHECK_HOST_API_KEY') ?: null);
echo $token ? "(using API token from env)\n" : "(anonymous)\n";

$throttle = static function () use ($token): void {
    if ($token) { usleep(500_000); return; }
    sleep(5);
};

$failures = 0;

$step = static function (string $label, callable $fn) use (&$failures): void {
    echo "Testing $label...\n";
    try {
        echo '  OK: ' . $fn() . "\n";
    } catch (\Throwable $e) {
        $failures++;
        echo '  FAILED: ' . $e->getMessage() . "\n";
    }
};

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new \RuntimeException($message);
    }
};

$api = new CheckHost($token);

// --- Utilities -------------------------------------------------------

$step('myip()', function () use ($api, $assert) {
    $res = $api->myip();
    $assert(!empty($res['ip']), 'expected an ip field');
    return $res['ip'];
});
$throttle();

$step('myinfo()', function () use ($api, $assert) {
    $res = $api->myinfo();
    $assert(!empty($res['ip']), 'expected an ip field');
    return $res['ip'] . ' -> ' . ($res['country'] ?? '?');
});
$throttle();

$step('locations()', function () use ($api, $assert) {
    $res = $api->locations();
    $assert(!empty($res['locationlist']), 'expected a locationlist');
    return count($res['locationlist']) . ' nodes';
});
$throttle();

$step('info()', function () use ($api, $assert) {
    $res = $api->info('8.8.8.8');
    $assert(!empty($res['ip']), 'expected an ip field');
    return $res['ip'] . ' -> ' . ($res['country'] ?? '?');
});
$throttle();

$step('whois()', function () use ($api, $assert) {
    $res = $api->whois('check-host.cc');
    $assert(!empty($res), 'expected a non-empty RDAP record');
    return 'RDAP record retrieved';
});
$throttle();

// --- Monitoring ------------------------------------------------------

$lastUuid = null;

$step('ping()', function () use ($api, $assert, &$lastUuid) {
    $res = $api->ping('8.8.8.8', ['region' => ['DE']]);
    $assert(!empty($res['uuid']), 'expected a uuid');
    $lastUuid = $res['uuid'];
    return 'uuid ' . $res['uuid'];
});
$throttle();

$step('dns()', function () use ($api, $assert) {
    $res = $api->dns('check-host.cc', ['region' => ['DE']]);
    $assert(!empty($res['uuid']), 'expected a uuid');
    return 'uuid ' . $res['uuid'];
});
$throttle();

$step('tcp()', function () use ($api, $assert) {
    $res = $api->tcp('1.1.1.1', 53, ['region' => ['DE']]);
    $assert(!empty($res['uuid']), 'expected a uuid');
    return 'uuid ' . $res['uuid'];
});
$throttle();

$step('udp()', function () use ($api, $assert) {
    $res = $api->udp('1.1.1.1', 53, ['region' => ['DE']]);
    $assert(!empty($res['uuid']), 'expected a uuid');
    return 'uuid ' . $res['uuid'];
});
$throttle();

$step('http()', function () use ($api, $assert) {
    $res = $api->http('https://check-host.cc', ['region' => ['DE']]);
    $assert(!empty($res['uuid']), 'expected a uuid');
    return 'uuid ' . $res['uuid'];
});
$throttle();

$step('mtr()', function () use ($api, $assert) {
    $res = $api->mtr('8.8.8.8', ['region' => ['DE']]);
    $assert(!empty($res['uuid']), 'expected a uuid');
    return 'uuid ' . $res['uuid'];
});
$throttle();

if ($lastUuid !== null) {
    $step('report()', function () use ($api, $assert, $lastUuid) {
        $res = $api->report($lastUuid);
        $assert(isset($res['data']), 'expected a data map');
        return count($res['data']) . ' node(s) reported so far';
    });
    $throttle();
}

// --- Network Intelligence --------------------------------------------

$step('ipIntel()', function () use ($api, $assert) {
    $res = $api->ipIntel('1.1.1.1');
    $assert(($res['success'] ?? false) === true, 'expected success: true');
    $assert(($res['ip'] ?? '') === '1.1.1.1', 'expected the ip to echo back');
    return 'family ' . ($res['family'] ?? '?');
});
$throttle();

$step('asnIntel()', function () use ($api, $assert) {
    $res = $api->asnIntel('AS13335');
    $assert(($res['success'] ?? false) === true, 'expected success: true');
    $assert(($res['asn'] ?? 0) === 13335, 'expected asn 13335');
    return (string) ($res['as_name'] ?? '?');
});
$throttle();

$step('prefixIntel()', function () use ($api, $assert) {
    $res = $api->prefixIntel('1.1.1.0', 24);
    $assert(($res['success'] ?? false) === true, 'expected success: true');
    return (string) ($res['cidr'] ?? '?');
});
$throttle();

$step('domainIntel()', function () use ($api, $assert) {
    $res = $api->domainIntel('check-host.cc');
    $assert(($res['success'] ?? false) === true, 'expected success: true');
    return (string) ($res['domain'] ?? '?');
});
$throttle();

$step('portIntel()', function () use ($api, $assert) {
    $res = $api->portIntel(443);
    $assert(($res['success'] ?? false) === true, 'expected success: true');
    return $res['port'] . ' (' . ($res['well_known'] ?? '?') . ')';
});
$throttle();

$step('softwareIntel()', function () use ($api, $assert) {
    $res = $api->softwareIntel('nginx');
    $assert(($res['success'] ?? false) === true, 'expected success: true');
    return (string) ($res['name'] ?? '?');
});
$throttle();

$step('recentScans()', function () use ($api, $assert) {
    $res = $api->recentScans('check-host.cc');
    $assert(($res['success'] ?? false) === true, 'expected success: true');
    $assert(is_array($res['recent_scans'] ?? null), 'expected a recent_scans array');
    return count($res['recent_scans']) . ' recent scan(s)';
});

echo "\n";
if ($failures === 0) {
    echo "All live checks passed.\n";
    exit(0);
}
echo "$failures live check(s) failed.\n";
exit(1);
