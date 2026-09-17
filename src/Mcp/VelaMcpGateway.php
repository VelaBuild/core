<?php

namespace VelaBuild\Core\Mcp;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\MenuItem;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Models\VelaMcpProposal;
use VelaBuild\Core\Services\ToolSettingsService;

final class VelaMcpGateway
{
    private const WRITES = ['content.create', 'content.update', 'content.delete', 'meta.update', 'nav.update', 'scripts.update'];

    public function call(string $tool, array $args): array
    {
        return match ($tool) {
            'site.describe' => $this->describe(),
            'content.list' => $this->listing($args),
            'content.search' => $this->listing($args, true),
            'content.get' => $this->get((string) ($args['external_id'] ?? '')),
            'nav.get' => $this->nav(),
            'scripts.get' => $this->scripts(),
            'propose' => $this->prepare($args),
            'apply' => $this->transition($args, false),
            'revert' => $this->transition($args, true),
            default => throw new \InvalidArgumentException('Unsupported tool.'),
        };
    }

    private function describe(): array
    {
        $reads = ['site.describe', 'content.list', 'content.search', 'content.get', 'nav.get', 'scripts.get'];
        return ['capabilities' => array_map(fn ($key) => ['key' => $key, 'mode' => in_array($key, $reads, true) ? 'read' : 'write', 'revertible' => true, 'constraints' => $key === 'content.list' ? ['max_page_size' => 100] : []], [...$reads, ...self::WRITES]), 'platform' => 'vela', 'platform_version' => app()->version(), 'adapter_version' => '1.0.0', 'content_types' => ['page', 'article'], 'preferred_body_format' => 'json', 'content_mapping' => null, 'limits' => ['max_list_page_size' => 100, 'max_resources_per_proposal' => 1], 'notes' => []];
    }

    private function listing(array $args, bool $search = false): array
    {
        $limit = min($search ? 50 : 100, max(1, (int) ($args['limit'] ?? 50)));
        $type = $args['type'] ?? null;
        $rows = collect();
        if ($type === null || $type === 'article') {
            $q = Content::query();
            if ($search) $q->where(fn ($x) => $x->where('title', 'like', '%'.$args['query'].'%')->orWhere('description', 'like', '%'.$args['query'].'%'));
            if (!empty($args['status'])) $q->where('status', $args['status']);
            $rows = $rows->concat($q->limit($limit)->get()->map(fn ($m) => $this->resource($m, 'article', false)));
        }
        if (($type === null || $type === 'page') && $rows->count() < $limit) {
            $q = Page::query();
            if ($search) $q->where(fn ($x) => $x->where('title', 'like', '%'.$args['query'].'%')->orWhere('meta_description', 'like', '%'.$args['query'].'%'));
            if (!empty($args['status'])) $q->where('status', $args['status']);
            $rows = $rows->concat($q->limit($limit - $rows->count())->get()->map(fn ($m) => $this->resource($m, 'page', false)));
        }
        return $this->result($rows->values()->all(), $rows->count(), $rows->count(), false, 'type,title,id');
    }

    private function get(string $id): array
    {
        [$type, $key] = array_pad(explode(':', $id, 2), 2, null);
        $model = $type === 'page' ? Page::with('rows.blocks')->findOrFail($key) : ($type === 'article' ? Content::findOrFail($key) : null);
        abort_unless($model, 404);
        return $this->result($this->resource($model, $type, true), 1, 1, false, '');
    }

    private function scripts(): array
    {
        $settings = app(ToolSettingsService::class);
        $ga4 = $settings->get('ga_measurement_id');
        $gtm = $settings->get('gtm_container_id');
        $data = is_string($gtm) && $gtm !== ''
            ? ['provider' => 'gtm', 'container_id' => $gtm]
            : (is_string($ga4) && $ga4 !== '' ? ['provider' => 'ga4', 'container_id' => $ga4] : ['provider' => null, 'container_id' => null]);

        return $this->result($data, 1, 1, false, '');
    }

    private function nav(): array
    {
        $menus = [];
        foreach (Menu::query()->with('items')->orderBy('slot')->get() as $menu) {
            $menus[$menu->slot] = $menu->items->map(function (MenuItem $item): array {
                $url = $item->resolveUrl();
                $parts = parse_url($url);
                $path = is_array($parts) && isset($parts['path']) ? $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '') : '/';

                return ['label' => $item->resolveLabel(), 'path' => $path];
            })->all();
        }

