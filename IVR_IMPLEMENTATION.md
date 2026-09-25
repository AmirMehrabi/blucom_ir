# Blucom — IVR, Queues and DID Routing Implementation

You are working on **Blucom**, a Laravel + FreeSWITCH VoIP/PBX application.

Before making any changes:

1. Read the repository's `AGENTS.md`.
2. Inspect the existing Laravel implementation.
3. Inspect the existing database schema and models.
4. Inspect the existing FreeSWITCH/XML-CURL integration.
5. Preserve all currently working SIP registration, inbound calling and outbound calling functionality.
6. Do not make assumptions about FreeSWITCH configuration when the actual configuration can be inspected.

Your task is to PLAN and then IMPLEMENT the next Blucom feature set:

* IVRs
* queues
* queue members
* generic inbound destinations
* DID → IVR routing
* IVR → queue routing
* extension → DID outbound caller-ID assignment

Do not build unrelated PBX features.

---

# 1. TARGET SCENARIO

The system must support this inbound call flow:

```
DID: 02191090000
         ↓
       Main IVR
         ↓
   Play audio prompt:

   "Press 1 for Sales"
   "Press 2 for Support"

         ↓

    ┌────┴────┐
    │         │
    1         2
    │         │
    ▼         ▼
  Sales     Support
  Queue      Queue
    │         │
 ┌──┴──┐   ┌──┴──┐
 ▼     ▼   ▼     ▼
101   102 201   202
```

Outbound calling must be independently configured:

```
Extension 101 ─┐
Extension 102 ─┼──→ Caller ID 02191090000
Extension 201 ─┤
Extension 202 ─┘
                      ↓
               provider-trunk
                      ↓
                     PSTN
```

Inbound and outbound configuration are separate concerns.

Do not assume that an extension receiving calls from a DID automatically has permission to use that DID as outbound caller ID.

---

# 2. ARCHITECTURAL PRINCIPLE

Laravel/MariaDB is the source of truth for business configuration.

FreeSWITCH is the telephony execution engine.

Laravel defines:

* DIDs
* extensions
* inbound routes
* IVRs
* IVR options
* queues
* queue membership
* outbound caller-ID permissions/routes

FreeSWITCH executes:

* SIP
* RTP
* DTMF collection
* audio playback
* IVR interaction
* queue execution
* extension ringing
* provider gateway calls

Do NOT implement live call execution inside Laravel.

Do NOT keep an HTTP request open while an IVR is running.

Do NOT implement DTMF handling in Laravel.

FreeSWITCH performs the actual call.

---

# 3. DO NOT HARD-CODE THE EXAMPLE

The following are examples only:

```
DID 02191090000

Extensions:
101
102
201
202

IVR:
Main IVR

Queues:
Sales
Support

Gateway:
provider-trunk
```

The application must allow administrators to create equivalent configurations from the UI.

Do not hard-code these values in business logic.

`provider-trunk` may remain an infrastructure-configured allowed gateway for the current MVP.

---

# 4. FIRST: PRODUCE AN IMPLEMENTATION PLAN

Before changing code, inspect the repository and produce a concise implementation plan covering:

1. Current relevant models/tables
2. Current FreeSWITCH integration
3. Schema changes
4. Laravel services/classes to add or modify
5. Admin UI changes
6. XML-CURL/dialplan changes
7. Queue implementation strategy in FreeSWITCH
8. Audio storage strategy
9. Testing strategy
10. Migration/rollback strategy

Identify anything in the existing implementation that conflicts with this design.

Do not rewrite working functionality unnecessarily.

After planning, implement the feature incrementally.

---

# 5. GENERIC INBOUND DESTINATIONS

Do NOT design inbound routes as:

```
did -> extension_id
```

That is too restrictive.

An inbound route must conceptually be:

```
DID
  ↓
destination_type
destination_id
```

For this version, supported inbound destination types must include:

```
extension
ivr
queue
```

The design should be extensible later to:

```
ring_group
voicemail
conference
```

but DO NOT implement those features now.

Use an explicit, validated destination type.

Do not allow arbitrary PHP class names, FreeSWITCH applications, XML fragments or dial strings to be stored as destination types.

---

# 6. IVR MODEL

Implement an `ivrs` concept.

At minimum an IVR needs:

```
id
name
prompt/audio reference
timeout
max_attempts
enabled
timestamps
```

Do not store raw FreeSWITCH XML in the database.

The database stores business configuration.

The FreeSWITCH integration layer translates that configuration into safe FreeSWITCH instructions.

---

# 7. IVR OPTIONS

Implement IVR options.

Conceptually:

```
ivr_options

id
ivr_id
digit
destination_type
destination_id
enabled
timestamps
```

Example:

```
Main IVR

1 -> queue -> Sales
2 -> queue -> Support
0 -> extension -> Reception
```

