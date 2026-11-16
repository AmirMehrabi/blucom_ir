-- The dialplan supplies only published menu keys and authorized extensions.
if not session or not session:ready() then return end

local greeting, commandKeys, extensions = argv[1], argv[2], argv[3]
local complete, prefixes = {}, {}
for key in commandKeys:gmatch('%d') do complete[key] = true end
for extension in extensions:gmatch('%d+') do
    complete[extension] = true
    for length = 1, #extension - 1 do
        prefixes[extension:sub(1, length)] = true
    end
end

for attempt = 1, 2 do
    if not session:ready() then return end
    -- read interrupts the greeting as soon as the first digit arrives.
    local digits = session:read(1, 1, greeting, 8000, '#', 3000)
    while session:ready() and prefixes[digits] do
        local nextDigit = session:getDigits(1, '', 3000)
        if nextDigit == '' or nextDigit == '#' then break end
        digits = digits .. nextDigit
    end
    if session:ready() and complete[digits] then
        session:setVariable('blucom_ivr_digit', digits)
        return
    end
end
