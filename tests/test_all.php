<?php

require_once __DIR__ . '/../src/Exceptions/CheckHostException.php';
require_once __DIR__ . '/../src/CheckHost.php';

use CheckHostCc\CheckHostApi\CheckHost;

try {
    // CI exposes CHECK_HOST_API_KEY as a masked GitLab CI variable (higher
    // rate limits). Locally we fall back to anonymous limits.
    $apikey = getenv('CHECK_HOST_API_KEY') ?: null;
    if ($apikey) echo "(using API key from env)\n";
    $api = new CheckHost($apikey);

    echo "Testing myip()...\n";
    $ip = $api->myip();
    echo "Result: OK (Returned IP info)\n";
    sleep(6);

    echo "Testing locations()...\n";
    $locations = $api->locations();
    echo "Result: OK (Active nodes: " . count($locations) . ")\n";
    sleep(6);

    echo "Testing info()...\n";
    $info = $api->info('8.8.8.8');
    echo "Result: OK\n";
    sleep(6);

    echo "Testing whois()...\n";
    $whois = $api->whois('check-host.cc');
    echo "Result: OK\n";
    sleep(6);

    echo "Testing ping()...\n";
    $ping = $api->ping('8.8.8.8', ['nodes' => 1]); // Use minimum nodes to avoid heavy load
    echo "Result: OK (UUID: " . ($ping['uuid'] ?? 'none') . ")\n";
    $lastUuid = $ping['uuid'] ?? null;
    sleep(6);

    echo "Testing dns()...\n";
    $dns = $api->dns('check-host.cc', ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($dns['uuid'] ?? 'none') . ")\n";
    sleep(6);

    echo "Testing tcp()...\n";
    $tcp = $api->tcp('1.1.1.1', 53, ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($tcp['uuid'] ?? 'none') . ")\n";
    sleep(6);

    echo "Testing udp()...\n";
    $udp = $api->udp('1.1.1.1', 53, ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($udp['uuid'] ?? 'none') . ")\n";
    sleep(6);

    echo "Testing http()...\n";
    $http = $api->http('https://check-host.cc', ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($http['uuid'] ?? 'none') . ")\n";
    sleep(6);

    echo "Testing mtr()...\n";
    $mtr = $api->mtr('8.8.8.8', ['nodes' => 1]);
    echo "Result: OK (UUID: " . ($mtr['uuid'] ?? 'none') . ")\n";
    sleep(6);

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
