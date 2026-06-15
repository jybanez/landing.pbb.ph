<?php

class PbbLanding_Gateway
{
    private $config;
    private $logPath;

    public function __construct(array $config, $logPath)
    {
        $this->config = $config;
        $this->logPath = (string) $logPath;
    }

    public function matches($path, array $registry)
    {
        return $this->matchingApp((string) $path, $registry);
    }

    public function allowedPeer(PbbLanding_Request $request, array $allowedDomains)
    {
        if (empty($this->config['require_peer_domain'])) {
            return true;
        }

        $peer = $this->peerDomain($request);
        if ($peer === '') {
            return false;
        }

        return in_array($peer, $allowedDomains, true);
    }

    public function peerDomain(PbbLanding_Request $request)
    {
        foreach (array('X-PBB-Peer-Domain', 'X-Forwarded-Host') as $header) {
            $value = $request->header($header);
            if (is_string($value) && trim($value) !== '') {
                $first = trim(explode(',', $value)[0]);
                return PbbLanding_Host::normalize($first);
            }
        }

        foreach (array('Origin', 'Referer') as $header) {
            $value = $request->header($header);
            if (is_string($value) && trim($value) !== '') {
                return PbbLanding_Host::fromUrl($value);
            }
        }

        return '';
    }

    public function forward(PbbLanding_Request $request, array $registry)
    {
        $app = $this->matchingApp($request->path, $registry);
        if ($app === null) {
            return PbbLanding_Response::json(404, array('error' => 'gateway_route_not_registered'));
        }

        $gateway = $app['public_gateway'];
        $targetBase = rtrim((string) $gateway['target_base_url'], '/');
        $targetPath = substr($request->path, strlen((string) $gateway['path_prefix']));
        if ($targetPath === false || !$this->pathMatchesAllowedPrefix($targetPath, $gateway)) {
            return PbbLanding_Response::json(404, array('error' => 'gateway_path_not_allowed'));
        }
        if ($this->pathHasUnsafeSegments($targetPath)) {
            return PbbLanding_Response::json(404, array('error' => 'gateway_path_not_allowed'));
        }

        $targetUrl = $targetBase . $targetPath . ($request->queryString !== '' ? '?' . $request->queryString : '');
        $maxBytes = isset($this->config['max_body_bytes']) ? (int) $this->config['max_body_bytes'] : 10485760;
        if (strlen($request->body) > $maxBytes) {
            return PbbLanding_Response::json(413, array('error' => 'request_body_too_large'));
        }

        if (!function_exists('curl_init')) {
            return PbbLanding_Response::json(502, array('error' => 'curl_extension_missing'));
        }

        $started = microtime(true);
        $ch = curl_init($targetUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $request->method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, isset($this->config['timeout_seconds']) ? (int) $this->config['timeout_seconds'] : 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        if (!empty($this->config['ca_bundle'])) {
            curl_setopt($ch, CURLOPT_CAINFO, $this->config['ca_bundle']);
        }
        if (!in_array($request->method, array('GET', 'HEAD'), true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $request->body);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->forwardHeaders($request, parse_url($targetBase, PHP_URL_HOST)));

        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $this->logGateway($request, $targetPath, $status, $elapsedMs);

        if ($raw === false) {
            return PbbLanding_Response::json(502, array('error' => 'gateway_forward_failed', 'detail' => $error));
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        return new PbbLanding_Response($status > 0 ? $status : 502, $this->responseHeaders($rawHeaders), $body);
    }

    private function matchingApp($path, array $registry)
    {
        $apps = isset($registry['apps']) && is_array($registry['apps']) ? $registry['apps'] : array();
        $match = null;
        $matchLength = -1;

        foreach ($apps as $app) {
            if (!is_array($app) || !$this->gatewayEnabled($app)) {
                continue;
            }

            $prefix = (string) $app['public_gateway']['path_prefix'];
            if ($this->pathStartsWithPrefix((string) $path, $prefix) && strlen($prefix) > $matchLength) {
                $match = $app;
                $matchLength = strlen($prefix);
            }
        }

        return $match;
    }

    private function gatewayEnabled(array $app)
    {
        if (empty($app['enabled']) || empty($app['public_gateway']) || !is_array($app['public_gateway'])) {
            return false;
        }

        $gateway = $app['public_gateway'];
        return !empty($gateway['enabled'])
            && !empty($gateway['m2m_only'])
            && !empty($gateway['path_prefix'])
            && isset($gateway['allowed_path_prefixes'])
            && is_array($gateway['allowed_path_prefixes']);
    }

    private function pathStartsWithPrefix($path, $prefix)
    {
        $prefix = rtrim((string) $prefix, '/');
        if ($prefix === '') {
            return false;
        }

        return $path === $prefix || strpos($path, $prefix . '/') === 0;
    }

    private function pathMatchesAllowedPrefix($targetPath, array $gateway)
    {
        if (!isset($gateway['allowed_path_prefixes']) || !is_array($gateway['allowed_path_prefixes'])) {
            return false;
        }

        foreach ($gateway['allowed_path_prefixes'] as $prefix) {
            $prefix = (string) $prefix;
            if ($this->pathStartsWithPrefix((string) $targetPath, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function pathHasUnsafeSegments($path)
    {
        $decoded = rawurldecode((string) $path);
        if (strpos($decoded, '\\') !== false) {
            return true;
        }

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function forwardHeaders(PbbLanding_Request $request, $targetHost)
    {
        $blocked = array(
            'host', 'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization',
            'te', 'trailer', 'transfer-encoding', 'upgrade',
            'x-forwarded-host', 'x-forwarded-for', 'x-real-ip', 'x-pbb-peer-domain',
        );

        $headers = array('Host: ' . $targetHost);
        foreach ($request->headers as $name => $value) {
            if (in_array(strtolower($name), $blocked, true)) {
                continue;
            }
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    private function responseHeaders($rawHeaders)
    {
        $safe = array();
        $blocked = array('connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer', 'transfer-encoding', 'upgrade');
        foreach (explode("\n", (string) $rawHeaders) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'HTTP/') === 0 || strpos($line, ':') === false) {
                continue;
            }
            list($name, $value) = explode(':', $line, 2);
            if (in_array(strtolower(trim($name)), $blocked, true)) {
                continue;
            }
            $safe[trim($name)] = trim($value);
        }

        return $safe;
    }

    private function logGateway(PbbLanding_Request $request, $targetPath, $status, $elapsedMs)
    {
        if ($this->logPath === '') {
            return;
        }
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $entry = array(
            'at' => date('c'),
            'method' => $request->method,
            'path' => $request->path,
            'target_path' => $targetPath,
            'peer_domain' => $this->peerDomain($request),
            'status' => $status,
            'elapsed_ms' => $elapsedMs,
            'body_bytes' => strlen($request->body),
        );
        file_put_contents($this->logPath, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
