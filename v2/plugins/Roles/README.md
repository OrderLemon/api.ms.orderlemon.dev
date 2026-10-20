# Roles plugin (gateway to users.ms)

Roles and permissions for the app. Every route is called with the device token
(`Authorization: Bearer <device token>`) and lives under `/v2/roles/…`. users.ms owns the data
and the rules; this plugin only forwards.

**Everything is per shop.** Each shop has its own Owner, Billing and Staff with their own
toggles, and a user has one role per shop. Calls about roles or other users name the shop with
`shop_id`; "Team" and "Owner" below mean *in that shop*.

| Method | Path | Body | Forwards to | Who |
|---|---|---|---|---|
| GET | `/registry` | | `users_registry`: every permission by category, with `owner_only` | anyone |
| GET | `/me` | | `users_roles_me`: the caller's shops, role and permissions in each | anyone |
| POST | `/list` | `{shop_id}` | `users_roles_list`: the shop's roles with their toggles | Team |
| PUT | `/permissions` | `{shop_id, role_id, feature, enabled}` | `users_roles_permissions_update`: switch one toggle | Owner |
| POST | `/get` | `{shop_id, phonenumber}` | `users_roles_get`: that user's role in the shop | Team / yourself |
| PUT | `/assign` | `{shop_id, phonenumber, role_id}` | `users_roles_assign`: give a role in the shop; `role_id: null` removes access | Team (Owner role: Owner) |
| POST | `/create` | `{shop_id, name, description?, permissions: [feature…]}` | `users_roles_create` (201): a custom role with exactly those permissions on | Owner |
| PUT | `/rename` | `{shop_id, role_id, name, description?}` | `users_roles_rename`: rename a custom role | Owner |
| POST | `/delete` | `{shop_id, role_id}` | `users_roles_delete`: delete a custom role nobody has | Owner |

**System roles** (Owner, Billing, Staff; `system: true` in `/list`) can't be renamed or deleted
(`409 system_role`). Billing's and Staff's toggles can still be switched. A custom role's
`permissions` must be known features and not owner-only; its name is unique within the shop
(`409 role_name_taken`); it can only be deleted once nobody has it (`409 role_in_use`).

Errors keep users.ms's code and details: `validation_failed` 422, `forbidden` 403,
`user_not_found` 404, `role_locked` / `role_name_taken` / `role_in_use` / `system_role` 409,
`shop_lookup_unavailable` 503. Unknown errors are
logged and become `502 service_error`.

## How it works

- **Depends on the Users plugin** (`plugin.json` → `"dependencies": ["Users"]`). It uses that
  plugin's `UsersService` (calls users.ms and keeps its error codes) and `DeviceIdentity` (sends
  the device token as `device_uuid` and its `company_id` claim; a client can't override them).
- **No new config node.** It calls users.ms through the same `universe` node as the Users plugin.

## Config

These functions must be in `api.ms`'s `function_map`:

```json
"users_registry":                 { "service": "users.ms", "method": "POST", "path": "/users/registry" },
"users_roles_me":                 { "service": "users.ms", "method": "POST", "path": "/users/roles/me" },
"users_roles_list":               { "service": "users.ms", "method": "POST", "path": "/users/roles/list" },
"users_roles_permissions_update": { "service": "users.ms", "method": "PUT",  "path": "/users/roles/permissions" },
"users_roles_get":                { "service": "users.ms", "method": "POST", "path": "/users/roles/get" },
"users_roles_assign":             { "service": "users.ms", "method": "PUT",  "path": "/users/roles" },
"users_roles_create":             { "service": "users.ms", "method": "POST", "path": "/users/roles/create" },
"users_roles_rename":             { "service": "users.ms", "method": "PUT",  "path": "/users/roles/rename" },
"users_roles_delete":             { "service": "users.ms", "method": "POST", "path": "/users/roles/delete" }
```

Don't add a `roles` entry to `resources` in api.ms's config: it would collide with this
plugin's slug and stop the service at boot.

`/v2/users/roles/shop-added` in users.ms (a new shop's roles and first Owner) is a service call
for whatever creates shops; it is **not** exposed here.
