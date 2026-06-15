# PBB Landing

PBB Landing is a lightweight PHP/JS app for the local PBB LAN launcher and public hub-safe metadata/gateway surface.

## Local Development

Use the PHP runtime bundled with this WAMP install:

```powershell
& 'C:\wamp64\bin\php\php8.2.29\php.exe' -S 127.0.0.1:8088 -t public
```

The production local host is `https://pbb.ph`. Kit Setup will provide vhosts, TLS, registry token configuration, and app registry records.

For browser-only local smoke checks without a `pbb.ph` vhost, opt into a dev host before starting the server:

```powershell
$env:PBB_LANDING_DEV_HOSTS = '127.0.0.1,localhost'
& 'C:\wamp64\bin\php\php8.2.29\php.exe' -S 127.0.0.1:8088 -t public
```

Do not set `PBB_LANDING_DEV_HOSTS` in Kit-managed installs.

## Internal Registry Token

Set:

```text
PBB_LANDING_REGISTRY_TOKEN_HASH=<sha256 of Kit-generated token>
```

Internal registry requests use:

```text
Authorization: Bearer <Kit-generated token>
```
