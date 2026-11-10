-- Generic application: authorization and extension IDs come from fresh XML-CURL.
-- Only unanswered direct bridges are eligible; never intercept an active conversation.
if not session or not session:ready() then return end
local identity, targets = argv[1], argv[2]
local function reject(cause)
    if session:ready() then session:hangup(cause) end
end
if not identity or not identity:match('^[%w_-]+$') or not targets or not targets:match('^[%d,]+$')
    or session:getVariable('sip_auth_username') ~= identity then
    reject('CALL_REJECTED')
    return
end
local allowed = {}
for id in targets:gmatch('%d+') do allowed[id] = true end
local api = freeswitch.API()
local function get(uuid, name) return api:execute('uuid_getvar', uuid .. ' ' .. name) end
local function eligible(uuid)
    if not allowed[get(uuid, 'blucom_pickup_target')]
        or get(uuid, 'current_application') ~= 'bridge' then return nil end
    -- Read live channel timestamps, not answer_epoch (a CDR variable).
    local dump = api:execute('uuid_dump', uuid)
    local answered = dump:match('Caller%-Channel%-Answered%-Time: (%d+)')
    local state = dump:match('Channel%-Call%-State: ([A-Z_]+)')
    if answered ~= '0' or (state ~= 'RINGING' and state ~= 'EARLY') then return nil end
    return tonumber(dump:match('Caller%-Channel%-Created%-Time: (%d+)'))
end
local candidates = {}
-- UUID is the first column. Never parse caller names or numbers as commands.
for line in api:execute('show', 'channels as delim |'):gmatch('[^\r\n]+') do
    local uuid = line:match('^([%x%-]+)|')
    if uuid and #uuid == 36 and uuid ~= session:get_uuid() then
        local created = eligible(uuid)
        if created then candidates[#candidates + 1] = {uuid = uuid, created = created} end
    end
end
table.sort(candidates, function(a, b)
    if a.created == b.created then return a.uuid < b.uuid end
    return a.created < b.created
end)
local owner = session:get_uuid()
for _, candidate in ipairs(candidates) do
    local key = 'blucom-pickup/' .. candidate.uuid
    if session:ready() and api:execute('hash', 'insert_ifempty/' .. key .. '/' .. owner):match('^%+OK') then
        -- Expire orphaned claims if the script/channel terminates unexpectedly.
        local cleanup = 'delete_ifmatch/' .. key .. '/' .. owner
        local scheduled = api:execute('sched_api', '+10 none hash ' .. cleanup):match('^%+OK')
        if scheduled and eligible(candidate.uuid) then
            -- Native checks repeat immediately before connecting. The atomic
            -- claim serializes competing pickup requests for the same caller.
            session:setVariable('intercept_unanswered_only', 'true')
            session:setVariable('intercept_unbridged_only', 'true')
            session:execute('intercept', candidate.uuid)
            api:execute('hash', cleanup)
            if session:answered() then return end
        else
            api:execute('hash', cleanup)
        end
    end
end
reject('NO_PICKUP')
