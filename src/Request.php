<?php

class PbbLanding_Request
{
    public $method;
    public $host;
    public $path;
    public $queryString;
    public $headers;
    public $body;
    public $server;

    public function __construct($method, $host, $path, $queryString, array $headers, $body, array $server)
    {
        $this->method = strtoupper((string) $method);
        $this->host = (string) $host;
        $this->path = $path === '' ? '/' : (string) $path;
        $this->queryString = (string) $queryString;
        $this->headers = $headers;
        $this->body = (string) $body;
        $this->server = $server;
    }

    public static function fromGlobals()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : '');
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $parts = parse_url($uri);
        $path = isset($parts['path']) ? $parts['path'] : '/';
        $query = isset($parts['query']) ? $parts['query'] : '';

        return new self(
            isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            $host,
            $path,
            $query,
            self::headersFromServer($_SERVER),
            file_get_contents('php://input'),
            $_SERVER
        );
    }

    public static function headersFromServer(array $server)
    {
        $headers = array();
        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }

        foreach (array('CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length') as $key => $name) {
            if (isset($server[$key])) {
                $headers[$name] = $server[$key];
            }
        }

        return $headers;
    }

    public function header($name)
    {
        $needle = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $needle) {
                return (string) $value;
            }
        }

        return null;
    }

    public function json()
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
