# PBB Landing Design Contract

## Purpose

PBB Landing is a lightweight PHP/JS app named `pbb-landing` with two surfaces:

- Public hub landing at `https://{hub.domain}`.
- Local LAN launcher and private registry API at `https://pbb.ph`.

Landing discovers hub identity from Relay and installed app metadata from its own registry. It must not become the authority for sibling app behavior.

## Source Context

Active Relay hub snapshot:

```text
C:/wamp64/www/pbb/relay/public/hub.json
```

Current Relay `hub.json` top-level shape:

```json
{
  "base_url": "https://hub.pbb.ph",
  "hub_id": 11,
  "relay_hub_id": "072217",
  "name": "CEBU CITY, CEBU",
  "code": "cebu-cebu",
  "deployment": "city",
  "domain": "cebu-cebu.pbb.ph",
  "status": "active",
  "country_code": "PH",
  "reg_code": "07",
  "prov_code": "0722",
  "citymun_code": "072217",
  "brgy_code": null,
  "uplinks": [],
  "sources": [],
  "hydrated_at": "2026-06-13T03:23:26+00:00",
  "hydrated_from": "hq_heartbeat",
  "snapshot_version": "hub-11:...",
  "snapshot_hash": "...",
  "hq_snapshot_hash": "..."
}
```

Important: Relay `hub.json` includes hierarchy and source hub details. Landing public output must use a deny-by-default projection and must not publish `uplinks`, `sources`, internal topology, hashes, local paths, ports, or secrets.

## Host Model

Landing determines request behavior from the effective HTTP host after normalization:

- `pbb.ph`: local LAN launcher and `/internal/*` only.
- Relay `hub.json.domain`: public hub page, public metadata projection, and explicitly enabled public gateway routes only.
- Any other host: fail closed.

Host comparisons must be exact after lowercasing, trimming one trailing dot, and removing any request port.

## Public-Safe Hub Projection

Endpoint:

```text
GET /.well-known/pbb-hub.json
```

No `/hub.json` public alias is planned for Phase 1. Relay keeps owning its own `hub.json`; Landing serves only a sanitized projection under the well-known path.

Allowed only on:

```text
https://{hub.domain}
```

Denied on:

```text
https://pbb.ph
```

Source:

- Prefer request-time read/fetch of Relay `hub.json`.
- Relay hydrates `hub.json` on every Relay-HQ heartbeat, so Landing should treat Relay as the live hub identity source.
- If cached, use a short TTL and include freshness metadata.
- If Relay is unavailable, return a degraded state clearly marked as unavailable or stale.

Draft response:

```json
{
  "schema_version": 1,
  "generated_at": "2026-06-14T18:00:00+08:00",
  "source": {
    "name": "pbb-relay",
    "fresh": true,
    "hydrated_at": "2026-06-13T03:23:26+00:00"
  },
  "hub": {
    "id": "072217",
    "hq_id": "11",
    "name": "CEBU CITY, CEBU",
    "code": "cebu-cebu",
    "deployment": "city",
    "domain": "cebu-cebu.pbb.ph",
    "status": "active",
    "country": "PH",
    "region_code": "07",
    "province_code": "0722",
    "citymun_code": "072217",
    "barangay_code": null,
    "last_heartbeat_at": "2026-06-13T03:23:26+00:00"
  },
  "services": {
    "relay": "available"
  },
  "endpoints": {
    "relay": "https://cebu-cebu.pbb.ph/relay/api/v1"
  }
}
```

Public allowlist from Relay:

- `hub_id` as `hq_id`.
- `relay_hub_id` as `id`.
- `name`.
- `code`.
- `deployment`.
- `domain`.
- `status`.
- `country_code` as `country`.
- `reg_code` as `region_code`.
- `prov_code` as `province_code`.
- `citymun_code`.
- `brgy_code` as `barangay_code`.
- `hydrated_at` as source freshness and `last_heartbeat_at`.

Public denylist:

- `base_url`.
- `uplinks`.
- `sources`.
- `snapshot_version`.
- `snapshot_hash`.
- `hq_snapshot_hash`.
- Any app install paths, local hostnames, LAN IPs, ports, credentials, tokens, private key paths, CA paths, or exact package/build internals.

## Registry Schema

Private file:

```text
storage/registry.json
```

Draft shape:

