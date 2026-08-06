<?php

namespace CheckHostCc\CheckHostApi;

use CheckHostCc\CheckHostApi\Exceptions\CheckHostException;

class CheckHost
{
    private ?string $token;
    private string $baseUrl = 'https://api.check-host.cc';

    private const USER_AGENT = 'CheckHost-PHP-API/1.1.0';

    /** Scopes accepted by POST /fullscan. */
    private const FULLSCAN_SCOPES = ['basic', 'deep', 'full'];

    /** Fullscan statuses that mean the job will not progress further. */
    private const FULLSCAN_TERMINAL = ['complete', 'partial', 'failed'];

    /**
     * @param string|null $token API token (UUID). Sent as an
     *                           `Authorization: Bearer <token>` header on every
     *                           request. Falls back to the CHECK_HOST_API_TOKEN
     *                           environment variable (or the legacy
     *                           CHECK_HOST_API_KEY). Optional - without one you
     *                           get anonymous access under tighter rate limits.
     */
    public function __construct(?string $token = null)
    {
        if ($token === null) {
            $token = getenv('CHECK_HOST_API_TOKEN') ?: (getenv('CHECK_HOST_API_KEY') ?: null);
        }
        $this->token = $token ?: null;
    }

    /**
     * Set a custom base URL (e.g., for DEV server)
     */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Builds the outgoing header list. The token travels in the
     * Authorization header only - never in the URL or the request body.
     *
     * @return string[]
     */
    private function buildHeaders(string $accept, bool $jsonBody): array
    {
        $headers = [
            'Accept: ' . $accept,
            'User-Agent: ' . self::USER_AGENT,
        ];
        if ($jsonBody) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        return $headers;
    }

    /**
     * Performs the actual cURL call. Isolated so tests can subclass and
     * intercept it without touching the network.
     *
     * @param string[] $headers
     * @return array{body: string|false, error: string, status: int}
     */
    protected function execute(
        string $method,
        string $url,
        array $headers,
        ?string $body
    ): array {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        // Advertise every encoding libcurl can decode and let it inflate the
        // body transparently. The API edge gzips large JSON payloads (notably
        // /locations) whether or not we ask, which would otherwise reach
        // json_decode() as binary garbage.
        curl_setopt($ch, CURLOPT_ACCEPT_ENCODING, '');

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // No curl_close(): it has been a no-op since PHP 8.0 and is
        // deprecated in 8.5. The handle is freed when $ch goes out of scope.

        return ['body' => $response, 'error' => $error, 'status' => (int) $status];
    }

    /**
     * Executes an HTTP request to the API and decodes the JSON response.
     *
     * @param string $method HTTP method (GET, POST)
     * @param string $path Endpoint path
     * @param array $payload Optional JSON payload
     * @return array Response data decoded from JSON
     * @throws CheckHostException
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $headers = $this->buildHeaders('application/json', true);
        $body = $method === 'POST' ? json_encode($payload) : null;

        $result = $this->execute($method, $url, $headers, $body === false ? null : $body);
        $response = $result['body'];
        $httpCode = $result['status'];

        if ($response === false) {
            throw new CheckHostException("Network Error: " . $result['error']);
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $message = "API Error ($httpCode)";
            if (isset($data['message'])) {
                $message .= ": " . $data['message'];
            }
            elseif (isset($data['error'])) {
                $message .= ": " . $data['error'];
            }
            if ($httpCode === 429) {
                $message = "Ratelimit reached. Please supply an API token or slow down your requests.";
            }
            throw new CheckHostException($message, $httpCode);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CheckHostException("Invalid JSON response from server.");
        }

        return is_array($data) ? $data : ['data' => $data];
    }

    /**
     * Get Client IP
     *
     * @return array|string
     * @throws CheckHostException
     */
    public function myip()
    {
        return $this->request('GET', '/myip');
    }

