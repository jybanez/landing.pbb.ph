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

function test_config($root, $token)
{
    return array(
        'app' => array('id' => 'pbb-landing', 'name' => 'PBB Landing', 'version' => '0.1.0'),
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

$response = $app->handle(request('GET', 'pbb.ph', '/.well-known/pbb-hub.json'));
assert_true($response->status === 404, 'public projection fails on pbb.ph');

$response = $app->handle(request('GET', 'cebu-cebu-relay.pbb.ph', '/internal/registry/apps', array('Authorization' => 'Bearer ' . $token)));
assert_true($response->status === 404, 'internal registry fails on public host even with token');

$response = $app->handle(request('GET', 'pbb.ph', '/internal/registry/apps'));
assert_true($response->status === 401, 'internal registry requires token on local host');

$relayRecord = array(
    'id' => 'pbb-relay',
    'name' => 'PBB Relay',
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
        'visible' => true,
        'sort' => 20,
        'icon' => 'comms.radio',
        'logo_url' => 'https://relay.pbb.ph/assets/pbb-relay-mark.svg',
        'logo_alt' => 'PBB Relay',
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

$response = $app->handle(request('GET', 'pbb.ph', '/'));
assert_true(strpos($response->body, 'class="app-logo app-logo-mark"') !== false && strpos($response->body, 'https://relay.pbb.ph/assets/pbb-relay-mark.svg') !== false, 'launcher renders app-owned logo when provided');
assert_true(strpos($response->body, 'ui-navbar-brand-media') === false && strpos($response->body, '<span class="ui-navbar-brand-label">CEBU CITY, CEBU</span>') !== false && strpos($response->body, '<span class="ui-navbar-brand-subtitle">PBB Landing v0.1.0</span>') !== false, 'launcher navbar uses hub name and Landing version without brand icon');

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

if ($failures) {
    echo "\n" . count($failures) . " failure(s).\n";
    exit(1);
}

echo "\nAll tests passed.\n";
