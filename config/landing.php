<?php

$root = dirname(__DIR__);

return array(
    'app' => array(
        'id' => 'pbb-landing',
        'name' => 'PBB Landing',
        'version' => '0.1.0',
        'release_name' => 'Landing Surface And Gateway Baseline',
    ),
    'hosts' => array(
        'local' => 'pbb.ph',
        'hq' => 'hub.pbb.ph',
        // Comma-separated local development hosts. Leave empty in Kit-managed installs.
        'development' => getenv('PBB_LANDING_DEV_HOSTS') ?: '',
    ),
    'paths' => array(
        'registry' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'registry.json',
        'audit_log' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'landing-audit.log',
        'gateway_log' => $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'landing-gateway.log',
        'relay_hub_json' => 'C:/wamp64/www/pbb/relay/public/hub.json',
    ),
    'security' => array(
        // Set PBB_LANDING_REGISTRY_TOKEN_HASH to hash('sha256', <Kit-generated-token>).
        'registry_token_hash' => getenv('PBB_LANDING_REGISTRY_TOKEN_HASH') ?: '',
    ),
    'gateway' => array(
        'max_body_bytes' => 10485760,
        'require_peer_domain' => true,
        'ca_bundle' => getenv('PBB_LANDING_CA_BUNDLE') ?: '',
        'timeout_seconds' => 30,
    ),
);
