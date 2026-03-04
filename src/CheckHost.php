<?php

namespace CheckHostCc\CheckHostApi;

use CheckHostCc\CheckHostApi\Exceptions\CheckHostException;

class CheckHost
{
    private ?string $apiKey;
    private string $baseUrl = 'https://api.check-host.cc';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Set a custom base URL (e.g., for DEV server)
     */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Executes an HTTP request to the API using cURL.
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

        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'User-Agent: CheckHost-PHP-API/1.0.0'
        ];

        // Insert API key if available
        if ($this->apiKey !== null && $method === 'POST') {
            $payload['apikey'] = $this->apiKey;
        }

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds timeout

        // Handle API key for GET requests via query params (e.g., report)
        if ($method === 'GET' && $this->apiKey !== null && !empty($path)) {
        // Usually not needed for public GET, but if rate-limited, can be attached
        // As per swagger, GET /myip, /locations, /report/{uuid} don't explicitly require apikey in swagger,
        // but we can append it just in case or leave it. We will leave it.
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($response === false) {
            throw new CheckHostException("Network Error: " . $error);
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
                $message = "Ratelimit reached. Please provide an API key or slow down your requests.";
            }
            throw new CheckHostException($message, $httpCode);
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CheckHostException("Invalid JSON response from server.");
        }

        return $data;
    }

    /**
     * Get Client IP
     *
     * @return array|string
     * @throws CheckHostException
     */
    public function myip()
    {
        $data = $this->request('GET', '/myip');
        // The endpoint likely returns a direct IP or a JSON with IP
        // Swagger: '200': description: Your public IP address.
        // Assuming it's typically a direct string or JSON object. Let's return $data.
        return $data;
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
        return $this->request('GET', '/report/' . urlencode($uuid));
    }
}
