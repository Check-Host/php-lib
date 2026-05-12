<?php

require_once __DIR__ . '/../src/Exceptions/CheckHostException.php';
require_once __DIR__ . '/../src/CheckHost.php';

use CheckHostCc\CheckHostApi\CheckHost;

// With CHECK_HOST_API_KEY (CI) we can spam the API; without it the
// anonymous-tier per-target bucket needs ~5s between calls.
$_throttle = static function (): void {
    if (getenv('CHECK_HOST_API_KEY')) { usleep(500_000); return; }
    sleep(5);
};

try {
    // CI exposes CHECK_HOST_API_KEY as a masked GitLab CI variable (higher
    // rate limits). Locally we fall back to anonymous limits.
    $apikey = getenv('CHECK_HOST_API_KEY') ?: null;
    if ($apikey) echo "(using API key from env)\n";
    $api = new CheckHost($apikey);

    echo "Testing myip()...\n";
    $ip = $api->myip();
    echo "Result: OK (Returned IP info)\n";
    $_throttle();

    echo "Testing locations()...\n";
    $locations = $api->locations();
    echo "Result: OK (Active nodes: " . count($locations) . ")\n";
    $_throttle();

    echo "Testing info()...\n";
    $info = $api->info('8.8.8.8');
    echo "Result: OK\n";
    $_throttle();

    echo "Testing whois()...\n";
    $whois = $api->whois('check-host.cc');
    echo "Result: OK\n";
    $_throttle();

    echo "Testing ping()...\n";
    $ping = $api->ping('8.8.8.8', ['nodes' => 1]); // Use minimum nodes to avoid heavy load
    echo "Result: OK (UUID: " . ($ping['uuid'] ?? 'none') . ")\n";
    $lastUuid = $ping['uuid'] ?? null;
    $_throttle();

    echo "Testing dns()...\n";
    $dns = $api->dns('check-host.cc', ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($dns['uuid'] ?? 'none') . ")\n";
    $_throttle();

    echo "Testing tcp()...\n";
    $tcp = $api->tcp('1.1.1.1', 53, ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($tcp['uuid'] ?? 'none') . ")\n";
    $_throttle();

    echo "Testing udp()...\n";
    $udp = $api->udp('1.1.1.1', 53, ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($udp['uuid'] ?? 'none') . ")\n";
    $_throttle();

    echo "Testing http()...\n";
    $http = $api->http('https://check-host.cc', ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($http['uuid'] ?? 'none') . ")\n";
    $_throttle();

    echo "Testing mtr()...\n";
    $mtr = $api->mtr('8.8.8.8', ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($mtr['uuid'] ?? 'none') . ")\n";
    $_throttle();

    if ($lastUuid) {
        echo "Testing report()...\n";
        $report = $api->report($lastUuid);
        echo "Result: OK\n";
    }

    echo "\nAll tests passed.\n";

}
catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