```json
{
  "schema_version": 1,
  "generated_at": "2026-06-14T18:00:00+08:00",
  "generated_by": "kit-setup",
  "apps": {
    "pbb-relay": {
      "id": "pbb-relay",
      "name": "PBB Relay",
      "display_name": "Relay",
      "version": "1.1.0",
      "enabled": true,
      "install_scope": "local",
      "install_path": "C:/wamp64/www/pbb/relay",
      "public_path": "C:/wamp64/www/pbb/relay/public",
      "local_url": "https://relay.pbb.ph",
      "launch_url": "https://relay.pbb.ph",
      "health_url": "https://relay.pbb.ph/api/status",
      "audience": ["admin", "support"],
      "launcher": {
        "visible": true,
        "sort": 20,
        "icon": "comms.radio",
        "logo_url": "https://relay.pbb.ph/assets/pbb-relay-mark.svg",
        "logo_kind": "mark"
      },
      "public_gateway": {
        "enabled": true,
        "path_prefix": "/relay",
        "target_base_url": "https://relay.pbb.ph",
        "allowed_path_prefixes": ["/api/v1/"],
        "m2m_only": true
      }
    }
  }
}
```

Validation rules:

- `schema_version` must be `1`.
- `apps` is an object keyed by canonical app id.
- Record `id` must match the URL path `{app_id}` for writes.
- `enabled` must be boolean.
- `install_scope` must be `local`, `remote`, or `disabled`.
- `audience` values should be from `citizen`, `operator`, `admin`, `support`.
- URLs must be absolute HTTPS URLs for production Kit installs.
- `launcher.logo_url` is optional but preferred; apps should provide an HTTPS square mark or compact logo hosted by their own app. `launcher.icon` remains a Helper icon fallback only.
- `launcher.logo_kind` must be `mark` for square marks or `logo` for wider compact logos.
- Recommended app-owned launcher mark path is `public/assets/launcher/app-icon.png`, exposed as `https://{app-local-domain}/assets/launcher/app-icon.png`.
- Launcher marks should be square, tightly cropped to the rounded app tile, text-free, centered, and legible at `44px` on a dark UI. Avoid extra white canvas or margins; use transparency when possible.
- Landing renders `logo_kind=mark` in a stable `44x44` slot with `object-fit: contain` and small padding. Important glyphs should not depend on edge-to-edge details.
- `public_gateway.m2m_only` must be `true` when public gateway is enabled.
- `public_gateway.path_prefix` must start with `/`, must not end with `/`, and must be unique across enabled gateway records.
- `public_gateway.target_base_url` must be a Kit-provided local app URL, never caller-provided during gateway handling.
- `public_gateway.allowed_path_prefixes` must be a non-empty array of non-root target paths, such as `["/api/v1"]`; Landing forwards only paths under these app-declared machine API namespaces.

## Internal Registry API

Routes:

```text
GET    /internal/registry/apps
PUT    /internal/registry/apps/{app_id}
DELETE /internal/registry/apps/{app_id}
```

Allowed only when:

- Host is exactly `pbb.ph`.
- Host is not Relay `hub.json.domain`.
- Authorization uses a Kit-generated token.

Auth header:

```text
Authorization: Bearer <landing-registry-token>
```

Token handling:

- Kit Setup generates the plaintext token during Landing install or repair.
- Landing config stores only a token hash.
- Landing compares with constant-time hash verification.
- Token values must never be logged.

Responses:

- `200 OK` for successful `GET`.
- `200 OK` or `204 No Content` for successful `PUT` and `DELETE`.
- `401 Unauthorized` for missing or invalid token.
- `404 Not Found` for `/internal/*` on public host.
- `422 Unprocessable Entity` for invalid payload.

Write behavior:

- Validate entire normalized registry before commit.
- Write via temp file in `storage/`, then atomic rename.
- Append audit lines to `storage/logs/landing-audit.log`.
- Audit includes timestamp, action, app id, host, source address, result, and validation summary. It must not include tokens or request bodies.

### Kit Registry Sync Contract

Kit Setup calls Landing only through the local LAN host:

```text
https://pbb.ph/internal/registry/apps/{app_id}
```

Do not use loopback for Kit-to-Landing integration.

Auth:

```http
Authorization: Bearer <landing-registry-token>
Content-Type: application/json
Accept: application/json
```

Token source:

- Kit generates the plaintext registry token during Landing install or repair.
- Kit stores its own secret copy for future app install/repair registry sync.
- Landing receives only `hash('sha256', token)` in config.
- Landing never logs the token or request body.

Methods:

- `GET /internal/registry/apps`: returns the full private registry document.
- `PUT /internal/registry/apps/{app_id}`: create-or-replace upsert for exactly one app. Repeating the same payload is idempotent except `generated_at` and audit timestamp.
- `DELETE /internal/registry/apps/{app_id}`: remove one app entry. Deleting a missing app is idempotent and still returns success.

Current status codes:

- `200 OK`: successful `GET`, successful `PUT`, successful `DELETE`.
- `401 Unauthorized`: missing or invalid bearer token.
- `404 Not Found`: any `/internal/*` request on the public hub host or unknown host.
- `405 Method Not Allowed`: unsupported method on a registry item route.
- `422 Unprocessable Entity`: payload is invalid.

