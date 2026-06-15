<?php

class PbbLanding_Response
{
    public $status;
    public $headers;
    public $body;

    public function __construct($status, array $headers, $body)
    {
        $this->status = (int) $status;
        $this->headers = $headers;
        $this->body = (string) $body;
    }

    public static function json($status, array $payload, array $headers = array())
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return new self($status, $headers, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function html($status, $html, array $headers = array())
    {
        $headers['Content-Type'] = 'text/html; charset=utf-8';
        return new self($status, $headers, $html);
    }

    public static function text($status, $text, array $headers = array())
    {
        $headers['Content-Type'] = 'text/plain; charset=utf-8';
        return new self($status, $headers, $text);
    }

    public function send()
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
