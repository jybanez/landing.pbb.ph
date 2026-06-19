<?php

class PbbLanding_App
{
    private $config;
    private $hubSource;
    private $projection;
    private $registry;
    private $auth;
    private $renderer;
    private $gateway;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->hubSource = new PbbLanding_HubSource($config['paths']['relay_hub_json']);
        $this->projection = new PbbLanding_HubProjection();
        $this->registry = new PbbLanding_RegistryStore($config['paths']['registry'], $config['paths']['audit_log']);
        $this->auth = new PbbLanding_Auth($config['security']['registry_token_hash']);
        $this->renderer = new PbbLanding_Renderer(isset($config['app']) && is_array($config['app']) ? $config['app'] : array());
        $this->gateway = new PbbLanding_Gateway($config['gateway'], $config['paths']['gateway_log']);
    }

    public function handle(PbbLanding_Request $request)
    {
        list($hub, $hubState) = $this->hubSource->read();
        $registry = $this->registry->read();
        $publicHost = $this->projection->publicHost($hub);
        $localHost = PbbLanding_Host::normalize($this->config['hosts']['local']);
        $requestHost = PbbLanding_Host::normalize($request->host);
        $isLocalSurface = $requestHost === $localHost || in_array($requestHost, $this->developmentHosts(), true);

        if ($request->path === '/assets/app.css') {
            return $this->asset('app.css');
        }
        if ($request->path === '/assets/app.js') {
            return $this->asset('app.js');
        }
        if ($request->path === '/assets/helper-ui-bundle.js') {
            return $this->asset('helper-ui-bundle.js');
        }

        if ($this->isReadinessRead($request) && ($isLocalSurface || ($publicHost !== '' && $requestHost === $publicHost))) {
            return $this->headAware($request, PbbLanding_Response::json(200, array(
                'ok' => true,
                'app' => isset($this->config['app']['id']) ? $this->config['app']['id'] : 'pbb-landing',
                'version' => isset($this->config['app']['version']) ? $this->config['app']['version'] : null,
                'surface' => $isLocalSurface ? 'local' : 'public',
            )));
        }

        if (strpos($request->path, '/internal/') === 0) {
            if ($requestHost !== $localHost) {
                return PbbLanding_Response::json(404, array('error' => 'not_found'));
            }
            if (!$this->auth->valid($request)) {
                return PbbLanding_Response::json(401, array('error' => 'unauthorized'));
            }
            return $this->internal($request);
        }

        if ($isLocalSurface) {
            if ($this->gateway->matches($request->path, $registry) !== null) {
                return PbbLanding_Response::json(404, array('error' => 'gateway_not_available_on_local_host'));
            }
            if ($this->isRootRead($request)) {
                $projection = $this->projection->publicProjection($hub, $registry, $hubState);
                return $this->headAware($request, PbbLanding_Response::html(200, $this->renderer->launcher($projection, $registry)));
            }
            return PbbLanding_Response::json(404, array('error' => 'not_found'));
        }

        if ($publicHost !== '' && $requestHost === $publicHost) {
            if ($request->path === '/.well-known/pbb-hub.json' && $request->method === 'GET') {
                return PbbLanding_Response::json(200, $this->projection->publicProjection($hub, $registry, $hubState));
            }
            if ($this->gateway->matches($request->path, $registry) !== null) {
                $peers = $this->projection->peerDomains($hub, $this->config['hosts']['hq']);
                if (!$this->gateway->allowedPeer($request, $peers)) {
                    return PbbLanding_Response::json(403, array('error' => 'gateway_peer_not_allowed'));
                }
                return $this->gateway->forward($request, $registry);
            }
            if ($this->isRootRead($request)) {
                return $this->headAware($request, PbbLanding_Response::html(200, $this->renderer->publicHub($this->projection->publicProjection($hub, $registry, $hubState))));
            }
            return PbbLanding_Response::json(404, array('error' => 'not_found'));
        }

        return PbbLanding_Response::json(404, array('error' => 'unknown_host'));
    }

    private function internal(PbbLanding_Request $request)
    {
        if ($request->path === '/internal/registry/apps' && $request->method === 'GET') {
            return PbbLanding_Response::json(200, $this->registry->read());
        }

        if (preg_match('#^/internal/registry/apps/([a-z0-9][a-z0-9-]*)$#', $request->path, $matches)) {
            $appId = $matches[1];
            $context = array(
                'host' => PbbLanding_Host::normalize($request->host),
                'source' => isset($request->server['REMOTE_ADDR']) ? $request->server['REMOTE_ADDR'] : null,
            );

            if ($request->method === 'PUT') {
                $payload = $request->json();
                if (!is_array($payload)) {
                    return PbbLanding_Response::json(422, array('error' => 'invalid_json'));
                }
                list($ok, $errors, $record) = $this->registry->putApp($appId, $payload, $context);
                if (!$ok) {
                    return PbbLanding_Response::json(422, array('error' => 'invalid_registry_record', 'errors' => $errors));
                }
                return PbbLanding_Response::json(200, array('app' => $record));
            }

            if ($request->method === 'DELETE') {
                $this->registry->deleteApp($appId, $context);
                return PbbLanding_Response::json(200, array('deleted' => $appId));
            }
        }

        return PbbLanding_Response::json(404, array('error' => 'not_found'));
    }

    private function developmentHosts()
    {
        $value = isset($this->config['hosts']['development']) ? (string) $this->config['hosts']['development'] : '';
        if (trim($value) === '') {
            return array();
        }

        $hosts = array();
        foreach (explode(',', $value) as $host) {
            $normalized = PbbLanding_Host::normalize($host);
            if ($normalized !== '') {
                $hosts[] = $normalized;
            }
        }

        return array_values(array_unique($hosts));
    }

    private function isRootRead(PbbLanding_Request $request)
    {
        return $request->path === '/' && ($request->method === 'GET' || $request->method === 'HEAD');
    }

    private function isReadinessRead(PbbLanding_Request $request)
    {
        return ($request->path === '/up' || $request->path === '/api/health')
            && ($request->method === 'GET' || $request->method === 'HEAD');
    }

    private function headAware(PbbLanding_Request $request, PbbLanding_Response $response)
    {
        if ($request->method !== 'HEAD') {
            return $response;
        }

        return new PbbLanding_Response($response->status, $response->headers, '');
    }

    private function asset($name)
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $name;
        if (!is_file($path)) {
            return PbbLanding_Response::text(404, 'Not found');
        }
        $type = substr($name, -4) === '.css' ? 'text/css; charset=utf-8' : 'application/javascript; charset=utf-8';
        return new PbbLanding_Response(200, array('Content-Type' => $type), file_get_contents($path));
    }
}