Successful `PUT` response:

```json
{
  "ok": true,
  "app": {
    "id": "pbb-hotline",
    "name": "PBB Hotline",
    "display_name": "Hotline",
    "version": "5.6.1",
    "enabled": true,
    "install_scope": "local",
    "install_path": "C:/wamp64/www/pbb/hotline",
    "public_path": "C:/wamp64/www/pbb/hotline/public",
    "local_url": "https://hotline.pbb.ph",
    "launch_url": "https://hotline.pbb.ph/command",
    "health_url": "https://hotline.pbb.ph/up",
    "audience": ["citizen", "operator", "command", "admin"],
    "launcher": {
      "visible": true,
      "sort": 10,
      "icon": "hotline",
      "logo_url": "https://hotline.pbb.ph/assets/launcher/app-icon.png",
      "logo_kind": "mark"
    },
    "surfaces": {
      "public": "https://hotline.pbb.ph",
      "citizen": "https://hotline.pbb.ph/citizen",
      "operator": "https://hotline.pbb.ph/operator",
      "command": "https://hotline.pbb.ph/command",
      "admin": "https://hotline.pbb.ph/admin"
    },
    "public_gateway": {
      "enabled": false,
      "reason": "Phase 1 public gateway scope is Relay only."
    }
  }
}
```

Validation error response:

```json
{
  "error": "invalid_registry_record",
  "errors": [
    "id must match route app_id",
    "launcher.logo_url must be an absolute HTTPS URL"
  ]
}
```

Required app fields for Kit:

- `id`: canonical app id, equal to route `{app_id}`.
- `name`: formal/internal product name, such as `PBB Hotline`.
- `display_name`: short production-facing launcher label, such as `Hotline`. Landing uses this as the launcher tile label and default launcher image alt text when present.
- `enabled`: boolean.

Strongly recommended app fields:

- `version`.
- `display_name`: short launcher label. If omitted, Landing falls back to `name`.
- `install_scope`: `local`, `remote`, or `disabled`; defaults to `local`.
- `install_path`.
- `public_path`.
- `local_url`: absolute HTTPS local LAN app URL.
- `launch_url`: absolute HTTPS primary launcher URL.
- `health_url`: absolute HTTPS advisory liveness URL.
- `audience`: array such as `citizen`, `operator`, `command`, `admin`, `support`.
- `launcher.visible`: boolean.
- `launcher.sort`: integer ordering.
- `launcher.logo_url`: absolute HTTPS app-owned mark/logo URL.
- `launcher.logo_alt`: optional accessible text for the app image only when it intentionally differs from `display_name`.
- `launcher.logo_kind`: `mark` or `logo`.
- `launcher.icon`: fallback Helper icon key only.
- `surfaces`: optional object of named HTTPS URLs for secondary app surfaces.
- `source.release_json`: optional local release metadata source path for diagnostics.

Gateway fields:

- Non-Relay apps in Phase 1 should set `public_gateway.enabled=false`.
- Relay should set:

```json
{
  "public_gateway": {
    "enabled": true,
    "path_prefix": "/relay",
    "target_base_url": "https://relay.pbb.ph",
    "allowed_path_prefixes": ["/api/v1/"],
    "m2m_only": true
  }
}
```

Asset reachability:

- Landing validates `launcher.logo_url` is absolute HTTPS.
- Landing does not fetch or block registry writes on asset reachability in Phase 1.
- Kit should verify asset reachability after app vhost/certificate setup and treat failure as a registry sync warning unless the install policy later promotes it to a blocker.

## Public App Gateway Contract

Landing gateway routing is registry-driven. Landing owns the common host gates, peer checks, header filtering, size limits, TLS forwarding, and logging. App-specific public prefixes and app-specific machine API namespaces come from `registry.json` so new PBB apps do not require Landing code changes.

Phase 1 enables Relay only, using this registry entry:

Public route:

```text
https://{hub.domain}/relay/api/v1/{tail}
```

Forward target:

```text
https://relay.pbb.ph/api/v1/{tail}
```

Allowed only when:

- Host is exactly Relay `hub.json.domain`.
- Host is not `pbb.ph`.
- A registry app has `enabled=true`.
- The same app has `public_gateway.enabled=true` and `public_gateway.m2m_only=true`.
- The request path starts with that app's `public_gateway.path_prefix`.
- After stripping `public_gateway.path_prefix`, the target path remains under one of that app's `public_gateway.allowed_path_prefixes`.
- Forward target uses that app's configured `public_gateway.target_base_url`.

Phase 1 gateway path policy:

