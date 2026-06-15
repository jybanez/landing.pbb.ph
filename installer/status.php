<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$release = array();
$releasePath = $root . DIRECTORY_SEPARATOR . 'release.json';
if (is_file($releasePath)) {
    $decoded = json_decode((string) file_get_contents($releasePath), true);
    if (is_array($decoded)) {
        $release = $decoded;
    }
}

$manifestPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'install-manifest.json';
$manifest = array();
if (is_file($manifestPath)) {
    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    if (is_array($decoded)) {
        $manifest = $decoded;
    }
}

$installed = !empty($manifest);
$registryPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'registry.json';

echo json_encode(array(
    'schema_version' => 1,
    'app' => 'pbb-landing',
    'version' => isset($release['version']) ? (string) $release['version'] : '0.1.0',
    'installed' => $installed,
    'status' => $installed ? 'healthy' : 'not-installed',
    'health' => array(
        'registry' => is_file($registryPath) ? 'ok' : 'missing',
        'manifest' => $installed ? 'ok' : 'missing',
    ),
    'runtime_services' => array(),
    'warnings' => array(),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
