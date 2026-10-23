-- This replaces every FreeSWITCH media/network primitive with a deterministic fake.
local count = 0
local journal_path = os.tmpname() .. '.json'
local function scenario(options)
    local calls, bridges, actions, phases, commands = 0, 0, {}, {}, {}
    local vars = {
        blucom_result_path = journal_path,
        blucom_session_id = '00000000-0000-4000-8000-000000000001',
        blucom_customer_uuid = '00000000-0000-4000-8000-000000000002',
        blucom_customer_number = '+989120000001', blucom_caller_id = '982100000000',
        blucom_gateway = 'approved-test', blucom_agent_audio = options.agent_prompt and '/tmp/agent.wav' or '',
        blucom_customer_audio = options.customer_prompt and '/tmp/customer.wav' or '',
        blucom_require_confirmation = options.confirm and 'true' or 'false',
        blucom_recording_path = options.record and '/tmp/record.wav' or '',
        blucom_recording_complete = '/tmp/record.complete', accountcode = 'btenant_1',
    }
    local function leg(is_agent)
        local value = { alive = true, answered_value = true, vars = is_agent and vars or {} }
        function value:get_uuid() return is_agent and '00000000-0000-4000-8000-000000000003' or vars.blucom_customer_uuid end
        function value:getVariable(name) return self.vars[name] end
        function value:setVariable(name, v) self.vars[name] = v end
        function value:ready() return self.alive end
        function value:answered() return self.answered_value end
        function value:setHangupHook(name) assert(type(name) == 'string', 'FreeSWITCH expects a named Lua callback') end
        function value:flushDigits() end
        function value:playAndGetDigits() return options.digit or '' end
        function value:hangupCause() return options.customer_cause or 'USER_BUSY' end
        function value:hangup() self.alive = false end
        function value:execute(app, arg)
            actions[#actions + 1] = { app, arg, is_agent }
            if app == 'playback' then
                self.vars.current_application_response = options.playback_fail and 'FILE NOT FOUND' or 'FILE PLAYED'
                if not is_agent and options.agent_hangup_prompt then session.alive = false end
            end
        end
        return value
    end
    session = leg(true)
    if options.no_agent_answer then session.answered_value = false end
    local real_open = io.open
    io.open = function(path, mode)
        if path == journal_path .. '.tmp' then return real_open(path, mode) end
        return { close = function() end }
    end
    freeswitch = {
        API = function() return { execute = function(_, app, arg) commands[#commands+1] = app .. ' ' .. arg; if app == 'uuid_dump' and bridges > 0 then return 'Caller-Channel-Bridged-Time: ' .. os.time() .. '000000' end; return '+OK' end } end,
        Event = function() return { addHeader = function(_, key, value) if key == 'Blucom-Phase' then phases[#phases+1] = value end end, fire = function() end } end,
        Session = function(dial, agent)
            calls = calls + 1
            assert(vars.blucom_agent_ready_at, 'Customer dialed before agent readiness')
            assert(not options.confirm or options.digit == '1', 'Customer dialed without confirmation')
            assert(dial:find('ignore_early_media=true', 1, true))
            local b = leg(false)
            if options.customer_fail then b.alive = false end
            if options.early_media then b.answered_value = false end
            if options.agent_hangup_dial then agent.alive = false end
            return b
        end,
        bridge = function(agent, customer)
            assert(agent:ready() and customer:ready())
            assert(not options.playback_fail)
            bridges = bridges + 1
            actions[#actions+1] = { 'bridge' }
        end,
    }
    dofile('deploy/freeswitch/blucom_two_party.lua')
    io.open = real_open
    assert(calls == (options.expect_calls or 0), 'Unexpected customer origination count')
    assert(bridges == (options.expect_bridges or 0), 'Unexpected bridge count')
    assert(not session:ready(), 'Agent leg leaked')
    local recordings = 0
    for _, action in ipairs(actions) do
        if action[1] == 'record_session' then recordings = recordings + 1 end
    end
    assert(recordings == (options.record and (options.expect_bridges or 0) or 0), 'Recording enabled at wrong stage')
    if options.expect_failure then assert(vars.blucom_failure == options.expect_failure, 'Wrong failure') end
    count = count + 1
end
scenario({ expect_calls = 1, expect_bridges = 1 })
scenario({ no_agent_answer = true })
scenario({ confirm = true, digit = '1', expect_calls = 1, expect_bridges = 1 })
scenario({ confirm = true, expect_failure = 'agent_declined_confirmation' })
scenario({ confirm = true, digit = '2', expect_failure = 'agent_declined_confirmation' })
scenario({ customer_fail = true, expect_calls = 1, expect_failure = 'customer_busy' })
scenario({ customer_fail = true, customer_cause = 'NO_ANSWER', expect_calls = 1, expect_failure = 'customer_unanswered' })
scenario({ customer_fail = true, customer_cause = 'GATEWAY_DOWN', expect_calls = 1, expect_failure = 'trunk_failure' })
scenario({ agent_prompt = true, expect_calls = 1, expect_bridges = 1 })
scenario({ customer_prompt = true, expect_calls = 1, expect_bridges = 1 })
scenario({ agent_prompt = true, customer_prompt = true, confirm = true, digit = '1', expect_calls = 1, expect_bridges = 1 })
scenario({ agent_prompt = true, playback_fail = true, expect_failure = 'playback_failed' })
scenario({ customer_prompt = true, playback_fail = true, expect_calls = 1, expect_failure = 'playback_failed' })
scenario({ early_media = true, expect_calls = 1 })
scenario({ agent_hangup_dial = true, expect_calls = 1 })
scenario({ customer_prompt = true, agent_hangup_prompt = true, expect_calls = 1 })
scenario({ record = true, expect_calls = 1, expect_bridges = 1 })
os.remove(journal_path)
print('Lua workflow scenarios passed: ' .. count)