- Allow only app-declared machine API namespaces, currently Relay's `/relay` gateway prefix plus `/api/v1` target namespace.
- Do not expose browser UI, session, bootstrap, CSRF, login, or user routes through any public gateway.
- Allow incoming public gateway machine traffic only from `hub.pbb.ph` and domains derived from the current Relay `hub.json.uplinks[]` and `hub.json.sources[]`.
- Derive the gateway peer allowlist from current Relay `hub.json` at request time or via a very short TTL cache. Do not persist a separate peer registry in Landing.
- Store only the normalized domain strings needed for gateway allowlist checks, such as `uplink_domain`, `source_domain`, `uplinks[].hub.domain`, and `sources[].hub.domain`.
- Do not expose the gateway peer allowlist through public `/.well-known/pbb-hub.json`.
- Landing must not trust `Origin`, `Referer`, `X-Forwarded-Host`, or other caller-supplied identity headers as proof that a request came from those domains. Caller authentication remains Relay's responsibility.

Explicitly denied:

```text
/relay
/relay/
/relay/login
/relay/assets/*
/relay/api/bootstrap
/relay/api/csrf-token
/relay/api/login
/relay/api/user
```

Forwarding rules:

- Strip only the matched app `public_gateway.path_prefix` before forwarding.
- Preserve method, query string, request body, and content type.
- Filter hop-by-hop headers.
- Do not trust public caller identity headers.
- Set target host to the configured local target host, such as `relay.pbb.ph`.
- Enforce request size limits.
- Keep TLS verification enabled using the Kit-provided CA bundle.
- Return the target response status, body, and safe headers.
- Log route, method, source address, target app, target URL path, response status, timing, and body size. Do not log secrets or full bodies.

## Kit Setup Responsibilities

During install or repair, Kit Setup must provide:

- Landing app install path and public path.
- `https://pbb.ph` vhost pointing to Landing.
- `https://{hub.domain}` vhost pointing to Landing after Relay `hub.json.domain` is known.
- Landing registry token generation and delivery.
- Landing config values:
  - local host: `pbb.ph`
  - Relay hub JSON URL or local path
  - registry token hash
  - CA bundle path
  - request size limits
  - audit log path
- Registry records for every selected local or remote app.
- Launcher identity fields for each registered app:
  - discover app-provided launcher asset metadata from release or installer metadata when available,
  - ensure bundled app assets land under the app public path, recommended `public/assets/launcher/app-icon.png`,
  - verify the resulting HTTPS URL is reachable after vhost/certificate setup,
  - write `display_name`, `launcher.logo_url`, and `launcher.logo_kind` to Landing registry,
  - omit `launcher.logo_alt` unless the accessible image text intentionally differs from `display_name`,
  - keep `launcher.icon` as a fallback when no app-owned logo is available.
- Relay public gateway record for Phase 1.
- Health/smoke checks for local launcher, public metadata, internal route gating, and Relay gateway refusal/forwarding behavior.

Kit Setup must call Landing through:

```text
https://pbb.ph/internal/registry/apps/{app_id}
```

Kit Setup must not use loopback as the Landing integration URL.

## Release Recommendation

Initial package metadata should use:

```json
{
  "schema_version": 1,
  "app": "pbb-landing",
  "name": "PBB Landing",
  "version": "0.1.0",
  "display_version": "v0.1.0",
  "release_name": "Landing Surface And Gateway Baseline",
  "type": "php-lightweight"
}
```

Use `0.1.0` while Landing is being introduced as a new app and Kit integration is still being proven. Promote to `1.0.0` only after Phase 1 acceptance criteria pass end-to-end through Kit Setup.

## Security And Test Cases

Security tests:

- Public projection excludes `uplinks`, `sources`, `base_url`, hashes, paths, ports, and secrets.
- Public projection works only on exact `hub.json.domain`.
- Public projection fails on `pbb.ph`.
- `/internal/*` works only on `pbb.ph` with a valid token.
- `/internal/*` fails on public hub host even with a valid token.
- `/relay/api/v1/*` works only on public hub host.
- `/relay/api/v1/*` fails on `pbb.ph`.
- Browser UI paths under `/relay` fail publicly.
- Gateway refuses unregistered prefixes.
- Gateway refuses registered apps with `public_gateway.enabled=false`.
- Gateway refuses target URLs not present in trusted `registry.json`.
- Gateway rejects oversize bodies.
- Gateway filters spoofable identity and hop-by-hop headers.
- Registry writes are atomic.
- Audit logs do not expose tokens or request payload secrets.

Implementation tests:

- Host normalization unit tests.
- Hub projection sanitizer unit tests.
- Registry payload validation unit tests.
- Registry atomic write tests.
- Internal host/token gating feature tests.
- Public/local surface separation feature tests.
- Gateway route matching unit tests.
- Gateway forwarding feature test with a fake Relay target.
- Regression tests proving browser UI routes are not proxied.
