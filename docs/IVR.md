# Phone menus (IVR)

## User flow

Users with `phones.manage` can create a phone menu, upload a greeting, assign
keys `0`–`9` to an active person or team in their workspace, and select a
fallback. They save a draft, preview its audio, then publish it. A number can
choose a published menu alongside a person or team. Editing a draft does not
change live calls. One previous published configuration can be restored.

The first release supports one menu level per call. A menu cannot be deleted
while a number points to it. Menus do not change extensions or outbound calls.

## FreeSWITCH flow

The database remains authoritative. There is no per-menu FreeSWITCH XML file
and no restart or `reloadxml` for menu edits. XML-CURL's `public` dialplan
matches an owned, active number and sets a menu ID, number ID, and published
version on the call. `mod_dptools` answers, runs `play_and_get_digits` with the
published WAV, and transfers to the private `blucom_ivr` context. A second
XML-CURL lookup resolves the chosen digit from the version the caller heard.
Unknown, invalid, and missing keys use the configured fallback after two
attempts. A disabled or foreign destination fails closed.

Greetings live in `storage/app/ivr/<tenant>/<menu>/<id>.wav`. This path must
be shared between the Laravel app and FreeSWITCH. The web server writes it;
the FreeSWITCH user needs read access. `ffmpeg` converts uploaded audio into
mono 16 kHz 16-bit PCM WAV, capped at 60 seconds. The files are outside the
web root; authenticated editors can preview them through
`/menus/{id}/audio/{version}`.

`mod_dptools` and the existing XML-CURL `dialplan` binding must be loaded.
No static IVR menu configuration or SIP profile change is required.

## Call history

Append these three fields to the existing `blucom` CDR CSV template, after
`originate_disposition`, without changing the preceding columns:

```text
"${blucom_ivr_menu_id}","${blucom_ivr_digit}","${blucom_ivr_number_id}"
```

The importer uses them to retain the original called number after the
internal transfer and to show the selected menu key. The existing importer
continues to accept older CDR rows. Only a completed bridge or answered team
call counts as an answered IVR call; a caller who leaves in the menu is missed.

## Server checks

```bash
sudo fs_cli -x 'module_exists mod_dptools'
sudo fs_cli -x 'module_exists mod_xml_curl'
sudo -u freeswitch test -r storage/app/ivr/<tenant>/<menu>/<id>.wav
sudo fs_cli -x 'sofia status profile internal reg'
sudo tail -f /var/log/freeswitch/freeswitch.log
```

After publishing a real greeting, attach a test number, call it, press each
configured key, try an unassigned key and no input, and confirm the fallback,
call history, and two-way audio. Do not replace a working number route until
that test succeeds.
