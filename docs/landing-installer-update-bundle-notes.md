# Landing Installer And Update Bundle Notes

Source Kit docs reviewed:

- `C:\wamp64\www\pbb\kit-setup\docs\app-bundle-packaging-standard.md`
- `C:\wamp64\www\pbb\kit-setup\docs\app-bundle-versioning-and-update-contract.md`
- `C:\wamp64\www\pbb\kit-setup\docs\app-installer-template.md`
- `C:\wamp64\www\pbb\kit-setup\docs\kit-setup-runner.md`
- `C:\wamp64\www\pbb\kit-setup\docs\pre-build-verification-checklist.md`
- `C:\wamp64\www\pbb\kit-setup\docs\updater-workflow.md`

## Target Bundle Shape

Landing should produce a canonical bundle:

```text
pbb-landing-0.1.0.zip
```

Expected archive layout:

```text
release.json
checksums.sha256
app/
  config/
  docs/
  public/
  src/
  storage/
  tests/                 optional; normally excluded from production bundle
installer/
  index.php              optional browser entrypoint
  install-run.php        required unattended runner
  status.php             required status endpoint
  schema/
    install.schema.json
docs/
  release-notes.md
```

Landing is a lightweight PHP app with no Composer or npm runtime dependency. The production bundle should still be built from a clean staging directory and exclude `.git`, logs, local caches, temp files, and any generated runtime state.

## Release Metadata Gaps

Current `release.json` is a baseline app identity file. Before bundling, add or verify:

- `build.version`
- `build.id`
- `build.built_at`
- `build.git_commit`
- `build.builder`
- `release_date`
- `repository.type=github`
- `repository.owner=jybanez`
- `repository.repo=landing.pbb.ph`
- `repository.url=https://github.com/jybanez/landing.pbb.ph`
- `updates.source=github-releases`
- `updates.channel=stable` or `testing`
- `update.contract_version`
- `update.from_versions`
- `update.compatibility`
- `update.requires_database_migration=false`
- `update.requires_data_prep_rerun=false`
- `update.requires_service_restart=false`
- `update.rollback_supported=true`

Landing should declare no runtime services:

```json
"runtime_services": []
```

Landing should declare its own Landing metadata disabled for launcher/gateway unless Kit wants Landing to appear as a launcher app. The important integration is that Landing stores other app registry records, not that it registers itself.

## Installer Responsibilities

Landing installer should support:

- `preflight`
- `fresh`
- `repair`
- `upgrade`

Minimum unattended command:

```powershell
php installer/install-run.php --mode fresh --config <config.json> --report <report.json>
```

Install modes should:

- validate PHP `>=8.2` and extensions `json`, `openssl`, and `curl`
- validate `app.install_path` and `app.public_path`
- copy staged `app/` payload into `app.install_path`
- preserve runtime `storage/registry.json` during repair/upgrade when present
- preserve runtime logs but never package them
- create `storage/logs`
- write a local install manifest
- write a safe report
- avoid editing global Apache config directly

Kit owns:

- `https://pbb.ph` vhost
- `https://{hub.domain}` vhost
- TLS/certificate files
- Apache include generation and restart
- Landing registry token generation
- passing only the SHA-256 token hash to Landing configuration
- syncing other apps to Landing registry after their install/repair/update

## Configuration Needs

Landing needs a Kit-provided config path or generated PHP config values for:

- local host: `pbb.ph`
- HQ host: `hub.pbb.ph`
- Relay `hub.json` source path
- registry path
- audit log path
- gateway log path
- registry token hash
- gateway body limit
- gateway timeout
- CA bundle path, if needed for local TLS trust

The installer must not print or report the plaintext Landing registry token.

## Update Behavior

Landing updates should be file-preserving:

- replace application code/assets/docs
- preserve `storage/registry.json`
- preserve logs
- preserve generated config/token hash
- preserve any install manifest
- rerun PHP syntax and basic status checks when possible

Landing currently has no database migration, no runtime service, and no Data Prep rerun requirement.

If a future update changes public gateway enforcement, update metadata should flag it clearly because it affects cross-app exposure policy.

## Bundle Audit

Before handing a Landing bundle to Kit:

- build from a clean stage, not the working checkout directly
- generate `checksums.sha256` after staging
- verify checksum scan is clean
- verify `.git`, logs, caches, temp files, and local runtime secrets are absent
- verify `public/.htaccess` is present
- verify `release.json` repository/update metadata is present
- verify `installer/install-run.php`, `installer/status.php`, and schema exist
- run Landing PHP tests with `C:\wamp64\bin\php\php8.2.29\php.exe`
- compute archive SHA-256
- copy ZIP to Kit `packages\bundled`
- update Kit `packages\packages.bundled.json`
- publish the same ZIP as a GitHub Release asset for updater flows
- announce the app id, version, build id, commit, archive hash, and compatibility in the chat log

## Current Landing Tooling

Implemented in this repo:

- `installer/install-run.php` supports `preflight`, `fresh`, `repair`, and `upgrade`.
- `installer/schema/install.schema.json` documents the Kit-provided config payload.
- `installer/status.php` reports installed release and manifest state.
- `tools/build-bundle.php` stages a Kit-shaped ZIP, writes `checksums.sha256`, injects build metadata, and refuses canonical builds from a dirty worktree unless `--allow-dirty` is passed for a test build.
- Installer smoke tests cover preflight, fresh install, token hash handling, and repair registry preservation.

The canonical release ZIP should be produced after the implementation commit is clean, then handed to Kit and attached to the matching GitHub release.
