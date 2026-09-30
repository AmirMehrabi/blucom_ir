# Call recordings

FreeSWITCH captures media. Laravel stores tenant-scoped policies, reserves
capacity, generates recording actions in XML-CURL, finalizes files, and serves
private playback/downloads. Normal setting changes require no reload or restart.

## Customer workflow

Open **صدای تماس‌ها** to search by caller/destination, DID, direction, date,
internal extension, team, and recording state. Play opens a keyboard-accessible
native dialog with seeking, speed, call details, expiry, download, and deletion.
Call history links to the same player. Individual number settings are available
from customer line cards, admin number tabs, and **تنظیم ضبط تماس**.

Each number defaults to recording off. Select inbound, outbound, or both;
conversation only or audio from answer including menus/waiting; retention of
1–365 days; and a recording duration limit of 1–120 minutes. The duration limit
stops only the recorder, not the call. Inbound announcement uploads are converted
to 8 kHz mono WAV, limited to 15 seconds, and played before recording starts.
They do not announce recording to the outbound recipient. Old announcement
uploads are cleaned up after three hours unless still referenced by settings
or an active recording snapshot.

Changes affect future calls. An explicitly confirmed separate action can change
the expiry of existing files belonging to the selected number. Expiry is counted
from call end, so reducing retention can make old files immediately inaccessible
and eligible for deletion. Call history survives audio deletion. Downloads that
have already left the service are outside its retention control.

## Permissions

- `recordings.view`: list, call-history recording links, playback.
- `recordings.download`: authenticated attachment downloads.
- `recordings.delete`: confirmed deletion of completed audio.
- `recordings.manage`: number policies and tenant-wide storage settings.

Admins have all four permissions. They are assignable in user management and
included in defaults for newly created operators. Existing accounts are not
silently granted access to recorded conversations: an admin can assign the
permissions needed by each operator. All record and settings queries enforce
tenant ownership. Playback is private, has no public storage link, supports
HTTP Range, and rejects expired, incomplete, missing, and symlinked audio.
Playback/download/delete and settings changes write business audit events without
credentials or audio contents.

## Storage and rotation

Private local storage is intentionally used on the existing combined host.
`VOIP_RECORDINGS_SPOOL` should be a stable absolute shared path outside `public`.
The `recordings` disk stores `<tenant>/<recording-uuid>.wav`. Original customer
phone numbers never enter filenames. WAV is both the playback and download
format; no second audio variant is needed.

The tenant quota defaults to 1024 MB, bounded by the deployment allowance
`VOIP_RECORDINGS_MAX_QUOTA_MB` (default 10240 MB). There is no plan/billing
integration in this feature; this configured allowance is the upper limit that
customers can choose. Quota settings apply to all numbers in that tenant.
Choose to skip new recordings at capacity, or remove the oldest ready audio
before admitting the new recording. Active files are excluded from eviction.
A skip is visible in call history/recordings and does not fail the call.

Reservations budget a complete bounded 8 kHz, 16-bit, stereo recording plus its
header (32000 bytes/second). A shared cache lock serializes admissions across
tenants; tenant row locks protect ownership and quota decisions. The processor
keeps quarantined failed audio counted until deletion succeeds. A physical-disk
reserve (`VOIP_RECORDINGS_MIN_FREE_MB`, default 512 MB) also protects the host.
Keep the configured cache store shared across PHP workers (Redis/database).

Deletion first commits an inaccessible tombstone, then removes audio and
completion artifacts. Partial failures remain counted and are retried by the
processor, so a later transaction failure cannot restore access to deleted audio.
Retention removes ready/failed/empty expired audio and preserves call metadata.

## Recording and recovery

Inbound policy is resolved from the normalized `Hunt-Destination-Number`
(with `Caller-Destination-Number` fallback) for the requested call; outbound policy comes from the authenticated extension's
approved route and DID. `Caller-Unique-ID`/`Unique-ID` is a core FreeSWITCH UUID,
not a tenant claim. Diagnostic lookups without a valid call UUID produce no
recording reservation. Reserved ownership and DID are snapshotted at setup.

The recorder stays on the original session through the existing transfer and
bridge paths. `RECORD_BRIDGE_REQ=true` excludes unbridged IVR/queue waiting;
full coverage starts at answer. Stereo separates the session read/write audio,
not universally the same business roles after transfers. There is one recorder
per original call UUID in the currently supported routes.

