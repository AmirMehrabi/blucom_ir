-- Generic, listen-only supervisor application. No customer configuration lives here.
-- Called only by the private backend through ESL, never by a public dial code.
local target, identity, domain, profile, seconds, mode = table.unpack(argv)
local function valid_uuid(value)
    return type(value) == 'string' and #value == 36 and value:match('^[%x%-]+$')
end
if not session or not session:ready() then return end
if not valid_uuid(target) or not identity or not identity:match('^[%w_-]+$')
    or not domain or not domain:match('^[%w_.-]+$') or not profile or not profile:match('^[%w_-]+$')
    or not tonumber(seconds) or tonumber(seconds) < 1 or tonumber(seconds) > 3600
    or (mode ~= 'web' and mode ~= 'extension') then
    session:hangup('CALL_REJECTED')
    return
end
local api = freeswitch.API()
local listener = session:get_uuid()
if api:execute('hash', 'select/monitor-session/' .. listener) ~= target then
    session:hangup('CALL_REJECTED')
    return
end
local function get(name) return api:execute('uuid_getvar', target .. ' ' .. name) end
local outgoing = get('direction') == 'outbound'
local actual = outgoing and get('dialed_user') or get('sip_auth_username')
local realm = outgoing and get('dialed_domain') or get('sip_auth_realm')
local channel = get('channel_name')
local bridge = get('bridge_uuid')
if actual ~= identity or realm ~= domain or not channel:find('sofia/' .. profile .. '/', 1, true)
    or not valid_uuid(bridge) or api:execute('uuid_exists', bridge) ~= 'true' then
    session:hangup('CALL_REJECTED')
    return
end
session:setVariable('eavesdrop_enable_dtmf', 'false')
session:setVariable('eavesdrop_whisper_aleg', 'false')
session:setVariable('eavesdrop_whisper_bleg', 'false')
session:setVariable('eavesdrop_bridge_aleg', 'true')
session:setVariable('eavesdrop_bridge_bleg', 'true')
session:execute('sched_hangup', '+' .. seconds .. ' ALLOTTED_TIMEOUT')
session:setVariable('blucom_monitor_started', 'true')
session:execute('eavesdrop', target)
api:execute('hash', 'delete/monitor-session/' .. listener)
if session:ready() then session:hangup('NORMAL_CLEARING') end
