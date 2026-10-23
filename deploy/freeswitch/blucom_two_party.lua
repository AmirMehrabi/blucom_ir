-- Shared application for server-authorized manual two-party sessions.
-- No database credentials, HTTP input, tenant dialplan files or SIP header policy.
local api = freeswitch.API()
local agent = session
local customer = nil
local agent_uuid = agent:get_uuid()
local customer_uuid = agent:getVariable('blucom_customer_uuid') or ''
local session_id = agent:getVariable('blucom_session_id') or ''
local waiting = 'tone_stream://%(2000,4000,440,480);loops=-1'
local waiting_started = false
local ready_at = ''
local times = {}
local failure_reason = nil
local result_path = agent:getVariable('blucom_result_path') or ''
local function journal(final)
    if result_path == '' then return false end
    if not result_path:match('^/[a-zA-Z0-9_./%-]+%.json$') then return false end
    local output = io.open(result_path .. '.tmp', 'w')
    if not output then return false end
    local fields = {'"session_id":"' .. session_id .. '"', '"agent_uuid":"' .. agent_uuid .. '"',
        '"customer_uuid":"' .. customer_uuid .. '"', '"final":' .. (final and 'true' or 'false')}
    for key, epoch in pairs(times) do fields[#fields+1] = '"' .. key .. '":' .. tostring(epoch) end
    if failure_reason then fields[#fields+1] = '"failure_reason":"' .. failure_reason .. '"' end
    output:write('{' .. table.concat(fields, ',') .. '}')
    output:close()
    return os.rename(result_path .. '.tmp', result_path) ~= nil
end


local function phase(name, failure)
    agent:setVariable('blucom_phase', name)
    agent:setVariable('blucom_phase_' .. name .. '_at', tostring(os.time()))
    times[name] = os.time()
    if failure then agent:setVariable('blucom_failure', failure); failure_reason = failure end
    if not journal(false) and name ~= 'failed' then error('Call journal unavailable') end
    local event = freeswitch.Event('CUSTOM', 'blucom::two_party')
    event:addHeader('Blucom-Agent-UUID', agent_uuid)
    event:addHeader('Blucom-Phase', name)
    event:addHeader('Blucom-Agent-Ready-At', ready_at)
    if failure then event:addHeader('Blucom-Failure', failure) end
    event:fire()
end

local function stop_waiting()
    if waiting_started and agent:ready() then
        api:execute('uuid_displace', agent_uuid .. ' stop ' .. waiting)
    end
    waiting_started = false
end

local function prompt(leg, path, name)
    if path == '' then return true end
    if not path:match('^/[a-zA-Z0-9_./%-]+$') then return false end
    local file = io.open(path, 'rb')
    if not file then return false end
    file:close()
    phase(name)
    leg:execute('playback', path)
    return leg:ready() and leg:getVariable('current_application_response') == 'FILE PLAYED'
end

local function run()
    if not agent:ready() or not agent:answered() then return end
    if not session_id:match('^[a-f0-9%-]+$') or not customer_uuid:match('^[a-f0-9%-]+$') or #customer_uuid ~= 36 then
        error('Invalid correlation')
    end
    local gateway = agent:getVariable('blucom_gateway') or ''
    local number = agent:getVariable('blucom_customer_number') or ''
    local caller = agent:getVariable('blucom_caller_id') or ''
    if not gateway:match('^[a-zA-Z0-9_%-]+$') or not number:match('^%+[1-9][0-9]+$') or not caller:match('^[1-9][0-9]+$') then
        error('Invalid approved target')
    end
    local max_seconds = math.min(7200, math.max(60, tonumber(agent:getVariable('blucom_max_seconds')) or 3600))
    api:execute('sched_hangup', '+' .. max_seconds .. ' ' .. agent_uuid .. ' ALLOTTED_TIMEOUT')
    function blucom_two_party_hangup()
        api:execute('uuid_kill', customer_uuid .. ' ORIGINATOR_CANCEL')
    end
    agent:setHangupHook('blucom_two_party_hangup')
    phase('agent_answered')
    if not prompt(agent, agent:getVariable('blucom_agent_audio') or '', 'agent_prompt') then
        if agent:ready() then phase('failed', 'playback_failed') end
        return
    end
    if agent:getVariable('blucom_require_confirmation') == 'true' then
        phase('agent_confirmation')
        agent:flushDigits()
        local timeout = math.min(30, math.max(5, tonumber(agent:getVariable('blucom_confirmation_timeout')) or 10))
        -- One attempt. Other digits reject immediately; no stale pre-prompt DTMF.
        local digit = agent:playAndGetDigits(1, 1, 1, timeout * 1000, '#', 'tone_stream://%(200,0,1000)', '', '[0-9]')
        if not agent:ready() then return end
        if digit ~= '1' then phase('failed', 'agent_declined_confirmation'); return end
    end
    if not agent:ready() then return end
    ready_at = tostring(os.time())
    agent:setVariable('blucom_agent_ready_at', ready_at)
    phase('customer_dialing')
    local timeout = math.min(90, math.max(5, tonumber(agent:getVariable('blucom_customer_timeout')) or 45))
    api:execute('uuid_displace', agent_uuid .. ' start ' .. waiting .. ' 0 mux')
    waiting_started = true
    local dial = '{origination_uuid=' .. customer_uuid .. ',originate_timeout=' .. timeout .. ',ignore_early_media=true' ..
        ',origination_caller_id_number=' .. caller .. ',origination_caller_id_name=' .. caller ..
        ',hangup_after_bridge=true,blucom_session_id=' .. session_id ..
        ',blucom_agent_uuid=' .. agent_uuid .. ',accountcode=' .. (agent:getVariable('accountcode') or '') ..
        '}sofia/gateway/' .. gateway .. '/' .. number
    customer = freeswitch.Session(dial, agent)
    stop_waiting()
    if not agent:ready() then return end
    if not customer:ready() or not customer:answered() then
        local cause = customer:hangupCause()
        local reason = 'customer_unreachable'
        if cause == 'USER_BUSY' then reason = 'customer_busy'
        elseif cause == 'NO_ANSWER' or cause == 'NO_USER_RESPONSE' or cause == 'ALLOTTED_TIMEOUT' then reason = 'customer_unanswered'
        elseif cause == 'NETWORK_OUT_OF_ORDER' or cause == 'NORMAL_TEMPORARY_FAILURE' or cause == 'GATEWAY_DOWN' then reason = 'trunk_failure' end
        phase('failed', reason)
        agent:execute('playback', 'tone_stream://%(300,200,480,620);loops=3')
        return
    end
    phase('customer_answered')
    api:execute('sched_hangup', '+' .. max_seconds .. ' ' .. customer_uuid .. ' ALLOTTED_TIMEOUT')
    if not prompt(customer, agent:getVariable('blucom_recording_notice') or '', 'customer_prompt') then
        if agent:ready() then phase('failed', 'playback_failed') end
        return
    end
    if not prompt(customer, agent:getVariable('blucom_customer_audio') or '', 'customer_prompt') then
        if agent:ready() then phase('failed', 'playback_failed') end
        return
    end
    if not agent:ready() or not customer:ready() then return end
    local recording_path = agent:getVariable('blucom_recording_path') or ''
    if recording_path ~= '' and recording_path:match('^/[a-zA-Z0-9_./%-]+$') then
        agent:setVariable('RECORD_STEREO', 'true')
        agent:setVariable('RECORD_MIN_SEC', '0')
        agent:setVariable('RECORD_BRIDGE_REQ', 'true')
        agent:setVariable('RECORD_ANSWER_REQ', 'true')
        agent:setVariable('record_sample_rate', '8000')
        agent:setVariable('RECORD_HANGUP_ON_ERROR', 'false')
        local marker = agent:getVariable('blucom_recording_complete') or ''
        if marker:match('^/[a-zA-Z0-9_./%-]+$') then
            agent:setVariable('record_post_process_exec_api', 'luarun:blucom_recording_complete.lua ' .. marker)
            local limit = tonumber(agent:getVariable('blucom_recording_max_seconds')) or 3600
            agent:execute('record_session', recording_path .. ' +' .. math.min(7200, limit))
        end
    end
    -- Only CHANNEL_BRIDGE / switch-owned bridge_epoch establish conversation time.
    freeswitch.bridge(agent, customer)
end

local ok = pcall(run)
-- Lua retains the agent session while this application runs, allowing a final
-- snapshot of the switch-owned bridge timestamp even after a leg hung up.
local dump = api:execute('uuid_dump', agent_uuid) or ''
local bridge_us = tonumber(dump:match('Caller%-Channel%-Bridged%-Time:%s*(%d+)')) or 0
if bridge_us > 0 then times.bridged = math.floor(bridge_us / 1000000) end
if not ok and not failure_reason then failure_reason = 'control_unavailable' end
if not times.bridged and not failure_reason then
    failure_reason = times.agent_confirmation and not times.customer_dialing and 'agent_declined_confirmation' or 'agent_hangup'
end

stop_waiting()
if not ok and agent:ready() then phase('failed', 'control_unavailable') end
if customer and customer:ready() then customer:hangup('NORMAL_CLEARING') end
if agent:ready() then agent:hangup('NORMAL_CLEARING') end
times.ended = os.time()
journal(true)
