<?php

class PbbLanding_Renderer
{
    private $app;

    public function __construct(array $app = array())
    {
        $this->app = $app;
    }

    public function publicHub(array $projection)
    {
        $hub = isset($projection['hub']) && is_array($projection['hub']) ? $projection['hub'] : array();
        $services = isset($projection['services']) && is_array($projection['services']) ? $projection['services'] : array();

        $serviceHtml = '';
        foreach ($services as $name => $state) {
            $serviceHtml .= '<li><span class="service-name">' . $this->helperIcon('status.info') . $this->e($name) . '</span><strong class="ui-badge">' . $this->e($state) . '</strong></li>';
        }

        return '<!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>PBB Hub</title>' . $this->styles() . $this->scripts() . '</head><body class="public-surface">'
            . '<main class="shell"><section class="ui-surface panel">'
            . '<p class="ui-eyebrow eyebrow">Project Bantay Bayan Hub</p>'
            . '<h1 class="ui-title">' . $this->e(isset($hub['name']) ? $hub['name'] : 'PBB Hub') . '</h1>'
            . '<dl class="facts">'
            . '<dt>Hub ID</dt><dd>' . $this->e(isset($hub['id']) ? $hub['id'] : 'Unavailable') . '</dd>'
            . '<dt>Domain</dt><dd>' . $this->e(isset($hub['domain']) ? $hub['domain'] : 'Unavailable') . '</dd>'
            . '<dt>Status</dt><dd>' . $this->e(isset($hub['status']) ? $hub['status'] : 'degraded') . '</dd>'
            . '<dt>Last heartbeat</dt><dd>' . $this->e(isset($hub['last_heartbeat_at']) ? $hub['last_heartbeat_at'] : 'Unavailable') . '</dd>'
            . '</dl><h2>Public Services</h2><ul class="service-list">' . $serviceHtml . '</ul>'
            . '</section></main></body></html>';
    }

    public function launcher(array $hubProjection, array $registry)
    {
        $hub = isset($hubProjection['hub']) && is_array($hubProjection['hub']) ? $hubProjection['hub'] : array();
        $apps = isset($registry['apps']) && is_array($registry['apps']) ? $registry['apps'] : array();
        uasort($apps, array($this, 'sortApps'));

        $appHtml = '';
        $launcherItems = array();
        foreach ($apps as $app) {
            if (!is_array($app) || empty($app['enabled'])) {
                continue;
            }
            $name = isset($app['name']) ? $app['name'] : $app['id'];
            $launch = isset($app['launch_url']) ? $app['launch_url'] : (isset($app['local_url']) ? $app['local_url'] : '#');
            $health = isset($app['health_url']) ? $app['health_url'] : '';
            $audience = isset($app['audience']) && is_array($app['audience']) ? implode(', ', $app['audience']) : '';
            $identity = $this->appIdentity($app);
            $label = $audience !== '' ? $name . ' - ' . $audience : $name;
            $launcherItems[] = $this->launcherItem($app, $name, $launch, $health, $audience);
            $appHtml .= '<a class="app-tile" data-health-url="' . $this->e($health) . '" href="' . $this->e($launch) . '" aria-label="' . $this->e($label) . '">'
                . '<span class="app-tile-icon-wrap">' . $identity . '<span class="status-dot checking" data-health-badge aria-label="Checking"></span></span>'
                . '<span class="app-tile-name">' . $this->e($name) . '</span>'
                . '</a>';
        }

        if ($appHtml === '') {
            $appHtml = '<section class="ui-surface empty-state"><span class="app-icon large">' . $this->helperIcon('data.grid') . '</span><p class="empty">No apps are registered yet.</p></section>';
        }

        $hubName = isset($hub['name']) ? $hub['name'] : 'PBB Landing';
        $displayVersion = isset($this->app['display_version'])
            ? $this->app['display_version']
            : (isset($this->app['version']) ? 'v' . $this->app['version'] : '');
        $subtitle = trim('PBB Landing ' . $displayVersion);

        return '<!doctype html><html lang="en" data-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>PBB Landing</title>' . $this->styles() . $this->scripts() . '</head><body class="local-surface">'
            . '<nav class="ui-navbar landing-navbar" aria-label="PBB launcher"><div class="ui-navbar-start">'
            . '<a class="ui-navbar-brand" href="/" aria-label="PBB Landing">'
            . '<span class="ui-navbar-brand-text"><span class="ui-navbar-brand-label">' . $this->e($hubName) . '</span><span class="ui-navbar-brand-subtitle">' . $this->e($subtitle) . '</span></span>'
            . '</a></div><div class="ui-navbar-end"><span class="ui-navbar-status"><span class="ui-navbar-status-text">Local Network</span>'
            . '<span class="ui-badge badge">pbb.ph</span></span></div></nav>'
            . '<main class="shell launcher-shell"><section class="app-grid" id="launcher-grid" data-enhance="icon-grid">' . $appHtml . '</section>'
            . '<script type="application/json" id="launcher-items">' . $this->json($launcherItems) . '</script></main></body></html>';
    }

