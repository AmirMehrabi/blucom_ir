<laravel-boost-guidelines>




# AGENTS.md — Blucom FreeSWITCH Integration Rules

## 1. Purpose

Blucom is a multi-tenant VoIP SaaS. Laravel is the control plane and source of truth. FreeSWITCH is the real-time SIP/RTP/media engine.

The MVP must let an administrator/customer:

1. Register/manage SIP numbers (DIDs).
2. Configure outbound settings for provider connections.
3. Configure inbound routing for each SIP number.
4. Create/manage SIP extensions.
5. Register SIP clients such as Zoiper.
6. Route inbound PSTN calls to configured extensions.
7. Route outbound extension calls through approved provider gateways.

The current working FreeSWITCH installation is the reference behavior. Do not redesign working SIP behavior unnecessarily.

---

## 2. Current FreeSWITCH Reference Configuration

### Server

- Debian GNU/Linux 13
- FreeSWITCH 1.11.3-release
- Public IPv4: `5.202.19.86`

### Sofia profiles

#### Internal

- Profile: `internal`
- SIP: `5.202.19.86:5060`
- Context: `default`
- Dialplan: XML
- Used by customer SIP endpoints such as Zoiper.
- RTP IP: `5.202.19.86`

#### External

- Profile: `external`
- SIP: `5.202.19.86:5080`
- Context: `public`
- Dialplan: XML
- Used by provider SIP trunks.
- RTP IP: `5.202.19.86`

Never expose the `default` context to unauthenticated provider traffic.

The intended boundary is:

```text
Customer SIP endpoint
        |
        v
 internal:5060
        |
        v
 default context

Provider trunk
        |
        v
 external:5080
        |
        v
 public context
        |
        v
 validated inbound route
        |
        v
 customer extension
```

---

## 3. Current Provider Gateway

Current gateway:

```text
provider-trunk
```

Provider SIP endpoint:

```text
172.28.238.162:5060
```

Gateway profile:

```text
external
```

Gateway context:

```text
public
```

The gateway is currently registered and healthy.

Credentials must NEVER be copied into source control, AGENTS.md, tickets, prompts, seed data, logs, or generated code. Store secrets using environment/secret storage and application encryption where appropriate.

---

## 4. Current Working DID Behavior

The current working test DID is:

```text
982191093464
```

An inbound call currently behaves as:

```text
Provider
  -> external profile
  -> public context
  -> destination_number = 982191093464
  -> transfer 1000 XML default
  -> extension 1000
  -> Zoiper
```

The provider SIP Request-URI contains the gateway identifier:

```text
gw+provider-trunk
```

Do NOT treat that as the DID.

FreeSWITCH exposes the actual called number as:

```text
${destination_number}
```

Example observed in the working installation:

```text
Processing +989336337953 <+989336337953>->982191093464 in context public
```

Therefore inbound routing must match the normalized DID represented by `destination_number`.

---

## 5. Current SIP Extension

Current test extension:

```text
1000
```

It registers against:

```text
5.202.19.86:5060
```

The test SIP password is local test data and MUST NOT be committed.

The extension belongs to the `default` context.

Zoiper successfully registers and makes outbound calls.

---

# 6. Core Architecture Rule

## Laravel owns configuration.

## FreeSWITCH executes configuration.

Do NOT make Laravel SSH into FreeSWITCH and rewrite:

- `/etc/freeswitch/directory/*.xml`
- `/etc/freeswitch/dialplan/*.xml`
- gateway XML files
- per-customer XML files

Do NOT create one XML file per tenant, customer, DID, extension, or route.

The database is the source of truth.

FreeSWITCH should obtain dynamic directory and dialplan configuration through `mod_xml_curl`.

---

# 7. Target Architecture

```text
                    +----------------------+
                    |       Laravel        |
                    |    Blucom Control     |
                    |                      |
                    | Mysql           |
                    | Redis                |
                    | Admin API            |
                    +----------+-----------+
                               |
                         XML-CURL / HTTP
                               |
                               v
                    +----------------------+
                    |      FreeSWITCH      |
                    |                      |
Customer SIP ------>| internal :5060       |
                    |                      |
Provider SIP ------>| external :5080       |
                    |                      |
                    | Sofia                |
                    | XML-CURL             |
                    | RTP                  |
                    +----------------------+
```