Supported DTMF keys should be explicitly validated.

At minimum support:

```
0-9
```

If `*` and `#` are supported by the chosen FreeSWITCH implementation, they may also be allowed.

Each digit must be unique within an IVR.

The administrator must not be able to configure two actions for digit 1 in the same IVR.

---

# 8. IVR FAILURE BEHAVIOR

The IVR needs defined behavior for:

* no input
* invalid input
* maximum attempts exceeded

For the first implementation use sensible defaults such as:

```
timeout = 5 seconds
max at
tempts = 3
```

Allow the IVR to replay its prompt after invalid/no input.

After maximum attempts, safely terminate the interaction unless a configured fallback destination exists.

If implementing fallback destinations is straightforward with the existing architecture, model them using the same safe destination abstraction.

Do not create unrestricted dialplan fallthrough.

---

# 9. AUDIO / PROMPTS

Administrators must be able to upload/select an audio prompt for an IVR.

Example:

```
main-menu.wav
```

Do not store large audio binaries directly in MariaDB.

Store metadata/path references in the database.

Create a controlled storage strategy so FreeSWITCH can reliably access the media.

Requirements:

* validate uploads
* generate safe filenames
* prevent path traversal
* do not allow arbitrary filesystem paths from administrator input
* keep telephony audio separate from arbitrary user uploads
* document supported audio formats
* make storage location configurable

If conversion to a FreeSWITCH-compatible audio format is required, isolate conversion in a dedicated service.

Do not make FreeSWITCH fetch a normal authenticated Laravel web page every time a prompt is played.

---

# 10. QUEUES

Implement queues using the appropriate native FreeSWITCH queue/call-center capabilities.

Do not implement queue behavior in Laravel.

Laravel stores queue configuration.

FreeSWITCH executes the queue.

A queue must at minimum have:

```
id
name
strategy
enabled
timestamps
```

Start with only the queue strategies that can be implemented reliably.

Do not expose arbitrary FreeSWITCH strategy strings without validation.

---

# 11. QUEUE MEMBERS

Create explicit queue membership.

Conceptually:

```
queue_members

id
queue_id
sip_extension_id
priority/order if required
enabled
timestamps
```

Example:

```
Sales Queue
    101
    102

Support Queue
    201
    202
```

An extension may belong to multiple queues if the architecture permits it.

Deleting an extension must not leave broken queue memberships.

Use foreign keys and appropriate application validation.

---

# 12. AGENT VS EXTENSION

Keep the initial implementation simple.

For this MVP, a queue member may be backed directly by a SIP extension.

Example:

```
Sales Queue
    Extension 101
    Extension 102
```

Do not build a complex call-center workforce/agent-login system unless FreeSWITCH requires a minimal representation internally.

Architect the domain so a dedicated `agents` concept can be introduced later if required.

Do NOT build:

* agent breaks
* agent shifts
* workforce management
* advanced agent scoring
* supervisor dashboards

in this milestone.

---

# 13. ADMIN UI — IVRS

Add:

```
PBX
  └── IVRs
```

The administrator should be able to:

* list IVRs
* create IVR
* edit IVR
* enable/disable IVR
* select/upload prompt
* configure timeout
* configure maximum attempts
* add/remove digit options

The IVR editing experience should make the call flow obvious.

Example:

```
Main IVR

Prompt:
[ main-menu.wav ]

Timeout:
[ 5 seconds ]

Maximum Attempts:
[ 3 ]

OPTIONS

Digit    Destination Type    Destination
------------------------------------------------
1        Queue               Sales
2        Queue               Support
0        Extension           100

[+ Add Option]
```

Do not require administrators to understand FreeSWITCH XML.

---

# 14. ADMIN UI — QUEUES

Add:

```
PBX
  └── Queues
```

The administrator should be able to:

* list queues
* create queue
* edit queue
* enable/disable queue
* select strategy
* add extensions as members
* remove extensions from queue

Example:

```
Sales

Members:
[x] 101 - Ali
[x] 102 - Sara
```

And:

```
Support

Members:
[x] 201 - Reza
[x] 202 - Maryam
```

Keep the UI simple.

---

# 15. ADMIN UI — DID INBOUND ROUTING

Modify DID/inbound route configuration.

The administrator must be able to select:

```
DID:
02191090000

Inbound Destination Type:
IVR

Destination:
Main IVR
```

The same UI should support:

```
Destination Type: Extension
Destination: 1000
```

or:

```
Destination Type: Queue
Destination: Sales
```

The destination selector must only show enabled and valid destinations.

---

# 16. OUTBOUND CALLER-ID CONFIGURATION

Outbound configuration is independent from the IVR.

The administrator must be able to authorize extensions to use a DID for outbound calls.

For example:

