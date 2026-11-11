-- Invoked on the answered PSTN leg by the private outbound worker.
local path = session:getVariable('blucom_audio_path') or ''
local result = session:getVariable('blucom_audio_result') or ''
if not path:match('^/[%w_./%-]+%.wav$') or not result:match('^/[%w_./%-]+%.result$') then
    session:hangup('NORMAL_TEMPORARY_FAILURE')
    return
end

if not session:ready() or not session:answered() then
    return
end
local audio = io.open(path, 'rb')
local duration_ms = nil
if audio then
    local header = audio:read(12)
    if header and header:sub(1, 4) == 'RIFF' and header:sub(9, 12) == 'WAVE' then
        for _ = 1, 16 do
            local chunk = audio:read(8)
            if not chunk or #chunk ~= 8 then break end
            local a, b, c, d = chunk:byte(5, 8)
            local bytes = a + 256 * b + 65536 * c + 16777216 * d
            if chunk:sub(1, 4) == 'data' then
                duration_ms = math.floor(bytes / 32)
                break
            end
            if not audio:seek('cur', bytes + bytes % 2) then break end
        end
    end
    audio:close()
end

local started = os.time()
session:setVariable('blucom_audio_playing_at', tostring(started))
session:setVariable('playback_terminators', 'none')
session:setVariable('playback_timeout_sec', '65')
if duration_ms and duration_ms > 0 and duration_ms <= 60000 then
    session:execute('playback', path)
end
local response = session:getVariable('current_application_response')
local outcome = 'playback_failed'
local played_ms = tonumber(session:getVariable('playback_ms') or '') or 0
if response == 'FILE PLAYED' and duration_ms and played_ms >= duration_ms then
    outcome = 'completed'
elseif not session:ready() or played_ms > 0 then
    outcome = 'interrupted'
end

local temp = result:gsub('%.result$', '.tmp')
local file = io.open(temp, 'w')
if file then
    file:write(outcome .. ' ' .. started .. '\n')
    file:close()
    os.rename(temp, result)
end
if session:ready() then
    session:hangup('NORMAL_CLEARING')
end
