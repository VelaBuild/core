<?php

namespace VelaBuild\Core\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
            'content.get' => ['external_id' => $schema->string(), 'path' => $schema->string()],
            'propose' => ['capability' => $schema->string()->required(), 'target_external_id' => $schema->string(), 'target_url' => $schema->string(), 'payload' => $schema->object()->required(), 'rationale' => $schema->string()->required(), 'idempotency_key' => $schema->string()->required()],
            'apply' => ['proposal_id' => $schema->string()->required(), 'external_ref' => $schema->string()->required(), 'expected_hashes' => $schema->object()->required()],
            'revert' => ['proposal_id' => $schema->string()->required(), 'external_ref' => $schema->string()->required()],
            default => [],
        };
    }

    public function handle(Request $request, VelaMcpGateway $gateway): mixed
    {
        try {
            $result = $gateway->call($this->toolName, $request->all());
        } catch (HttpExceptionInterface|ModelNotFoundException $e) {
            $status = $e instanceof ModelNotFoundException ? 404 : $e->getStatusCode();
            if (! in_array($status, [404, 409, 422], true)) {
                throw $e;
            }
            // "No such page", "not a change this site takes" and "the page changed since" are
            // answered with that HTTP status by VelaMcpAuth, so a client can tell them apart
            // from an outage (and only a real "not found" frees an address for a new article).
            request()->attributes->set('vela_mcp_refusal', $status);
            return Response::error(match ($status) { 404 => 'Not found.', 409 => 'The content changed since this was prepared.', default => 'This change is not supported.' });
        }
        return Response::structured($result);
    }
}
