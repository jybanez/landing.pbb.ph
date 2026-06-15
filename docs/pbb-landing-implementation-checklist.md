# PBB Landing Implementation Checklist

Status legend:

- `[ ]` Not started
- `[~]` In progress
- `[x]` Complete
- `[!]` Blocked or needs decision

## Phase 0: Design Baseline

- [x] Read `C:/wamp64/www/pbb/kit-setup/docs/pbb-landing-project-proposal.md`.
- [x] Inspect active Relay `hub.json` shape at `C:/wamp64/www/pbb/relay/public/hub.json`.
- [x] Confirm Relay `hub.json` includes private-adjacent hierarchy data requiring public projection.
- [x] Draft public-safe hub projection schema.
- [x] Draft private `registry.json` schema.
- [x] Draft internal registry API with auth and host gating.
- [x] Draft Relay public gateway forwarding contract.
- [x] Identify Kit Setup install/repair responsibilities.
- [x] List security and test cases before implementation.
- [x] Create design contract doc.
- [x] Create implementation checklist doc.

## Phase 1: Project Skeleton

- [x] Choose exact lightweight PHP structure.
- [x] Create `public/index.php` front controller.
- [x] Create `config/landing.php`.
- [x] Create `storage/registry.json` seed file.
- [x] Create `storage/logs/.gitkeep` or equivalent empty log directory marker.
- [x] Create minimal routing layer.
- [x] Create response helpers for JSON, HTML, and errors.
- [x] Add local development instructions.

## Phase 2: Core Security Gates

- [x] Implement host normalization.
- [x] Implement surface detection for `pbb.ph` versus Relay `hub.json.domain`.
- [x] Implement deny-by-default fallback for unknown hosts.
- [x] Implement `/internal/*` local-host-only gate.
- [x] Implement public-host-only gate for gateway routes.
- [x] Implement token hash configuration.
- [x] Implement bearer token validation with constant-time compare.
- [x] Add tests for host normalization.
- [x] Add tests for local/public/internal route separation.

## Phase 3: Relay Hub Projection

- [x] Implement Relay `hub.json` source reader.
- [ ] Implement optional short TTL cache if needed.
- [x] Implement projection sanitizer with explicit allowlist.
- [x] Implement degraded public state when Relay data is unavailable.
- [x] Implement `GET /.well-known/pbb-hub.json`.
- [x] Implement public hub HTML page from the same sanitized projection.
- [x] Add tests proving denied Relay fields never appear in public output.
- [ ] Add tests for unavailable or malformed Relay `hub.json`.

## Phase 4: Registry Storage And API

- [x] Implement registry reader with empty/default fallback.
- [x] Implement registry schema validation.
- [x] Implement URL and gateway prefix normalization.
- [x] Implement atomic registry writes.
- [x] Implement registry audit logging.
- [x] Implement `GET /internal/registry/apps`.
- [x] Implement `PUT /internal/registry/apps/{app_id}`.
- [x] Implement `DELETE /internal/registry/apps/{app_id}`.
- [x] Seed initial sample registry entries for `pbb-relay` and `pbb-hotline`.
- [x] Document exact Kit registry sync API contract.
- [~] Add tests for valid and invalid registry payloads.
- [ ] Add tests for atomic write behavior.
- [ ] Add tests proving tokens and request bodies are not written to audit logs.

## Phase 5: Local Launcher

- [x] Render local launcher only on `https://pbb.ph`.
- [x] Read launcher entries from `registry.json`.
- [ ] Group or label entries by audience.
- [x] Show app health status badges.
- [x] Add lightweight JS health refresh against local health endpoints.
- [x] Add optional app-owned launcher logo rendering with Helper icon fallback.
- [x] Collect launcher logo URLs from Relay, Hotline Beta, and Support.
- [x] Use official PBB Helper UI assets through the preferred `dist` bundle path.
- [x] Keep Landing-specific CSS thin.
- [x] Add browser smoke checks for local launcher rendering.

## Phase 6: Public App Gateway

