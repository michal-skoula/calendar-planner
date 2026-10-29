<?php

use App\Mcp\Servers\PlannerServer;
use Laravel\Mcp\Facades\Mcp;

/*
| HTTP MCP server, for Claude Desktop, Claude Code and claude.ai connectors.
| Authenticates with the same Sanctum bearer token as the Shortcut endpoint:
| `php artisan planner:token`.
*/
Mcp::web('/mcp/planner', PlannerServer::class)
    ->middleware('auth:sanctum')
    ->name('mcp.planner');

/*
| Local stdio server, for running the planner as a tool from this machine.
*/
Mcp::local('planner', PlannerServer::class);
