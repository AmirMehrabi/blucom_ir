# Blucom admin MVP — Milestone 1

This milestone adds database-backed VoIP configuration to the admin panel. It does **not** change `/etc/freeswitch`, Sofia profiles, or the registered `provider-trunk` gateway. Calls still use the current working static FreeSWITCH configuration until the later reviewed cutover.

## Data and admin UI

MySQL remains the application's database. The existing tenant columns remain for compatibility; new admin records belong to a single internal `Blucom` owner identified by `tenants.system_key=blucom`. Tenant and customer management are not exposed. Admin OTP login remains in place; customer portal and gateway provisioning routes are disabled.

The admin navigation contains Dashboard, SIP Extensions, DID Numbers, Inbound Routes, and Outbound Routes. The dashboard reports database counts only, since live FreeSWITCH health has not been integrated.

- Extension passwords use Laravel's encrypted cast. A generated or entered password appears only in the next admin page response after creation or reset. Existing passwords are never displayed.
- DIDs retain their entered number and a unique E.164 `normalized_number`; the `NumberNormalizer` service owns normalization. `enabled`, `inbound_enabled`, and `outbound_enabled` are independent controls.
- Each DID has at most one inbound destination. Each extension has at most one outbound route; multiple extensions may use the same DID as caller ID.
- The only outbound gateway allowed by `config/voip.php` is `provider-trunk`. Its database row is metadata for routing and does not provision or change the FreeSWITCH gateway. Gateway credentials are not stored by the import command.
- Routes and extensions cannot be deleted while referenced. Admin controllers enforce the internal owner relationship.

## Import the current test configuration

Run the migration, then import on an interactive terminal with the **current** extension 1000 password available locally:

```bash
php artisan migrate --force
php artisan voip:import-working
```

The import prompts without echoing the password, encrypts it in MySQL, and creates the test extension, DID, inbound route, outbound route, and gateway metadata if missing. It is idempotent. Do not pass the password as an argument or place it in a seed, ticket, or shell history. The current password is not stored in this repository, so the import must be run by an operator who has it. The import does not reload or restart FreeSWITCH.

## Later FreeSWITCH migration

Before the next milestone, inspect the actual `mod_xml_curl` module, XML-CURL bind configuration, directory and dialplan files, and loaded Sofia profiles on the FreeSWITCH host. Back up each file to be changed. Confirm the exact XML-CURL request fields and authenticated username variable from the running installation. Then connect the protected Laravel endpoint for directory lookups, verify Zoiper registration, verify outbound calls, migrate inbound DID routing, and finally migrate outbound policy. Remove static customer XML only after live registration, inbound, and outbound tests pass. Do not alter the provider gateway or Sofia profiles.
