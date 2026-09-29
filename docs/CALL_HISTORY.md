# Call history and dashboard

FreeSWITCH remains the source of call timing and hangup data. Its existing
`mod_cdr_csv` writes A-leg records to `/var/log/freeswitch/cdr-csv/Master.csv`.
Blucom imports that append-only spool every minute with `voip:import-cdr`.
The importer stores only calls associated with a known tenant DID or an
outbound route marked by Blucom's authenticated dialplan. Unknown SIP scans
are skipped. FreeSWITCH keeps writing CSV if Laravel or MySQL is unavailable;
the importer resumes from its last file offset. Each FreeSWITCH UUID is unique
in MySQL, so replay is safe.

The standard `example` CSV template has 15 fields. The deployed `blucom`
template appends four fields, preserving the first 15 in the same order:

```xml
<template name="blucom">"${caller_id_name}","${caller_id_number}","${destination_number}","${context}","${start_stamp}","${answer_stamp}","${end_stamp}","${duration}","${billsec}","${hangup_cause}","${uuid}","${bleg_uuid}","${accountcode}","${read_codec}","${write_codec}","${sip_auth_username}","${sip_profile_name}","${blucom_call_direction}","${blucom_extension_id}"</template>
```

Set `default-template` to `blucom`, keep `legs=a`, back up the FreeSWITCH
configuration, validate XML, and reload only `mod_cdr_csv`. The Laravel XML-CURL
dialplan sets `accountcode=btenant_<id>`, `blucom_call_direction`, and
`blucom_extension_id` on authorized routes. Outbound import also verifies the
authenticated SIP username against that extension. Prior 15-field rows can be
backfilled for known inbound DIDs; older outbound rows cannot be attributed
securely and are skipped.

On the current combined app/FreeSWITCH host, run the importer as `www-data`
with supplementary `freeswitch` group membership so it can read the CSV file.
Do not expose the FreeSWITCH log directory through the web server. A systemd
timer runs the command each minute:

```text
WorkingDirectory=/var/www/html/blucom_ir
ExecStart=/usr/bin/php artisan voip:import-cdr
User=www-data
Group=www-data
SupplementaryGroups=freeswitch
```

Environment overrides: `VOIP_CDR_CSV_PATH` (default
`/var/log/freeswitch/cdr-csv/Master.csv`), `VOIP_CDR_TIMEZONE` (default `UTC`,
matching the verified FreeSWITCH host clock), and `VOIP_DISPLAY_TIMEZONE`
(default `Asia/Tehran`). Historical imports can be replayed with
`php artisan voip:import-cdr --replay`; UUID uniqueness prevents duplicates.

`duration_seconds` covers the entire call attempt. `billable_seconds` covers
the answered portion. An answered CDR has an answer timestamp; inbound calls
that ring without an answer are missed; other failures retain the FreeSWITCH
hangup cause. The dashboard and call history show completed calls only. Live
ringing/active state requires event ingestion and is outside this feature.

## Deployed FreeSWITCH state (2026-09-29)

- The existing `mod_cdr_csv` was already loaded and recording A-legs. Its
  configuration was backed up at
  `/root/blucom-voip-backups/2026-09-29-cdr/cdr_csv.conf.xml` before the
  `blucom` template was added. Only `mod_cdr_csv` was reloaded; Sofia profiles
  and gateways were not restarted. A new CDR was verified to have 19 fields.
- `blucom-cdr-import.service` and `blucom-cdr-import.timer` are installed under
  `/etc/systemd/system/`. The service runs as `www-data` with supplementary
  `freeswitch` group membership. The timer runs each minute and is active.
- The first run scanned 311,042 existing A-leg rows and imported 10 calls for
  the configured DID. Historical outbound rows lacked the new authenticated
  extension markers and were skipped. The next timer run processed an appended
  row successfully. The static `provider-trunk` remained `REGED` and `UP`,
  and extension `8888` remained registered.

For operational checks:

```bash
sudo systemctl list-timers blucom-cdr-import.timer
sudo journalctl -u blucom-cdr-import.service -n 30 --no-pager
sudo fs_cli -x "module_exists mod_cdr_csv"
```

To roll back CDR ingestion, disable the timer and restore the backed-up
`cdr_csv.conf.xml`, then reload `mod_cdr_csv`. Keep the application migration
and saved call records unless a separate data-retention decision requires
removing them.
