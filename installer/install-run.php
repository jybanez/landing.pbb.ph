<?php

declare(strict_types=1);

$options = parse_args($argv);
$mode = (string) ($options['mode'] ?? 'fresh');
$configPath = (string) ($options['config'] ?? '');
$reportPath = (string) ($options['report'] ?? '');
$startedAt = date(DATE_ATOM);

if ($configPath === '' || $reportPath === '') {
    fwrite(STDERR, "Usage: php install-run.php --mode <preflight|fresh|repair|upgrade> --config <path> --report <path>\n");
    exit(2);
}

$steps = array();
$warnings = array();
$errors = array();
$status = 'success';
$config = array();

try {
    $config = read_json_file($configPath);
    $steps[] = step('load-config', 'success', 'Unattended config loaded.');

    if (!in_array($mode, array('preflight', 'fresh', 'repair', 'upgrade'), true)) {
        fail($errors, 'mode.unsupported', 'Unsupported mode: ' . $mode);
    } else {
        $steps[] = step('validate-mode', 'success', 'Mode is supported: ' . $mode);
    }

    $installPath = normalized_path((string) ($config['app']['install_path'] ?? ''));
    if ($installPath === '') {
        fail($errors, 'app.install_path', 'app.install_path is required.');
    }

    $publicPath = normalized_path((string) ($config['app']['public_path'] ?? ''));
    if ($publicPath !== '' && !same_path($publicPath, $installPath . DIRECTORY_SEPARATOR . 'public')) {
        $warnings[] = warning('app.public_path', 'Landing public_path is normally install_path/public; Kit vhost generation should point at the installed public directory.');
    }

    $payloadPath = app_payload_path();
    if (!is_dir($payloadPath)) {
        fail($errors, 'payload.missing', 'App payload directory is missing: ' . $payloadPath);
    } else {
        $steps[] = step('payload', 'success', 'App payload located.');
    }

    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        fail($errors, 'php.version', 'PHP 8.2 or newer is required. Current: ' . PHP_VERSION);
    } else {
        $steps[] = step('php.version', 'success', 'PHP version is supported: ' . PHP_VERSION);
    }

    foreach (array('json', 'openssl', 'curl') as $extension) {
        if (!extension_loaded($extension)) {
            fail($errors, 'php.extension.' . $extension, 'Required PHP extension is missing: ' . $extension);
        }
    }
    if (!$errors) {
        $steps[] = step('php.extensions', 'success', 'Required PHP extensions are loaded.');
    }

    $landing = isset($config['landing']) && is_array($config['landing']) ? $config['landing'] : array();
    $tokenHash = (string) ($landing['registry_token_hash'] ?? '');
    if ($tokenHash !== '' && !preg_match('/^[a-f0-9]{64}$/i', $tokenHash)) {
        fail($errors, 'landing.registry_token_hash', 'landing.registry_token_hash must be a SHA-256 hex string.');
    }

    if (!$errors && $mode !== 'preflight') {
        ensure_dir($installPath);
        $steps[] = step('prepare-install-path', 'success', 'Install path is ready.');

        $preservedRegistry = null;
        $installedRegistry = $installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'registry.json';
        if (in_array($mode, array('repair', 'upgrade'), true) && is_file($installedRegistry)) {
            $preservedRegistry = (string) file_get_contents($installedRegistry);
        }

        copy_tree($payloadPath, $installPath, array(
            'storage/logs/*.log',
            'config/landing.local.php',
            'storage/installer/install-manifest.json',
        ));
        $steps[] = step('copy-app-payload', 'success', 'Landing app files copied.');

        ensure_dir($installPath . DIRECTORY_SEPARATOR . 'storage');
        ensure_dir($installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs');
        ensure_dir($installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installer');

        if ($preservedRegistry !== null) {
            file_put_contents($installedRegistry, $preservedRegistry);
            $steps[] = step('preserve-registry', 'success', 'Existing Landing registry preserved.');
        } elseif (!is_file($installedRegistry)) {
            write_json_file($installedRegistry, array(
                'schema_version' => 1,
                'generated_at' => null,
                'generated_by' => 'pbb-landing-installer',
                'apps' => array(),
            ));
            $steps[] = step('seed-registry', 'success', 'Empty Landing registry seeded.');
        }

        write_local_config($installPath, $config);
        $steps[] = step('write-local-config', 'success', 'Landing local config written.');

        $manifest = install_manifest($config, $mode, $installPath);
        write_json_file($installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'install-manifest.json', $manifest);
        $steps[] = step('write-manifest', 'success', 'Install manifest written.');
    }
} catch (Throwable $e) {
    fail($errors, 'installer.exception', $e->getMessage());
}

if ($errors) {
    $status = 'failed';
}

$report = array(
    'schema_version' => 1,
    'app' => 'pbb-landing',
    'version' => release_value('version', '0.1.0'),
    'run_id' => (string) ($config['kit']['run_id'] ?? ''),
    'mode' => $mode,
    'status' => $status,
    'started_at' => $startedAt,
    'finished_at' => date(DATE_ATOM),
    'summary' => $status === 'failed' ? 'PBB Landing installer failed.' : 'PBB Landing installer completed.',
    'steps' => $steps,
    'urls' => array(
        'local' => 'https://pbb.ph',
    ),
    'runtime_services' => array(),
    'warnings' => $warnings,
    'errors' => $errors,
);

write_json_file($reportPath, $report);
fwrite(STDOUT, "PBB Landing installer {$mode}: {$status}\n");
exit($status === 'failed' ? 1 : 0);

function parse_args(array $argv)
{
    $options = array();
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $arg = (string) $argv[$i];
        if (strpos($arg, '--') !== 0) {
            continue;
        }
        $name = substr($arg, 2);
        $value = true;
        $next = $argv[$i + 1] ?? null;
        if (is_string($next) && strpos($next, '--') !== 0) {
            $value = $next;
            $i++;
        }
        $options[$name] = $value;
    }

    return $options;
}

