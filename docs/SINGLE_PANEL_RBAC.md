# Single panel and role permissions

Blucom now uses one OTP login (`/login`) and one panel (`/dashboard`). The old
`admin.*` and `hub.*` hostnames can both serve this same application; login no
longer selects a role from the hostname. Accounts are created by an admin or
from the CLI. Entering an unknown mobile number does not create an account.

## Roles

| Role | Access |
| --- | --- |
| Admin | Full control of the wizard, advanced SIP configuration, connection review, and users. |
| Operator | Only the explicitly assigned permissions below. |

Operator permissions are stored in `user_permissions`. Admin bypasses those
checks. Every protected wizard action has route middleware, including POST/PUT
actions; hiding a link in the sidebar is not the authorization boundary.

| Permission | What it allows |
| --- | --- |
| `dashboard.view` | Operator dashboard and its scoped totals. |
| `lines.view` | Line list for the shared workspace. |
| `providers.manage` | Add and correct provider connections. |
| `numbers.manage` | Add and correct numbers. |
| `phones.manage` | Choose answerers, view phone setup, reset phone credentials. |

Granting any management permission through the Users page also grants
`lines.view`, so the operator has a way to find the line being managed. Advanced
SIP configuration, connection approval, and user management remain admin only.
Admins manage roles and operator permissions at `/users`. An operator without
any permissions sees `/access-denied` after login.

## CLI

```bash
php artisan operator:create 09123456789 --name="Operator Name"
```

`customer:create` remains as a compatibility alias. Its `--business` option is
ignored because this is one organization. Both commands give the operator the
default wizard permissions. Admins can narrow those permissions at `/users`.
OTP delivery requires the configured Kavenegar provider; when it is missing,
login returns a delivery error instead of logging the one-time code.

## Data transition

The `tenants` table remains an internal FreeSWITCH ownership boundary. The
application uses one `Blucom` workspace; the UI no longer presents customers
or tenant signup. Migration `2026_09_25_000003` changes existing customer
accounts to operators and grants the existing wizard access. Migration
`2026_09_25_000004` moves records from active legacy workspaces into the
shared workspace, keeping their previous ownership in
`workspace_consolidation_log`. Disabled workspaces are left untouched so the
migration cannot reactivate their call routes. Gateway, number, extension,
inbound route, and outbound route IDs remain unchanged.

The customer gateway XML feature gate and existing FreeSWITCH profile settings
are unchanged. XML routing now distinguishes the legacy gateway by its lack of
workspace ownership, rather than by the workspace's `system_key`. This keeps
operator-owned gateways gated even though their records now share the Blucom
workspace. This UI and RBAC change does not make those gateways live.

## Deployment

On the application host, deploy this revision, run `php artisan migrate --force`,
build the Vite assets, and clear cached application configuration/views/routes.
Make sure `public/hot` is absent in production so Laravel serves the built CSS.
The FreeSWITCH server needs no configuration change for this panel update.