    /**
     * Geolocation + ASN for the caller's own IP.
     *
     * Same response shape as info(), resolved against the requesting
     * client's IP. Subject to bot detection - repeated cache misses can
     * return a 429 carrying a captcha verification URL.
     *
     * @return array
     * @throws CheckHostException
     */
    public function myinfo(): array
    {
        return $this->request('GET', '/myinfo');
    }

    /**
     * Available Global Nodes
     *
     * @return array
     * @throws CheckHostException
     */
    public function locations(): array
    {
        return $this->request('GET', '/locations');
    }

    /**
     * Host/IP Information
     *
     * @param string $target The IP or Hostname to inspect
     * @return array
     * @throws CheckHostException
     */
    public function info(string $target): array
    {
        return $this->request('POST', '/info', ['target' => $target]);
    }

    /**
     * WHOIS Lookup
     *
     * @param string $target The IP or Hostname to query
     * @return array
     * @throws CheckHostException
     */
    public function whois(string $target): array
    {
        return $this->request('POST', '/whois', ['target' => $target]);
    }

    /**
     * ICMP Ping Check
     *
     * @param string $target
     * @param array $options ['region' => [], 'repeatchecks' => int, 'timeout' => int]
     * @return array
     * @throws CheckHostException
     */
    public function ping(string $target, array $options = []): array
    {
        $payload = array_merge(['target' => $target], $options);
        return $this->request('POST', '/ping', $payload);
    }

    /**
     * DNS Propagation Check
     *
     * @param string $target
     * @param array $options ['querymethod' => 'A', 'region' => []]
     * @return array
     * @throws CheckHostException
     */
    public function dns(string $target, array $options = []): array
    {
        $payload = array_merge(['target' => $target], $options);
        return $this->request('POST', '/dns', $payload);
    }

    /**
     * TCP Port Check
     *
     * @param string $target
     * @param int $port
     * @param array $options
     * @return array
     * @throws CheckHostException
     */
    public function tcp(string $target, int $port, array $options = []): array
    {
        $payload = array_merge(['target' => $target, 'port' => $port], $options);
        return $this->request('POST', '/tcp', $payload);
    }

    /**
     * UDP Port Check
     *
     * @param string $target
     * @param int $port
     * @param array $options ['payload' => 'hex...']
     * @return array
     * @throws CheckHostException
     */
    public function udp(string $target, int $port, array $options = []): array
    {
        $payload = array_merge(['target' => $target, 'port' => $port], $options);
        return $this->request('POST', '/udp', $payload);
    }

    /**
     * HTTP Performance Check
     *
     * @param string $target
     * @param array $options
     * @return array
     * @throws CheckHostException
     */
    public function http(string $target, array $options = []): array
    {
        $payload = array_merge(['target' => $target], $options);
        return $this->request('POST', '/http', $payload);
    }

    /**
     * MTR (My Traceroute)
     *
     * @param string $target
     * @param array $options
     * @return array
     * @throws CheckHostException
     */
    public function mtr(string $target, array $options = []): array
    {
        $payload = array_merge(['target' => $target], $options);
        return $this->request('POST', '/mtr', $payload);
    }

    /**
     * Retrieve Check Results
     *
     * @param string $uuid
     * @return array
     * @throws CheckHostException
     */
    public function report(string $uuid): array
    {
        return $this->request('GET', '/report/' . rawurlencode($uuid));
    }

    // ------------------------------------------------------------------
    // Network Intelligence
    // ------------------------------------------------------------------

    /**
     * Full intelligence profile for a single IPv4/IPv6 address.
     *
     * Sections: ptr, open_ports, banners, tls_certs, co_hosted_domains,
     * external_refs, leak_candidates, titles, techs, bgp, geo,
     * probe_findings, threat_matches, threat_count, honeypot,
     * honeypot_recent, honeypot_actor, honeypot_ja, honeypot_classes.
     *
     * Honeypot passwords are never returned in cleartext - each entry
     * exposes only `password_captured` (bool) and `password_len`.
     *
     * @param string $ip
     * @return array
     * @throws CheckHostException
     */
    public function ipIntel(string $ip): array
    {
        if (trim($ip) === '') {
            throw new CheckHostException('IP is required for an IP intelligence lookup.');
        }
        return $this->request('GET', '/ip/' . rawurlencode(trim($ip)));
    }

