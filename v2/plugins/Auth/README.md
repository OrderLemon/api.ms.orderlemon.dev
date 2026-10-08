# Auth plugin

Public entry point for user sign-in with Apple and Google. The browser calls this gateway; the gateway
forwards to login.ms (never exposed), which does the real work. See login.ms's `v2/plugins/Auth/README.md`
for the full guide: provider setup, frontend code and troubleshooting.

## Endpoints

| Endpoint | Body | Forwards to |
|---|---|---|
| `GET /v2/auth/config` | | `auth_config` |
| `POST /v2/auth/apple` | `code` (from Apple's popup), `nonce` (the **raw** value), `client_id?` | `auth_apple_login` |
| `POST /v2/auth/google` | `id_token` (Google's `credential`), `nonce` (the **raw** value) | `auth_google_login` |
| `POST /v2/auth/password` | `email`, `password` | `auth_password_login` |

Only these fields are forwarded, never the raw request body. The password is never logged.

⚠ **`POST /v2/auth/password` doesn't check the password yet** (`users` has no password column). login.ms only
allows it on non-production environments with an explicit flag; otherwise it returns
`503 password_login_unavailable`. See login.ms's README, section 6.5.

### `GET /v2/auth/config`

Called by the frontend on page load. Returns the public settings for each configured provider; a provider that
isn't configured is left out (hide its button). With nothing configured, `data` is `{}`.

```json
{
  "success": true,
  "data": {
    "apple":  { "client_id": "com.orderlemon.signin", "redirect_uri": "https://app.orderlemon.com/auth/apple/callback" },
    "google": { "client_id": "1234-abc.apps.googleusercontent.com" }
  }
}
```

### Sign-in results

| Result | Meaning for the UI |
|---|---|
| `200` + user | Signed in |
| `404 no_account` | No user with this email. Apple: if `details.is_private_email` is true, ask them to sign in again and choose "Share My Email". |
| `409 email_ambiguous` | Several users share this email; contact support |
| `403 account_disabled` | User account is disabled |
| `401 invalid_token` / `apple_invalid_code` | Expired, reused or forged token/code, or nonce mismatch; restart sign-in |
| `401 invalid_credentials` | Password sign-in: email or password wrong (deliberately not saying which) |
| `503 password_login_unavailable` | Password sign-in isn't enabled on this environment |
| `422 validation_failed` | Missing or malformed fields |
| `502 service_error` | login.ms or its configuration failed (the reason is logged here, not shown) |

## Secret config (api.ms)

```json
"universe": [
    { "name": "login.ms", "ip": "<login-ms-host>", "port": 443, "token": "<login.ms ms_server_token>", "ssl": true }
],
"function_map": {
    "auth_config":       { "service": "login.ms", "method": "GET",  "path": "/auth/config" },
    "auth_apple_login":  { "service": "login.ms", "method": "POST", "path": "/auth/apple/login" },
    "auth_google_login": { "service": "login.ms", "method": "POST", "path": "/auth/google/login" },
    "auth_password_login": { "service": "login.ms", "method": "POST", "path": "/auth/password/login" }
}
```
