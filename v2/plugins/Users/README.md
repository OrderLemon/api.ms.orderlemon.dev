# Users plugin (gateway to users.ms)

The app's user-management endpoints. Every route is called with the device token
(`Authorization: Bearer <device token>`) and lives under `/v2/users/…`.

| Method | Path | Body / query | Forwards to |
|---|---|---|---|
| GET | `/me` | | `users_get` (the caller) |
| PATCH | `/me` | `{…profile fields}` | `users_update` (the caller; `phonenumber` is dropped) |
| GET | `/list` | `?page=&per_page=&enabled=` | `users_list` (paginated, `meta` block) |
| POST | `/get` | `{phonenumber?}` | `users_get` |
| PATCH | `/` (i.e. `/v2/users`) | `{phonenumber?, …fields}` | `users_update` |
| POST | `/invite` | `{phonenumber, full_name, email?, language?, role_id?}` | `users_invite` (201) |
| POST | `/remove` | `{phonenumber}` | `users_remove` |
| POST | `/resend-invite` | `{phonenumber}` | `users_resend_invite` |

Phonenumbers never go in URLs, so routes that name another user are POST/PATCH with a body.
Which fields can be changed, and who may change another user, is decided by users.ms.

## Identity

The gateway sends users.ms the device token as `device_uuid` and the token's TokenStore
`company_id` claim as `company_id`, using the fixed users.ms service token. It always sets
these two keys itself (a client can't override them). users.ms resolves them through login.ms.
The static `ms_server_token` is not a device session: these routes answer `401` with it.

Errors from users.ms keep their code and details (`validation_failed`, `user_not_found`,
`forbidden`, `user_exists`, `invite_not_pending`, …). Unknown errors are logged and become
`502 service_error`.

## Removed users: the `users.removed` webhook

After `/remove`, users.ms ends the user's sessions in login.ms and emits `users.removed` with
`tokens` (the ended device tokens). This plugin revokes them in the TokenStore, so the removed
user's devices are locked out immediately. The tokens are already dead in login.ms, but the
subscription target must still be HTTPS.

Webhook deliveries carry no Bearer token, so they go to the public entry point
**`/users-events.php`** (repo root), not to `/v2`. It only accepts deliveries whose
`X-Signature: sha256=<hmac>` matches the raw body, keyed with `users.webhook_secret`; without
that secret, every delivery is refused (`500 config_error`). Rate limit: 120 per IP per minute.

## Config

`api.ms` secret config:

```json
"universe": [
    { "name": "users.ms", "ip": "<users-ms-host>", "port": 443, "token": "<users.ms ms_server_token>", "ssl": true }
],
"function_map": {
    "users_get":           { "service": "users.ms", "method": "POST",  "path": "/users/get" },
    "users_list":          { "service": "users.ms", "method": "POST",  "path": "/users/list" },
    "users_update":        { "service": "users.ms", "method": "PATCH", "path": "/users" },
    "users_invite":        { "service": "users.ms", "method": "POST",  "path": "/users/invite" },
    "users_remove":        { "service": "users.ms", "method": "POST",  "path": "/users/remove" },
    "users_resend_invite": { "service": "users.ms", "method": "POST",  "path": "/users/resend-invite" }
},
"users": {
    "webhook_secret": "<at least 32 random characters, e.g. openssl rand -hex 32>"
}
```

`users.ms` webhook subscription (`POST /v2/webhooks` on users.ms), with the **same** secret:

```json
{
    "name": "api.ms token revocation",
    "target": "https://<api-host>/users-events.php",
    "events": ["users.removed"],
    "secret": "<the same users.webhook_secret>"
}
```

Don't add a `users` entry to `resources` in api.ms's config: it would collide with this
plugin's slug and stop the service at boot.

## Not done yet

- The webhook worker on users.ms doesn't retry. If api.ms is down when a user is removed, their
  device tokens stay in the TokenStore. They can't do anything in users.ms (the user is disabled
  and login.ms no longer knows the sessions), but other api.ms routes still accept the token.
- Each call costs users.ms one login.ms round trip to resolve the device.