    /**
     * Autonomous-system intelligence: prefix counts, announced IP totals,
     * peers / providers / customers, IXP memberships, RPKI coverage,
     * GeoIP footprint, top ports and hosted-domain summaries.
     *
     * @param int|string $asn `13335` or `'AS13335'`.
     * @return array
     * @throws CheckHostException
     */
    public function asnIntel(int|string $asn): array
    {
        return $this->request('GET', '/as/' . $this->normaliseAsn($asn));
    }

    /**
     * CIDR prefix intelligence: BGP origin, RPKI validity, GeoIP
     * distribution, open-IP count, top ports and sample scanned hosts.
     *
     * @param string $net  Network address, e.g. '1.1.1.0'.
     * @param int    $mask Prefix length, 0-128.
     * @return array
     * @throws CheckHostException
     */
    public function prefixIntel(string $net, int $mask): array
    {
        if (trim($net) === '') {
            throw new CheckHostException('Network address is required for a prefix lookup.');
        }
        if ($mask < 0 || $mask > 128) {
            throw new CheckHostException("mask must be between 0 and 128, got '$mask'.");
        }
        return $this->request('GET', '/prefix/' . rawurlencode(trim($net)) . '/' . $mask);
    }

    /**
     * Domain intelligence: current DNS records plus passive-DNS history,
     * TLS certificates, CT-log evidence, discovered subdomains,
     * tech-stack and origin-leak (Cloudflare-bypass) candidates.
     *
     * @param string $domain
     * @return array
     * @throws CheckHostException
     */
    public function domainIntel(string $domain): array
    {
        if (trim($domain) === '') {
            throw new CheckHostException('Domain is required for a domain intelligence lookup.');
        }
        return $this->request('GET', '/domain/' . rawurlencode(trim($domain)));
    }

    /**
     * TLS certificate intelligence: subject, issuer, SANs, validity
     * window, every (ip, port) observed serving it, and CT-log entries.
     *
     * @param string $sha256 64-character hex fingerprint.
     * @return array
     * @throws CheckHostException
     */
    public function certIntel(string $sha256): array
    {
        $normalised = strtolower(trim($sha256));
        if (preg_match('/^[a-f0-9]{64}$/', $normalised) !== 1) {
            throw new CheckHostException('sha256 must be 64 hexadecimal characters.');
        }
        return $this->request('GET', '/cert/' . $normalised);
    }

    /**
     * Port exposure across the scanned Internet: open-IP count, most
     * common banners, top countries and ASNs, tech-stack and a sample of
     * recent hosts.
     *
     * @param int $port 1-65535.
     * @return array
     * @throws CheckHostException
     */
    public function portIntel(int $port): array
    {
        if ($port < 1 || $port > 65535) {
            throw new CheckHostException("port must be between 1 and 65535, got '$port'.");
        }
        return $this->request('GET', '/port/' . $port);
    }

    /**
     * Software / tech-stack intelligence: host counts for a detected
     * technology, version breakdown, categories and a sample of hosts.
     *
     * @param string      $name    Technology name, e.g. 'nginx'.
     * @param string|null $version Pin the stats to a single version.
     * @return array
     * @throws CheckHostException
     */
    public function softwareIntel(string $name, ?string $version = null): array
    {
        if (trim($name) === '') {
            throw new CheckHostException('Software name is required for a software lookup.');
        }
        $path = '/software/' . rawurlencode(trim($name));
        if ($version !== null && trim($version) !== '') {
            $path .= '/' . rawurlencode(trim($version));
        }
        return $this->request('GET', $path);
    }

