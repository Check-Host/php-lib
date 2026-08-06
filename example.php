<?php

require_once __DIR__ . '/src/Exceptions/CheckHostException.php';
require_once __DIR__ . '/src/CheckHost.php';

use CheckHostCc\CheckHostApi\CheckHost;

try {
    // Pass your API token to lift rate limits; it is sent as an
    // Authorization: Bearer header. Omit it for anonymous access.
    // $api = new CheckHost('YOUR_API_TOKEN_UUID');
    $api = new CheckHost();

    echo "Fetching my ip...\n";
    $ip = $api->myip();
    print_r($ip);

    echo "\nFetching locations short count...\n";
    $locations = $api->locations();
    echo "Active nodes: " . count($locations) . "\n";

    echo "\nPinging 8.8.8.8...\n";
    $ping = $api->ping('8.8.8.8', ['region' => ['EU']]);
    print_r($ping);

    if (isset($ping['uuid'])) {
        echo "\nWaiting 2 seconds before report...\n";
        sleep(2);
        $report = $api->report($ping['uuid']);
        print_r($report);
    }

    // Passive Network Intelligence - no check dispatched, instant result
    echo "\nLooking up intelligence for 1.1.1.1...\n";
    $intel = $api->ipIntel('1.1.1.1');
    $bgp = $intel['data']['bgp'] ?? [];
    printf(
        "AS%s %s (%s), RPKI %s\n",
        $bgp['asn'] ?? '?',
        $bgp['as_name'] ?? '?',
        $bgp['prefix'] ?? '?',
        $bgp['rpki_status'] ?? '?'
    );

    echo "\nChecking how widespread port 443 is...\n";
    $port = $api->portIntel(443);
    printf("%s: %s open IPs worldwide\n", $port['well_known'], $port['data']['open_ips']);

    echo "\nAll basic tests passed.\n";

}
catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