```
DID:
02191090000

Authorized Extensions:

[x] 101
[x] 102
[x] 201
[x] 202

Gateway:
provider-trunk
```

Alternatively, if the existing application models outbound routes per extension, preserve that architecture if it is sound.

The resulting configuration must express:

```
Extension 101
   ↓
Caller ID 02191090000
   ↓
provider-trunk
```

and similarly for 102, 201 and 202.

---

# 17. OUTBOUND SECURITY

This requirement is critical.

The SIP endpoint must NOT be trusted to choose its caller ID.

If extension 101 sends:

```
From: some-other-number
```

that must NOT automatically become the PSTN caller ID.

Determine outbound caller ID from Blucom's database.

The effective caller ID must be an enabled DID that the extension is authorized to use.

Likewise, clients must not be able to select arbitrary gateways.

Gateway selection must come from server-side configuration.

Never construct an unrestricted bridge from SIP-client input.

---

# 18. FREESWITCH CALL FLOW

The implementation must produce behavior equivalent to:

```
Incoming call
      ↓
external Sofia profile
      ↓
public context
      ↓
DID lookup
      ↓
inbound route
      ↓
IVR Main Menu
      ↓
play prompt
      ↓
collect DTMF
      ↓
   ┌──┴──┐
   1     2
   ↓     ↓
Sales  Support
Queue   Queue
   ↓     ↓
101/102 201/202
```

Laravel does not participate synchronously in each DTMF keypress.

FreeSWITCH must have enough configuration to execute the call flow after resolving the configuration.

---

# 19. QUEUE EXECUTION

Use FreeSWITCH's appropriate queue/call-center functionality rather than implementing a fake queue with sequential HTTP requests.

The implementation should support:

* caller enters queue
* configured extensions are eligible destinations
* FreeSWITCH attempts to connect caller to an available member
* caller waits when necessary
* call exits safely when the queue cannot service it

Keep initial queue behavior intentionally small.

Advanced queue announcements, estimated waiting time and analytics can be added later.

---

# 20. XML-CURL / DYNAMIC CONFIGURATION

Use the existing Blucom FreeSWITCH integration described in `AGENTS.md`.

Do not create files such as:

```
/etc/freeswitch/dialplan/customer-123.xml
/etc/freeswitch/directory/101.xml
```

for every admin CRUD operation.

Database configuration must be translated dynamically through the existing FreeSWITCH integration architecture.

However, do not force XML-CURL into areas where FreeSWITCH requires persistent runtime configuration without first understanding the appropriate FreeSWITCH mechanism.

For queue/call-center configuration:

1. Inspect the installed FreeSWITCH modules.
2. Determine the correct native module/configuration mechanism.
3. Document how Laravel configuration will be synchronized or exposed.
4. Implement the smallest reliable integration.

Do not invent FreeSWITCH configuration syntax.

---

# 21. DESTINATION RESOLVER

Implement a central destination abstraction/service.

Conceptually:

```
DestinationResolver
```

Input:

```
destination_type
destination_id
```

Output:

```
validated internal FreeSWITCH destination
```

Examples:

```
extension + 101
    -> extension routing

ivr + 4
    -> IVR 4

queue + 7
    -> Queue 7
```

Do not scatter:

```
if type === 'ivr'
if type === 'queue'
if type === 'extension'
```

through dozens of controllers.

Centralize destination validation and translation.

---

# 22. DATABASE INTEGRITY

Use:

* foreign keys
* unique constraints
* indexes
* transactions
* application validation

Prevent configurations such as:

```
IVR option -> deleted queue
```

or:

```
inbound route -> disabled/nonexistent object
```

Handle deletion carefully.

Prefer preventing deletion when an object is actively referenced, or require the administrator to change dependent routes first.

Do not silently break active call flows.

---

# 23. LOOP DETECTION

Because destinations can eventually reference other destinations, prevent routing loops.

Example:

```
IVR A
  1 -> IVR B

IVR B
  1 -> IVR A
```

This can create an endless call flow.

For this milestone, either:

* prohibit IVR-to-IVR destinations, or
* implement safe loop detection

Do not introduce recursive routing without safeguards.

---

# 24. OBSERVABILITY

Log configuration resolution failures without logging credentials.

Useful information includes:

```
call UUID
DID
extension
destination type
destination ID
queue
route resolution result
```

Do NOT log:

* SIP passwords
* provider passwords
* authentication responses
* API secrets

Make it possible to correlate Laravel routing decisions with FreeSWITCH call UUIDs.

---

# 25. TESTS

Implement automated tests.

## IVR tests

Test:

```
create IVR
update IVR
duplicate digit rejected
disabled IVR cannot be selected
invalid destination rejected
deleted destination handled safely
```

## Inbound routing

Test:

```
DID -> extension
DID -> IVR
DID -> queue
unknown DID rejected
disabled DID rejected
disabled route rejected
```

## Queue tests

Test:

```
queue creation
adding members
removing members
disabled member
deleted extension
empty queue behavior
```

## Outbound tests

Test:

```
101 -> authorized DID -> provider-trunk

102 -> authorized DID -> provider-trunk

unauthorized extension attempting DID -> rejected

spoofed SIP caller ID -> ignored/rejected

disabled DID -> rejected

arbitrary gateway -> rejected
```

---

# 26. REAL INTEGRATION TEST

After automated tests pass, configure a real example.

Use available test numbers rather than hard-coding the documentation example if the actual environment differs.

Target:

```
DID
  ↓
Main IVR
```

Prompt:

```
"Press 1 for Sales.
 Press 2 for Support."
```

Configure:

```
1 -> Sales Queue
     -> Extension 101
     -> Extension 102

2 -> Support Queue
     -> Extension 201
     -> Extension 202
```

Verify using real SIP clients:

TEST 1:

```
External phone
    -> DID
    -> IVR prompt heard
```

TEST 2:

```
Press 1
    -> Sales Queue
    -> 101/102 rings
```

TEST 3:

```
Press 2
    -> Support Queue
    -> 201/202 rings
```

TEST 4:

```
Invalid digit
    -> expected retry behavior
```

TEST 5:

```
No digit
    -> expected timeout behavior
```

TEST 6:

```
Extension 101 calls external number
    -> provider-trunk
    -> authorized DID presented as caller ID
```

TEST 7:

```
Extension 201 calls external number
    -> provider-trunk
    -> authorized DID presented as caller ID
```

---

# 27. DO NOT IMPLEMENT YET

Do not expand scope into:

* visual drag-and-drop call-flow builder
* advanced agent management
* supervisor dashboards
* queue analytics
* billing
* CRM
* WebRTC
* automatic provider provisioning
* multiple FreeSWITCH nodes
* speech recognition
* text-to-speech
* AI voice agents
* conference rooms
* complex voicemail
* advanced recording management
* workforce scheduling

Those are future features.

---

# 28. IMPLEMENTATION ORDER

Implement in small milestones.

## Milestone 1 — Domain/Data Model

Implement:

* IVR schema/models
* IVR options
* queues
* queue members
* generic inbound destinations
* outbound authorization model

Migrations and tests first.

Do not modify FreeSWITCH yet.

Stop and report what changed.

---

## Milestone 2 — Admin UI

Implement:

* IVR CRUD
* prompt upload
* IVR options editor
* Queue CRUD
* queue member management
* DID destination selector
* outbound DID authorization UI

Run tests.

Stop and report.

---

## Milestone 3 — IVR FreeSWITCH Integration

Implement:

```
DID
  -> IVR
  -> audio
  -> DTMF
  -> destination
```

Test with a real call before continuing.

---

## Milestone 4 — Queue Integration

Implement:

```
IVR
  -> Queue
  -> extensions
```

Test Sales independently.

Then test Support independently.

Do not change both simultaneously during initial debugging.

---

## Milestone 5 — Outbound

Implement/verify:

```
extension
   -> authorized DID
   -> provider-trunk
```

Ensure caller-ID spoofing is prevented.

---

## Milestone 6 — End-to-End Acceptance

Verify:

```
DID
 ↓
IVR
 ├── 1 → Sales Queue → 101/102
 └── 2 → Support Queue → 201/202
```

and:

```
101/102/201/202
      ↓
authorized DID
      ↓
provider-trunk
      ↓
     PSTN
```

Do not remove working fallback/static FreeSWITCH configuration until the equivalent dynamic functionality has passed real call tests.

---

# 29. DEFINITION OF DONE

This feature is complete when an administrator can configure the entire scenario through Blucom without manually editing customer-specific FreeSWITCH XML:

1. Create extensions 101, 102, 201 and 202.

2. Register those extensions using SIP clients.

3. Add a DID.

4. Create Sales Queue.

5. Add 101 and 102 to Sales.

6. Create Support Queue.

7. Add 201 and 202 to Support.

8. Upload/select an IVR prompt.

9. Create Main IVR.

10. Configure:

    ```
    1 -> Sales Queue
    2 -> Support Queue
    ```

11. Set the DID inbound destination to Main IVR.

12. Call the DID and hear the IVR.

13. Press 1 and reach Sales.

14. Press 2 and reach Support.

15. Authorize 101, 102, 201 and 202 to use the DID for outbound calls.

16. Make an external call from each extension through `provider-trunk`.

17. Confirm the configured DID is presented as outbound caller ID.

18. Perform all of the above without restarting FreeSWITCH for ordinary Blucom CRUD operations.

Preserve the existing working telephony system throughout the implementation.
