<!-- Part of the Monocle documentation - see README.md -->

# MCP servers

Part of [what gets recorded](../lenses.md).

Section `mcp`: calls to MCP servers your application provides - which tool, resource or prompt, the arguments, duration and whether it failed. Servers listed in `mcp.except_servers` (Monocle's own and Laravel Boost's by default) are not recorded - see [Configuration](../configuration.md#mcp). Over HTTP a call is part of the request capture; over stdio each call is its own capture of type `mcp`.

Needs `laravel/mcp`.

Error: a call that failed.
