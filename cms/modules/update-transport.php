<?php
require_once __DIR__ . '/update-policy.php';

final class PagecoreUpdateResponse {
    public $ok;
    public $status;
    public $body;
    public $headers;
    public $error;
    public $bytes;
    public function __construct($ok, $status = 0, $body = '', array $headers = array(), $error = null, $bytes = 0) {
        $this->ok = (bool) $ok;
        $this->status = (int) $status;
        $this->body = $body;
        $this->headers = $headers;
        $this->error = $error;
        $this->bytes = (int) $bytes;
    }
}

/**
 * The only outbound HTTP in Pagecore. Every request is HTTPS, verified, size
 * capped, and confined to an allowlist of hosts that is re-checked on every
 * redirect hop. There is deliberately no option to relax any of that.
 */
final class PagecoreUpdateTransport {
    const MAX_REDIRECTS = 3;
    const CONNECT_TIMEOUT = 5;

    private $allowedHosts;
    private $timeout;
    private $maxBytes;
    private $userAgent;
    private $proxy;
    private $caBundle;
    private $fetcher;

    public function __construct(array $options = array()) {
        $this->allowedHosts = isset($options['allowed_hosts']) ? (array) $options['allowed_hosts'] : array();
        $this->timeout = isset($options['timeout']) ? max(1, (int) $options['timeout']) : 20;
        $this->maxBytes = isset($options['max_bytes']) ? max(1024, (int) $options['max_bytes']) : 1048576;
        $this->userAgent = isset($options['user_agent']) ? (string) $options['user_agent'] : 'Pagecore';
        $this->proxy = isset($options['proxy']) && is_string($options['proxy']) ? $options['proxy'] : '';
        // Names a CA bundle on hosts whose PHP ships without one. This selects
        // the trust store; it never disables verification.
        $this->caBundle = isset($options['ca_bundle']) && is_string($options['ca_bundle']) ? $options['ca_bundle'] : '';
        // Tests inject a fetcher so redirect and allowlist behaviour is verified without egress.
        $this->fetcher = isset($options['fetcher']) && is_callable($options['fetcher']) ? $options['fetcher'] : null;
    }

    public static function available() {
        return function_exists('curl_init') || filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Fetch a document into memory. $headers is a list of "Name: value" strings. */
    public function get($url, array $headers = array()) {
        return $this->request($url, $headers, null);
    }

    /** Stream a document to a file, refusing anything past the byte cap. */
    public function download($url, $destination, array $headers = array()) {
        $sink = @fopen($destination, 'w+b');
        if (!$sink) { return new PagecoreUpdateResponse(false, 0, '', array(), 'Could not open the download target.'); }
        $response = $this->request($url, $headers, $sink);
        fclose($sink);
        if (!$response->ok) { @unlink($destination); }
        return $response;
    }

    /** Resolve redirects manually so the host allowlist applies at every hop. */
    private function request($url, array $headers, $sink) {
        if (!self::available() && $this->fetcher === null) {
            return new PagecoreUpdateResponse(false, 0, '', array(), 'No HTTPS client is available: install curl or enable allow_url_fopen.');
        }
        $visited = array();
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (PagecoreUpdatePolicy::allowedHost($url, $this->allowedHosts) === null) {
                return new PagecoreUpdateResponse(false, 0, '', array(), 'Refused a URL outside the update host allowlist.');
            }
            if (isset($visited[$url])) {
                return new PagecoreUpdateResponse(false, 0, '', array(), 'The update host returned a redirect loop.');
            }
            $visited[$url] = true;

            $result = $this->perform($url, $headers, $sink);
            if (!empty($result['error'])) {
                return new PagecoreUpdateResponse(false, (int) $result['status'], '', array(), $result['error']);
            }
            $status = (int) $result['status'];
            $responseHeaders = isset($result['headers']) ? (array) $result['headers'] : array();
            if ($status === 301 || $status === 302 || $status === 303 || $status === 307 || $status === 308) {
                $location = isset($responseHeaders['location']) ? $responseHeaders['location'] : '';
                if ($location === '') {
                    return new PagecoreUpdateResponse(false, $status, '', $responseHeaders, 'The update host sent a redirect without a target.');
                }
                $url = $this->resolveLocation($url, $location);
                if ($sink) { ftruncate($sink, 0); rewind($sink); }
                continue;
            }
            $body = isset($result['body']) ? (string) $result['body'] : '';
            return new PagecoreUpdateResponse(
                $status >= 200 && $status < 300,
                $status,
                $body,
                $responseHeaders,
                $status >= 200 && $status < 300 ? null : 'The update host answered with HTTP ' . $status . '.',
                isset($result['bytes']) ? (int) $result['bytes'] : strlen($body)
            );
        }
        return new PagecoreUpdateResponse(false, 0, '', array(), 'The update host redirected too many times.');
    }