Laravel MUST NOT carry RTP.

Laravel MUST NOT become a SIP proxy.

Laravel MUST NOT handle SIP registration packets directly.

FreeSWITCH handles SIP registration, SIP signaling, RTP, media, bridges, ringing, hangup, codecs, and call execution.

Laravel handles configuration, tenants, customers, DIDs, extensions, routing, business rules, billing state, reporting, and administrative actions.

---

# 8. Database Model

Use tenant-scoped records.

At minimum:

## tenants

```text
id
name
status
created_at
updated_at
```

## sip_numbers

A DID/SIP number owned by a tenant.

```text
id
tenant_id
number
normalized_number
provider_gateway_id
status
inbound_enabled
outbound_enabled
created_at
updated_at
```

Recommended constraints:

```text
unique(tenant_id, normalized_number)
```

If the provider network requires global DID uniqueness, also enforce global uniqueness.

## sip_gateways

A provider trunk.

```text
id
name
host
port
transport
username
password_encrypted
profile
context
enabled
created_at
updated_at
```

Never expose provider passwords to the frontend.

## sip_extensions

```text
id
tenant_id
extension
password_encrypted
display_name
enabled
created_at
updated_at
```

Recommended:

```text
unique(tenant_id, extension)
```

## inbound_routes

```text
id
tenant_id
sip_number_id
destination_type
destination_id
enabled
created_at
updated_at
```

For the MVP, `destination_type=extension` is sufficient.

## outbound_routes

```text
id
tenant_id
sip_number_id
gateway_id
enabled
created_at
updated_at
```

This determines which DID/gateway is used for outbound calls.

---

# 9. Domain Model Rules

Do not confuse these concepts:

- provider gateway
- SIP number/DID
- tenant
- SIP extension
- inbound route
- outbound route

Example:

```text
Tenant: Acme

Gateway:
    provider-trunk

DID:
    982191093464

Extension:
    1000

Inbound:
    982191093464 -> 1000

Outbound:
    1000 -> provider-trunk
    caller ID = 982191093464
```

The provider gateway is infrastructure.

The DID belongs to a tenant.

The extension belongs to a tenant.

Routing connects these objects.

---

# 10. XML-CURL

Use `mod_xml_curl` for dynamic FreeSWITCH configuration.

Laravel should expose a dedicated internal endpoint, for example:

```text
POST /internal/freeswitch/xml
```

The exact HTTP method/path may be chosen based on FreeSWITCH's XML-CURL request behavior, but it MUST be an internal server-to-server endpoint.

Do not expose it as a public unauthenticated API.

Protect it using a shared secret, mTLS, private networking, firewall restrictions, or another strong server-to-server mechanism.

The endpoint must return FreeSWITCH-compatible XML, not JSON.

At minimum support:

```text
directory
dialplan
```

The controller must be thin. Business authorization and lookup logic belong in services/domain code.

---

# 11. XML-CURL Directory Behavior

When FreeSWITCH needs a SIP user, Laravel returns directory XML based on the database.

Conceptually:

```text
FreeSWITCH
    |
    | directory request for 1000
    v
Laravel
    |
    | query tenant + extension
    v
Mysql
    |
    v
directory XML
```

The returned directory configuration should contain only what FreeSWITCH needs, such as:

- user ID
- authentication material
- user context
- caller ID configuration
- required SIP feature variables

Do not return unrelated tenant data.

Do not log SIP passwords.

Prefer a FreeSWITCH-compatible password/authentication representation rather than inventing a custom authentication protocol.

---

# 12. XML-CURL Dialplan Behavior

Laravel dynamically generates dialplan XML from database state.

## Inbound

Conceptually:

```text
destination_number
        |
        v
find sip_number
        |
        v
find inbound_route
        |
        v
transfer to extension
```

Current test behavior:

```text
982191093464 -> 1000
```

Equivalent static behavior:

```xml
<condition field="destination_number"
           expression="^(982191093464)$">
    <action application="transfer"
            data="1000 XML default"/>
</condition>
```

