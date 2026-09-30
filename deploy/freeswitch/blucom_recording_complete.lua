-- Generic infrastructure hook. Called after FreeSWITCH closes a recording.
-- No credentials, HTTP, customer configuration, or media processing here.
local path = argv and argv[1]
if not path or not path:match('^/[%w_/%-]+/[0-9a-f%-]+%.complete$') then
    return
end
local id = path:match('/([0-9a-f%-]+)%.complete$')
if not id or #id ~= 36 then return end
local file = io.open(path .. '.tmp', 'w')
if file then
    file:write('complete\n')
    file:close()
    os.rename(path .. '.tmp', path)
end
