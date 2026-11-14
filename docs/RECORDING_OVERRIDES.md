# Destination recording settings

Queues, IVRs, and extensions have a Call recording setting. Users with
`recordings.manage` can save the setting on an owned destination. IVR recording
settings take effect independently of publishing the greeting and menu choices.

- **Inherit** uses the current call policy, initially the SIP number policy.
- **Always record** enables recording even if the line excludes that direction.
- **Never record** stops recording when entering the destination. Earlier audio
  follows its original retention policy. A later Always setting can start a
  new segment.

`VOIP_RECORDINGS_ENABLED` controls availability. An explicit Always setting
uses the line's coverage, announcement, retention and duration when configured.
Without a line policy, it uses full inbound coverage, outbound conversation
coverage, 30 days of retention, a 60-minute segment limit, and no announcement.
Storage capacity and required announcements still apply. Recording requires a
call associated with a SIP number. Calls solely between internal extensions
currently have no recording policy.

FreeSWITCH starts and stops recordings on the call's original leg. Segments
have distinct files and a shared call UUID. They appear in call details after
the call completes. To protect Never-record agents from initial bridge audio,
a queue with any enabled Never-record agent pauses recording before entering
the queue. Queue-wait audio is omitted for those calls. Recording can resume
when the selected agent's policy permits it.

## Rollout

Install `deploy/freeswitch/blucom_queue_recording.lua` in the inspected FreeSWITCH
script directory, alongside the existing recording completion script, before
making overrides available. Keep a private backup of any existing script.
Apply the Laravel migration and deploy the application. The generic Lua script
loads when used and requires no FreeSWITCH restart or XML reload. Schema rollback
requires resolving multi-segment records before restoring uniqueness on call UUID.

Verify registration, direct inbound and outbound calls, and a real call through
an Always IVR, a Never queue, and an Always agent. Check that the resulting
recordings have separate IDs and omit queue audio. Test a mixed queue with both
Never and Inherit agents, including ring-all, and verify that no agent audio is
retained for the Never agent.