function read_json_file($path)
{
    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('Unable to read JSON file: ' . $path);
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON file: ' . $path);
    }
    return $data;
}

function write_json_file($path, array $data)
{
    ensure_dir(dirname($path));
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function ensure_dir($path)
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
}

function app_payload_path()
{
    $root = dirname(__DIR__);
    $bundlePayload = $root . DIRECTORY_SEPARATOR . 'app';
    if (is_dir($bundlePayload)) {
        return $bundlePayload;
    }
    return $root;
}

function copy_tree($source, $target, array $excludePatterns)
{
    $source = rtrim((string) $source, DIRECTORY_SEPARATOR);
    $target = rtrim((string) $target, DIRECTORY_SEPARATOR);
    ensure_dir($target);

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $sourcePath = $item->getPathname();
        $relative = str_replace('\\', '/', substr($sourcePath, strlen($source) + 1));
        if ($relative === '.git' || strpos($relative, '.git/') === 0 || excluded($relative, $excludePatterns)) {
            continue;
        }

        $targetPath = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if ($item->isDir()) {
            ensure_dir($targetPath);
            continue;
        }

        ensure_dir(dirname($targetPath));
        copy($sourcePath, $targetPath);
    }
}

function excluded($relative, array $patterns)
{
    foreach ($patterns as $pattern) {
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
        if (preg_match($regex, $relative)) {
            return true;
        }
    }
    return false;
}

function write_local_config($installPath, array $config)
{
    $landing = isset($config['landing']) && is_array($config['landing']) ? $config['landing'] : array();
    $paths = isset($landing['paths']) && is_array($landing['paths']) ? $landing['paths'] : array();
    $gateway = isset($landing['gateway']) && is_array($landing['gateway']) ? $landing['gateway'] : array();

    $registry = (string) ($paths['registry'] ?? ($installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'registry.json'));
    $auditLog = (string) ($paths['audit_log'] ?? ($installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'landing-audit.log'));
    $gatewayLog = (string) ($paths['gateway_log'] ?? ($installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'landing-gateway.log'));
    $relayHubJson = (string) ($paths['relay_hub_json'] ?? 'https://relay.pbb.ph/hub.json');

    $local = array(
        'hosts' => array(
            'local' => (string) ($landing['local_host'] ?? 'pbb.ph'),
            'hq' => (string) ($landing['hq_host'] ?? 'hub.pbb.ph'),
            'development' => '',
        ),
        'paths' => array(
            'registry' => $registry,
            'audit_log' => $auditLog,
            'gateway_log' => $gatewayLog,
            'relay_hub_json' => $relayHubJson,
        ),
        'security' => array(
            'registry_token_hash' => (string) ($landing['registry_token_hash'] ?? ''),
        ),
        'gateway' => array(
            'max_body_bytes' => (int) ($gateway['max_body_bytes'] ?? 10485760),
            'require_peer_domain' => array_key_exists('require_peer_domain', $gateway) ? (bool) $gateway['require_peer_domain'] : true,
            'ca_bundle' => (string) ($gateway['ca_bundle'] ?? ''),
            'timeout_seconds' => (int) ($gateway['timeout_seconds'] ?? 30),
        ),
    );

    $content = "<?php\n\nreturn " . var_export($local, true) . ";\n";
    file_put_contents($installPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'landing.local.php', $content);
}

function install_manifest(array $config, $mode, $installPath)
{
    return array(
        'schema_version' => 1,
        'app' => 'pbb-landing',
        'name' => 'PBB Landing',
        'version' => release_value('version', '0.1.0'),
        'installed_at' => date(DATE_ATOM),
        'install_mode' => $mode,
        'install_path' => $installPath,
        'public_path' => $installPath . DIRECTORY_SEPARATOR . 'public',
        'app_url' => 'https://pbb.ph',
        'environment' => (string) ($config['app']['app_env'] ?? 'production'),
        'runtime_services' => array(),
        'health' => array(
            'status' => 'installed',
        ),
    );
}

function release_value($key, $default)
{
    $releasePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'release.json';
    if (is_file($releasePath)) {
        $release = json_decode((string) file_get_contents($releasePath), true);
        if (is_array($release) && isset($release[$key])) {
            return (string) $release[$key];
        }
    }
    return $default;
}

function normalized_path($path)
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    return rtrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
}

function same_path($a, $b)
{
    return strtolower(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, rtrim((string) $a, '/\\'))) === strtolower(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, rtrim((string) $b, '/\\')));
}

function fail(array &$errors, $id, $message)
{
    $errors[] = array('id' => $id, 'message' => $message);
}

function warning($id, $message)
{
    return array('id' => $id, 'message' => $message);
}

function step($id, $status, $message)
{
    return array('id' => $id, 'status' => $status, 'message' => $message);
}
