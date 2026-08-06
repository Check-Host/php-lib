<?php

/**
 * Offline unit tests. The cURL layer is stubbed out via a subclass, so
 * nothing here touches the network - these run deterministically in CI.
 *
 *   php tests/test_unit.php
 */

require_once __DIR__ . '/../src/Exceptions/CheckHostException.php';
require_once __DIR__ . '/../src/CheckHost.php';

use CheckHostCc\CheckHostApi\CheckHost;
use CheckHostCc\CheckHostApi\Exceptions\CheckHostException;

/**
 * Records every request instead of issuing it, and replays a canned body.
 */
final class RecordingCheckHost extends CheckHost
{
    /** @var array<int, array{method: string, url: string, headers: string[], body: ?string}> */
    public array $calls = [];

    public string $responseBody = '{"ok":true}';
    public int $responseStatus = 200;

    protected function execute(
        string $method,
        string $url,
        array $headers,
        ?string $body
    ): array {
        $this->calls[] = [
            'method'  => $method,
            'url'     => $url,
            'headers' => $headers,
            'body'    => $body,
        ];
        return [
            'body'   => $this->responseBody,
            'error'  => '',
            'status' => $this->responseStatus,
        ];
    }

    public function lastCall(): array
    {
        return $this->calls[count($this->calls) - 1];
    }

    public function lastHeader(string $prefix): ?string
    {
        foreach ($this->lastCall()['headers'] as $header) {
            if (str_starts_with($header, $prefix)) {
                return $header;
            }
        }
        return null;
    }
}

$passed = 0;
$failed = 0;

function check(string $label, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  PASS  $label\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  FAIL  $label\n        {$e->getMessage()}\n";
        $failed++;
    }
}

