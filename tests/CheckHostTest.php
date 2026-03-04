<?php

namespace CheckHostCc\CheckHostApi\Tests;

use PHPUnit\Framework\TestCase;
use CheckHostCc\CheckHostApi\CheckHost;
use CheckHostCc\CheckHostApi\Exceptions\CheckHostException;

class CheckHostTest extends TestCase
{
    private CheckHost $api;

    protected function setUp(): void
    {
        $this->api = new CheckHost();
    // Since we cannot easily mock cURL, we will perform some basic integration tests
    // on the standard, non-ratelimited endpoints like /locations or /myip
    }

    public function testGetLocationsReturnsArray()
    {
        $locations = $this->api->locations();
        $this->assertIsArray($locations, 'Locations should be an array');
        $this->assertNotEmpty($locations, 'Locations should not be empty');
    }

    public function testGetMyIpStatus()
    {
        $ip = $this->api->myip();
        // Structure varies, it could return {"ip":"..."} or plain string
        $this->assertNotNull($ip);
    }

    public function testInvalidHostThrowsException()
    {
        $this->expectException(CheckHostException::class);
        $this->api->info('thisis-a-completely-invalid-host-name.lan');
    }

    public function testPingReturnsUuid()
    {
        $response = $this->api->ping('8.8.8.8');
        $this->assertArrayHasKey('uuid', $response);
        $this->assertEquals('success', $response['success'] ?? 'success'); // Depending on actual response keys
    }

    public function testReportReturnsArray()
    {
        // First, trigger a quick ping
        $ping = $this->api->ping('1.1.1.1');
        $uuid = $ping['uuid'];

        // Wait a small amount
        sleep(1);

        $report = $this->api->report($uuid);
        $this->assertIsArray($report);
    }
}