The generic `blucom_recording_complete.lua` hook writes an atomic completion
marker only after FreeSWITCH closes the WAV. The processor additionally requires
a completed, tenant-matching CDR before publishing audio, verifies RIFF/chunk
sizes, PCM format, and its reservation bound, and moves the file atomically.
A move interrupted before DB commit is recovered from the destination file.
Missing markers get a ten-minute grace period after the completed CDR, then
become visible failures. Without proof of call completion, a reservation stays
protected; inspect the CDR import timer if reservations persist unexpectedly.
The existing CSV layout and importer cursor remain unchanged. Recording metadata
is joined by UUID, so adding recording does not require a CDR module reload.

## Deployment

1. Run application tests and build assets, deploy the additive migration.
2. Inspect the loaded FreeSWITCH modules and existing profiles/gateway first.
3. Back up any existing recording script, relevant service units, and private
   environment file before replacing them. Never print the environment file.
4. Install `deploy/freeswitch/blucom_recording_complete.lua` in the reported
   FreeSWITCH `script_dir`, readable by the `freeswitch` user.
5. Create a private spool writable by FreeSWITCH and readable/deletable by the
   recording processor. Grant `www-data` a named/default ACL on spool files,
   because moved WAVs retain FreeSWITCH ownership. Keep the published root private
   and writable by `www-data`.
6. Set the stable `VOIP_RECORDINGS_SPOOL` and, after recording probes succeed,
   `VOIP_RECORDINGS_ENABLED=true`. Rebuild Laravel configuration caches.
7. Install `deploy/blucom-recording-processor.service` and `.timer`, run
   `systemctl daemon-reload`, and enable the timer. No FreeSWITCH restart is needed.
8. Check registration, inbound and outbound calls, both recorded voices, IVR and
   queue waiting boundaries, transfers/fallback, and the existing caller ID.
   Use isolated generated tones before placing real calls. Number policies remain
   off until deliberately enabled by an administrator/customer.

The service imports/finalizes every minute. Allow up to roughly two minutes
between hangup, CDR import, and recording publication. `voip:process-recordings`
can be run manually for diagnostics. It does not scan or publish arbitrary files
found on disk: a trusted database reservation must exist first.

Rollback: disable `VOIP_RECORDINGS_ENABLED`, rebuild config caches, and keep the
processor running until active recordings finish. Preserve the database and
private audio; normal calls then receive their original routing without recorder
instructions. Restore backed-up bootstrap files only after active recording
hooks have finished. The additive migration is compatible with the prior app.

## Deployment verification — 2026-09-30

Application implementation deployed from `a40f8f064da4c1a4ea46091c71f4ea02677a7867`.
The full automated Laravel suite passed: 157 tests, 901 assertions. The production
asset build and additive migration completed successfully.

On the installed FreeSWITCH 1.11.3 build, isolated tone probes verified stereo
recording, the completion hook, and coverage boundaries: conversation recorded
4.02 seconds of bridged audio; full coverage recorded 5.02 seconds including the
preceding one-second tone. Spectral checks found the caller's 440 Hz tone and
callee's 550 Hz tone on separate channels; only full coverage included the
preceding 660 Hz tone.

An isolated temporary database-backed DID and extension verified authenticated
SIP registration, public-context XML-CURL routing to the registered endpoint,
bidirectional RTP, a finalized six-second WAV, CDR association, authenticated
recordings/settings/playback/download responses, and audio deletion preserving
call history. Test records, audio, and scripts were removed. Temporary SIP
contacts expire automatically within two minutes after the probe.

The recording processor timer is enabled and its service completed as
`www-data` with exit status zero. The existing CDR import timer remains active.
Internal port 5060 and external port 5080 remain running; the existing provider
gateway remains registered. FreeSWITCH was not restarted and gateway/profile
configuration was not changed. Infrastructure backups are retained under
`/home/ammir/deploy-backups/recordings-20260930` with restricted permissions.

Existing real numbers remain recording-off until explicitly configured.
Real incoming/outgoing PSTN calls, caller ID, and two-person voice playback are
pending the user's later Zoiper test. Automated checks do not substitute for
that provider-network check. Browser visual inspection was unavailable in this
session; rendered views and authenticated routes were covered by application
and live route checks.
