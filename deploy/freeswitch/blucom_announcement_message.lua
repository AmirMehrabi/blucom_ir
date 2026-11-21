-- Enter the mailbox only after successful announcement playback.
if not session or not session:ready() then return end
if session:getVariable("blucom_audio_outcome") ~= "announcement:FILE PLAYED" then return end

local key, domain, mailbox = argv[1], argv[2], argv[3]
if key == "automatic" then
    session:setVariable("skip_greeting", "true")
    session:setVariable("skip_instructions", "true")
    session:setVariable("skip_record_check", "true")
    session:setVariable("skip_record_urgent_check", "true")
else
    if session:getDigits(1, "", 5000) ~= key or not session:ready() then return end
end

session:execute("flush_dtmf")
session:setVariable("blucom_audio_outcome", "voicemail_entered")
session:execute("voicemail", "default " .. domain .. " " .. mailbox)
