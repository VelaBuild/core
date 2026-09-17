<?php

namespace VelaBuild\Core\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

final class VelaServer extends Server
{
    protected string $name = 'Vela CMS';
    protected string $version = '1.0.0';
    protected string $instructions = 'Read and propose reviewed changes to this Vela website. Writes require propose, apply, and may be reverted.';

    protected function boot(): void
    {
        $this->tools = array_map(fn (string $name) => new VelaTool($name), [
            'site.describe', 'content.list', 'content.search', 'content.get', 'nav.get',
            'scripts.get', 'propose', 'apply', 'revert',
        ]);
    }
}

final class VelaTool extends Tool
{
    public function __construct(private readonly string $toolName)
    {
        $this->name = $toolName;
        $this->title = str_replace('.', ' ', ucfirst($toolName));
        $this->description = match ($toolName) {
            'site.describe' => 'Describe this site and its safe capabilities.',
            'content.list' => 'List pages and articles without full bodies.',
            'content.search' => 'Search pages and articles.',
            'content.get' => 'Read one page or article including its body.',
            'scripts.get' => 'Read the configured site-wide GA4 or Google Tag Manager container.',
            'nav.get' => 'Read the site navigation menus as labels and same-site paths.',
            'propose' => 'Prepare and preview a reversible website change without publishing it.',
            'apply' => 'Apply an approved prepared proposal if its source snapshot is unchanged.',
            'revert' => 'Revert an applied proposal if its applied snapshot is unchanged.',
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return match ($this->toolName) {
            'content.list' => ['type' => $schema->string(), 'status' => $schema->string(), 'limit' => $schema->integer()->min(1)->max(100)],
            'content.search' => ['query' => $schema->string()->required(), 'type' => $schema->string(), 'limit' => $schema->integer()->min(1)->max(50)],
            'content.get' => ['external_id' => $schema->string()->required()],
            'propose' => ['capability' => $schema->string()->required(), 'target_external_id' => $schema->string(), 'payload' => $schema->object()->required(), 'rationale' => $schema->string()->required(), 'idempotency_key' => $schema->string()->required()],
            'apply' => ['proposal_id' => $schema->string()->required(), 'external_ref' => $schema->string()->required(), 'expected_hashes' => $schema->object()->required()],
            'revert' => ['proposal_id' => $schema->string()->required(), 'external_ref' => $schema->string()->required()],
            default => [],
        };
    }

    public function handle(Request $request, VelaMcpGateway $gateway): mixed
    {
        $result = $gateway->call($this->toolName, $request->all());
        return Response::structured($result);
    }
}