- [x] Implement gateway registry lookup.
- [x] Implement registry-driven app gateway prefix matching.
- [x] Implement registry-driven allowed target path prefixes.
- [x] Strip only the matched app gateway prefix before forwarding.
- [x] Preserve query string, method, body, and content type.
- [x] Filter hop-by-hop and spoofable identity headers.
- [x] Set target host header to the matched app gateway target host.
- [x] Enforce request body size limits.
- [x] Forward with TLS verification using Kit CA bundle.
- [x] Return target response status, body, and safe headers.
- [x] Add gateway metadata logging.
- [x] Add tests for allowed Relay routes.
- [x] Add tests for denied UI/browser routes.
- [x] Add tests proving non-Relay app gateway records work without Landing route changes.
- [x] Add tests for local-host gateway refusal.
- [x] Add tests for disabled or missing registry gateway config.

## Phase 7: Kit Setup Integration

- [x] Define Landing release/install contract.
- [ ] Add or update Kit package metadata for `pbb-landing`.
- [ ] Add Landing local vhost generation for `pbb.ph`.
- [ ] Add Landing public vhost generation for Relay `hub.json.domain`.
- [ ] Generate and persist Landing registry token.
- [ ] Configure Landing with token hash, Relay `hub.json` source, CA bundle, and limits.
- [ ] Add Kit registry POST after each app install/repair.
- [ ] Add Kit launcher logo discovery, HTTPS validation, and registry field writing.
- [ ] Register Relay with Phase 1 public gateway settings.
- [ ] Add Kit repair behavior for stale or missing registry entries.
- [ ] Add Kit smoke checks for local launcher, public projection, internal gating, and gateway refusal.
- [ ] Ensure generated Apache vhosts route all non-file paths to Landing `public/index.php`.

## Phase 8: Verification And Hardening

- [x] Run PHP syntax checks.
- [x] Run unit tests.
- [x] Run feature tests.
- [x] Run local launcher browser verification.
- [x] Verify public projection contains no denied fields.
- [x] Verify public gateway fails on `pbb.ph`.
- [x] Verify internal registry routes fail on public hub host, even with token.
- [x] Verify Relay gateway forwarding against local Relay.
- [x] Verify Landing front-controller rewrite for `pbb.ph` gateway paths.
- [!] Verify public hub-domain gateway through Apache vhost.
- [x] Review logs for secret leakage.
- [x] Update implementation checklist statuses.

## Gateway Test Notes

- `https://pbb.ph/relay/api/v1/diagnostics` reaches Landing and returns `404 {"error":"gateway_not_available_on_local_host"}`.
- `http://pbb.ph/relay/api/v1/diagnostics` reaches Landing and returns the same local-host gateway refusal.
- App-level request handling on current Relay hub domain `cebu-cebu.pbb.ph` forwards `/relay/api/v1/diagnostics` to Relay and returns Relay diagnostics with HTTP `200`.
- Raw and encoded dot-segment gateway paths are rejected before forwarding.
- Current WAMP Apache config binds Landing to `pbb.ph` only. Current Relay hub domain `cebu-cebu.pbb.ph` is not yet a Landing `ServerName` or `ServerAlias`, so true public-domain HTTP/HTTPS gateway probing is blocked until Kit or Apache vhost setup maps the Relay `hub.json.domain` to Landing.

## Open Decisions

- [x] Public metadata endpoint is only `/.well-known/pbb-hub.json` in Phase 1; it is a sanitized projection of Relay `hub.json`.
- [x] Relay `hub.json` remains the live source and is hydrated on every Relay-HQ heartbeat.
- [x] Internal registry `GET` returns the full private registry behind `pbb.ph` plus token.
- [x] Local admin registry inspection page is deferred; inspect `storage/registry.json` manually for now.
- [x] Recommended first version is `0.1.0` with release name `Landing Surface And Gateway Baseline`.
- [x] Gateway peer policy: allow incoming public gateway machine traffic only from `hub.pbb.ph` and domains derived from current Relay `hub.json.uplinks[]` and `hub.json.sources[]`.
- [x] Public projection peer policy: do not expose peer details publicly; store or cache only normalized domain strings needed for internal gateway allowlist checks.
