<?php

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$failures = array();

function assert_true($condition, $message)
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
        echo "FAIL: " . $message . "\n";
        return;
    }
    echo "PASS: " . $message . "\n";
}

function temp_root()
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pbb-landing-tests-' . uniqid();
    mkdir($root, 0775, true);
    mkdir($root . DIRECTORY_SEPARATOR . 'logs', 0775, true);
    return $root;
}

function write_json($path, array $payload)
{
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function remove_tree($path)
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

function run_installer($mode, $configPath, $reportPath)
{
    $php = PHP_BINARY;
    $installer = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'install-run.php';
    $command = escapeshellarg($php) . ' ' . escapeshellarg($installer) . ' --mode ' . escapeshellarg($mode) . ' --config ' . escapeshellarg($configPath) . ' --report ' . escapeshellarg($reportPath);
    exec($command, $output, $code);
    return array($code, implode("\n", $output));
}

function test_config($root, $token)
{
    return array(
        'app' => array('id' => 'pbb-landing', 'name' => 'PBB Landing', 'display_name' => 'Landing', 'version' => '0.1.0'),
        'hosts' => array('local' => 'pbb.ph', 'hq' => 'hub.pbb.ph'),
        'paths' => array(
            'registry' => $root . DIRECTORY_SEPARATOR . 'registry.json',
            'audit_log' => $root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'audit.log',
            'gateway_log' => $root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'gateway.log',
            'relay_hub_json' => $root . DIRECTORY_SEPARATOR . 'hub.json',
        ),
        'security' => array('registry_token_hash' => hash('sha256', $token)),
        'gateway' => array('max_body_bytes' => 1024, 'require_peer_domain' => true, 'ca_bundle' => '', 'timeout_seconds' => 1),
    );
}

function request($method, $host, $path, array $headers = array(), $body = '')
{
    return new PbbLanding_Request($method, $host, $path, '', $headers, $body, array('REMOTE_ADDR' => '127.0.0.1'));
}

$root = temp_root();
$token = 'test-token';
$registryPath = $root . DIRECTORY_SEPARATOR . 'registry.json';
$hubPath = $root . DIRECTORY_SEPARATOR . 'hub.json';

write_json($registryPath, array('schema_version' => 1, 'generated_at' => null, 'generated_by' => 'test', 'apps' => array()));
write_json($hubPath, array(
    'base_url' => 'https://hub.pbb.ph',
    'hub_id' => 11,
    'relay_hub_id' => '072217',
    'name' => 'CEBU CITY, CEBU',
    'code' => 'cebu-cebu',
    'deployment' => 'city',
    'domain' => 'cebu-cebu-relay.pbb.ph',
    'status' => 'active',
    'country_code' => 'PH',
    'reg_code' => '07',
    'prov_code' => '0722',
    'citymun_code' => '072217',
    'uplinks' => array(
        array('uplink_domain' => 'cebu.hub.ph', 'hub' => array('domain' => 'cebu.hub.ph', 'status' => 'active')),
    ),
    'sources' => array(
        array('hub' => array('domain' => 'apas-cebu-cebu-relay.pbb.ph', 'status' => 'active')),
    ),
    'hydrated_at' => '2026-06-13T03:23:26+00:00',
    'snapshot_hash' => 'secret-ish-hash',
));

$config = test_config($root, $token);
$app = new PbbLanding_App($config);

assert_true(PbbLanding_Host::normalize('CEBU-CEBU-RELAY.PBB.PH:443.') === 'cebu-cebu-relay.pbb.ph', 'host normalization strips case, port, trailing dot');

$response = $app->handle(request('GET', 'cebu-cebu-relay.pbb.ph', '/.well-known/pbb-hub.json'));
$payload = json_decode($response->body, true);
assert_true($response->status === 200, 'public projection is available on hub domain');
assert_true(isset($payload['hub']['id']) && $payload['hub']['id'] === '072217', 'public projection includes relay hub id');
assert_true(!isset($payload['uplinks']) && !isset($payload['sources']), 'public projection does not expose top-level peer lists');
assert_true(strpos($response->body, 'snapshot_hash') === false && strpos($response->body, 'base_url') === false, 'public projection excludes denied Relay fields');

$response = $app->handle(request('HEAD', 'cebu-cebu-relay.pbb.ph', '/'));
assert_true($response->status === 200 && $response->body === '' && isset($response->headers['Content-Type']) && strpos($response->headers['Content-Type'], 'text/html') === 0, 'public hub root supports HEAD with empty body');

$response = $app->handle(request('GET', 'pbb.ph', '/.well-known/pbb-hub.json'));
assert_true($response->status === 404, 'public projection fails on pbb.ph');

$response = $app->handle(request('GET', 'cebu-cebu-relay.pbb.ph', '/internal/registry/apps', array('Authorization' => 'Bearer ' . $token)));
assert_true($response->status === 404, 'internal registry fails on public host even with token');

$response = $app->handle(request('GET', 'pbb.ph', '/internal/registry/apps'));
assert_true($response->status === 401, 'internal registry requires token on local host');

$relayRecord = array(
    'id' => 'pbb-relay',
    'name' => 'PBB Relay',
    'display_name' => 'Relay',
    'version' => '1.1.0',
    'enabled' => true,
    'install_scope' => 'local',
    'install_path' => 'C:/wamp64/www/pbb/relay',
    'public_path' => 'C:/wamp64/www/pbb/relay/public',
    'local_url' => 'https://relay.pbb.ph',
    'launch_url' => 'https://relay.pbb.ph',
    'health_url' => 'https://relay.pbb.ph/api/status',
    'audience' => array('admin', 'support'),
    'launcher' => array(
        'visible' => false,
        'sort' => 20,
        'icon' => 'comms.radio',
        'logo_url' => 'https://relay.pbb.ph/assets/pbb-relay-mark.svg',
        'logo_kind' => 'mark',
    ),
    'public_gateway' => array(
        'enabled' => true,
        'path_prefix' => '/relay',
        'target_base_url' => 'https://relay.pbb.ph',
        'allowed_path_prefixes' => array('/api/v1/'),
        'm2m_only' => true,
    ),
);

$response = $app->handle(request('PUT', 'pbb.ph', '/internal/registry/apps/pbb-relay', array(
    'Authorization' => 'Bearer ' . $token,
    'Content-Type' => 'application/json',
), json_encode($relayRecord)));
assert_true($response->status === 200, 'valid registry PUT succeeds');

$invalidLogoRecord = $relayRecord;
$invalidLogoRecord['id'] = 'pbb-invalid-logo';
$invalidLogoRecord['launcher']['logo_url'] = 'http://relay.pbb.ph/assets/pbb-relay-mark.svg';
$response = $app->handle(request('PUT', 'pbb.ph', '/internal/registry/apps/pbb-invalid-logo', array(
    'Authorization' => 'Bearer ' . $token,
    'Content-Type' => 'application/json',
), json_encode($invalidLogoRecord)));
assert_true($response->status === 422, 'registry rejects non-HTTPS launcher logo URLs');

$response = $app->handle(request('GET', 'pbb.ph', '/internal/registry/apps', array('Authorization' => 'Bearer ' . $token)));
$payload = json_decode($response->body, true);
assert_true(isset($payload['apps']['pbb-relay']['install_path']), 'registry GET returns full private registry behind token');

$hotlineRecord = $relayRecord;
$hotlineRecord['id'] = 'pbb-hotline';
$hotlineRecord['name'] = 'PBB Hotline';
$hotlineRecord['display_name'] = 'Hotline';
$hotlineRecord['local_url'] = 'https://hotline.pbb.ph';
$hotlineRecord['launch_url'] = 'https://hotline.pbb.ph';
$hotlineRecord['health_url'] = 'https://hotline.pbb.ph/up';
$hotlineRecord['launcher']['visible'] = true;
$hotlineRecord['launcher']['sort'] = 10;
$hotlineRecord['launcher']['icon'] = 'hotline';
$hotlineRecord['launcher']['logo_url'] = 'https://hotline.pbb.ph/assets/launcher/app-icon.png';
$hotlineRecord['public_gateway']['enabled'] = false;
$response = $app->handle(request('PUT', 'pbb.ph', '/internal/registry/apps/pbb-hotline', array(
    'Authorization' => 'Bearer ' . $token,
    'Content-Type' => 'application/json',
), json_encode($hotlineRecord)));
assert_true($response->status === 200, 'registry accepts visible launcher app');

$response = $app->handle(request('GET', 'pbb.ph', '/'));
assert_true(strpos($response->body, 'class="app-logo app-logo-mark"') !== false && strpos($response->body, 'https://hotline.pbb.ph/assets/launcher/app-icon.png') !== false, 'launcher renders app-owned logo when provided');
assert_true(strpos($response->body, '<span class="app-tile-name">Hotline</span>') !== false && strpos($response->body, '<span class="app-tile-name">PBB Hotline</span>') === false, 'launcher prefers display_name over formal app name');
assert_true(strpos($response->body, 'alt="Hotline"') !== false, 'launcher defaults app logo alt text from display_name');
assert_true(strpos($response->body, '<span class="app-tile-name">Relay</span>') === false, 'launcher hides apps with launcher.visible=false');
assert_true(strpos($response->body, 'ui-navbar-brand-media') === false && strpos($response->body, '<span class="ui-navbar-brand-label">CEBU CITY, CEBU</span>') !== false && strpos($response->body, '<span class="ui-navbar-brand-subtitle">PBB Landing v0.1.0</span>') !== false, 'launcher navbar uses hub name and Landing version without brand icon');

$response = $app->handle(request('HEAD', 'pbb.ph', '/'));
assert_true($response->status === 200 && $response->body === '' && isset($response->headers['Content-Type']) && strpos($response->headers['Content-Type'], 'text/html') === 0, 'local launcher root supports HEAD with empty body');

$response = $app->handle(request('GET', 'pbb.ph', '/up'));
$payload = json_decode($response->body, true);
assert_true($response->status === 200 && isset($payload['ok']) && $payload['ok'] === true && isset($payload['surface']) && $payload['surface'] === 'local', 'local readiness endpoint returns JSON health');

$response = $app->handle(request('HEAD', 'pbb.ph', '/api/health'));
assert_true($response->status === 200 && $response->body === '' && isset($response->headers['Content-Type']) && strpos($response->headers['Content-Type'], 'application/json') === 0, 'local health endpoint supports HEAD with empty body');

$response = $app->handle(request('GET', 'unknown.example', '/up'));
assert_true($response->status === 404, 'readiness endpoint fails on unknown hosts');

$response = $app->handle(request('GET', 'pbb.ph', '/relay/api/v1/receive'));
assert_true($response->status === 404, 'gateway fails on pbb.ph');

$response = $app->handle(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/receive'));
assert_true($response->status === 403, 'gateway requires peer domain signal');

$response = $app->handle(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/receive', array('X-PBB-Peer-Domain' => 'evil.example')));
assert_true($response->status === 403, 'gateway rejects peer outside hub and hub.json links');

$gateway = new PbbLanding_Gateway($config['gateway'], $config['paths']['gateway_log']);
list($hub) = (new PbbLanding_HubSource($hubPath))->read();
$peers = (new PbbLanding_HubProjection())->peerDomains($hub, 'hub.pbb.ph');
assert_true(in_array('hub.pbb.ph', $peers, true) && in_array('cebu.hub.ph', $peers, true) && in_array('apas-cebu-cebu-relay.pbb.ph', $peers, true), 'peer allowlist derives only normalized needed domains');
assert_true($gateway->allowedPeer(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/receive', array('X-PBB-Peer-Domain' => 'hub.pbb.ph')), $peers), 'gateway accepts allowed HQ peer domain signal');
assert_true($gateway->allowedPeer(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/receive', array('Origin' => 'https://apas-cebu-cebu-relay.pbb.ph')), $peers), 'gateway accepts allowed Origin peer signal');
assert_true($gateway->allowedPeer(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/receive', array('Referer' => 'https://hub.pbb.ph/sync')), $peers), 'gateway accepts allowed Referer peer signal');

$response = $app->handle(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/../relay/login', array('X-PBB-Peer-Domain' => 'hub.pbb.ph')));
assert_true($response->status === 404, 'gateway rejects raw dot-segment path escape');

$response = $app->handle(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/%2e%2e/relay/login', array('X-PBB-Peer-Domain' => 'hub.pbb.ph')));
assert_true($response->status === 404, 'gateway rejects encoded dot-segment path escape');

$response = $app->handle(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/receive', array('X-PBB-Peer-Domain' => 'hub.pbb.ph'), str_repeat('x', 1025)));
assert_true($response->status === 413, 'gateway rejects oversized body before forwarding');

$supportGatewayRecord = $relayRecord;
$supportGatewayRecord['id'] = 'pbb-support';
$supportGatewayRecord['name'] = 'PBB Support';
$supportGatewayRecord['display_name'] = 'Support';
$supportGatewayRecord['local_url'] = 'https://support.pbb.ph';
$supportGatewayRecord['launch_url'] = 'https://support.pbb.ph';
$supportGatewayRecord['health_url'] = 'https://support.pbb.ph/up';
$supportGatewayRecord['public_gateway']['path_prefix'] = '/support-gateway';
$supportGatewayRecord['public_gateway']['target_base_url'] = 'https://support.pbb.ph';
$supportGatewayRecord['public_gateway']['allowed_path_prefixes'] = array('/m2m');
$response = $app->handle(request('PUT', 'pbb.ph', '/internal/registry/apps/pbb-support', array(
    'Authorization' => 'Bearer ' . $token,
    'Content-Type' => 'application/json',
), json_encode($supportGatewayRecord)));
assert_true($response->status === 200, 'registry accepts non-Relay gateway records');

$response = $app->handle(request('GET', 'pbb.ph', '/support-gateway/m2m/ping'));
assert_true($response->status === 404 && strpos($response->body, 'gateway_not_available_on_local_host') !== false, 'local host refuses dynamically registered gateway routes');

$response = $app->handle(request('POST', 'cebu-cebu-relay.pbb.ph', '/support-gateway/admin/login', array('X-PBB-Peer-Domain' => 'hub.pbb.ph')));
assert_true($response->status === 404 && strpos($response->body, 'gateway_path_not_allowed') !== false, 'gateway allowed target prefixes are registry-driven');

$response = $app->handle(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v10/receive', array('X-PBB-Peer-Domain' => 'hub.pbb.ph')));
assert_true($response->status === 404 && strpos($response->body, 'gateway_path_not_allowed') !== false, 'gateway allowed target prefix matching is boundary-aware');

$disabledRelayRecord = $relayRecord;
$disabledRelayRecord['public_gateway']['enabled'] = false;
$response = $app->handle(request('PUT', 'pbb.ph', '/internal/registry/apps/pbb-relay', array(
    'Authorization' => 'Bearer ' . $token,
    'Content-Type' => 'application/json',
), json_encode($disabledRelayRecord)));
assert_true($response->status === 200, 'registry can disable relay gateway');

$response = $app->handle(request('POST', 'cebu-cebu-relay.pbb.ph', '/relay/api/v1/receive', array('X-PBB-Peer-Domain' => 'hub.pbb.ph')));
assert_true($response->status === 404, 'gateway rejects route when relay public gateway is disabled');

$installerRoot = temp_root();
$installPath = $installerRoot . DIRECTORY_SEPARATOR . 'installed-landing';
$installerConfigPath = $installerRoot . DIRECTORY_SEPARATOR . 'landing.config.json';
$installerReportPath = $installerRoot . DIRECTORY_SEPARATOR . 'landing.report.json';
$tokenHash = hash('sha256', 'installer-token');
$installerConfig = array(
    'schema_version' => 1,
    'mode' => 'fresh',
    'kit' => array('run_id' => 'test-installer'),
    'app' => array(
        'install_path' => $installPath,
        'public_path' => $installPath . DIRECTORY_SEPARATOR . 'public',
        'app_url' => 'https://pbb.ph',
        'app_env' => 'production',
    ),
    'landing' => array(
        'local_host' => 'pbb.ph',
        'hq_host' => 'hub.pbb.ph',
        'registry_token_hash' => $tokenHash,
        'paths' => array(
            'relay_hub_json' => 'C:/wamp64/www/pbb/relay/public/hub.json',
        ),
        'gateway' => array(
            'max_body_bytes' => 2048,
            'require_peer_domain' => true,
            'timeout_seconds' => 12,
        ),
    ),
);
write_json($installerConfigPath, $installerConfig);

list($code) = run_installer('preflight', $installerConfigPath, $installerReportPath);
$preflightReport = json_decode((string) file_get_contents($installerReportPath), true);
assert_true($code === 0 && isset($preflightReport['status']) && $preflightReport['status'] === 'success', 'installer preflight succeeds');
assert_true(!is_dir($installPath), 'installer preflight does not create install path');

list($code) = run_installer('fresh', $installerConfigPath, $installerReportPath);
$installReport = json_decode((string) file_get_contents($installerReportPath), true);
assert_true($code === 0 && isset($installReport['status']) && $installReport['status'] === 'success', 'installer fresh install succeeds');
assert_true(is_file($installPath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php'), 'installer copies Landing public entrypoint');
assert_true(is_file($installPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'landing.local.php'), 'installer writes local config');
assert_true(is_file($installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'installer' . DIRECTORY_SEPARATOR . 'install-manifest.json'), 'installer writes install manifest');
$localConfigText = (string) file_get_contents($installPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'landing.local.php');
assert_true(strpos($localConfigText, $tokenHash) !== false && strpos($localConfigText, 'installer-token') === false, 'installer writes token hash without plaintext token');

$installedRegistryPath = $installPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'registry.json';
write_json($installedRegistryPath, array(
    'schema_version' => 1,
    'generated_at' => 'preserved',
    'generated_by' => 'test',
    'apps' => array('pbb-preserved' => array('id' => 'pbb-preserved', 'name' => 'Preserved', 'enabled' => true)),
));
list($code) = run_installer('repair', $installerConfigPath, $installerReportPath);
$repairedRegistry = json_decode((string) file_get_contents($installedRegistryPath), true);
assert_true($code === 0 && isset($repairedRegistry['apps']['pbb-preserved']), 'installer repair preserves existing registry');

remove_tree($installerRoot);

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}

echo "\nAll tests passed.\n";
