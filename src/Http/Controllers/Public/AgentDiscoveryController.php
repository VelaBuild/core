<?php

namespace VelaBuild\Core\Http\Controllers\Public;

use VelaBuild\Core\Http\Controllers\Controller;

class AgentDiscoveryController extends Controller
{
    /**
     * Serve /.well-known/api-catalog
     *
     * Returns an API catalog in linkset+json format so that automated
     * agents can discover the site's public API endpoints.
     */
    public function apiCatalog()
    {
        $payload = [
            'linkset' => [
                [
                    'anchor' => url('/api/content'),
                    'service-desc' => [
                        ['href' => url('/api/content'), 'type' => 'application/json'],
                    ],
                    'service-doc' => [
                        ['href' => url('/api/content'), 'type' => 'application/json'],
                    ],
                ],
            ],
        ];

        return response()->json($payload)
            ->header('Content-Type', 'application/linkset+json');
    }

    /**
     * Serve /.well-known/mcp/server-card.json
     *
     * Advertises the Model Context Protocol server so that MCP-aware
     * agents can connect without prior configuration.
     */
    public function mcpServerCard()
    {
        return response()->json([
            'serverInfo' => [
                'name' => config('app.name', 'Vela CMS'),
                'version' => '1.0.0',
            ],
            'transport' => [
                'type' => 'http',
                'url' => url('/api/mcp'),
            ],
            'capabilities' => [
                'tools' => true,
                'resources' => true,
            ],
        ]);
    }

    /**
     * Serve /.well-known/agent-skills/index.json
     *
     * Provides an Agent Skills Index so that AI agents can discover
     * what capabilities this site exposes.
     */
    public function agentSkillsIndex()
    {
        return response()->json([
            '$schema' => 'https://agentskills.io/schema/v0.2.0/index.json',
            'skills' => [
                [
                    'name' => 'content-api',
                    'type' => 'api',
                    'description' => 'Read-only API for searching, listing and fetching published site content',
                    'url' => url('/api/content'),
                    'sha256' => '',
                ],
                [
                    'name' => 'mcp-server',
                    'type' => 'mcp',
                    'description' => 'Model Context Protocol server for AI agent content access',
                    'url' => url('/api/mcp'),
                    'sha256' => '',
                ],
            ],
        ]);
    }
}
