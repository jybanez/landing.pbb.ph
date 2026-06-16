<?php

class PbbLanding_RegistryStore
{
    private $path;
    private $auditLog;

    public function __construct($path, $auditLog)
    {
        $this->path = (string) $path;
        $this->auditLog = (string) $auditLog;
    }

    public function read()
    {
        if (!is_file($this->path)) {
            return $this->emptyRegistry();
        }

        $payload = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($payload)) {
            return $this->emptyRegistry();
        }

        if (!isset($payload['apps']) || !is_array($payload['apps'])) {
            $payload['apps'] = array();
        }

        return $payload;
    }

    public function putApp($appId, array $record, array $auditContext)
    {
        $errors = $this->validateApp($appId, $record);
        if ($errors) {
            $this->audit('put_rejected', $appId, 'invalid', $auditContext, $errors);
            return array(false, $errors, null);
        }

        $registry = $this->read();
        $registry['schema_version'] = 1;
        $registry['generated_at'] = date('c');
        $registry['generated_by'] = 'kit-setup';
        $registry['apps'][$appId] = $this->normalizeApp($record);
        $this->write($registry);
        $this->audit('put', $appId, 'ok', $auditContext, array());

        return array(true, array(), $registry['apps'][$appId]);
    }

    public function deleteApp($appId, array $auditContext)
    {
        $registry = $this->read();
        if (isset($registry['apps'][$appId])) {
            unset($registry['apps'][$appId]);
        }
        $registry['generated_at'] = date('c');
        $this->write($registry);
        $this->audit('delete', $appId, 'ok', $auditContext, array());
    }

    private function write(array $registry)
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $tmp = $this->path . '.tmp.' . bin2hex(function_exists('openssl_random_pseudo_bytes') ? openssl_random_pseudo_bytes(6) : uniqid('', true));
        file_put_contents($tmp, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
        rename($tmp, $this->path);
    }

    private function validateApp($appId, array $record)
    {
        $errors = array();
        if (!isset($record['id']) || (string) $record['id'] !== (string) $appId) {
            $errors[] = 'id must match route app_id';
        }
        if (!isset($record['name']) || trim((string) $record['name']) === '') {
            $errors[] = 'name is required';
        }
        if (isset($record['display_name']) && trim((string) $record['display_name']) === '') {
            $errors[] = 'display_name must not be empty when provided';
        }
        if (!isset($record['enabled']) || !is_bool($record['enabled'])) {
            $errors[] = 'enabled must be boolean';
        }
        if (isset($record['install_scope']) && !in_array($record['install_scope'], array('local', 'remote', 'disabled'), true)) {
            $errors[] = 'install_scope must be local, remote, or disabled';
        }

        foreach (array('local_url', 'launch_url', 'health_url') as $key) {
            if (isset($record[$key]) && !$this->isHttpsUrl($record[$key])) {
                $errors[] = $key . ' must be an absolute HTTPS URL';
            }
        }

        if (isset($record['public_gateway']) && is_array($record['public_gateway'])) {
            $gateway = $record['public_gateway'];
            if (!empty($gateway['enabled'])) {
                if (empty($gateway['m2m_only']) || $gateway['m2m_only'] !== true) {
                    $errors[] = 'public_gateway.m2m_only must be true';
                }
                if (empty($gateway['path_prefix']) || substr((string) $gateway['path_prefix'], 0, 1) !== '/') {
                    $errors[] = 'public_gateway.path_prefix must start with /';
                }
                if (!$this->isHttpsUrl(isset($gateway['target_base_url']) ? $gateway['target_base_url'] : null)) {
                    $errors[] = 'public_gateway.target_base_url must be an absolute HTTPS URL';
                }
                if (!isset($gateway['allowed_path_prefixes']) || !is_array($gateway['allowed_path_prefixes']) || count($gateway['allowed_path_prefixes']) === 0) {
                    $errors[] = 'public_gateway.allowed_path_prefixes must be a non-empty array';
                } else {
                    foreach ($gateway['allowed_path_prefixes'] as $prefix) {
                        if (!is_string($prefix) || $prefix === '' || $prefix[0] !== '/' || rtrim($prefix, '/') === '') {
                            $errors[] = 'public_gateway.allowed_path_prefixes entries must be non-root paths starting with /';
                            break;
                        }
                    }
                }
            }
        }

        if (isset($record['launcher']) && is_array($record['launcher'])) {
            $launcher = $record['launcher'];
            if (isset($launcher['logo_url']) && trim((string) $launcher['logo_url']) !== '' && !$this->isHttpsUrl($launcher['logo_url'])) {
                $errors[] = 'launcher.logo_url must be an absolute HTTPS URL';
            }
            if (isset($launcher['logo_kind']) && !in_array($launcher['logo_kind'], array('mark', 'logo'), true)) {
                $errors[] = 'launcher.logo_kind must be mark or logo';
            }
        }

        return $errors;
    }

    private function normalizeApp(array $record)
    {
        if (!isset($record['install_scope'])) {
            $record['install_scope'] = 'local';
        }
        if (!isset($record['audience']) || !is_array($record['audience'])) {
            $record['audience'] = array();
        }
        if (isset($record['public_gateway']) && is_array($record['public_gateway']) && isset($record['public_gateway']['path_prefix'])) {
            $record['public_gateway']['path_prefix'] = rtrim((string) $record['public_gateway']['path_prefix'], '/');
        }
        if (isset($record['public_gateway']) && is_array($record['public_gateway']) && isset($record['public_gateway']['allowed_path_prefixes']) && is_array($record['public_gateway']['allowed_path_prefixes'])) {
            $prefixes = array();
            foreach ($record['public_gateway']['allowed_path_prefixes'] as $prefix) {
                $normalized = rtrim((string) $prefix, '/');
                if ($normalized !== '') {
                    $prefixes[] = $normalized;
                }
            }
            $record['public_gateway']['allowed_path_prefixes'] = array_values(array_unique($prefixes));
        }

        return $record;
    }

    private function isHttpsUrl($url)
    {
        return is_string($url)
            && preg_match('/^https:\/\/[^\/\s]+/i', $url)
            && parse_url($url, PHP_URL_HOST);
    }

    private function audit($action, $appId, $result, array $context, array $errors)
    {
        if ($this->auditLog === '') {
            return;
        }
        $dir = dirname($this->auditLog);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $entry = array(
            'at' => date('c'),
            'action' => $action,
            'app_id' => $appId,
            'result' => $result,
            'host' => isset($context['host']) ? $context['host'] : null,
            'source' => isset($context['source']) ? $context['source'] : null,
            'errors' => $errors,
        );
        file_put_contents($this->auditLog, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    private function emptyRegistry()
    {
        return array(
            'schema_version' => 1,
            'generated_at' => null,
            'generated_by' => 'pbb-landing',
            'apps' => array(),
        );
    }
}
