# Auth plugin

User sign-in with Apple, Google and email + password. The browser talks to `api.ms`; `api.ms` forwards to
login.ms (never exposed), which does the real work. See login.ms's `v2/plugins/Auth/README.md` for the full
guide: provider setup, frontend code and troubleshooting.

## Endpoints

Sign-in happens on the **public entry point `/auth.php`** at the repo root. It sits outside the v2 front
controller, so the core's Bearer check doesn't apply; it protects itself with short-lived guest tokens and
per-IP rate limits. Only logout is a `/v2` route.

| Endpoint | Auth | Body | Forwards to |
|---|---|---|---|
| `POST /auth.php?a=session` | none | | `auth_config` (for `config`) |
| `POST /auth.php?a=apple` | guest token | `code` (from Apple's popup), `nonce` (the **raw** value), `client_id?` | `auth_apple_login` |
| `POST /auth.php?a=google` | guest token | `id_token` (Google's `credential`), `nonce` (the **raw** value) | `auth_google_login` |
| `POST /auth.php?a=password` | guest token | `email`, `password` | `auth_password_login` |
| `POST /v2/auth/logout` | device token | | `auth_logout` |

Every sign-in also accepts optional `os`, `app_ver`, `language`, `one_signal_id`. Only these fields are
forwarded, never the raw request body. The password is never logged.

⚠ **`?a=password` doesn't check the password yet** (`users` has no password column). login.ms only allows it on
non-production environments with an explicit flag; otherwise it returns `503 password_login_unavailable`.
See login.ms's README, section 6.5.

### Guest tokens (`?a=session`)

Called by the frontend on page load. Returns a guest token for the sign-in calls plus the public provider config:

```json
{
  "success": true,
  "data": {
    "token": "MTc5MTQ2OTY5NS40…Uu_hkL8iEflHrBm3…",
    "expires_at": 1791469695,
    "config": {
      "apple":  { "client_id": "com.orderlemon.signin", "redirect_uri": "https://app.orderlemon.com/auth/apple/callback" },
      "google": { "client_id": "1234-abc.apps.googleusercontent.com" }
    }
  }
}
```

- **Signed, not stored:** `base64url(expires_at.random)` + HMAC-SHA256 with `auth.guest_token_secret`.
  Issuing them costs no Redis memory. They are valid for 10 minutes (`401 guest_token_expired` after that:
  get a new one and retry).
- **Never in `TokenStore`,** so the core rejects them on every `/v2` route: they only work on `/auth.php`.
- **Rate limits per client IP:** 30 guest tokens and 10 sign-in attempts per minute (`429 rate_limited`).
  Behind a proxy or CDN, make sure the real client IP reaches PHP, or all users share one limit.
- A provider that isn't configured in login.ms is left out of `config` (hide its button). With none, `config` is `{}`.

### Device sessions

A successful sign-in returns `{ "user": {…}, "token": "…" }`. login.ms stores the token's hash in
`devices_{company_id}`; this plugin registers the token in the core `TokenStore` **without expiry**, with claims
`{company_id}`. The core `AuthMiddleware` then accepts `Authorization: Bearer <token>` on every `/v2` request,
and `TokenStore::claims($request->bearerToken())` gives the `company_id`; the user is the `devices_{company_id}`
row whose `device_uuid` is the token's SHA-256.

Logout revokes the token in `TokenStore`, then login.ms deletes the device row. If `TokenStore` can't store the
token (Redis off), sign-in answers `503 sessions_unavailable` and the device row is removed again.

**Redis requirements:** persistent (`appendonly yes`), and `maxmemory-policy` not `allkeys-*`
(use `volatile-lru` or `noeviction`), otherwise sessions can disappear. See login.ms's README, section 6.6.

### Sign-in results

| Result | Meaning for the UI |
|---|---|
| `200` + `{user, token}` | Signed in; use `token` as the bearer for `/v2` from now on |
| `401` "Invalid guest token" / `401 guest_token_expired` | Get a new guest token (`?a=session`) and retry |
| `403 no_company` | The user has no company, so no device session can be created |
| `503 sessions_unavailable` | Redis/TokenStore isn't working on api.ms |
| `404 no_account` | No user with this email. Apple: if `details.is_private_email` is true, ask them to sign in again and choose "Share My Email". |
| `409 email_ambiguous` | Several users share this email; contact support |
| `403 account_disabled` | User account is disabled |
| `401 invalid_token` / `apple_invalid_code` | Expired, reused or forged token/code, or nonce mismatch; restart sign-in |
| `401 invalid_credentials` | Password sign-in: email or password wrong (deliberately not saying which) |
| `503 password_login_unavailable` | Password sign-in isn't enabled on this environment |
| `422 validation_failed` | Missing or malformed fields |
| `429 rate_limited` | Too many attempts from this IP; wait a minute |
| `502 service_error` | login.ms or its configuration failed (the reason is logged here, not shown) |

## Code

| File | Role |
|---|---|
| `/auth.php` (repo root) | Boots the v2 container, hands the request to `GuestAuthHandler`, adds CORS headers |
| `src/Guest/GuestAuthHandler.php` | `?a=` dispatch, per-IP rate limits, guest-token check |
| `src/Guest/GuestTokens.php` | Issues and verifies the signed guest tokens |
| `src/Controllers/SignInController.php` | Forwards to login.ms, registers device tokens in `TokenStore`, logout |
| `src/AuthPlugin.php` | Wiring; the `/v2/auth/logout` route |

## Secret config (api.ms)

```json
"universe": [
    { "name": "login.ms", "ip": "<login-ms-host>", "port": 443, "token": "<login.ms ms_server_token>", "ssl": true }
],
"function_map": {
    "auth_config":       { "service": "login.ms", "method": "GET",  "path": "/auth/config" },
    "auth_apple_login":  { "service": "login.ms", "method": "POST", "path": "/auth/apple/login" },
    "auth_google_login": { "service": "login.ms", "method": "POST", "path": "/auth/google/login" },
    "auth_password_login": { "service": "login.ms", "method": "POST", "path": "/auth/password/login" },
    "auth_logout":       { "service": "login.ms", "method": "POST", "path": "/auth/logout" }
},
"auth": {
    "guest_token_secret": "<at least 32 random characters, e.g. openssl rand -hex 32>"
}
```
