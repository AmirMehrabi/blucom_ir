-- Runs on the member leg when mod_callcenter bridges its selected agent.
local destination = argv and argv[1]
if not session or not destination or not destination:match('^rec%-[a-f0-9]+%-[a-f0-9]+$') then
    return
end

local peer = session:getVariable('bridge_uuid')
if not peer or not peer:match('^[a-f0-9%-]+$') or #peer ~= 36 then
    return
end

local api = freeswitch.API()
local member = api:execute('uuid_getvar', peer .. ' cc_member_session_uuid')
local agent = api:execute('uuid_getvar', peer .. ' cc_agent')
local id = agent and agent:match('^blucom_a_([1-9][0-9]*)@default$')
if member == session:get_uuid() and id then
    session:execute('execute_extension', destination .. '-' .. id .. ' XML blucom_recording')
end
