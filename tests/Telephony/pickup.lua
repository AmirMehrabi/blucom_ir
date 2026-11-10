local source = 'deploy/freeswitch/blucom_pickup.lua'
local count = 0
local first = '11111111-1111-4111-8111-111111111111'
local second = '22222222-2222-4222-8222-222222222222'
local picker = '33333333-3333-4333-8333-333333333333'
local function scenario(options, expected)
    local intercepted, cause, answered, reads = nil, nil, false, 0
    local flags, hashes = {}, {}
    local data = {
        [first] = {target = '10', created = '200', answered = '0', state = 'RINGING', app = 'bridge'},
        [second] = {target = '10', created = '100', answered = '0', state = 'EARLY', app = 'bridge'},
    }
    if options.foreign then data[second].target = '99' end
    if options.active then data[second].answered = '100'; data[second].state = 'ACTIVE' end
    if options.not_bridge then data[second].app = 'playback' end
    if options.none then data[first].answered = '100'; data[second].answered = '100' end
    if options.locked then hashes['blucom-pickup/' .. second] = 'another-picker' end
    session = {
        ready = function() return cause == nil end,
        get_uuid = function() return picker end,
        getVariable = function(_, name) return options.unauthenticated and '' or 'alice' end,
        setVariable = function(_, name, value) flags[name] = value end,
        answered = function() return answered end,
        hangup = function(_, value) cause = value end,
        execute = function(_, app, uuid)
            assert(app == 'intercept')
            assert(flags.intercept_unanswered_only == 'true' and flags.intercept_unbridged_only == 'true')
            assert(hashes['blucom-pickup/' .. uuid] == picker, 'Must hold atomic claim before intercept')
            -- Simulates native answer guard when the phone answers after final lookup.
            if not options.answer_race then intercepted = uuid; answered = true end
        end,
    }
    freeswitch = {API = function() return {execute = function(_, command, args)
        if command == 'show' then return 'uuid|direction\n' .. first .. '|inbound\n' .. second .. '|inbound\n' end
        if command == 'sched_api' then return options.scheduler_fail and '-ERR' or '+OK Added: 1' end
        if command == 'hash' then
            local operation, key, value = args:match('^([^/]+)/(blucom%-pickup/[^/]+)/(.+)$')
            if operation == 'insert_ifempty' then
                if hashes[key] then return '-ERR key already exists' end
                hashes[key] = value; return '+OK\n'
            end
            if operation == 'delete_ifmatch' and hashes[key] == value then hashes[key] = nil end
            return '+OK'
        end
        local uuid, name = args:match('^([^ ]+) (.+)$')
        if command == 'uuid_getvar' then
            if name == 'blucom_pickup_target' then return data[uuid].target end
            if name == 'current_application' then return data[uuid].app end
        end
        if command == 'uuid_dump' then
            uuid = args
            if uuid == second then
                reads = reads + 1
                if options.disappears and reads > 1 then return '-ERR No such channel' end
            end
            local row = data[uuid]
            return 'Channel-Call-State: ' .. row.state .. '\nCaller-Channel-Answered-Time: ' .. row.answered .. '\nCaller-Channel-Created-Time: ' .. row.created .. '\n'
        end
        error('Unexpected API command: ' .. command .. ' ' .. args)
    end} end}
    argv = {'alice', options.malformed and '10;evil' or '10'}
    dofile(source)
    assert(intercepted == expected, 'Unexpected pickup target')
    if expected then assert(not cause, 'Successful pickup must remain connected') else assert(cause, 'Failure must end safely') end
    assert(hashes['blucom-pickup/' .. first] == nil)
    assert(hashes['blucom-pickup/' .. second] == (options.locked and 'another-picker' or nil))
    count = count + 1
end
scenario({}, second)
scenario({foreign = true}, first)
scenario({active = true}, first)
scenario({not_bridge = true}, first)
scenario({locked = true}, first)
scenario({disappears = true}, first)
scenario({none = true}, nil)
scenario({unauthenticated = true}, nil)
scenario({malformed = true}, nil)
scenario({answer_race = true}, nil)
scenario({scheduler_fail = true}, nil)
print('Lua pickup scenarios passed: ' .. count)
