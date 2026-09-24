# FreeSWITCH + Blucom — A–Z Setup Guide

> This guide describes the previous customer-portal workflow and is not the active admin-only MVP procedure. See [MVP Milestone 1](MVP_MILESTONE_1.md) for the current implementation. **Do not apply the FreeSWITCH steps below.** In particular, the `bind-url`/`<settings>` XML-CURL examples below do not match the documented `gateway-url`/`<bindings>` configuration. Binding `directory` or `dialplan` replaces static lookup for that whole section, so the working XML must stay available until a tested cutover and rollback are ready. Reference: [FreeSWITCH XML-CURL manual](https://developer.signalwire.com/freeswitch/integration/xml-curl/).

Configure FreeSWITCH to pull directory/dialplan from Laravel (XML-CURL), register your first SIP number in the admin panel, and connect a softphone (Zoiper).

**Reference server (from AGENTS.md)**

| Item | Value |
|------|--------|
| OS | Debian GNU/Linux 13 |
| FreeSWITCH | 1.11.3-release |
| Public IPv4 | `5.202.19.86` |
| Internal profile | `internal` → SIP `5060`, context `default` |
| External profile | `external` → SIP `5080`, context `public` |
| Provider gateway | `provider-trunk` → `172.28.238.162:5060` |
| Test DID | `982191093464` |
| Test extension | `1000` |

> Rules: never expose `default` to unauthenticated provider traffic; never put customer passwords in Git; never restart FreeSWITCH for CRUD.

---

## 0. Architecture (what talks to what)

```text
Zoiper / SIP phone          Laravel (Blucom)              FreeSWITCH
      |  REGISTER                |                              |
      |------------------------->|  (no SIP)                    |
      |                          |                              |
      |                     POST /internal/freeswitch/xml      |
      |                          |<-----------------------------|  mod_xml_curl
      |                          |  directory / dialplan XML    |
      |                          |----------------------------->|
      |<-------------------------|  200 OK XML                  |
      |  200 OK (via FS)         |                              |

Provider --SIP--> external:5080 --context public--> dialplan from Laravel
Phone    --SIP--> internal:5060 --context default--> dialplan from Laravel
```

Laravel owns configuration. FreeSWITCH executes it. Laravel never carries RTP.

---

## 1. Prerequisites

On the **application server** (where this repo runs):

1. PHP 8.3+, MySQL, Redis (if used), this app bootstrapped (`composer setup` or equivalent).
2. `.env` configured (section 2).
3. App reachable **over HTTP from the FreeSWITCH host** (private net preferred, or public HTTPS URL).

On the **FreeSWITCH server** (`5.202.19.86`):

1. FreeSWITCH installed and currently working (baseline from AGENTS.md).
2. Outbound HTTP allowed to the Laravel URL (or SSH tunnel / reverse proxy).
3. SIP + RTP open to the world as required:

```bash
# Example (ufw) — adjust to your firewall
ufw allow 5060,5080/udp
ufw allow 5060,5080/tcp
ufw allow 16384:32768/udp   # RTP
```

Confirm baseline **before** any change:

```bash
fs_cli -x "status"
fs_cli -x "sofia status"
fs_cli -x "sofia status profile internal"
fs_cli -x "sofia status profile external"
fs_cli -x "gateway status"          # expect provider-trunk Registered
```

**Backup first** (AGENTS Rule 10):

```bash
sudo tar czf /root/freeswitch-backup-$(date +%F).tgz /etc/freeswitch
```

---

## 2. Laravel environment

Edit `.env` on the app server:

```env
APP_URL=http://hub.blucom.ir
# or the URL FreeSWITCH will call — must match what FS uses below

FREESWITCH_XML_CURL_TOKEN=<long-random-secret>   # e.g. openssl rand -hex 32

VOIP_SIP_HOST=5.202.19.86
VOIP_SIP_PORT=5060
VOIP_DIRECTORY_DOMAIN=5.202.19.86
VOIP_COUNTRY_CODE=98
```

Apply config:

```bash
php artisan config:clear
php artisan migrate --force
php artisan cache:clear
```

Create the platform admin (CLI only):

```bash
php artisan admin:user create --mobile=+98912XXXXXXX --name="Admin"
php artisan admin:user list
```

Serve the app (production: nginx/php-fpm; quick test: `php artisan serve --host=0.0.0.0 --port=8000`).

**Token rule:** FreeSWITCH sends `X-FS-Token` (or `Authorization: Bearer`). If the token env is empty, Laravel answers **503**. Never commit the real token.

---

## 3. Install / enable `mod_xml_curl` on FreeSWITCH

```bash
fs_cli -x "load mod_xml_curl"
fs_cli -x "module_exists mod_xml_curl"
```

If missing, install the package that provides `mod_xml_curl` for your FreeSWITCH build, then load it again.

Add to autoload so it survives restarts — typically:

`/etc/freeswitch/autoload_configs/modules.xml.xml` → ensure  
`<load module="mod_xml_curl"/>` is present (inspect the real file first; do not remove working entries).

---

## 4. XML-CURL binding (the only new FS config file you need)

Create **`/etc/freeswitch/autoload_configs/xml_curl.conf.xml`** (inspect first if it already exists):

```xml
<configuration name="xml_curl.conf" description="XML Curl">
  <settings>
    <!-- Directory + dialplan from Laravel -->
    <param name="bind-url" value="http://127.0.0.1:8000/internal/freeswitch/xml?user=${user}&amp;domain=${domain}"/>
    <!-- Prefer the real app URL FreeSWITCH can reach: -->
    <!-- <param name="bind-url" value="http://APP_INTERNAL_HOST/internal/freeswitch/xml"/> -->

    <param name="auth-user-var" value="X-FS-Token"/>
    <!-- Many builds use bind-paths style auth: set token via bind URL or header plugin below. -->
  </settings>
</configuration>
```

**Recommended binding** (token in header — confirm your FS build’s syntax in `mod_xml_curl` docs; alternative: pass token as query string and also accept it in Laravel):

Exact bind styles vary by FreeSWITCH build. Use **one** of:

**A. Shared secret in URL** (simple):

```xml
<param name="bind-url"
       value="http://APP_HOST:PORT/internal/freeswitch/xml?token=YOUR_TOKEN&amp;section=${section}&amp;key_name=${key_name}&amp;key_value=${key_value}"/>
```

Laravel currently expects header **`X-FS-Token`** or **`Authorization: Bearer …`**. If you can only pass the token in the query string, either:

- put a reverse proxy (nginx) in front of the endpoint that injects the header from the query, **or**
- change `AuthenticateFreeSwitch` to also read `token` from the request (small code change).

**B. Nginx on the app host** injects the header:

```nginx
# FreeSWITCH reaches only this path
location = /internal/freeswitch/xml {
    internal; # optional: only from FS IP / private net
    allow 5.202.19.86;
    deny all;

    proxy_set_header X-FS-Token "YOUR_TOKEN";
    proxy_pass http://127.0.0.1:8000;
}
```

FS bind-url → `http://nginx-or-app/internal/freeswitch/xml?section=${section}…`

**Security requirements (AGENTS §10):**

- Endpoint is **internal only** (firewall allow-list FreeSWITCH IP → Laravel port).
- Strong shared secret (or mTLS / private network).
- Never expose `/internal/freeswitch/xml` to the public internet without auth.

**Enable bindings** (directory + dialplan):

```xml
<param name="bind-url" value="http://APP/internal/freeswitch/xml?section=${section}"/>
```

`section` is supplied by FreeSWITCH as `directory` or `dialplan` — Laravel routes on it.

Reload:

```bash
fs_cli -x "reloadxml"
fs_cli -x "reload mod_xml_curl"
fs_cli -x "fs_cli -x \"show xml_curl\""   # if supported
```

---

## 5. Smoke-test XML-CURL **before** removing static files

From the FreeSWITCH host (or any host that can reach Laravel):

```bash
TOKEN='your-token'

# Directory lookup for extension 1000 (after extension exists in DB)
curl -sS -X POST "http://APP/internal/freeswitch/xml" \
  -H "Content-Type: application/json" \
  -H "X-FS-Token: $TOKEN" \
  -d '{"section":"directory","user":"1000","domain":"5.202.19.86"}'

# Public (inbound) dialplan
curl -sS -X POST "http://APP/internal/freeswitch/xml" \
  -H "Content-Type: application/json" \
  -H "X-FS-Token: $TOKEN" \
  -d '{"section":"dialplan","context":"public"}'

# Default (outbound) dialplan
curl -sS -X POST "http://APP/internal/freeswitch/xml" \
  -H "Content-Type: application/json" \
  -H "X-FS-Token: $TOKEN" \
  -d '{"section":"dialplan","context":"default","variable_user":"1000"}'
```

**Healthy signs**

| Check | Expected |
|-------|----------|
| HTTP | `200`, `Content-Type: application/xml` |
| Directory miss | `<document type="freeswitch/xml">` with **no** `<user` |
| Directory hit | `<user id="1000">` + `password` param + `user_context=default` |
| Public + route | `condition` on DID + `transfer` `1000 XML default` |
| Default + auth user | `bridge` `sofia/gateway/provider-trunk/...` |
| Wrong/missing token | `401` (or `503` if token env unset) |

**Failure checklist**

| Symptom | Likely cause |
|---------|----------------|
| 503 | `FREESWITCH_XML_CURL_TOKEN` empty |
| 401 | Wrong token / header name |
| 403 | App auth middleware (shouldn’t apply — endpoint uses token middleware) |
| HTML in body | PHP error — check `storage/logs/laravel.log` |
| Empty directory | Extension missing/disabled, or tenant disabled |
| Empty dialplan | No enabled route, number not `assigned`, or tenant disabled |
| Connection refused | FS cannot reach app URL / firewall |

---

## 6. Sofia profiles — leave working config alone

Do **not** rewrite these for CRUD. Confirm only:

**Internal** (`/etc/freeswitch/sip_profiles/internal.xml` or conf vars):

- `sip-ip` / `rtp-ip` = `5.202.19.86`
- `context` = `default`
- port `5060`

**External**:

- `context` = `public`
- port `5080`

Directory source for internal profile should use XML binding (xml_curl) **in addition to or instead of** static `directory/` files — inspect how your build includes directory. Typical approach:

Ensure dialplan contexts load from XML curl. On many installs, `dialplan/default.xml` and `dialplan/public.xml` still exist as static files. During migration:

1. Keep static files.
2. Verify xml_curl responses with curl (section 5).
3. Confirm `fs_cli` log shows configuration fetched via xml_curl when a call/register happens.
4. Only then remove **customer-specific** static entries (e.g. `directory/default/1000.xml`, the static DID condition under `dialplan/public`).

Never delete working config before the replacement is verified (AGENTS §30).

---

## 7. Admin panel — register your first SIP number (catalog)

Host: **`admin.blucom.ir`** (hostname containing `admin.`).

1. **Login** with the admin mobile (OTP). First admin comes from `php artisan admin:user create …`.
2. **داشبورد** shows real counts (orgs, assigned/available/pending numbers, extensions, gateways).
3. **دروازه‌ها (Gateways)**
   - Create e.g. `provider-trunk`
   - Host `172.28.238.162`, port `5060`, transport `udp`
   - Username/password = provider credentials (write-only; never displayed)
   - Profile/context are **locked** to `external` / `public` by the server
   - Leave **فعال**
4. **شماره‌ها (Numbers)** — add to inventory:
   - Example: `982191093464` (or `092191093464` — normalized to E.164)
   - Optional: set **دروازه/ترانک** to `provider-trunk`
   - Status becomes **موجود (available)**
5. **سازمان‌ها (Tenants)** — review orgs; disable a tenant to cut directory + dialplan access immediately (no FS restart).

**BYOD path (customer-owned numbers):** customer submits a number → status **در انتظار** → admin **تأیید و تخصیص** (or **تأیید → سبد**).

---

## 8. Customer panel — first number + extension + routes

Host: **`hub.blucom.ir`**.

1. **Login** with customer mobile (OTP). First use auto-creates the tenant.
2. **شماره‌های SIP**
   - Either **تخصیص به من** on a pool number the admin created,  
   - or submit BYOD and wait for approval.
3. **داخلی‌ها**
   - Create extension e.g. `1000`
   - Copy the **one-time credentials banner**:
     - User: `1000`
     - Password: `<shown once>`
     - Server: `5.202.19.86:5060`
4. **مسیر ورودی**
   - DID `…982191093464` → internal `1000` → فعال
5. **مسیر خروجی**
   - DID (caller ID) → gateway `provider-trunk` → فعال

No FreeSWITCH restart, no `reloadxml` for any of this (XML-CURL is dynamic).

---

## 9. Softphone (Zoiper) registration

On Zoiper (desktop or mobile):

| Field | Value |
|-------|--------|
| Account type | SIP |
| Username / User | `1000` |
| Password | from one-time banner |
| Server / Domain | `5.202.19.86` |
| Port | `5060` |
| Transport | UDP (unless you enabled TLS) |
| Outbound proxy | leave empty (or `5.202.19.86:5060`) |

**Verify on FreeSWITCH:**

```bash
fs_cli -x "sofia status profile internal reg"
# expect 1000 … Registered … Registration Count: 1

fs_cli -x "sofia status"
```

Watch logs while registering:

```bash
tail -f /var/log/freeswitch/freeswitch.log
# or: fs_cli -x "sofia loglevel all 9"  (then turn back down)
```

**Directory is fetched from Laravel** — if registration fails with 401 after a good DB password:

1. curl directory for `1000` (section 5) — must show the same password.
2. Confirm `VOIP_DIRECTORY_DOMAIN` matches the domain Zoiper sends (`5.202.19.86` or your configured domain).
3. Confirm tenant is **active**.
4. Confirm extension `enabled=1`.

---

## 10. Test matrix

### A. Registration

```text
Zoiper → internal:5060 → xml_curl directory → Laravel DB → 200 OK → Registered
```

### B. Outbound

```text
Zoiper 1000 dials 0912… or +989…
  → context default
  → Laravel dialplan: approved outbound route
  → bridge sofia/gateway/provider-trunk/<dest>
  → provider
```

Check:

```bash
fs_cli -x "sofia status gateway provider-trunk"
```

Caller ID must be the authorized DID (e.g. `982191093464`), not the extension number.

### C. Inbound

```text
Provider → external:5080 → context public
  → destination_number = DID
  → Laravel dialplan: transfer "1000 XML default"
  → extension 1000 → Zoiper rings
```

Log line to look for:

```text
Processing +989… <+989…>->982191093464 in context public
```

### D. Isolation / safety

| Test | Expected |
|------|----------|
| Unknown DID | No transfer (empty/other extension) |
| Unknown outbound caller | No bridge |
| Tenant disabled in admin | Directory empty / no route for that tenant |
| Customer releases number | Inbound route gone on next xml_curl fetch |
| CRUD in Laravel only | **No** FS restart |

---

## 11. Day-2 operations (no restarts)

| Action | FS impact |
|--------|-----------|
| Create/assign number, extension, route | None — next xml_curl pull |
| Disable gateway / tenant / number | None — XML reflects DB |
| Edit gateway host/credentials in admin | New outbound bridges use new values; **existing gateway registration** may need `fs_cli -x "sofia profile external rescan"` or gateway reload once |
| Change Sofia profiles / modules / xml_curl.conf | `reloadxml` or module reload — not for CRUD |
| FreeSWITCH crash/restart | Static bootstrap + xml_curl return; keep `xml_curl.conf.xml` in backup |

Rotate `FREESWITCH_XML_CURL_TOKEN` → update FS bind + `systemctl restart` **only if** token cannot be hot-reloaded; prefer updating both sides in a maintenance window.

---

## 12. Rollback

If xml_curl misbehaves:

1. Disable/remove `xml_curl.conf.xml` binding (or unload `mod_xml_curl`).
2. Restore static `directory/default/*.xml` and `dialplan/public` DID condition from `/root/freeswitch-backup-*.tgz`.
3. `fs_cli -x "reloadxml"`.
4. Verify register + inbound + outbound against the baseline (AGENTS §26 Rule 12).

---

## Quick command cheat sheet

```bash
# FreeSWITCH
fs_cli -x "status"
fs_cli -x "sofia status profile internal reg"
fs_cli -x "gateway status"
fs_cli -x "reloadxml"
fs_cli -x "reload mod_xml_curl"
fs_cli -x "console loglevel notice"

# Laravel
php artisan migrate:status
php artisan config:clear
php artisan admin:user list
php artisan test

# XML-CURL probe
curl -sS -X POST "$APP_URL/internal/freeswitch/xml" \
  -H 'Content-Type: application/json' \
  -H "X-FS-Token: $TOKEN" \
  -d '{"section":"directory","user":"1000"}'
```

---

## Minimal path (if you only do five things)

1. Set `FREESWITCH_XML_CURL_TOKEN` + `VOIP_DIRECTORY_DOMAIN` in Laravel `.env`, migrate, create admin.  
2. Add `xml_curl.conf.xml` bind to `/internal/freeswitch/xml` with token; firewall to FS only; `reload mod_xml_curl`.  
3. curl directory + dialplan until XML looks right.  
4. Admin panel: create `provider-trunk` + number `982191093464`.  
5. Customer panel: assign number, create ext `1000`, inbound + outbound routes; Zoiper → `5.202.19.86:5060`.

Then run the test matrix and keep static FS files until inbound + outbound both pass.
