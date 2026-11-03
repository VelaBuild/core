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

    public function test_site_and_content_bodies_use_a_body_format_clients_recognise(): void
    {
        // Page rows and article Editor.js documents are both structured blocks, read back as
        // such. Clients such as Gemma accept only html, markdown, mdx or blocks and refuse the
        // whole connection on anything else; they write articles in Markdown, converted here.
        $gateway = app(VelaMcpGateway::class);
        $article = Content::create(['title' => 'Blocks', 'slug' => 'blocks', 'content' => '{"blocks":[]}', 'status' => 'published']);
        $describe = $gateway->call('site.describe', []);
        $create = collect($describe['capabilities'])->firstWhere('key', 'content.create');

        $this->assertSame('markdown', $describe['preferred_body_format']);
        $this->assertSame('/posts/', $create['constraints']['create_path_prefix']);
        $this->assertSame('blocks', $gateway->call('content.get', ['external_id' => 'article:'.$article->id])['data']['body_format']);
    }

    public function test_a_markdown_article_is_previewed_at_its_address_then_published_and_reverted(): void
    {
        $gateway = app(VelaMcpGateway::class);
        $payload = ['title' => 'Day trips', 'body' => "Intro line.\n\n## The route\n\nRide **south** along the coast.", 'description' => 'Where to ride.'];
        $args = ['capability' => 'content.create', 'target_url' => 'http://localhost/posts/day-trips', 'payload' => $payload, 'rationale' => 'Publish the approved article', 'idempotency_key' => 'gemma-create-1'];

        $prepared = $gateway->call('propose', $args);

        // The operation is the one a client previews itself: a creation at the address.
        $this->assertSame([['op' => 'create', 'target' => '/posts/day-trips', 'field' => null, 'before' => null, 'after' => ['path' => '/posts/day-trips'] + $payload,
            'note' => 'A new page at this path; reverting this proposal removes it.']], $prepared['diff']['operations']);
        $this->assertSame($prepared, $gateway->call('propose', $args));
        $this->assertFalse(Content::where('slug', 'day-trips')->exists());

        $applied = $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $article = Content::where('slug', 'day-trips')->firstOrFail();
        $blocks = json_decode($article->content, true)['blocks'];
        $this->assertSame(url('/posts/day-trips'), $applied['resulting_url']);
        $this->assertSame('published', $article->status);
        $this->assertSame('Where to ride.', $article->description);
        $this->assertSame('header', collect($blocks)->firstWhere('data.text', 'The route')['type']);
        $this->assertStringContainsString('<strong>south</strong>', json_encode($blocks, JSON_UNESCAPED_SLASHES));
        $this->assertSame('article:'.$article->id, $gateway->call('content.get', ['path' => '/posts/day-trips'])['data']['external_id']);
        $this->get('/posts/day-trips')->assertOk();

        $gateway->call('revert', ['proposal_id' => 'approved', 'external_ref' => $prepared['external_ref']]);
        $this->assertSoftDeleted($article);
    }

    public function test_a_new_article_needs_a_free_posts_address(): void
    {
        $gateway = app(VelaMcpGateway::class);
        Content::create(['title' => 'Taken', 'slug' => 'taken', 'content' => '{"blocks":[]}', 'status' => 'published']);
        $propose = fn (string $url, string $key) => $gateway->call('propose', ['capability' => 'content.create', 'target_url' => $url,
            'payload' => ['title' => 'New', 'body' => 'Text'], 'rationale' => 'Publish', 'idempotency_key' => $key]);

        foreach ([['http://localhost/posts/taken', 409], ['http://localhost/blog/new', 422], ['https://elsewhere.test/posts/new', 422]] as [$url, $status]) {
            try {
                $propose($url, $url);
                $this->fail('Expected a refusal for '.$url);
            } catch (HttpException $e) {
                $this->assertSame($status, $e->getStatusCode(), $url);
            }
        }
    }

    public function test_an_unknown_address_is_answered_as_not_found_over_mcp(): void
    {
        // A client only treats an address as free for a new article on a real "not found".
        // (laravel/mcp hands tools their arguments from its own provider, which a site
        // auto-discovers but the package tests do not.)
        $this->app->register(\Laravel\Mcp\Server\McpServiceProvider::class);
        app(\VelaBuild\Core\Services\McpSettingsService::class)->set('enabled', '1');
        app(\VelaBuild\Core\Services\McpSettingsService::class)->set('api_key', 'test-key');
        $call = fn (array $arguments) => $this->withToken('test-key')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => '1', 'method' => 'tools/call',
            'params' => ['name' => 'content.get', 'arguments' => $arguments, '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => new \stdClass]]],
            ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'content.get']);

        $article = Content::create(['title' => 'Here', 'slug' => 'here', 'content' => '{"blocks":[]}', 'status' => 'published']);
        $call(['path' => '/posts/here'])->assertOk()->assertJsonPath('result.structuredContent.data.external_id', 'article:'.$article->id);
        $call(['path' => '/posts/nothing-here'])->assertNotFound()->assertJsonPath('ok', false)->assertJsonPath('error.code', 'not_found');
        $call(['external_id' => 'article:999999'])->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_meta_and_body_edits_are_previewed_per_field_and_write_the_right_columns(): void
    {
        $gateway = app(VelaMcpGateway::class);
        $page = \VelaBuild\Core\Models\Page::create(['title' => 'About', 'slug' => 'about', 'status' => 'published', 'locale' => 'en', 'meta_description' => 'Old']);
        $meta = $gateway->call('propose', ['capability' => 'meta.update', 'target_external_id' => 'page:'.$page->id,
            'payload' => ['title' => 'About the café', 'description' => 'New'], 'rationale' => 'Better snippet', 'idempotency_key' => 'meta-1']);

        $this->assertSame([['op' => 'create', 'target' => 'page:'.$page->id, 'field' => 'meta.title', 'before' => null, 'after' => 'About the café', 'note' => null],
            ['op' => 'update', 'target' => 'page:'.$page->id, 'field' => 'meta.description', 'before' => 'Old', 'after' => 'New', 'note' => null]], $meta['diff']['operations']);
        $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $meta['external_ref']]);
        $page->refresh();
        $this->assertSame(['About', 'About the café', 'New'], [$page->title, $page->meta_title, $page->meta_description]);

        $article = Content::create(['title' => 'Post', 'slug' => 'post', 'content' => '{"blocks":[]}', 'description' => 'Short', 'status' => 'published']);
        $body = $gateway->call('propose', ['capability' => 'content.update', 'target_external_id' => 'article:'.$article->id,
            'payload' => ['body' => 'Fresh **text**.'], 'rationale' => 'Refresh', 'idempotency_key' => 'body-1']);
        $this->assertSame([['op' => 'update', 'target' => 'article:'.$article->id, 'field' => 'body', 'before' => '{"blocks":[]}', 'after' => 'Fresh **text**.', 'note' => null]], $body['diff']['operations']);
        $gateway->call('apply', ['proposal_id' => 'approved', 'external_ref' => $body['external_ref']]);
        $this->assertStringContainsString('<strong>text</strong>', json_encode(json_decode($article->fresh()->content, true), JSON_UNESCAPED_SLASHES));

        // An article has no SEO title of its own, and a page body is edited in the page builder.
        foreach ([['meta.update', 'article:'.$article->id, ['title' => 'X']], ['content.update', 'page:'.$page->id, ['body' => 'X']]] as $i => [$capability, $target, $payload]) {
            try {
                $gateway->call('propose', ['capability' => $capability, 'target_external_id' => $target, 'payload' => $payload, 'rationale' => 'r', 'idempotency_key' => 'refused-'.$i]);
                $this->fail('Expected 422 for '.$capability);
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_an_article_is_reported_at_the_address_it_is_served_from(): void
    {
        // Articles are public at /posts/{slug}; reporting /{slug} sent clients to a 404.
        $article = Content::create(['title' => 'Served', 'slug' => 'served-here', 'content' => '{"blocks":[]}', 'status' => 'published']);
        $data = app(VelaMcpGateway::class)->call('content.get', ['external_id' => 'article:'.$article->id])['data'];

        $this->assertSame(url('/posts/served-here'), $data['url']);
        $this->assertSame('/posts/served-here', $data['path']);
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
