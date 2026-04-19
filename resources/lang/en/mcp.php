<?php

return [
    'settings_title'    => 'MCP Server',
    'settings_desc'     => 'Expose your site content to AI assistants and external tools via the Model Context Protocol.',
    'settings_saved'    => 'MCP settings saved.',

    'title'             => 'MCP Server (Model Context Protocol)',
    'intro'             => 'Enable an API server that allows AI assistants and external tools to read your site content via the Model Context Protocol. Authentication is required via a bearer token.',
    'enable'            => 'Enable MCP server',

    'api_key'           => 'API Key',
    'enter_api_key'     => 'Enter or generate an API key',
    'generate_key'      => 'Generate new key',
    'show_key'          => 'Show/hide key',
    'key_configured'    => 'Key configured',
    'key_cleared'       => 'Key cleared — save to apply',
    'no_key_set'        => 'No API key set. Generate one or enter your own.',

    'set_via_env'       => 'Set via .env file',
    'configured_in_env' => 'Configured in .env — cannot be changed here',
    'enabled_via_env'   => 'MCP server is enabled/disabled via the MCP_ENABLED environment variable.',

    'endpoint_title'    => 'API Endpoints',
    'endpoint_desc'     => 'Use these endpoints with a bearer token to access your site content.',
    'auth_header'       => 'Include this header with every request:',

    'env_title'         => 'Environment Variables',
    'env_desc'          => 'You can also configure MCP via .env instead of this settings page. When set in .env, those fields are locked here.',

    'cache_types'       => 'Cache types: all, home, pages, articles, images, pwa',
];
