# Call teams and FreeSWITCH queues

## User flow

An extension is a person's phone identity. A call team groups enabled extensions.
On a number's **Choose who answers** screen, select either one person or a team.
An operator assigned to an extension can mark themselves **Available** or **On
Break** in the panel. Their direct extension and outbound route remain unchanged.
The admin can create teams, choose members, select longest idle, ring all, or
round robin, set a 15–600 second wait limit, and choose an optional fallback
extension. An empty or disabled team is not a valid inbound destination.

## Execution

- Laravel database records (`call_queues`, `call_queue_members`,
  `sip_extensions.queue_status`) are authoritative.
- XML-CURL's public dialplan sends an approved, tenant-owned DID to
  `mod_callcenter` using a deterministic `blucom_q_<id>@default` name. It
  answers and plays hold music while the caller waits. On timeout, the
  configured same-tenant fallback extension is attempted.
- A local systemd timer runs `php artisan voip:sync-queues` every 15 seconds.
  The command writes **one** generated `callcenter.conf.xml` for all queues,
  reloads XML only when its content changes, and reconciles loaded queues,
  agents, tiers, availability and waiting counts using `fs_cli`. It does not
  restart FreeSWITCH. No per-tenant XML files are created.
- A queue is loaded only if its tenant is active, it is enabled and it has at
  least one enabled member. The agent contact is an existing directory user.
  FreeSWITCH handles ringing and audio. Tenant and number relationships are
  validated in Laravel before dialplan generation.
- An agent who rejects a call or reports busy is held out of new queue offers
  for 30 seconds, giving another available team member a chance to answer.
  Unanswered offers have the same 30-second cooldown. If no member is ready,
  the queue's no-agent timeout and fallback policy apply.
- The existing CSV CDR template appends the queue ID, `cc_cause`, queue
  timestamps, fallback marker, and originate result after the original 19
  columns. The importer attributes queue calls to the DID tenant and records
  queue wait and outcome. A caller who leaves before reaching an agent is
  counted as missed by the queue. If a timed-out team call reaches its fallback
  extension, the call record remains answered while the team outcome remains
  canceled.

## Server state (2026-09-29)

`mod_callcenter` is installed and loaded; `modules.conf.xml` enables it for
future starts. `VOIP_QUEUES_ENABLED=true` is set in the server environment.
`blucom-queue-sync.timer` is enabled and `mod_cdr_csv` was reloaded with the
extended template. Backups of the original module, call center, CDR and
environment files are under `/root/blucom-voip-backups/2026-09-29-queues`.
The sync service runs as the `freeswitch` system user. Temporary teams using
extension 8888 confirmed that this user can write the generated configuration
and reconcile FreeSWITCH. The queue, tier, and Available/On Break transitions
were verified, and the temporary teams were removed. The existing live DID
remains routed to its original extension.

## Checks

```bash
sudo systemctl status blucom-queue-sync.timer
sudo journalctl -u blucom-queue-sync.service -n 30 --no-pager
sudo fs_cli -x 'callcenter_config queue list'
sudo fs_cli -x 'callcenter_config agent list'
sudo fs_cli -x 'callcenter_config tier list'
```

After assigning a live number to a team, place an inbound call and verify
ringing, two-way audio, waiting, timeout/fallback and the call record. The
service can distribute calls only when the assigned extensions are registered.

FreeSWITCH queue behavior and API commands are documented in the
[official call queues reference](https://developer.signalwire.com/freeswitch/applications/call-queues/).
