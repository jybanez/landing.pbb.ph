<?php

class PbbLanding_Auth
{
    private $tokenHash;

    public function __construct($tokenHash)
    {
        $this->tokenHash = (string) $tokenHash;
    }

    public function valid(PbbLanding_Request $request)
    {
        if ($this->tokenHash === '') {
            return false;
        }

        $header = $request->header('Authorization');
        if (!is_string($header) || stripos($header, 'Bearer ') !== 0) {
            return false;
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return false;
        }

        return $this->same($this->tokenHash, hash('sha256', $token));
    }

    private function same($known, $given)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($known, $given);
        }

        if (strlen($known) !== strlen($given)) {
            return false;
        }

        $result = 0;
        for ($i = 0; $i < strlen($known); $i++) {
            $result |= ord($known[$i]) ^ ord($given[$i]);
        }

        return $result === 0;
    }
}