The real implementation MUST generate this from database records.

## Outbound

Laravel/database configuration determines:

- gateway
- authorized caller ID/DID
- permitted destination patterns
- local/mobile/international restrictions
- whether outbound calling is enabled

FreeSWITCH then bridges to the approved gateway.

Never allow arbitrary user-provided gateway names or SIP URLs to become bridge targets.

---

# 13. Outbound Security / Toll Fraud

Outbound routing is high risk.

Never do this:

```xml
<action application="bridge"
        data="sofia/gateway/${user_input}/${destination_number}"/>
```

if `user_input` can contain an arbitrary gateway or SIP destination.

Instead:

1. Authenticate the SIP extension.
2. Resolve its tenant.
3. Resolve an approved outbound route.
4. Resolve the gateway from trusted database configuration.
5. Normalize the destination number.
6. Validate the destination against tenant/provider policy.
7. Select an authorized caller ID.
8. Bridge only through the approved gateway.

Do not allow customer SIP headers to override these decisions.

---

# 14. Caller ID Rules

Outbound caller ID comes from an authorized DID/business configuration.

Do not trust arbitrary SIP `From`, `P-Asserted-Identity`, or related customer-provided headers as authorization.

Example:

```text
Extension 1000
    |
    v
Tenant outbound route
    |
    v
DID 982191093464
    |
    v
Provider
```

The customer cannot arbitrarily present a number that is not assigned/authorized.

---

# 15. Number Normalization

Choose one canonical internal format, preferably E.164.

For example:

```text
+9898219109364
```

Do not treat these as unrelated internal identities:

```text
982191093464
0098...
+98...
```

Provider-specific formatting belongs at the provider/gateway boundary.

Document the normalization policy and test it.

---

# 16. Tenant Isolation

Every lookup involving:

- extensions
- DIDs
- inbound routes
- outbound routes
- caller IDs
- tenant-owned gateways

must enforce tenant ownership.

Never trust a `tenant_id` supplied by a SIP endpoint.

A tenant must never access another tenant's:

- extension
- DID
- gateway
- route
- caller ID

---

# 17. Admin vs Customer

Laravel surfaces:

```text
admin.blucom.ir
hub.blucom.ir
```

Admin manages system/provider configuration.

Customer manages tenant-owned:

- SIP numbers
- extensions
- inbound routing
- outbound settings

Customer users MUST NOT configure arbitrary FreeSWITCH infrastructure.

---

# 18. Static FreeSWITCH Configuration After Migration

Static files should contain infrastructure/bootstrap configuration only.

Examples:

```text
sip_profiles/internal.xml
sip_profiles/external.xml
autoload_configs/xml_curl.conf.xml
vars.xml
autoload_configs/*.xml
acl/*.xml
```

Dynamic customer configuration MUST NOT live permanently in:

```text
directory/default/*.xml
dialplan/public/*.xml
dialplan/default/*.xml
```

The existing static files may remain temporarily during migration/testing.

---

# 19. Migration Plan

Do not migrate everything in one uncontrolled change.

### Phase 1 — Verify XML-CURL

Verify `mod_xml_curl` is installed and loadable.

Inspect the currently installed FreeSWITCH configuration before editing it.

### Phase 2 — Configure XML-CURL

Configure FreeSWITCH to call the Laravel internal endpoint.

Use a dedicated internal hostname/IP where possible.

Test with a harmless lookup before removing existing working XML.

### Phase 3 — Migrate extension 1000

Move extension 1000 from static XML to database-backed XML-CURL.

Verify:

- Zoiper registration
- authentication
- outbound calling

### Phase 4 — Migrate inbound DID

Move:

```text
982191093464 -> 1000
```

from static public dialplan to XML-CURL.

Verify inbound calling.

### Phase 5 — Migrate outbound routing

Move gateway selection and caller-ID policy into database-backed dialplan generation.

Verify outbound calling.

### Phase 6 — Remove customer XML

Only after all tests pass should customer-specific static XML be removed.

---

# 20. Do Not Reload/Restart for CRUD

Creating or changing:

