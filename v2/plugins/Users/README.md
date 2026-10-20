# Users plugin (gateway to users.ms)

The app's user-management endpoints. Every route is called with the device token
(`Authorization: Bearer <device token>`) and lives under `/v2/users/…`.

| Method | Path | Body / query | Forwards to |
|---|---|---|---|
| GET | `/me` | | `resolve_session` → `users_get` `{phonenumber: caller}` |
| PUT | `/me` | `{…profile fields}` | `resolve_session` → `users_update` (`phonenumber` is always the caller's) |
| GET | `/{shop_id}/list` | `?role_id=&page=&per_page=&enabled=` | `users_list`: the shop's users with their role there (paginated, `meta` block) |
| POST | `/{shop_id}/get` | `{phonenumber}` | `users_get` |
| PUT | `/{shop_id}` | `{phonenumber, …fields}` | `users_update` |
| POST | `/{shop_id}/invite` | `{phonenumber, full_name, role_id, email?, language?}` | `users_invite` (201): a new user with that role in that shop |
| POST | `/{shop_id}/remove` | `{phonenumber}` | `users_remove`: out of that shop; `account_disabled` when it was their last |
| POST | `/{shop_id}/resend-invite` | `{phonenumber}` | `users_resend_invite` |
Users are per shop: like the other plugins, every call about another user has the shop as
the first path segment (`/v2/users/{shop_id}/…`); the gateway sends it to users.ms as
`shop_id`, and a `shop_id` in the body is ignored. users.ms checks the caller's role there. Roles and permissions are in the **Roles** plugin
(`/v2/roles/…`), which reuses this plugin's users.ms client (`UsersService`) and
`Support\DeviceIdentity`. Full contract: users.ms's Users plugin README.

Phonenumbers never go in URLs, so routes that name another user are POST/PUT with a body.
users.ms requires `phonenumber` on every route that targets a user (`422` without it, `404`
when there is no such user). For `/me`, the gateway gets the caller's phonenumber from login.ms
(`resolve_session`) and sends it.
Which fields can be changed, and who may change another user, is decided by users.ms.

## Identity

The gateway sends users.ms the device token as `device_uuid` and the token's TokenStore
`company_id` claim as `company_id`, using the fixed users.ms service token. It always sets
these two keys itself (a client can't override them). users.ms resolves them through login.ms.
The static `ms_server_token` is not a device session: these routes answer `401` with it.

Errors from users.ms keep their code and details (`validation_failed`, `user_not_found`,
`forbidden`, `user_exists`, `invite_not_pending`, `role_locked`, `shop_lookup_unavailable`, …).
Unknown errors are logged and become `502 service_error`.

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
    "resolve_session":     { "service": "login.ms", "method": "POST",  "path": "/auth/session/resolve" },
    "users_get":           { "service": "users.ms", "method": "POST",  "path": "/users/get" },
    "users_list":          { "service": "users.ms", "method": "POST",  "path": "/users/list" },
    "users_update":        { "service": "users.ms", "method": "PUT",   "path": "/users" },
    "users_invite":        { "service": "users.ms", "method": "POST",  "path": "/users/invite" },
    "users_remove":        { "service": "users.ms", "method": "POST",  "path": "/users/remove" },
    "users_resend_invite": { "service": "users.ms", "method": "POST",  "path": "/users/resend-invite" },
    "users_registry":                 { "service": "users.ms", "method": "POST", "path": "/users/registry" },
    "users_roles_me":                 { "service": "users.ms", "method": "POST", "path": "/users/roles/me" },
    "users_roles_list":               { "service": "users.ms", "method": "POST", "path": "/users/roles/list" },
    "users_roles_permissions_update": { "service": "users.ms", "method": "PUT",  "path": "/users/roles/permissions" },
    "users_roles_get":                { "service": "users.ms", "method": "POST", "path": "/users/roles/get" },
    "users_roles_assign":             { "service": "users.ms", "method": "PUT",  "path": "/users/roles" },
    "users_roles_create":             { "service": "users.ms", "method": "POST", "path": "/users/roles/create" },
    "users_roles_rename":             { "service": "users.ms", "method": "PUT",  "path": "/users/roles/rename" },
    "users_roles_delete":             { "service": "users.ms", "method": "POST", "path": "/users/roles/delete" }
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
