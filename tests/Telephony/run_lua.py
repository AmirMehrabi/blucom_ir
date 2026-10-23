"""Run the isolated workflow fixture with system liblua; no SIP or network APIs."""
import ctypes
import ctypes.util
import sys

libpath = ctypes.util.find_library('lua5.4') or ctypes.util.find_library('lua5.3') or ctypes.util.find_library('lua5.2')
if not libpath:
    print('liblua is unavailable', file=sys.stderr)
    sys.exit(77)
lua = ctypes.CDLL(libpath)
lua.luaL_newstate.restype = ctypes.c_void_p
lua.luaL_openlibs.argtypes = [ctypes.c_void_p]
lua.luaL_loadfilex.argtypes = [ctypes.c_void_p, ctypes.c_char_p, ctypes.c_char_p]
lua.lua_pcallk.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.c_int, ctypes.c_int, ctypes.c_longlong, ctypes.c_void_p]
lua.lua_tolstring.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.POINTER(ctypes.c_size_t)]
lua.lua_tolstring.restype = ctypes.c_char_p
lua.lua_close.argtypes = [ctypes.c_void_p]
state = lua.luaL_newstate()
lua.luaL_openlibs(state)
result = lua.luaL_loadfilex(state, b'tests/Telephony/two_party.lua', None)
if result == 0:
    result = lua.lua_pcallk(state, 0, -1, 0, 0, None)
if result:
    print(lua.lua_tolstring(state, -1, None).decode(), file=sys.stderr)
lua.lua_close(state)
sys.exit(1 if result else 0)