function assertSame_(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            '%sexpected %s, got %s',
            $what ? "$what: " : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertThrows(string $needle, callable $fn): void
{
    try {
        $fn();
    } catch (CheckHostException $e) {
        if (!str_contains($e->getMessage(), $needle)) {
            throw new \RuntimeException("expected message containing '$needle', got '{$e->getMessage()}'");
        }
        return;
    }
    throw new \RuntimeException("expected a CheckHostException containing '$needle', none thrown");
}

// Keep an ambient CI token out of the anonymous-path assertions.
putenv('CHECK_HOST_API_TOKEN');
putenv('CHECK_HOST_API_KEY');

echo "Authentication\n";

check('token is sent as an Authorization: Bearer header', function () {
    $api = new RecordingCheckHost('tok-123');
    $api->ping('1.1.1.1');
    assertSame_('Authorization: Bearer tok-123', $api->lastHeader('Authorization'));
});

check('token never reaches the request body', function () {
    $api = new RecordingCheckHost('tok-123');
    $api->ping('1.1.1.1', ['region' => ['DE']]);
    $body = json_decode($api->lastCall()['body'], true);
    assertSame_(['target' => '1.1.1.1', 'region' => ['DE']], $body);
    assertSame_(null, $body['apikey'] ?? null, 'apikey must not be present');
});

check('token never reaches the URL', function () {
    $api = new RecordingCheckHost('tok-123');
    $api->locations();
    if (str_contains($api->lastCall()['url'], 'tok-123')) {
        throw new \RuntimeException('token leaked into the URL');
    }
});

check('GET requests are authenticated too', function () {
    $api = new RecordingCheckHost('tok-123');
    $api->ipIntel('1.1.1.1');
    assertSame_('Authorization: Bearer tok-123', $api->lastHeader('Authorization'));
});

check('binary requests are authenticated too', function () {
    $api = new RecordingCheckHost('tok-123');
    $api->ogImage('uuid-1');
    assertSame_('Authorization: Bearer tok-123', $api->lastHeader('Authorization'));
    assertSame_('Accept: image/png', $api->lastHeader('Accept'));
});

check('anonymous clients send no Authorization header', function () {
    $api = new RecordingCheckHost();
    $api->ping('1.1.1.1');
    assertSame_(null, $api->lastHeader('Authorization'));
});

check('CHECK_HOST_API_TOKEN is read from the environment', function () {
    putenv('CHECK_HOST_API_TOKEN=env-token');
    try {
        $api = new RecordingCheckHost();
        $api->myip();
        assertSame_('Authorization: Bearer env-token', $api->lastHeader('Authorization'));
    } finally {
        putenv('CHECK_HOST_API_TOKEN');
    }
});

check('legacy CHECK_HOST_API_KEY is still honoured', function () {
    putenv('CHECK_HOST_API_KEY=legacy-token');
    try {
        $api = new RecordingCheckHost();
        $api->myip();
        assertSame_('Authorization: Bearer legacy-token', $api->lastHeader('Authorization'));
    } finally {
        putenv('CHECK_HOST_API_KEY');
    }
});

echo "\nIntelligence endpoints\n";

$intelCases = [
    ['ipIntel',       ['1.1.1.1'],            '/ip/1.1.1.1'],
    ['asnIntel',      ['AS13335'],            '/as/13335'],
    ['asnIntel',      [13335],                '/as/13335'],
    ['asnIntel',      ['13335'],              '/as/13335'],
    ['prefixIntel',   ['1.1.1.0', 24],        '/prefix/1.1.1.0/24'],
    ['domainIntel',   ['check-host.cc'],      '/domain/check-host.cc'],
    ['certIntel',     [str_repeat('A', 64)],  '/cert/' . str_repeat('a', 64)],
    ['portIntel',     [443],                  '/port/443'],
    ['softwareIntel', ['nginx'],              '/software/nginx'],
    ['softwareIntel', ['nginx', '1.24.0'],    '/software/nginx/1.24.0'],
    ['recentScans',   ['check-host.cc'],      '/scan/check-host.cc'],
];

foreach ($intelCases as [$method, $args, $expectedPath]) {
    check("$method() -> $expectedPath", function () use ($method, $args, $expectedPath) {
        $api = new RecordingCheckHost();
        $api->responseBody = '{"success":true}';
        $api->{$method}(...$args);
        assertSame_('https://api.check-host.cc' . $expectedPath, $api->lastCall()['url']);
        assertSame_('GET', $api->lastCall()['method']);
    });
}

check('domainIntel() percent-encodes the path segment', function () {
    $api = new RecordingCheckHost();
    $api->responseBody = '{"success":true}';
    $api->domainIntel('a b.example');
    assertSame_('https://api.check-host.cc/domain/a%20b.example', $api->lastCall()['url']);
});

check('intelligence endpoints reject malformed input', function () {
    $api = new RecordingCheckHost();
    assertThrows('IP is required',            fn() => $api->ipIntel('  '));
    assertThrows('asn must look like',        fn() => $api->asnIntel('not-an-asn'));
    assertThrows('mask must be',              fn() => $api->prefixIntel('1.1.1.0', 129));
    assertThrows('Network address is required', fn() => $api->prefixIntel(' ', 24));
    assertThrows('64 hexadecimal',            fn() => $api->certIntel('deadbeef'));
    assertThrows('between 1 and 65535',       fn() => $api->portIntel(70000));
    assertThrows('Software name is required', fn() => $api->softwareIntel(''));
    assertThrows('Target is required',        fn() => $api->recentScans(''));
});

echo "\nFullscan\n";

check('fullscan() posts target and scope', function () {
    $api = new RecordingCheckHost();
    $api->responseBody = '{"success":true,"uuid":"scan-1"}';
    $api->fullscan('check-host.cc', 'full');
    assertSame_('https://api.check-host.cc/fullscan', $api->lastCall()['url']);
    assertSame_('POST', $api->lastCall()['method']);
    assertSame_(
        ['target' => 'check-host.cc', 'scope' => 'full'],
        json_decode($api->lastCall()['body'], true)
    );
});

check('fullscan() defaults to the deep scope', function () {
    $api = new RecordingCheckHost();
    $api->responseBody = '{"success":true}';
    $api->fullscan('check-host.cc');
    assertSame_('deep', json_decode($api->lastCall()['body'], true)['scope']);
});

check('fullscan() rejects an unknown scope and an empty target', function () {
    $api = new RecordingCheckHost();
    assertThrows('scope must be one of', fn() => $api->fullscan('x', 'turbo'));
    assertThrows('Target is required',   fn() => $api->fullscan(' '));
});

check('fullscanStatus() and fullscanResults() build the documented paths', function () {
    $api = new RecordingCheckHost();
    $api->responseBody = '{"success":true,"job":{}}';
    $api->fullscanStatus('scan-1');
    assertSame_('https://api.check-host.cc/fullscan/scan-1', $api->lastCall()['url']);

    $api->responseBody = '{"success":true,"data":{}}';
    $api->fullscanResults('scan-1');
    assertSame_('https://api.check-host.cc/fullscan/scan-1/results', $api->lastCall()['url']);
});

check('isFullscanFinished() recognises terminal statuses', function () {
    assertSame_(true,  CheckHost::isFullscanFinished(['status' => 'complete']));
    assertSame_(true,  CheckHost::isFullscanFinished(['status' => 'partial']));
    assertSame_(true,  CheckHost::isFullscanFinished(['status' => 'failed']));
    assertSame_(false, CheckHost::isFullscanFinished(['status' => 'pending']));
    assertSame_(false, CheckHost::isFullscanFinished(['status' => 'running']));
    assertSame_(false, CheckHost::isFullscanFinished([]));
});

check('waitForFullscan() returns immediately for a terminal job', function () {
    $api = new RecordingCheckHost();
    $api->responseBody = '{"success":true,"job":{"uuid":"scan-1","status":"complete"}}';
    $job = $api->waitForFullscan('scan-1');
    assertSame_('complete', $job['status']);
    assertSame_(1, count($api->calls));
});

check('waitForFullscan() throws once the deadline passes', function () {
    $api = new RecordingCheckHost();
    $api->responseBody = '{"success":true,"job":{"status":"running","subjobs_done":1,"subjobs_total":5}}';
    assertThrows('not finished after 0s', fn() => $api->waitForFullscan('scan-1', 1, 0));
});

check('waitForFullscan() can return a non-terminal job instead', function () {
    $api = new RecordingCheckHost();
    $api->responseBody = '{"success":true,"job":{"status":"running"}}';
    $job = $api->waitForFullscan('scan-1', 1, 0, false);
    assertSame_('running', $job['status']);
});

echo "\nOther endpoints\n";

check('myinfo() hits the documented path', function () {
    $api = new RecordingCheckHost();
    $api->responseBody = '{"ip":"1.2.3.4"}';
    $api->myinfo();
    assertSame_('https://api.check-host.cc/myinfo', $api->lastCall()['url']);
});

check('setBaseUrl() overrides the host and trims trailing slashes', function () {
    $api = new RecordingCheckHost();
    $api->setBaseUrl('https://example.com/api/');
    $api->myip();
    assertSame_('https://example.com/api/myip', $api->lastCall()['url']);
});

check('non-2xx responses raise a CheckHostException carrying the status', function () {
    $api = new RecordingCheckHost();
    $api->responseStatus = 429;
    $api->responseBody = '{"success":false,"error":"slow down"}';
    try {
        $api->ping('1.1.1.1');
    } catch (CheckHostException $e) {
        assertSame_(429, $e->getCode());
        return;
    }
    throw new \RuntimeException('expected a CheckHostException');
});

check('4xx bodies surface the API error message', function () {
    $api = new RecordingCheckHost();
    $api->responseStatus = 422;
    $api->responseBody = '{"success":false,"error":"The target field is required."}';
    assertThrows('The target field is required.', fn() => $api->ping('1.1.1.1'));
});

echo "\n";
echo "$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
