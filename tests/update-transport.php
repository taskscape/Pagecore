<?php

require dirname(__DIR__) . '/cms/modules/update-transport.php';

$failures = array();
function transport_check($condition, $message) { global $failures; if (!$condition) { $failures[] = $message; } }

$hosts = array('raw.githubusercontent.com', 'github.com', 'objects.githubusercontent.com');
$requested = array();

/** Scripted responses keyed by URL, so redirect handling is exercised without egress. */
function transport_client(array $routes, array $hosts, &$requested) {
    $requested = array();
    return new PagecoreUpdateTransport(array(
        'allowed_hosts' => $hosts,
        'max_bytes' => 65536,
        'fetcher' => function ($url, $headers, $sink) use ($routes, &$requested) {
            $requested[] = $url;
            if (!isset($routes[$url])) { return array('status' => 404, 'headers' => array(), 'body' => ''); }
            $route = $routes[$url];
            if (isset($route['error'])) { return array('status' => 0, 'error' => $route['error']); }
            $body = isset($route['body']) ? $route['body'] : '';
            if ($sink && $body !== '') { fwrite($sink, $body); }
            return array(
                'status' => $route['status'],
                'headers' => isset($route['headers']) ? $route['headers'] : array(),
                'body' => $sink ? '' : $body,
                'bytes' => strlen($body),
            );
        },
    ));
}

$feedUrl = 'https://raw.githubusercontent.com/taskscape/Pagecore/main/release/latest.json';

/* ---------------------------------------------------------- happy path */
$client = transport_client(array($feedUrl => array('status' => 200, 'body' => '{"schema":1}', 'headers' => array('etag' => 'W/"abc"'))), $hosts, $requested);
$response = $client->get($feedUrl);
transport_check($response->ok && $response->body === '{"schema":1}', 'A valid response was not returned.');
transport_check($response->headers['etag'] === 'W/"abc"', 'Response headers were not exposed to the caller.');

/* ------------------------------------------------- allowlist enforcement */
$client = transport_client(array(), $hosts, $requested);
$response = $client->get('https://evil.test/latest.json');
transport_check(!$response->ok, 'A request to an unlisted host succeeded.');
transport_check($requested === array(), 'An unlisted host was contacted before the allowlist was applied.');

$client = transport_client(array(), $hosts, $requested);
$response = $client->get('http://raw.githubusercontent.com/latest.json');
transport_check(!$response->ok && $requested === array(), 'A plain HTTP request was attempted.');

/* ------------------------------------------------------------ redirects */
// GitHub sends release downloads to objects.githubusercontent.com.
$assetUrl = 'https://github.com/taskscape/Pagecore/releases/download/build-abc/pagecore.zip';
$objectUrl = 'https://objects.githubusercontent.com/blob/pagecore.zip';
$client = transport_client(array(
    $assetUrl => array('status' => 302, 'headers' => array('location' => $objectUrl)),
    $objectUrl => array('status' => 200, 'body' => 'ARCHIVE'),
), $hosts, $requested);
$response = $client->get($assetUrl);
transport_check($response->ok && $response->body === 'ARCHIVE', 'An allowlisted redirect was not followed.');
transport_check(count($requested) === 2, 'The redirect target was not requested.');

$client = transport_client(array(
    $assetUrl => array('status' => 302, 'headers' => array('location' => 'https://evil.test/payload.zip')),
), $hosts, $requested);
$response = $client->get($assetUrl);
transport_check(!$response->ok, 'A redirect off the allowlist was followed.');
transport_check(count($requested) === 1, 'An off-allowlist redirect target was contacted.');

$client = transport_client(array(
    $assetUrl => array('status' => 302, 'headers' => array('location' => $assetUrl)),
), $hosts, $requested);
transport_check(!$client->get($assetUrl)->ok, 'A redirect loop was not detected.');

$chain = array();
for ($i = 0; $i < 6; $i++) {
    $chain['https://github.com/hop' . $i] = array('status' => 302, 'headers' => array('location' => 'https://github.com/hop' . ($i + 1)));
}
$client = transport_client($chain, $hosts, $requested);
transport_check(!$client->get('https://github.com/hop0')->ok, 'An unbounded redirect chain was followed.');
transport_check(count($requested) <= PagecoreUpdateTransport::MAX_REDIRECTS + 1, 'More hops were attempted than the limit allows.');

// A relative Location resolves against the current origin, never off it.
$client = transport_client(array(
    'https://github.com/a/b.zip' => array('status' => 302, 'headers' => array('location' => '/c/d.zip')),
    'https://github.com/c/d.zip' => array('status' => 200, 'body' => 'RELATIVE'),
), $hosts, $requested);
transport_check($client->get('https://github.com/a/b.zip')->body === 'RELATIVE', 'A relative redirect was not resolved against its origin.');

$client = transport_client(array(
    $assetUrl => array('status' => 302, 'headers' => array()),
), $hosts, $requested);
transport_check(!$client->get($assetUrl)->ok, 'A redirect without a target was accepted.');

/* -------------------------------------------------------- status handling */
$client = transport_client(array($feedUrl => array('status' => 404, 'body' => '')), $hosts, $requested);
$response = $client->get($feedUrl);
transport_check(!$response->ok && $response->status === 404, 'A 404 was not surfaced as a failure.');

$client = transport_client(array($feedUrl => array('status' => 304, 'headers' => array())), $hosts, $requested);
transport_check($client->get($feedUrl)->status === 304, 'A conditional 304 was not reported to the caller.');

$client = transport_client(array($feedUrl => array('error' => 'TLS handshake failed')), $hosts, $requested);
$response = $client->get($feedUrl);
transport_check(!$response->ok && strpos((string) $response->error, 'TLS handshake failed') !== false, 'A transport error was not propagated.');

/* -------------------------------------------------------------- downloads */
$target = tempnam(sys_get_temp_dir(), 'pagecore-transport-');
$client = transport_client(array($objectUrl => array('status' => 200, 'body' => 'BINARY-PAYLOAD')), $hosts, $requested);
$response = $client->download($objectUrl, $target);
transport_check($response->ok && file_get_contents($target) === 'BINARY-PAYLOAD', 'A download did not reach the target file.');

$client = transport_client(array(), $hosts, $requested);
$response = $client->download('https://evil.test/x.zip', $target);
transport_check(!$response->ok && !is_file($target), 'A refused download left a file behind.');
@unlink($target);

if ($failures) { fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL); exit(1); }
fwrite(STDOUT, "PASS: update transport enforces HTTPS, the host allowlist, and bounded redirects on every hop\n");