- a DID
- an extension
- an inbound route
- an outbound route

must NOT require a FreeSWITCH restart.

Avoid unnecessary `reloadxml` operations.

The purpose of XML-CURL is dynamic configuration.

If caching is introduced later, define explicit invalidation behavior.

---

# 21. XML Response Requirements

XML returned to FreeSWITCH MUST be:

- valid XML
- correctly escaped
- deterministic
- minimal
- generated from trusted database data
- free of debug output
- free of PHP warnings/notices
- free of HTML error pages

Never concatenate raw user input into XML.

Use a proper XML builder/serializer or correct escaping.

---

# 22. Failure Behavior

If a requested extension/DID/route does not exist or is disabled:

- do not invent a destination
- do not fall through to unrestricted outbound dialing
- return a safe FreeSWITCH response/no-match behavior

Unknown inbound DIDs must fail safely.

Unknown outbound destinations must fail safely.

An unauthenticated provider call must never gain access to the `default` context.

---

# 23. Logging

Laravel should log business events such as:

- extension created/disabled
- DID assigned/unassigned
- inbound route changed
- outbound route changed
- gateway changed
- caller ID changed

Never log:

- SIP passwords
- provider passwords
- API secrets
- OTPs
- session cookies

Treat FreeSWITCH SIP logs as sensitive operational data.

---

# 24. Testing Requirements

## Laravel tests

Cover:

- tenant isolation
- extension ownership
- DID ownership
- inbound route ownership
- outbound route ownership
- gateway authorization
- caller-ID authorization
- number normalization
- XML generation
- missing/disabled records
- malformed configuration

## SIP integration tests

### Registration

```text
Zoiper -> internal:5060 -> XML-CURL directory -> authenticated
```

### Inbound

```text
Provider -> external:5080 -> public -> DID -> extension
```

### Outbound

```text
Extension -> internal:5060 -> outbound dialplan -> approved gateway -> provider
```

### Isolation

Tenant A must never be able to:

- register as tenant B
- use tenant B's DID
- use tenant B's caller ID
- use tenant B's gateway
- route calls to tenant B's extension

---

# 25. MVP Acceptance Criteria

The MVP is functional when:

1. Admin can create/configure a SIP gateway.
2. Admin can assign a DID to a tenant.
3. Customer can create an extension.
4. Extension credentials can be delivered securely.
5. Zoiper can register using the extension.
6. Inbound calls to a configured DID ring the configured extension.
7. The extension can make an outbound call.
8. Outbound caller ID comes from an authorized DID.
9. Customers cannot select another tenant's DID.
10. Customers cannot select arbitrary gateways.
11. Normal configuration changes do not require manual FreeSWITCH XML editing.
12. Normal configuration changes do not require restarting FreeSWITCH.
13. Secrets never appear in Git.
14. Unknown inbound calls cannot enter the outbound/default dialing context.
15. Automated tests cover routing authorization and tenant isolation.

---

# 26. AI Agent Development Rules

These rules are mandatory.

## Rule 1 — FreeSWITCH is infrastructure

Do not move SIP/RTP processing into Laravel.

## Rule 2 — Database is authoritative

If generated XML and database state disagree, the database wins.

## Rule 3 — Never hardcode customer configuration

Never hardcode these as business logic:

```text
982191093464
1000
provider-trunk
```

They are current test values only.

## Rule 4 — Never write per-customer FreeSWITCH XML

Do not create Laravel code that writes normal customer configuration under `/etc/freeswitch/`.

## Rule 5 — Never restart FreeSWITCH for CRUD

Creating/updating a DID, extension, or route must not require a restart.

## Rule 6 — Never expose secrets

Do not return provider passwords to frontend clients.

Do not log SIP passwords or provider credentials.

## Rule 7 — Validate routing

Every DID, extension, gateway, caller ID, and destination must be resolved through authorized relationships.

## Rule 8 — Never trust SIP-provided tenant IDs

Tenant identity is resolved server-side.

## Rule 9 — Keep contexts separate

Provider calls enter `public`.

Customer registrations/calls enter `default`.

Do not create a public path into unrestricted outbound dialing.

