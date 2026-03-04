<?php

require_once __DIR__ . '/src/Exceptions/CheckHostException.php';
require_once __DIR__ . '/src/CheckHost.php';

use CheckHostCc\CheckHostApi\CheckHost;

try {
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

    echo "\nAll basic tests passed.\n";

}
catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
