# Polycom TCP registration reliability

## Production investigation — 2026-11-01

Extension 101's Polycom retained a TCP-NAT SIP registration after its associated
TCP connection disappeared. IVR key 0 selected the correct authorized extension,
but the bridge failed immediately with `NORMAL_TEMPORARY_FAILURE`. Restarting
the phone restored delivery temporarily. The other Polycom had a live TCP
connection while the failed phone did not.

The internal Sofia profile had no NAT OPTIONS probes enabled. Backed up
`/etc/freeswitch/sip_profiles/internal.xml` under
`/var/backups/freeswitch/polycom-keepalive-20261101-122927/`, validated the XML,
then added these infrastructure settings inside its existing `settings` element:

```xml
<param name="nat-options-ping" value="true"/>
<param name="ping-mean-interval" value="20"/>
```

Applied using `sofia profile internal rescan`; no profile or FreeSWITCH restart.
External gateways stayed registered. Existing live Polycom contacts began
responding to OPTIONS with nonzero measured round-trip times; the failed contact
was correctly marked unreachable. This verifies that the rescan activated probes.

An already-dead TCP connection still requires the phone to reconnect. After that,
verify repeated successful probes, an established TCP socket, inbound IVR key 0,
and outbound calls. Observe beyond the previous failure interval before calling
the recurrence resolved. Do not substitute a registration under a short display
number for the tenant-authorized SIP identity.

After the user restarted 101, a fresh TCP connection appeared and the phone
answered multiple OPTIONS probes (approximately 42 ms). Both Polycom TCP
connections remained established across approximately three minutes idle.
Direct test UUID `51fbb339-079f-4237-bf21-5936591c690f` received `Ring-Ready`
and was answered; the test's intentional `hangup()` ended with `NORMAL_CLEARING`.
This validates delivery after the observed idle period, not long-term stability.
User acceptance of inbound IVR audio and a normal outbound call is pending.

Rollback: restore the backed-up profile and rescan only `internal`.

Reference: https://developer.signalwire.com/freeswitch/users-and-endpoints/sip-profiles/