    private function sortApps($a, $b)
    {
        $as = isset($a['launcher']['sort']) ? (int) $a['launcher']['sort'] : 100;
        $bs = isset($b['launcher']['sort']) ? (int) $b['launcher']['sort'] : 100;
        if ($as === $bs) {
            return strcmp(isset($a['name']) ? $a['name'] : '', isset($b['name']) ? $b['name'] : '');
        }
        return $as < $bs ? -1 : 1;
    }

    private function styles()
    {
        return '<link rel="stylesheet" href="/vendor/helpers.pbb.ph/dist/helpers.ui.bundle.min.css"><link rel="stylesheet" href="/assets/app.css">';
    }

    private function scripts()
    {
        return '<script type="module" src="/assets/app.js?v=0.21.90"></script>';
    }

    private function appIdentity(array $app)
    {
        $launcher = isset($app['launcher']) && is_array($app['launcher']) ? $app['launcher'] : array();
        $name = isset($app['name']) ? $app['name'] : (isset($app['id']) ? $app['id'] : 'PBB App');
        $logoUrl = isset($launcher['logo_url']) ? (string) $launcher['logo_url'] : '';
        if ($logoUrl !== '' && preg_match('/^https:\/\/[^\/\s]+\/.+/i', $logoUrl)) {
            $kind = isset($launcher['logo_kind']) && in_array($launcher['logo_kind'], array('mark', 'logo'), true) ? $launcher['logo_kind'] : 'mark';
            $alt = isset($launcher['logo_alt']) ? (string) $launcher['logo_alt'] : $name . ' logo';
            return '<span class="app-logo app-logo-' . $this->e($kind) . '"><img src="' . $this->e($logoUrl) . '" alt="' . $this->e($alt) . '" loading="lazy" decoding="async"></span>';
        }

        $icon = isset($launcher['icon']) ? $launcher['icon'] : 'data.grid';
        return '<span class="app-icon">' . $this->helperIcon($icon) . '</span>';
    }

    private function launcherItem(array $app, $name, $launch, $health, $audience)
    {
        $launcher = isset($app['launcher']) && is_array($app['launcher']) ? $app['launcher'] : array();
        $logoUrl = isset($launcher['logo_url']) ? (string) $launcher['logo_url'] : '';
        $icon = isset($launcher['icon']) ? (string) $launcher['icon'] : 'data.grid';
        $item = array(
            'id' => isset($app['id']) ? (string) $app['id'] : (string) $name,
            'label' => (string) $name,
            'description' => (string) $audience,
            'href' => (string) $launch,
            'icon' => $icon,
            'status' => 'busy',
            'tone' => 'neutral',
            'meta' => array(
                'healthUrl' => (string) $health,
            ),
        );

        if ($logoUrl !== '' && preg_match('/^https:\/\/[^\/\s]+\/.+/i', $logoUrl)) {
            $item['image'] = $logoUrl;
        }

        return $item;
    }

    private function helperIcon($name)
    {
        return '<span class="helper-icon" data-helper-icon="' . $this->e($name) . '" aria-hidden="true"></span>';
    }

    private function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function json(array $value)
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
