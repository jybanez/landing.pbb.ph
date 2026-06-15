<?php

class PbbLanding_HubSource
{
    private $path;

    public function __construct($path)
    {
        $this->path = (string) $path;
    }

    public function read()
    {
        if ($this->path === '' || !is_file($this->path)) {
            return array(null, 'missing');
        }

        $payload = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($payload)) {
            return array(null, 'invalid');
        }

        return array($payload, 'ok');
    }
}
