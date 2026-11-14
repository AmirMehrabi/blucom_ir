# Inbound announcements and voicemail

In the customer portal, open the announcement library from a line, IVR editor,
or queue. Upload and preview a WAV or MP3 of up to 60 seconds. Choose an
announcement to play it and end the call, or choose an enabled extension's
voicemail box to collect a message. A mailbox is provisioned the first time
it is selected. A user retrieves messages by dialing `*98` on the registered
extension that owns the mailbox.

- **Lines → inbound route** applies the action during the line's open hours.
- **Lines → unanswered calls** applies the action after the direct extension
  cannot answer.
- **Menus → keys** applies the action on a DTMF selection. Publish to activate
  it. The no-selection destination can also be an audio action.
- **Queues → after waiting** applies the action on queue timeout or no-agent
  timeout. Existing queue strategy still governs agent selection.

Voicemail uses the configured FreeSWITCH profile and its prompts, minimum
length, duration and save behavior. The installed production profile uses
English prompts and a 3–300 second recording window. Test the actual caller
prompt and retrieval on the pilot line. The existing phone mailbox menu
supports a personal greeting. Saved messages remain on the switch.

## Rollout

Apply the Laravel migration and build the frontend through the normal
application deployment. Enable voicemail only where the isolated mailbox
profile has been validated. Audio settings are delivered via XML-CURL.

To enable the new call history outcomes, back up the production CDR CSV
configuration and append the following two quoted fields to the existing
`blucom` template, before its newline. Preserve the existing columns and
their positions.

```text
,"${blucom_audio_outcome}","${voicemail_message_len}"
```

Validate the XML and reload only `mod_cdr_csv` in a maintenance window.
Historical CSV rows with fewer columns remain readable. Verify a completed
announcement, a visit to the mailbox without a saved recording, and a
saved recording, including the original DID in any queue fallback CDR.
The importer uses the positive message duration reported by native voicemail
as evidence of delivery. It does not import the path or audio itself.

Before enabling a customer route, call a pilot DID externally. Verify one
press of the advertised IVR key, invalid and absent keys, queue timeout,
agent answer before timeout, and caller hangup during recording. Confirm the
saved message from the registered extension and check the call history.
To revert a customer change, restore the prior IVR publication or select the
previous line or queue destination in the portal.
