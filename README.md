# Check-Host API PHP Library

A lightweight, lightning-fast, and feature-complete PHP 8+ wrapper for the [Check-Host.cc](https://check-host.cc) API. Full documentation is available at [docs.check-host.cc](https://docs.check-host.cc).

Seamlessly integrate global network diagnostics into your backend. Perform remote Ping, TCP, UDP, DNS, and HTTP checks from multiple worldwide locations—straight from your PHP application. Checks from 60+ locations worldwide.

## Features

- **Zero Dependencies:** Built purely on the native PHP cURL extension. No Guzzle, no Symfony HTTP Client, zero package bloat.
- **Bulletproof Payloads:** Strictly utilizes POST requests for all active monitoring endpoints. This completely eliminates nasty URL-encoding issues with complex hostnames or custom UDP payloads.
- **Modern & Clean:** Written for PHP 8.1+ with full type hinting and clean structure.
- **Smart Authentication:** API Key auto-injection. Configure your key once during initialization, and the core SDK seamlessly handles all authentication payloads under the hood.

## Requirements

- **PHP**: ^8.1
- `ext-curl` and `ext-json`

## Installation

Ensure you have PHP 8.1+ installed. You can install the package directly from Packagist using Composer:
```bash
composer require check-hostcc/check-host-api-php
```

## Quickstart

```php
require 'vendor/autoload.php';

use CheckHostCc\CheckHostApi\CheckHost;

// Initialize the client. The API Key is optional.
// Without an API key, standard public rate limits apply.
// $checkHost = new CheckHost('YOUR_API_KEY_HERE');
// Or leave empty: new CheckHost()
$checkHost = new CheckHost();

// Example: Retrieve all current nodes
$locations = $checkHost->locations();
print_r($locations);
```

---

## Complete API Reference & Examples

This library supports both minimal invocations and detailed, options-rich requests for every endpoint. All failures (network issues, API errors, rate limits) throw a `CheckHostException`.

### Common Options Used in Examples
- `region`: Array of Nodes or ISO Country Codes (e.g. `['DE', 'NL']`) or Continents (e.g. `['EU']`).
- `repeatchecks`: Number of repeated probes to perform per node for higher accuracy (Live Check).
- `timeout`: Connection timeout threshold in seconds (Currently disabled on Check-host backend, but supported).

---

### Information & Utilities

#### Get My IP
Returns the requesting client's public IPv4 or IPv6 address.
```php
$ip = $checkHost->myip();
```

#### Get Locations
Fetches a dynamic list of all currently active monitoring nodes across the globe.
```php
$nodes = $checkHost->locations();
```

#### Host Info (GeoIP/ASN)
Retrieves detailed geolocation data, ISP information, and ASN details.
```php
// Minimal Example
$info = $checkHost->info('check-host.cc');
```

#### WHOIS Lookup
Performs a WHOIS registry lookup.
```php
// Minimal Example
$whois = $checkHost->whois('check-host.cc');
```

---

### Active Monitoring (POST Tasks)

Monitoring endpoints initiate tasks asynchronously and return a `Task Object` array containing an `uuid`. Use the `report()` method (documented below) to fetch the actual results.

#### Ping
Dispatches ICMP echo requests to the target from global nodes.
```php
// Minimal Example
$pingMin = $checkHost->ping('8.8.8.8');

// Max Example (With options)
$pingMax = $checkHost->ping('8.8.8.8', [
    'region' => ['DE', 'NL'],
    'repeatchecks' => 5,
    'timeout' => 5
]);
```

#### DNS
Queries global nameservers for specific DNS records.
```php
// Minimal Example
$dnsMin = $checkHost->dns('check-host.cc');

// Max Example (With options - TXT Record)
$dnsMax = $checkHost->dns('check-host.cc', [
    'querymethod' => 'TXT', // A, AAAA, MX, TXT, SRV, etc.
    'region' => ['US', 'DE']
]);
```

#### TCP
Attempts to establish a 3-way TCP handshake on a specific destination port.
```php
// Minimal Example (Target, Port)
$tcpMin = $checkHost->tcp('1.1.1.1', 443);

// Max Example (With options)
$tcpMax = $checkHost->tcp('1.1.1.1', 80, [
    'region' => ['DE', 'NL'],
    'repeatchecks' => 3,
    'timeout' => 10
]);
```

#### UDP
Sends UDP packets to a specified target and port.
```php
// Minimal Example (Target, Port)
$udpMin = $checkHost->udp('1.1.1.1', 53);

// Max Example (With custom hex payload and options)
$udpMax = $checkHost->udp('1.1.1.1', 123, [
    'payload' => '0b', // NTP Request Hex
    'region' => ['EU'],
    'repeatchecks' => 2,
    'timeout' => 5
]);
```

#### HTTP
Executes an HTTP/HTTPS request to the target to measure TTFB and latency.
```php
// Minimal Example
$httpMin = $checkHost->http('https://check-host.cc');

// Max Example (With options)
$httpMax = $checkHost->http('https://check-host.cc', [
    'region' => ['US', 'DE'],
    'repeatchecks' => 3,
    'timeout' => 10
]);
```

#### MTR
Initiates an MTR (My Traceroute) diagnostic.
```php
// Minimal Example
$mtrMin = $checkHost->mtr('1.1.1.1');

// Max Example (With protocols, IP forced, and options)
$mtrMax = $checkHost->mtr('1.1.1.1', [
    'repeatchecks' => 15,
    'forceIPversion' => 4,     // 4 or 6
    'forceProtocol' => 'TCP',  // default is ICMP
    'region' => ['DE', 'US']
]);
```

---

### Fetching Results

#### Report
Fetches the compiled report and real-time statuses from a previously initiated monitoring check (Ping, TCP, HTTP, etc.) using its unique `uuid`. Wait 1-2 seconds after starting a check before polling. Longer checks with multiple repeats take one check per second and can be requested multiple times.
```php
// The check UUID is returned by any monitoring method above
$taskUuid = 'c0b4b0e3-aed7-4ae2-9f53-7bac879697cb';

// Fetch the result payload
$report = $checkHost->report($taskUuid);
```

## License

ISC License
