<?php

namespace VelaBuild\Core\Tests\Feature;

use Symfony\Component\HttpKernel\Exception\HttpException;
use VelaBuild\Core\Mcp\VelaMcpGateway;
use VelaBuild\Core\Mcp\VelaServer;
use VelaBuild\Core\Mcp\VelaTool;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\VelaConfig;
use VelaBuild\Core\Tests\PackageTestCase;

class VelaMcpGatewayTest extends PackageTestCase
{
    public function test_official_mcp_server_exposes_structured_tools(): void
    {
        VelaServer::tool(new VelaTool('site.describe'))
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->where('platform', 'vela')->etc());
    }

    public function test_public_discovery_names_the_native_current_protocol_endpoint(): void
    {
        $this->getJson('/.well-known/mcp')
            ->assertOk()
            ->assertJsonPath('mcp_version', '2026-07-28')
            ->assertJsonPath('endpoint', 'http://localhost/api/mcp')
            ->assertJsonPath('auth.type', 'bearer');
    }

    public function test_update_is_prepared_without_mutation_then_applied_and_reverted(): void
    {
        $article = Content::create(['title' => 'Original', 'slug' => 'original', 'content' => '{"blocks":[]}', 'status' => 'published']);
        $gateway = app(VelaMcpGateway::class);

        $prepared = $gateway->call('propose', [
            'capability' => 'content.update', 'target_external_id' => 'article:'.$article->id,
            'payload' => ['title' => 'Changed'], 'rationale' => 'Improve title', 'idempotency_key' => 'update-1',
        ]);
        $replayed = $gateway->call('propose', [
            'capability' => 'content.update', 'target_external_id' => 'article:'.$article->id,
            'payload' => ['title' => 'Changed'], 'rationale' => 'Improve title', 'idempotency_key' => 'update-1',
        ]);

        $this->assertSame('Original', $article->fresh()->title);
        $this->assertSame($prepared, $replayed);
        $applied = $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertSame('Changed', $article->fresh()->title);
        $this->assertSame('applied', $applied['receipt']['status']);

        $reverted = $gateway->call('revert', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertSame('Original', $article->fresh()->title);
        $this->assertSame('reverted', $reverted['receipt']['status']);
    }

    public function test_stale_source_refuses_apply(): void
    {
        $article = Content::create(['title' => 'Original', 'slug' => 'original', 'content' => '{}', 'status' => 'draft']);
        $gateway = app(VelaMcpGateway::class);
        $prepared = $gateway->call('propose', ['capability' => 'content.update', 'target_external_id' => 'article:'.$article->id, 'payload' => ['title' => 'Proposed'], 'rationale' => 'Test', 'idempotency_key' => 'stale-1']);
        $article->update(['title' => 'External edit']);

        $this->expectException(HttpException::class);
        $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
    }

    public function test_create_and_delete_round_trip(): void
    {
        $gateway = app(VelaMcpGateway::class);
        $create = $gateway->call('propose', ['capability' => 'content.create', 'payload' => ['type' => 'article', 'title' => 'Created', 'slug' => 'created', 'content' => '{}'], 'rationale' => 'New article', 'idempotency_key' => 'create-1']);
        $applied = $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $create['external_ref']]);
        $article = Content::where('slug', 'created')->firstOrFail();
        $gateway->call('revert', ['proposal_id' => 'approved', 'external_ref' => $create['external_ref']]);
        $this->assertSoftDeleted($article);

        $deletable = Content::create(['title' => 'Delete me', 'slug' => 'delete-me', 'content' => '{}', 'status' => 'draft']);
        $delete = $gateway->call('propose', ['capability' => 'content.delete', 'target_external_id' => 'article:'.$deletable->id, 'payload' => [], 'rationale' => 'Remove', 'idempotency_key' => 'delete-1']);
        $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $delete['external_ref']]);
        $this->assertSoftDeleted($deletable);
        $gateway->call('revert', ['proposal_id' => 'approved', 'external_ref' => $delete['external_ref']]);
        $this->assertNotNull($deletable->fresh());
    }

    public function test_analytics_container_is_prepared_without_mutation_then_applied_and_reverted(): void
    {
        $gateway = app(VelaMcpGateway::class);
        $prepared = $gateway->call('propose', [
            'capability' => 'scripts.update',
            'payload' => ['provider' => 'ga4', 'container_id' => 'G-ABCD1234'],
            'rationale' => 'Install the configured analytics tag',
            'idempotency_key' => 'scripts-1',
        ]);

        $this->assertNull(VelaConfig::where('key', 'tool_ga_measurement_id')->value('value'));
        $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertSame('G-ABCD1234', VelaConfig::where('key', 'tool_ga_measurement_id')->value('value'));
        $this->assertNull(VelaConfig::where('key', 'tool_gtm_container_id')->value('value'));

        $gateway->call('revert', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertNull(VelaConfig::where('key', 'tool_ga_measurement_id')->value('value'));
    }

    public function test_article_taxonomies_apply_existing_categories_and_revert(): void
    {
        $first = Category::create(['name' => 'First']);
        $second = Category::create(['name' => 'Second']);
        $article = Content::create(['title' => 'Article', 'slug' => 'article', 'content' => '{}', 'status' => 'draft']);
        $article->categories()->sync([$first->id]);
        $gateway = app(VelaMcpGateway::class);
        $prepared = $gateway->call('propose', [
            'capability' => 'content.update', 'target_external_id' => 'article:'.$article->id,
            'payload' => ['taxonomies' => ['category' => ['second']]], 'rationale' => 'Correct category', 'idempotency_key' => 'taxonomy-1',
        ]);

        $this->assertSame(['First'], $article->fresh()->categories()->pluck('name')->all());
        $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertSame(['Second'], $article->fresh()->categories()->pluck('name')->all());
        $gateway->call('revert', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertSame(['First'], $article->fresh()->categories()->pluck('name')->all());
    }

    public function test_navigation_is_prepared_applied_and_reverted_as_same_site_paths(): void
    {
        $menu = Menu::create(['slot' => 'primary', 'label' => 'Primary']);
        $menu->items()->create(['order_column' => 0, 'type' => 'url', 'label' => 'Home', 'url' => '/', 'target' => '_self']);
        $gateway = app(VelaMcpGateway::class);
        $prepared = $gateway->call('propose', [
            'capability' => 'nav.update',
            'payload' => ['menus' => ['primary' => [['label' => 'About', 'path' => '/about']]]],
            'rationale' => 'Clarify primary navigation', 'idempotency_key' => 'nav-1',
        ]);

        $this->assertSame('Home', $menu->fresh()->items()->first()->label);
        $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertSame('About', $menu->fresh()->items()->first()->label);
        $gateway->call('revert', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertSame('Home', $menu->fresh()->items()->first()->label);
    }
}
