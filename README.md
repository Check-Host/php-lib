# Check-Host API PHP Library

A lightweight, lightning-fast, and feature-complete PHP 8+ wrapper for the [Check-Host.cc](https://check-host.cc) API. Full API reference: [check-host.cc/docs](https://check-host.cc/docs). A bundled OpenAPI 3.0.3 / Swagger spec ships at [`swagger.yaml`](./swagger.yaml) for codegen / offline browsing.

Seamlessly integrate global network diagnostics into your backend. Perform remote Ping, MTR, DNS, HTTP, TCP and UDP checks from multiple worldwide locations—straight from your PHP application. Checks from 60+ locations worldwide.

## Features

- **Zero Dependencies:** Built purely on the native PHP cURL extension. No Guzzle, no Symfony HTTP Client, zero package bloat.
- **Bulletproof Payloads:** Strictly utilizes POST requests for all active monitoring endpoints. This completely eliminates nasty URL-encoding issues with complex hostnames or custom UDP payloads.
- **Modern & Clean:** Written for PHP 8.1+ with full type hinting and clean structure.
- **Header-Based Authentication:** Configure your token once during initialization; the SDK attaches it as an `Authorization: Bearer` header to every request. The token never lands in a URL or a request body.

- **Network Intelligence & Fullscan:** Passive IP / ASN / prefix / domain / certificate / port / software lookups, plus deep on-demand scans with a built-in polling helper.

## Requirements

- **PHP**: ^8.1
- `ext-curl` and `ext-json`

## Installation

Ensure you have PHP 8.1+ installed. You can install the package directly from Packagist using Composer:
```bash
composer require check-hostcc/check-host-api-php
```

### Manual Installation

If you prefer not to use Composer, you can download the source code and require the class files directly:

```php
require_once '/path/to/src/Exceptions/CheckHostException.php';
require_once '/path/to/src/CheckHost.php';
```

## Quickstart

```php
require 'vendor/autoload.php';

use CheckHostCc\CheckHostApi\CheckHost;

// Initialize the client. The API token is optional.
// Without a token, standard public rate limits apply.
$checkHost = new CheckHost('YOUR_API_TOKEN_UUID');
// Or leave empty for anonymous access: new CheckHost()

// Example: Retrieve all current nodes
$locations = $checkHost->locations();
print_r($locations);
```

## Authentication

The token is sent as an `Authorization: Bearer <token>` header on every
request — GET, POST and binary alike. It is never placed in the query string
or the request body, so it does not leak into access logs, referrer headers
or browser history.

```php
// Explicit
$checkHost = new CheckHost('YOUR_API_TOKEN_UUID');

// Or from the environment (CHECK_HOST_API_TOKEN)
$checkHost = new CheckHost();
```

> **Migrating from 1.0.x:** the token used to travel in the JSON body as an
> `apikey` field. That field is deprecated server-side. The constructor
> argument is positional and unchanged, so `new CheckHost($yourToken)` keeps
> working as-is. The legacy `CHECK_HOST_API_KEY` environment variable is
> still read as a fallback.

---

## Complete API Reference & Examples

This library supports both minimal invocations and detailed, options-rich requests for every endpoint. All failures (network issues, API errors, rate limits) throw a `CheckHostException`.

### Common Options Used in Examples
- `region`: Array of Nodes or ISO Country Codes (e.g. `['DE', 'NL']`) or Continents (e.g. `['EU']`).
- `repeatchecks`: Number of repeated probes to perform per node for higher accuracy (Live Check).
- `timeout`: Connection timeout threshold in seconds. Supported by methods where a timeout is applicable (e.g., HTTP, TCP).

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

---

### Network Intelligence

Passive lookups against the dataset behind the entity pages — no check is dispatched to the monitoring nodes, so results come back immediately. Every response carries a `data` section; keys we hold no data for come back as empty arrays or `null`.

#### IP Profile
Reverse DNS, open ports and banners, TLS certificates, BGP/ASN attribution, GeoIP, tech-stack, co-hosted domains, origin-leak candidates, threat-intel matches and honeypot activity.
```php
$intel = $checkHost->ipIntel('1.1.1.1');
echo $intel['data']['bgp']['as_name'];                      // Cloudflare, Inc.
print_r(array_column($intel['data']['open_ports'], 'port')); // [443, ...]
```

Honeypot passwords are never returned in cleartext — entries expose only `password_captured` (bool) and `password_len`.

#### ASN Profile
Prefix counts, announced IP totals, peers / providers / customers, IXP memberships, RPKI coverage, GeoIP footprint and hosted-domain summaries. Accepts `13335` or `'AS13335'`.
```php
$intel = $checkHost->asnIntel('AS13335');
echo $intel['data']['prefix_count'], ' ', $intel['data']['rpki_coverage_pct'];
```

#### Prefix, Domain and Certificate
```php
$prefix = $checkHost->prefixIntel('1.1.1.0', 24);
$domain = $checkHost->domainIntel('check-host.cc');
$cert   = $checkHost->certIntel('3a1b8f0c…9f90');   // 64-char hex fingerprint

print_r($domain['data']['subdomains']);
print_r($cert['data']['served_by']);
```

#### Port and Software Exposure
```php
$port = $checkHost->portIntel(443);
echo $port['well_known'], ' ', $port['data']['open_ips'];

$nginx  = $checkHost->softwareIntel('nginx');            // all versions
$pinned = $checkHost->softwareIntel('nginx', '1.24.0');  // one version
```

---

### Fullscan

A deep, on-demand multi-stage scan (ports + banners + TLS + DNS + threat-intel) of an IP, CIDR, domain or ASN. Asynchronous: submit, poll, then read the results. Budget minutes, not seconds.

```php
$job = $checkHost->fullscan('check-host.cc', 'deep');
echo $job['uuid'], ' ', $job['status'];      // ... pending

// Block until the job reaches a terminal status (complete/partial/failed)
$finished = $checkHost->waitForFullscan($job['uuid'], 5, 300);
echo $finished['status'], " {$finished['subjobs_done']}/{$finished['subjobs_total']}";

$results = $checkHost->fullscanResults($job['uuid']);
foreach ($results['data']['open_ports'] as $entry) {
    echo $entry['port'], ' ', $entry['service'], "\n";
}
```

Scopes: `basic` (top-100 ports + banner), `deep` (default — full port range, TLS, body and threat-intel), `full` (deep plus subdomain enumeration; domains only).

Anonymous CIDR submissions are capped at `/24` (v4) and `/120` (v6); an API token raises that to `/20` and `/112`.

Before dispatching a scan, check whether a recent one already exists:
```php
$recent = $checkHost->recentScans('check-host.cc');
foreach ($recent['recent_scans'] as $prior) {
    if (CheckHost::isFullscanFinished($prior)) {
        $results = $checkHost->fullscanResults($prior['uuid']);
        break;
    }
}
```

For manual polling loops, `fullscanStatus($uuid)` returns `['success' => bool, 'job' => [...]]`.

---

## API surface

| Method | Endpoint |
|---|---|
| `myip()` | `GET /myip` |
| `myinfo()` | `GET /myinfo` |
| `locations()` | `GET /locations` |
| `info($target)` | `POST /info` |
| `whois($target)` | `POST /whois` |
| `ping($target, $options)` | `POST /ping` |
| `dns($target, $options)` | `POST /dns` |
| `tcp($target, $port, $options)` | `POST /tcp` |
| `udp($target, $port, $options)` | `POST /udp` |
| `http($target, $options)` | `POST /http` |
| `mtr($target, $options)` | `POST /mtr` |
| `report($uuid)` | `GET /report/{uuid}` |
| `ogImage($uuid)` | `GET /report/{uuid}/og-image` |
| `countryMap($uuid, $format, $resolution)` | `GET /report/{uuid}/country-map` |
| `ipIntel($ip)` | `GET /ip/{ip}` |
| `asnIntel($asn)` | `GET /as/{asn}` |
| `prefixIntel($net, $mask)` | `GET /prefix/{net}/{mask}` |
| `domainIntel($domain)` | `GET /domain/{domain}` |
| `certIntel($sha256)` | `GET /cert/{sha256}` |
| `portIntel($port)` | `GET /port/{port}` |
| `softwareIntel($name, $version)` | `GET /software/{name}[/{version}]` |
| `recentScans($target)` | `GET /scan/{target}` |
| `fullscan($target, $scope)` | `POST /fullscan` |
| `fullscanStatus($uuid)` | `GET /fullscan/{uuid}` |
| `fullscanResults($uuid)` | `GET /fullscan/{uuid}/results` |
| `waitForFullscan($uuid, $interval, $maxWait, $requireComplete)` | polls `GET /fullscan/{uuid}` |

## Development

```bash
php tests/test_unit.php   # offline unit tests (cURL stubbed, no network)
php tests/test_all.php    # live smoke test against the production API
```

## License

ISC License
