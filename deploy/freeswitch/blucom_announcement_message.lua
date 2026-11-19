-- Playback has finished. Only a fresh keypress may enter the selected mailbox.
if not session or not session:ready() then return end
if session:getVariable("blucom_audio_outcome") ~= "announcement:FILE PLAYED" then return end

local key, domain, mailbox = argv[1], argv[2], argv[3]
if session:getDigits(1, "", 5000) ~= key or not session:ready() then return end

session:execute("flush_dtmf")
session:setVariable("blucom_audio_outcome", "voicemail_entered")
session:execute("voicemail", "default " .. domain .. " " .. mailbox)
