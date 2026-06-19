<?php

class PbbLanding_HubSource
{
    private $source;
    private $caBundle;

    public function __construct($source, $caBundle = '')
    {
        $this->source = (string) $source;
        $this->caBundle = (string) $caBundle;
    }

    public function read()
    {
        if ($this->source === '') {
            return array(null, 'missing');
        }

        if ($this->isUrl($this->source)) {
            $json = $this->fetchUrl($this->source);
            if ($json === null) {
                return array(null, 'unavailable');
            }
        } elseif (is_file($this->source)) {
            $json = (string) file_get_contents($this->source);
        } else {
            return array(null, 'missing');
        }

        $payload = json_decode($json, true);
        if (!is_array($payload)) {
            return array(null, 'invalid');
        }

        return array($payload, 'ok');
    }

    private function isUrl($source)
    {
        return preg_match('#^https?://#i', (string) $source) === 1;
    }

    private function fetchUrl($url)
    {
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }
        if (!function_exists('curl_init')) {
            return null;
        }

        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_TIMEOUT, 10);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($handle, CURLOPT_USERAGENT, 'PBB Landing HubSource/1.0');
        if ($this->caBundle !== '' && is_file($this->caBundle)) {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $body === '' || $status < 200 || $status >= 300) {
            return null;
        }

        return $body;
    }
}
