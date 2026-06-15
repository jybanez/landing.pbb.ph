<?php

class PbbLanding_Host
{
    public static function normalize($host)
    {
        $host = trim(strtolower((string) $host));
        if ($host === '') {
            return '';
        }

        if (substr($host, -1) === '.') {
            $host = substr($host, 0, -1);
        }

        if (strpos($host, '[') === 0) {
            $end = strpos($host, ']');
            return $end === false ? $host : substr($host, 0, $end + 1);
        }

        $colon = strrpos($host, ':');
        if ($colon !== false && strpos($host, ':') === $colon) {
            $port = substr($host, $colon + 1);
            if (ctype_digit($port)) {
                $host = substr($host, 0, $colon);
            }
        }

        return $host;
    }

    public static function fromUrl($url)
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return self::normalize($url);
        }

        return self::normalize($host);
    }
}