    /**
     * Most-recent fullscan jobs submitted for a target, so you can
     * deep-link to a fresh report instead of triggering a redundant scan.
     *
     * @param string $target IP, CIDR, domain or ASN.
     * @return array
     * @throws CheckHostException
     */
    public function recentScans(string $target): array
    {
        if (trim($target) === '') {
            throw new CheckHostException('Target is required to list recent scans.');
        }
        return $this->request('GET', '/scan/' . rawurlencode(trim($target)));
    }

    // ------------------------------------------------------------------
    // Fullscan
    // ------------------------------------------------------------------

    /**
     * Dispatches a deep, multi-stage scan (ports + banners + TLS + DNS +
     * threat-intel) of an IP, CIDR, domain or ASN.
     *
     * Returns immediately with `status = pending`. Poll fullscanStatus()
     * for progress, or use waitForFullscan().
     *
     * Anonymous CIDR submissions are capped at /24 (v4) and /120 (v6); an
     * API token raises that to /20 and /112.
     *
     * @param string $target IPv4/IPv6 address, CIDR block, domain or AS number.
     * @param string $scope  'basic' = top-100 ports + banner; 'deep'
     *                       (default) = full port range + TLS + body +
     *                       threat-intel; 'full' = deep plus subdomain
     *                       enumeration (domains only).
     * @return array The job row.
     * @throws CheckHostException
     */
    public function fullscan(string $target, string $scope = 'deep'): array
    {
        if (trim($target) === '') {
            throw new CheckHostException('Target is required to submit a fullscan.');
        }
        $scope = strtolower(trim($scope));
        if (!in_array($scope, self::FULLSCAN_SCOPES, true)) {
            throw new CheckHostException(
                'scope must be one of ' . implode(', ', self::FULLSCAN_SCOPES) . ", got '$scope'."
            );
        }
        return $this->request('POST', '/fullscan', [
            'target' => trim($target),
            'scope'  => $scope,
        ]);
    }

    /**
     * Polls a fullscan's progress counters.
     *
     * @param string $uuid
     * @return array `['success' => bool, 'job' => [...]]`.
     * @throws CheckHostException
     */
    public function fullscanStatus(string $uuid): array
    {
        if (trim($uuid) === '') {
            throw new CheckHostException('UUID is required to poll a fullscan.');
        }
        return $this->request('GET', '/fullscan/' . rawurlencode(trim($uuid)));
    }

    /**
     * Fetches the aggregated findings a fullscan produced - open ports,
     * banners, DNS records, BGP context and TLS certificates. Partial
     * results are available while the job is still running.
     *
     * @param string $uuid
     * @return array
     * @throws CheckHostException
     */
    public function fullscanResults(string $uuid): array
    {
        if (trim($uuid) === '') {
            throw new CheckHostException('UUID is required to fetch fullscan results.');
        }
        return $this->request('GET', '/fullscan/' . rawurlencode(trim($uuid)) . '/results');
    }

    /**
     * True once a job row has reached a terminal status.
     *
     * @param array $job A job row from fullscan() / fullscanStatus().
     */
    public static function isFullscanFinished(array $job): bool
    {
        return in_array(strtolower((string) ($job['status'] ?? '')), self::FULLSCAN_TERMINAL, true);
    }

    /**
     * Polls /fullscan/{uuid} until the job reaches a terminal status.
     *
     * Fullscans are far slower than node checks - budget minutes, not
     * seconds - so the defaults are patient.
     *
     * @param string $uuid
     * @param int    $interval        Seconds between polls (minimum 1).
     * @param int    $maxWait         Total seconds to wait.
     * @param bool   $requireComplete Throw when the deadline passes while the
     *                                job is still pending/running. Pass false
     *                                to return the latest job row instead.
     * @return array The job row.
     * @throws CheckHostException
     */
    public function waitForFullscan(
        string $uuid,
        int $interval = 3,
        int $maxWait = 300,
        bool $requireComplete = true
    ): array {
        if (trim($uuid) === '') {
            throw new CheckHostException('UUID is required to poll a fullscan.');
        }
        $interval = max($interval, 1);
        $deadline = time() + $maxWait;

        $job = $this->fullscanStatus($uuid)['job'] ?? [];
        if (self::isFullscanFinished($job)) {
            return $job;
        }

        while (true) {
            $remaining = $deadline - time();
            if ($remaining <= 0) {
                break;
            }
            sleep(min($interval, $remaining));
            $job = $this->fullscanStatus($uuid)['job'] ?? [];
            if (self::isFullscanFinished($job)) {
                return $job;
            }
        }

        if ($requireComplete) {
            throw new CheckHostException(sprintf(
                'Fullscan %s not finished after %ds (status=%s, %s/%s sub-jobs).',
                $uuid,
                $maxWait,
                $job['status'] ?? 'unknown',
                $job['subjobs_done'] ?? '?',
                $job['subjobs_total'] ?? '?'
            ));
        }
        return $job;
    }

