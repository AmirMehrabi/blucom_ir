local actual_fs = freeswitch
local source = argv[1]
assert(loadfile(source))
local passed = 0
local uuid = '11111111-1111-4111-8111-111111111111'
local peer = '22222222-2222-4222-8222-222222222222'
local listener = '33333333-3333-4333-8333-333333333333'
local function run(overrides, permit, success)
    local variables = { direction='inbound', sip_auth_username='tenant_test', sip_auth_realm='test.local', channel_name='sofia/internal/test', bridge_uuid=peer }
    for k,v in pairs(overrides) do variables[k] = v end
    local actions, flags, hungup = {}, {}, nil
    freeswitch = { API = function() return { execute = function(_, command, data)
        if command == 'hash' then return permit and uuid or '' end
        if command == 'uuid_exists' then return 'true' end
        local name = data:match('^[^ ]+ (.+)$')
        return variables[name] or '_undef_'
    end } end }
    session = {
        ready=function() return true end,
        get_uuid=function() return listener end,
        hangup=function(_, cause) hungup=cause end,
        setVariable=function(_, name, value) flags[name]=value end,
        execute=function(_, name, value) actions[name]=value end,
    }
    argv={uuid, 'tenant_test', 'test.local', 'internal', '60', 'web'}
    assert(loadfile(source))()
    if success then
        assert(actions.eavesdrop == uuid, 'missing eavesdrop')
        assert(flags.eavesdrop_enable_dtmf == 'false')
        assert(flags.eavesdrop_whisper_aleg == 'false' and flags.eavesdrop_whisper_bleg == 'false')
        assert(flags.eavesdrop_bridge_aleg == 'true' and flags.eavesdrop_bridge_bleg == 'true')
        assert(hungup == 'NORMAL_CLEARING')
    else
        assert(not actions.eavesdrop, 'unauthorized eavesdrop')
        assert(hungup == 'CALL_REJECTED')
    end
    passed = passed + 1
end
run({}, true, true)
run({direction='outbound', dialed_user='tenant_test', dialed_domain='test.local'}, true, true)
run({sip_auth_username='another_tenant'}, true, false)
run({sip_auth_realm='other.local'}, true, false)
run({channel_name='sofia/external/provider'}, true, false)
run({bridge_uuid=''}, true, false)
run({}, false, false)
freeswitch=actual_fs
session=nil
stream:write('Native monitoring checks passed: '..passed..'\n')
