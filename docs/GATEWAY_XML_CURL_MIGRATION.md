# Gateway registration through Laravel XML-CURL

Laravel now stores admin-managed provider gateway settings and can serve them to
FreeSWITCH through the existing authenticated directory XML-CURL binding. The
feature is off by default (`VOIP_GATEWAY_XML_ENABLED=false`). The existing
`provider-trunk` static XML remains the working source until the live cutover.

## Verified server baseline (2026-09-25)

- `mod_xml_curl` is loaded; its binding covers `directory|dialplan` and calls
  `https://admin.blucom.ir/internal/freeswitch/xml`.
- The external profile is on `:5080`, has context `public`, and has
  `<domain name="all" alias="false" parse="true"/>`.
- `provider-trunk` is registered and UP on the external profile. Its static
  definition is in `sip_profiles/external/my-provider.xml`.
- The live directory lookup currently returns extension `2000`, and that
  extension is registered. The live public dialplan routes DID `982191093464`
  to `2000`. The older `1000` example in `AGENTS.md` is not the current live
  extension; the migration must preserve `2000`.
- Before server changes, relevant FreeSWITCH files were backed up in
  `/root/blucom-voip-backups/2026-09-25-gateway-migration` (root-only).

## Application behavior

When the feature flag is enabled, authenticated full-domain directory lookups
include enabled, approved external/public gateways in the domain's group/user
gateway tree, as required by `mod_sofia`. The explicit `purpose=gateways` lookup
is retained for diagnostics. A single-user authentication lookup excludes the
gateway definitions. The admin
gateway page stores registration credentials encrypted and never displays them.
An explicit `approved_for_outbound` flag controls which gateways may appear in
outbound routes and bridge dialplans.

Saving a gateway changes the database. Sofia keeps a loaded registration, so an
operator must rescan and check status after a gateway change. No web request
invokes `fs_cli`, SSH, or writes a FreeSWITCH XML file.

## Controlled cutover

1. Deploy the app, run `php artisan migrate --force`, and verify the existing
   gateway remains registered with `fs_cli -x "sofia status gateway provider-trunk"`.
2. On the FreeSWITCH host, import the *existing* static gateway file without
   printing its credentials:

   ```bash
   cd /var/www/html/blucom_ir
   sudo php artisan voip:import-gateway-xml /etc/freeswitch/sip_profiles/external/my-provider.xml
   ```

3. Inspect the gateway in the admin page. Compare its non-secret settings with
   the loaded gateway. Do not place credentials in a terminal argument, log, or
   ticket.
4. Set `VOIP_GATEWAY_XML_ENABLED=true` in the server's application environment
   and refresh Laravel configuration. Use an authenticated XML-CURL lookup with
   `section=directory`, `purpose=gateways`, and `profile=external` to verify
   valid XML; do not print the XML because it contains the gateway credential.
5. During a monitored window, move the static gateway file out of the include
   directory, run `reloadxml`, then `sofia profile external killgw provider-trunk`
   and `sofia profile external rescan`. Confirm it returns to REGED/UP. If it
   does not, restore the backed-up static file, `reloadxml`, and rescan.
6. Confirm Zoiper registration, an inbound call to the assigned DID, and an
   outbound call with the authorized caller ID. Inspect Sofia and XML-CURL logs
   without copying SIP passwords. Keep the backup until all three pass.

Only remove the static gateway from ongoing operation after these live checks.
Directory and dialplan customer CRUD continue without FreeSWITCH restarts.

## Current deployment state

The application migration has run on the FreeSWITCH host. The gateway's live
static settings, including its password, were imported into encrypted database
storage. An authenticated HTTPS lookup returned one gateway, and its username,
password, realm, proxy, registration setting, context, and ping setting all
matched the static definition. The gateway remained REGED/UP and existing
internal registrations were present after deployment.

The operator was unavailable for inbound and outbound test calls, so the static
gateway remains in place and `VOIP_GATEWAY_XML_ENABLED=false`. Steps 4–6 above
are pending a monitored call-test window. The app code does not automatically
rescan Sofia after a gateway edit.

## 2026-09-29 outbound incident

Outbound calls from the registered extension failed with `INVALID_GATEWAY`:
the dialplan selected the tenant gateway, but the external Sofia profile had
not loaded it. The XML-CURL binding sends an ordinary directory lookup during
`sofia profile external rescan`; it does not send the diagnostic
`purpose=gateways` parameter. The application now includes approved gateways
in that ordinary full-domain response. After deployment and an external profile
rescan, the tenant gateway was loaded and reached `REGED/UP`. A live outbound
call then completed with `NORMAL_CLEARING` (87 seconds total, 74 billable).

The static `my-provider.xml` was backed up under
`/root/blucom-voip-backups/2026-09-29-outbound-cutover` and removed from the
Sofia include directory. `reloadxml`, `sofia profile external killgw
provider-trunk`, and `sofia profile external rescan` completed. The tenant
gateway remained `REGED/UP` and the legacy gateway was no longer loaded.
Inbound and outbound live calls after this cutover still need confirmation.