        return $this->result(['menus' => $menus], count($menus), count($menus), false, 'slot,order_column');
    }

    private function resource(Model $model, string $type, bool $body): array
    {
        $snapshot = $this->snapshot($model, $type);
        return ['external_id' => $type.':'.$model->getKey(), 'type' => $type, 'url' => url('/'.$model->slug), 'path' => '/'.$model->slug, 'title' => $model->title, 'body' => $body ? ($type === 'page' ? json_encode($snapshot['rows'], JSON_THROW_ON_ERROR) : $model->content) : null, 'body_format' => 'json', 'excerpt' => $type === 'article' ? $model->description : null, 'meta' => ['title' => $type === 'page' ? $model->meta_title : null, 'description' => $type === 'page' ? $model->meta_description : $model->description, 'canonical' => null, 'noindex' => false, 'nofollow' => false, 'og_title' => null, 'og_description' => null, 'og_image_url' => null, 'schema' => [], 'hreflang' => []], 'status' => $model->status, 'published_at' => $type === 'article' ? $model->published_at?->utc()->toIso8601ZuluString() : null, 'modified_at' => $model->updated_at?->utc()->toIso8601ZuluString(), 'author_name' => null, 'taxonomies' => $type === 'article' ? ['category' => $model->categories()->pluck('name')->all()] : [], 'custom_fields' => [], 'parent_external_id' => $type === 'page' && $model->parent_id ? 'page:'.$model->parent_id : null, 'content_hash' => hash('sha256', json_encode($snapshot))];
    }

    private function snapshot(Model $model, string $type): array
    {
        return $type === 'page' ? ['fields' => $model->only(['title','slug','status','meta_title','meta_description','parent_id','order_column']), 'rows' => $model->rows->map(fn ($r) => ['row' => $r->getAttributes(), 'blocks' => $r->blocks->map->getAttributes()->all()])->all()] : ['fields' => $model->only(['title','slug','description','keyword','content','status','published_at']), 'categories' => $model->categories()->pluck('vela_categories.id')->all()];
    }

    private function prepare(array $args): array
    {
        abort_unless(in_array($args['capability'] ?? '', self::WRITES, true), 422);
        return DB::transaction(function () use ($args) {
            $existing = VelaMcpProposal::where('idempotency_key', $args['idempotency_key'])->first();
            if ($existing) return $this->handle($existing);
            $target = $args['target_external_id'] ?? null;
            $before = null;
            if ($args['capability'] === 'scripts.update') {
                $provider = $args['payload']['provider'] ?? null;
                $container = $args['payload']['container_id'] ?? null;
                $valid = $provider === 'ga4'
                    ? is_string($container) && preg_match('/^G-[A-Z0-9]{4,20}$/D', $container)
                    : ($provider === 'gtm' && is_string($container) && preg_match('/^GTM-[A-Z0-9]{4,20}$/D', $container));
                abort_unless($valid && array_keys($args['payload']) === ['provider', 'container_id'], 422);
                $settings = app(ToolSettingsService::class);
                abort_if($settings->isEnvLocked('ga_measurement_id') || $settings->isEnvLocked('gtm_container_id'), 409);
                $before = ['scripts' => $this->scripts()['data']];
            } elseif ($args['capability'] === 'nav.update') {
                $payload = $this->navigationPayload($args['payload'] ?? null);
                $args['payload'] = $payload;
                $before = ['nav' => $this->nav()['data']];
            } elseif ($args['capability'] !== 'content.create') {
                abort_unless(is_string($target), 422);
                [$type, $id] = $this->target($target);
                $model = $this->model($type, $id);
                $before = ['type' => $type, 'snapshot' => $this->snapshot($model, $type), 'resource' => $this->resource($model, $type, true)];
            } else {
                abort_unless(in_array($args['payload']['type'] ?? null, ['page', 'article'], true), 422);
            }
            $beforeHash = hash('sha256', json_encode($before['resource'] ?? $before['scripts'] ?? $before['nav'] ?? null));
            $proposal = VelaMcpProposal::create(['uuid' => (string) Str::uuid(), 'idempotency_key' => $args['idempotency_key'], 'capability' => $args['capability'], 'target_external_id' => $target, 'payload' => $args['payload'], 'before_snapshot' => $before, 'before_hash' => $beforeHash, 'status' => 'prepared']);
            return $this->handle($proposal);
        });
    }

    private function handle(VelaMcpProposal $proposal): array
    {
        $before = $proposal->before_snapshot;
        $scripts = $proposal->capability === 'scripts.update';
        $nav = $proposal->capability === 'nav.update';
        $targetName = $scripts ? 'site:scripts' : ($nav ? 'site:nav' : ($proposal->target_external_id ?? ($proposal->payload['slug'] ?? 'new-content')));
        $op = $proposal->capability === 'content.create' ? 'create' : ($proposal->capability === 'content.delete' ? 'delete' : 'update');

        return ['diff' => ['operations' => [['op' => $op, 'target' => $targetName, 'field' => $scripts ? 'analytics' : ($nav ? 'menus' : null), 'before' => $before['resource'] ?? $before['scripts'] ?? $before['nav']['menus'] ?? null, 'after' => $nav ? $proposal->payload['menus'] : $proposal->payload, 'note' => $scripts ? 'Install one site-wide analytics container; no arbitrary script is accepted.' : ($nav ? 'Replace reviewed menu slots with same-site links.' : null)]], 'summary' => ['resources_affected' => 1, 'operation_count' => 1, 'urls' => [], 'urls_truncated' => false, 'counts_by_op' => [$op => 1], 'body_bytes_added' => null, 'body_bytes_removed' => null]], 'external_ref' => $proposal->uuid, 'preview_url' => null, 'remote_artefact_created' => true, 'expires_at' => null];
    }

    private function transition(array $args, bool $revert): array
    {
        return DB::transaction(function () use ($args, $revert) {
            $reference = $args['external_ref'] ?? $args['proposal_id'] ?? null;
            abort_unless(is_string($reference), 422);
            $p = VelaMcpProposal::where('uuid', $reference)->lockForUpdate()->firstOrFail();
            $expected = $revert ? 'applied' : 'prepared';
            abort_unless($p->status === $expected, 409);
            if ($p->capability === 'scripts.update') {
                $settings = app(ToolSettingsService::class);
                abort_if($settings->isEnvLocked('ga_measurement_id') || $settings->isEnvLocked('gtm_container_id'), 409);
                $current = $this->scripts()['data'];
                abort_unless(hash('sha256', json_encode($current)) === ($revert ? $p->after_hash : $p->before_hash), 409);

                $next = $revert ? $p->before_snapshot['scripts'] : $p->payload;
                $settings->set('ga_measurement_id', $next['provider'] === 'ga4' ? $next['container_id'] : null);
                $settings->set('gtm_container_id', $next['provider'] === 'gtm' ? $next['container_id'] : null);
                $after = $this->scripts()['data'];
            } elseif ($p->capability === 'nav.update') {
                $current = $this->nav()['data'];
                abort_unless(hash('sha256', json_encode($current)) === ($revert ? $p->after_hash : $p->before_hash), 409);
                $this->replaceNavigation($revert ? $p->before_snapshot['nav'] : $p->payload);
                $after = $this->nav()['data'];
            } elseif (! $revert && $p->capability === 'content.create') {
                $type = $p->payload['type'];
                $model = $type === 'page' ? new Page : new Content;
                $this->write($model, $type, $p->payload);
                $p->target_external_id = $type.':'.$model->getKey();
                $after = $this->resource($model->fresh(), $type, true);
            } else {
                [$type, $id] = $this->target((string) $p->target_external_id);
                $model = $this->model($type, $id, true, $revert && $p->capability === 'content.delete');
                $current = $model->trashed()
                    ? ['external_id' => $type.':'.$model->getKey(), 'deleted' => true]
                    : $this->resource($model, $type, true);
                abort_unless(hash('sha256', json_encode($current)) === ($revert ? $p->after_hash : $p->before_hash), 409);
                if ($revert) {
                    if ($p->capability === 'content.create') $model->delete();
                    elseif ($p->capability === 'content.delete') $model->restore();
                    else $this->restore($model, $type, $p->before_snapshot['snapshot']);
                } elseif ($p->capability === 'content.delete') $model->delete();
                else $this->write($model, $type, $p->payload);
                $after = $model->exists && ! $model->trashed() ? $this->resource($model->fresh(), $type, true) : ['external_id' => $type.':'.$model->getKey(), 'deleted' => true];
            }
            $p->update(['status' => $revert ? 'reverted' : 'applied', 'after_snapshot' => $after, 'after_hash' => hash('sha256', json_encode($after))]);
            return ['ok' => true, 'external_ref' => $p->uuid, 'resulting_url' => isset($after['path']) ? url($after['path']) : null, 'error' => null, 'receipt' => ['status' => $revert ? 'reverted' : 'applied', 'content_hash' => hash('sha256', json_encode($after))]];
        });
    }

    private function result(mixed $data, int $returned, ?int $total, bool $truncated, string $sorted): array
    {
        return ['ok' => true, 'data' => $data, 'error' => null, 'meta' => ['returned_count' => $returned, 'total_count' => $total, 'truncated' => $truncated, 'cursor' => null, 'sorted_by' => $sorted, 'cost_class' => 'cheap', 'data_as_of' => now()->utc()->toIso8601ZuluString()]];
    }

    private function navigationPayload(mixed $payload): array
    {
        abort_unless(is_array($payload) && array_keys($payload) === ['menus'] && is_array($payload['menus']) && ! array_is_list($payload['menus']) && $payload['menus'] !== [] && count($payload['menus']) <= 10, 422);
        foreach ($payload['menus'] as $slot => $items) {
            abort_unless(is_string($slot) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $slot) === 1 && is_array($items) && array_is_list($items) && count($items) <= 50, 422);
            foreach ($items as $item) {
                abort_unless(is_array($item) && array_keys($item) === ['label', 'path'] && is_string($item['label']) && trim($item['label']) !== '' && mb_strlen($item['label']) <= 120
                    && is_string($item['path']) && preg_match('@^(?:/|(?:/[A-Za-z0-9][A-Za-z0-9._~-]*)+/?)(?:\?[^#\s]{1,500})?$@D', $item['path']) === 1, 422);
            }
        }

        return $payload;
    }

    private function replaceNavigation(array $payload): void
    {
        $payload = $this->navigationPayload($payload);
        foreach ($payload['menus'] as $slot => $items) {
            $menu = Menu::query()->firstOrCreate(['slot' => $slot], ['label' => ucfirst(str_replace(['-', '_'], ' ', $slot)), 'auto_add_pages' => false]);
            $menu->items()->delete();
            foreach ($items as $order => $item) {
                $menu->items()->create(['order_column' => $order, 'type' => MenuItem::TYPE_URL, 'label' => $item['label'], 'url' => $item['path'], 'target' => '_self']);
            }
        }
    }

    private function target(string $target): array
    {
        [$type, $id] = array_pad(explode(':', $target, 2), 2, null);
        abort_unless(in_array($type, ['page', 'article'], true) && ctype_digit((string) $id), 422);
        return [$type, (int) $id];
    }

    private function model(string $type, int $id, bool $lock = false, bool $trashed = false): Model
    {
        $query = $type === 'page' ? Page::query() : Content::query();
        if ($trashed) $query->withTrashed();
        if ($lock) $query->lockForUpdate();
        return $query->findOrFail($id);
    }

    private function write(Model $model, string $type, array $payload): void
    {
        $allowed = $type === 'page'
            ? ['title','slug','locale','status','meta_title','meta_description','custom_css','custom_js','order_column','parent_id']
            : ['title','slug','description','keyword','content','status','published_at'];
        $fields = array_intersect_key($payload, array_flip($allowed));
        abort_if($model->exists && $fields === [] && ! ($type === 'article' && array_key_exists('taxonomies', $payload)), 422);
        if (! $model->exists) {
            abort_unless(isset($fields['title']), 422);
            $fields['status'] ??= 'draft';
        }
        $model->fill($fields)->save();
        if ($type === 'article' && array_key_exists('taxonomies', $payload)) {
            $taxonomies = $payload['taxonomies'];
            abort_unless(is_array($taxonomies) && array_keys($taxonomies) === ['category'] && is_array($taxonomies['category']) && array_is_list($taxonomies['category']), 422);
            $slugs = array_values(array_unique($taxonomies['category']));
            abort_unless(count($slugs) <= 100 && collect($slugs)->every(fn ($slug) => is_string($slug) && preg_match('/^[a-z0-9][a-z0-9_-]{0,199}$/D', $slug) === 1), 422);
            $ids = Category::query()->get()->filter(fn (Category $category): bool => in_array($category->slug, $slugs, true))->mapWithKeys(fn (Category $category): array => [$category->slug => $category->id]);
            abort_unless($ids->count() === count($slugs), 422);
            $model->categories()->sync(array_map(fn (string $slug): int => (int) $ids[$slug], $slugs));
        }
    }

    private function restore(Model $model, string $type, array $snapshot): void
    {
        $model->forceFill($snapshot['fields'])->save();
        if ($type === 'article') $model->categories()->sync($snapshot['categories']);
    }
}