    /** Only absolute HTTPS targets are accepted; relative redirects are resolved against the origin. */
    private function resolveLocation($base, $location) {
        $location = trim((string) $location);
        if (preg_match('~^https?://~i', $location)) { return $location; }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) { return $location; }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
        if ($location === '' || $location[0] !== '/') {
            $path = isset($parts['path']) ? $parts['path'] : '/';
            $location = rtrim(substr($path, 0, (int) strrpos($path, '/')), '/') . '/' . $location;
        }
        return $origin . $location;
    }

    private function perform($url, array $headers, $sink) {
        if ($this->fetcher !== null) { return call_user_func($this->fetcher, $url, $headers, $sink); }
        return function_exists('curl_init')
            ? $this->performCurl($url, $headers, $sink)
            : $this->performStream($url, $headers, $sink);
    }

    private function performCurl($url, array $headers, $sink) {
        $handle = curl_init();
        $collected = array();
        $written = 0;
        $overflow = false;
        $buffer = '';
        curl_setopt_array($handle, array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => array_merge($headers, array('Accept-Encoding: identity')),
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_HTTPAUTH => CURLAUTH_NONE,
            CURLOPT_MAXFILESIZE => $this->maxBytes,
        ));
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'https');
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        curl_setopt($handle, CURLOPT_PROXY, $this->proxy);
        if ($this->caBundle !== '' && is_file($this->caBundle)) { curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle); }
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($resource, $line) use (&$collected) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) { $collected[strtolower(trim($parts[0]))] = trim($parts[1]); }
            return strlen($line);
        });
        // The counter is authoritative: CURLOPT_MAXFILESIZE only acts on an advertised length.
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, function ($resource, $chunk) use (&$written, &$overflow, &$buffer, $sink) {
            $length = strlen($chunk);
            if ($written + $length > $this->maxBytes) { $overflow = true; return 0; }
            $written += $length;
            if ($sink) {
                if (fwrite($sink, $chunk) === false) { return 0; }
            } else {
                $buffer .= $chunk;
            }
            return $length;
        });
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $number = curl_errno($handle);
        $error = $number !== 0 ? curl_error($handle) : null;
        if ($overflow) { return array('status' => $status, 'error' => 'The update download exceeded the configured size limit.'); }
        if ($error !== null && $status === 0) {
            return array('status' => 0, 'error' => self::describeCurlError($number, $error));
        }
        return array('status' => $status, 'headers' => $collected, 'body' => $buffer, 'bytes' => $written);
    }

    /**
     * A host with no CA bundle fails verification with an opaque message. That
     * failure is correct — verification is never relaxed — but the operator
     * needs to know it is a trust-store problem, not an unreachable server.
     */
    private static function describeCurlError($number, $message) {
        if (defined('CURLE_SSL_CACERT') && $number === CURLE_SSL_CACERT
            || defined('CURLE_PEER_FAILED_VERIFICATION') && $number === CURLE_PEER_FAILED_VERIFICATION
            || stripos($message, 'issuer certificate') !== false
            || stripos($message, 'certificate verify') !== false) {
            return 'The update host certificate could not be verified. This server has no usable CA bundle; '
                . 'set update_ca_bundle to the absolute path of one (for example /etc/ssl/certs/ca-certificates.crt). '
                . 'Details: ' . $message;
        }
        return 'Update request failed: ' . $message;
    }

    private function performStream($url, array $headers, $sink) {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => implode("\r\n", array_merge($headers, array('Accept-Encoding: identity', 'Connection: close'))),
                'user_agent' => $this->userAgent,
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'timeout' => $this->timeout,
                'protocol_version' => 1.1,
            ),
            'ssl' => array_merge(array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
            ), $this->caBundle !== '' && is_file($this->caBundle) ? array('cafile' => $this->caBundle) : array()),
        ));
        $stream = @fopen($url, 'rb', false, $context);
        if (!$stream) { return array('status' => 0, 'error' => 'Update request failed: the host could not be reached over HTTPS.'); }
        $meta = stream_get_meta_data($stream);
        $collected = array();
        $status = 0;
        foreach (isset($meta['wrapper_data']) ? (array) $meta['wrapper_data'] : array() as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match)) { $status = (int) $match[1]; continue; }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) { $collected[strtolower(trim($parts[0]))] = trim($parts[1]); }
        }
        $written = 0;
        $buffer = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) { break; }
            $written += strlen($chunk);
            if ($written > $this->maxBytes) {
                fclose($stream);
                return array('status' => $status, 'error' => 'The update download exceeded the configured size limit.');
            }
            if ($sink) { fwrite($sink, $chunk); } else { $buffer .= $chunk; }
        }
        fclose($stream);
        return array('status' => $status, 'headers' => $collected, 'body' => $buffer, 'bytes' => $written);
    }
}
