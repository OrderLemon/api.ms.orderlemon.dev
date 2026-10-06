# Auth plugin

Public entry point for merchant sign-in. The browser calls this gateway; the gateway forwards to
login.ms (never exposed), which does the real work. See login.ms's `v2/plugins/Auth/README.md` for
the full flow and the browser code.

## Endpoint

`POST /v2/auth/apple` with body:

| Field | |
|---|---|
| `code` | `authorization.code` from Apple's JS popup |
| `nonce` | the **raw** nonce the browser kept in memory (Apple only saw its SHA-256) |
| `client_id` | optional; defaults to login.ms's `default_client_id` |

| Result | Meaning for the UI |
|---|---|
| `200` + merchant | Signed in |
| `404 no_account` | No merchant with this Apple email. If `details.is_private_email` is true, ask them to sign in again and choose "Share My Email". |
| `409 email_ambiguous` | Several merchants share this email; contact support |
| `403 account_disabled` | Merchant account is disabled |
| `401 invalid_token` / `apple_invalid_code` | Code expired, already used, or nonce mismatch; restart sign-in |
| `422 validation_failed` | Missing or malformed fields |
| `502 service_error` | login.ms or its configuration failed (the reason is logged here, not shown) |

## Secret config (api.ms)

Add login.ms to `universe` and map the function:

```json
"universe": [
    { "name": "login.ms", "ip": "<login-ms-host>", "port": 443, "token": "<login.ms ms_server_token>", "ssl": true }
],
"function_map": {
    "auth_apple_login": { "service": "login.ms", "method": "POST", "path": "/auth/apple/login" }
}
```