## Rule 10 — Make small changes

When modifying FreeSWITCH integration:

1. inspect current configuration
2. back up relevant configuration
3. make one controlled change
4. validate it
5. reload only what is necessary
6. test registration
7. test inbound
8. test outbound
9. inspect logs
10. document the result

## Rule 11 — Never guess FreeSWITCH behavior

If unsure about a channel variable, XML-CURL request, Sofia behavior, dialplan behavior, gateway behavior, or XML structure, inspect the actual configuration/logs first.

## Rule 12 — Preserve the working baseline

Current working baseline:

```text
internal :5060
external :5080
provider-trunk
public context
default context
extension 1000
DID 982191093464
```

Do not break this baseline while migrating to database-backed configuration.

---

# 27. Laravel Service Boundaries

Keep FreeSWITCH integration isolated.

Suggested structure:

```text
app/
    Domain/
        Voip/
            Models/
            Services/
            Actions/
            Policies/

    Http/
        Controllers/
            FreeSwitch/
                XmlController.php
```

Suggested services:

```text
FreeSwitchXmlService
FreeSwitchDirectoryService
FreeSwitchDialplanService
SipNumberService
SipExtensionService
SipGatewayService
InboundRouteService
OutboundRouteService
```

The XML controller should be thin.

Business authorization belongs in domain services/policies, not in XML string-building code.

---

# 28. Request Flows

## SIP registration

```text
Zoiper
  |
  | REGISTER
  v
FreeSWITCH internal
  |
  | XML-CURL directory lookup
  v
Laravel
  |
  | Mysql
  v
Extension
  |
  v
directory XML
  |
  v
FreeSWITCH
  |
  v
Zoiper authenticated
```

## Inbound call

```text
Provider
  |
  v
FreeSWITCH external
  |
  | destination_number
  v
public dialplan lookup
  |
  v
Laravel
  |
  | DID -> inbound route -> extension
  v
FreeSWITCH transfer
  |
  v
internal extension
```

## Outbound call

```text
Zoiper
  |
  v
FreeSWITCH internal
  |
  | authenticated extension
  v
outbound policy
  |
  | tenant + DID + destination
  v
approved gateway
  |
  v
Provider
```

---

# 29. Future Work — Do Not Implement Prematurely

Not required for this MVP:

- ESL/Event Socket integration
- call event ingestion
- real-time call state
- recordings to object storage
- billing/rating engine
- number portability
- multiple provider routing
- least-cost routing
- call queues
- IVR builder
- ring groups
- voicemail
- WebRTC
- SIP over WebSocket
- high-availability FreeSWITCH cluster

Do not introduce these unless explicitly requested.

---

# 30. Infrastructure Change Procedure

When modifying the FreeSWITCH server:

1. Back up the relevant configuration.
2. Inspect the currently loaded module/profile.
3. Make the smallest change possible.
4. Validate configuration/XML.
5. Reload only the required configuration.
6. Check Sofia status.
7. Test SIP registration.
8. Test inbound calling.
9. Test outbound calling.
10. Inspect logs.
11. Record the resulting architecture/configuration in project documentation.

Never delete working configuration before the replacement has been verified.

---

# 31. Immediate Migration Goal

The immediate goal is NOT to build the entire Blucom VoIP platform.

The first engineering milestone is:

```text
Static FreeSWITCH customer XML
        |
        v
Mysql
        |
        v
Laravel XML-CURL
        |
        v
FreeSWITCH
```

while preserving exactly this behavior:

```text
Zoiper
  |
  v
internal:5060
  |
  v
extension 1000
  |
  v
approved outbound gateway
  |
  v
Provider
```

and:

```text
Provider
  |
  v
external:5080
  |
  v
public
  |
  v
DID 982191093464
  |
  v
extension 1000
  |
  v
Zoiper
```

The first implementation milestone is therefore:

> Replace the hardcoded test extension and DID routing with database-backed XML-CURL while keeping the existing SIP gateway and Sofia profiles unchanged.

Once that works, generalize the same mechanism for tenants, SIP numbers, extensions, inbound routes, and outbound routes.



</laravel-boost-guidelines>