    // ------------------------------------------------------------------
    // Images
    // ------------------------------------------------------------------

    /**
     * Fetches the dynamic 1200x630 PNG status map for a check UUID.
     *
     * Returns raw PNG bytes. Use `file_put_contents($path, $bytes)` to
     * save to disk.
     *
     * @throws CheckHostException
     */
    public function ogImage(string $uuid): string
    {
        return $this->requestBinary(
            '/report/' . rawurlencode($uuid) . '/og-image',
            'image/png'
        );
    }

    /**
     * Fetches the per-country world map for a check UUID. Default
     * format is SVG; pass 'png' with a resolution for the rasterised
     * variant.
     *
     * @param string $uuid
     * @param string $format     'svg' (default) or 'png'.
     * @param string $resolution PNG resolution: 'low' (800px),
     *                           'med' (1200px), or 'high' (2000px).
     *                           Ignored for SVG.
     * @return string Raw image bytes (UTF-8 text for SVG, binary for PNG).
     * @throws CheckHostException
     */
    public function countryMap(
        string $uuid,
        string $format = 'svg',
        string $resolution = 'med'
    ): string {
        if (!in_array($format, ['svg', 'png'], true)) {
            throw new CheckHostException(
                "format must be 'svg' or 'png', got '$format'."
            );
        }
        if (!in_array($resolution, ['low', 'med', 'high'], true)) {
            throw new CheckHostException(
                "resolution must be 'low', 'med', or 'high', got '$resolution'."
            );
        }
        $query = http_build_query(['format' => $format, 'res' => $resolution]);
        $accept = $format === 'png' ? 'image/png' : 'image/svg+xml';
        return $this->requestBinary(
            '/report/' . rawurlencode($uuid) . '/country-map?' . $query,
            $accept
        );
    }

    /**
     * Issues a GET request and returns the raw response body without
     * trying to JSON-decode it. Used for binary endpoints (og-image,
     * country-map).
     *
     * @throws CheckHostException
     */
    private function requestBinary(string $path, string $accept): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $result = $this->execute('GET', $url, $this->buildHeaders($accept, false), null);

        if ($result['body'] === false) {
            throw new CheckHostException("Network Error: " . $result['error']);
        }
        if ($result['status'] >= 400) {
            $msg = $result['status'] === 429
                ? "Ratelimit reached. Please supply an API token or slow down your requests."
                : "API Error ({$result['status']})";
            throw new CheckHostException($msg, $result['status']);
        }
        return $result['body'];
    }

    /**
     * Normalises an AS number to its bare decimal form.
     * Accepts 13335, '13335' and 'AS13335'.
     *
     * @throws CheckHostException
     */
    private function normaliseAsn(int|string $asn): string
    {
        if (is_int($asn)) {
            if ($asn < 0) {
                throw new CheckHostException("asn must be >= 0, got '$asn'.");
            }
            return (string) $asn;
        }
        if (preg_match('/^(?:AS)?(\d+)$/i', trim($asn), $matches) !== 1) {
            throw new CheckHostException("asn must look like '13335' or 'AS13335', got '$asn'.");
        }
        return $matches[1];
    }
}
