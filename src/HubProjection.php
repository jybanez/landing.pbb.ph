<?php

class PbbLanding_HubProjection
{
    public function publicProjection($hub, array $registry, $sourceState)
    {
        if (!is_array($hub)) {
            return array(
                'schema_version' => 1,
                'generated_at' => $this->now(),
                'source' => array(
                    'name' => 'pbb-relay',
                    'fresh' => false,
                    'state' => $sourceState,
                    'hydrated_at' => null,
                ),
                'hub' => array(
                    'status' => 'degraded',
                ),
                'services' => $this->services($registry),
                'endpoints' => array(),
            );
        }

        $domain = $this->stringValue(isset($hub['domain']) ? $hub['domain'] : null);
        $payload = array(
            'schema_version' => 1,
            'generated_at' => $this->now(),
            'source' => array(
                'name' => 'pbb-relay',
                'fresh' => $sourceState === 'ok',
                'state' => $sourceState,
                'hydrated_at' => $this->stringValue(isset($hub['hydrated_at']) ? $hub['hydrated_at'] : null),
            ),
            'hub' => array_filter(array(
                'id' => $this->stringValue(isset($hub['relay_hub_id']) ? $hub['relay_hub_id'] : null),
                'hq_id' => $this->stringValue(isset($hub['hub_id']) ? $hub['hub_id'] : null),
                'name' => $this->stringValue(isset($hub['name']) ? $hub['name'] : null),
                'code' => $this->stringValue(isset($hub['code']) ? $hub['code'] : null),
                'deployment' => $this->stringValue(isset($hub['deployment']) ? $hub['deployment'] : null),
                'domain' => $domain,
                'status' => $this->stringValue(isset($hub['status']) ? $hub['status'] : null),
                'country' => $this->stringValue(isset($hub['country_code']) ? $hub['country_code'] : null),
                'region_code' => $this->stringValue(isset($hub['reg_code']) ? $hub['reg_code'] : null),
                'province_code' => $this->stringValue(isset($hub['prov_code']) ? $hub['prov_code'] : null),
                'citymun_code' => $this->stringValue(isset($hub['citymun_code']) ? $hub['citymun_code'] : null),
                'barangay_code' => $this->stringValue(isset($hub['brgy_code']) ? $hub['brgy_code'] : null),
                'last_heartbeat_at' => $this->stringValue(isset($hub['hydrated_at']) ? $hub['hydrated_at'] : null),
            ), array($this, 'notNull')),
            'services' => $this->services($registry),
            'endpoints' => $this->endpoints($domain, $registry),
        );

        return $payload;
    }

    public function publicHost($hub)
    {
        if (!is_array($hub)) {
            return '';
        }

        return PbbLanding_Host::normalize(isset($hub['domain']) ? $hub['domain'] : '');
    }

    public function peerDomains($hub, $hqHost)
    {
        $domains = array();
        $this->appendDomain($domains, $hqHost);

        if (!is_array($hub)) {
            return $domains;
        }

        foreach (array('uplinks', 'sources') as $listKey) {
            if (!isset($hub[$listKey]) || !is_array($hub[$listKey])) {
                continue;
            }

            foreach ($hub[$listKey] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (array('uplink_domain', 'source_domain') as $key) {
                    if (isset($item[$key])) {
                        $this->appendDomain($domains, $item[$key]);
                    }
                }
                if (isset($item['hub']) && is_array($item['hub']) && isset($item['hub']['domain'])) {
                    $this->appendDomain($domains, $item['hub']['domain']);
                }
            }
        }

        return array_values(array_unique($domains));
    }

    private function services(array $registry)
    {
        $services = array();
        $apps = isset($registry['apps']) && is_array($registry['apps']) ? $registry['apps'] : array();
        foreach ($apps as $id => $app) {
            if (!is_array($app) || empty($app['enabled'])) {
                continue;
            }
            $key = preg_replace('/^pbb-/', '', (string) $id);
            $services[$key] = 'available';
        }

        if (!isset($services['relay'])) {
            $services['relay'] = 'unknown';
        }

        return $services;
    }

    private function endpoints($domain, array $registry)
    {
        $endpoints = array();
        if ($domain === '') {
            return $endpoints;
        }

        $apps = isset($registry['apps']) && is_array($registry['apps']) ? $registry['apps'] : array();
        foreach ($apps as $id => $app) {
            if (!is_array($app) || empty($app['enabled']) || empty($app['public_gateway']['enabled'])) {
                continue;
            }
            $key = preg_replace('/^pbb-/', '', (string) $id);
            $prefix = isset($app['public_gateway']['path_prefix']) ? (string) $app['public_gateway']['path_prefix'] : '';
            $allowed = isset($app['public_gateway']['allowed_path_prefixes']) && is_array($app['public_gateway']['allowed_path_prefixes'])
                ? $app['public_gateway']['allowed_path_prefixes']
                : array();
            if ($prefix !== '' && isset($allowed[0]) && (string) $allowed[0] !== '') {
                $endpoints[$key] = 'https://' . $domain . rtrim($prefix, '/') . '/' . ltrim((string) $allowed[0], '/');
            }
        }

        return $endpoints;
    }

    private function appendDomain(array &$domains, $value)
    {
        $domain = PbbLanding_Host::normalize($value);
        if ($domain !== '') {
            $domains[] = $domain;
        }
    }

    public function notNull($value)
    {
        return $value !== null;
    }

    private function stringValue($value)
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        return null;
    }

    private function now()
    {
        return date('c');
    }
}
