<?php

namespace VelaBuild\Core\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;
use VelaBuild\Core\Http\Controllers\Controller;
use VelaBuild\Core\Models\Category;
use VelaBuild\Core\Models\Content;
use VelaBuild\Core\Models\Menu;
use VelaBuild\Core\Models\MenuItem;
use VelaBuild\Core\Models\Page;
use VelaBuild\Core\Services\AiProviderManager;
use VelaBuild\Core\Vela;

class MenusController extends Controller
{
    public function index()
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        $registry = app(Vela::class)->frontMenus();
        $declared = $registry->forActiveTemplate();

        // Fold in any persisted menus for slots the active theme no longer
        // declares — flagged "orphaned" so the user can clean them up.
        $persisted = Menu::pluck('id', 'slot')->all();
        $rows = [];
        foreach ($declared as $slot => $config) {
            $rows[$slot] = [
                'slot'      => $slot,
                'label'     => $config['label'],
                'description' => $config['description'] ?? '',
                'auto_add_pages' => (bool) ($config['auto_add_pages'] ?? false),
                'item_count'   => $persisted[$slot] ?? null
                    ? Menu::find($persisted[$slot])->items()->count()
                    : null,
                'orphaned' => false,
            ];
        }
        foreach ($persisted as $slot => $id) {
            if (! isset($rows[$slot])) {
                $rows[$slot] = [
                    'slot'      => $slot,
                    'label'     => Menu::find($id)?->label ?: ucwords(str_replace(['-', '_'], ' ', $slot)),
                    'description' => '',
                    'auto_add_pages' => (bool) Menu::find($id)?->auto_add_pages,
                    'item_count' => Menu::find($id)?->items()->count(),
                    'orphaned' => true,
                ];
            }
        }

        return view('vela::admin.settings.menus.index', [
            'rows'         => $rows,
            'activeTheme'  => config('vela.template.active'),
        ]);
    }

    public function edit(string $slot)
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        $registry = app(Vela::class)->frontMenus();
        $config   = $registry->get($slot);

        $menu = Menu::firstOrCreate(
            ['slot' => $slot],
            [
                'label'          => $config['label'] ?? ucwords(str_replace(['-', '_'], ' ', $slot)),
                'auto_add_pages' => (bool) ($config['auto_add_pages'] ?? false),
            ]
        );

        return view('vela::admin.settings.menus.edit', [
            'menu'   => $menu->load('items'),
            'config' => $config,
            'pages'  => Page::orderBy('title')->get(['id', 'title', 'slug']),
            'posts'  => Content::orderBy('title')->limit(500)->get(['id', 'title', 'slug']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, string $slot)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $menu = Menu::where('slot', $slot)->firstOrFail();

        $data = Validator::make($request->all(), [
            'label'          => 'nullable|string|max:120',
            'auto_add_pages' => 'nullable|boolean',
            'items'          => 'nullable|array',
            'items.*.id'     => 'nullable|integer',
            'items.*.type'   => 'required|string|in:' . implode(',', [
                MenuItem::TYPE_PAGE, MenuItem::TYPE_CONTENT, MenuItem::TYPE_CATEGORY,
                MenuItem::TYPE_URL, MenuItem::TYPE_ROUTE,
                MenuItem::TYPE_HOME, MenuItem::TYPE_POSTS_INDEX, MenuItem::TYPE_CATEGORIES_INDEX,
            ]),
            'items.*.ref_id' => 'nullable|integer',
            'items.*.label'  => 'nullable|string|max:160',
            'items.*.url'    => 'nullable|string|max:1000',
            'items.*.route_name' => 'nullable|string|max:160',
            'items.*.target' => 'nullable|string|in:_self,_blank',
        ])->validate();

        $menu->update([
            'label'          => $data['label'] ?? $menu->label,
            'auto_add_pages' => (bool) ($data['auto_add_pages'] ?? false),
        ]);

        $keepIds = [];
        foreach (($data['items'] ?? []) as $i => $row) {
            $payload = [
                'menu_id'      => $menu->id,
                'order_column' => $i,
                'type'         => $row['type'],
                'ref_id'       => $row['ref_id'] ?? null,
                'ref_type'     => $this->refTypeFor($row['type']),
                'label'        => $row['label'] ?? null,
                'url'          => $row['url'] ?? null,
                'route_name'   => $row['route_name'] ?? null,
                'target'       => $row['target'] ?? '_self',
            ];

            if (! empty($row['id'])) {
                $item = $menu->items()->where('id', $row['id'])->first();
                if ($item) {
                    $item->update($payload);
                    $keepIds[] = $item->id;
                    continue;
                }
            }
            $created = MenuItem::create($payload);
            $keepIds[] = $created->id;
        }

        $menu->items()->whereNotIn('id', $keepIds)->delete();

        return back()->with('status', __('Menu saved.'));
    }

    public function destroy(string $slot)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        Menu::where('slot', $slot)->delete();

        return redirect()->route('vela.admin.settings.menus.index')
            ->with('status', __('Menu reset.'));
    }

    protected function refTypeFor(string $type): ?string
    {
        return match ($type) {
            MenuItem::TYPE_PAGE     => Page::class,
            MenuItem::TYPE_CONTENT  => Content::class,
            MenuItem::TYPE_CATEGORY => Category::class,
            default                 => null,
        };
    }

    /**
     * Ask the configured AI provider to propose a sensible item list for
     * this slot. Returns proposals as JSON; the admin UI appends them as
     * editable rows so the user can review before saving.
     */
    public function aiSuggest(string $slot, AiProviderManager $aiManager)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $registry = app(Vela::class)->frontMenus();
        $config   = $registry->get($slot) ?? ['label' => $slot];

        $pages = Page::where('status', 'published')
            ->orderBy('order_column')
            ->limit(40)
            ->get(['id', 'title', 'slug'])
            ->map(fn ($p) => "[page:{$p->id}] {$p->title}")
            ->implode("\n");

        $cats = Category::orderBy('name')->limit(40)->get(['id', 'name'])
            ->map(fn ($c) => "[category:{$c->id}] {$c->name}")
            ->implode("\n");

        $siteName = config('app.name', 'this site');
        $slotLabel = $config['label'] ?? $slot;

        $prompt = <<<PROMPT
You are setting up a navigation menu for "{$siteName}".
The menu slot is: "{$slotLabel}" (key: "{$slot}").

Available pages:
{$pages}

Available categories:
{$cats}

Built-in destinations you can use:
- home (the home page)
- posts_index (the all-articles listing)
- categories_index (the topics overview)

Pick a small, sensible set of items for this menu (typically 3–7).
For "primary" or header slots: home + key pages (about, contact, etc.) + posts_index when appropriate.
For "footer_quick_links": include the same plus legal pages if any exist.
For "footer_legal": only legal pages (privacy, terms, cookies).

Return ONLY a JSON array. Each item must have:
  - "type": one of "home", "posts_index", "categories_index", "page", "category"
  - "ref_id": (only for page/category) the numeric id
  - "label": the display label (short, plain text)

Do not wrap in markdown. Return [] if nothing fits.
PROMPT;

        try {
            $provider = $aiManager->resolveTextProvider();
            $raw = $provider->generateText($prompt, 800, 0.4);
            if (! $raw) {
                return response()->json(['error' => __('AI returned no response.')], 502);
            }

            // Strip code fences just in case the model wraps despite instructions.
            $raw = trim($raw);
            $raw = preg_replace('/^```(?:json)?\s*/', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);
            $items = json_decode($raw, true);

            if (! is_array($items)) {
                return response()->json(['error' => __('AI response was not valid JSON.'), 'raw' => $raw], 502);
            }

            $clean = [];
            foreach ($items as $item) {
                $type = $item['type'] ?? null;
                if (! in_array($type, [
                    MenuItem::TYPE_HOME, MenuItem::TYPE_POSTS_INDEX, MenuItem::TYPE_CATEGORIES_INDEX,
                    MenuItem::TYPE_PAGE, MenuItem::TYPE_CATEGORY,
                ], true)) continue;

                $clean[] = [
                    'type'   => $type,
                    'ref_id' => isset($item['ref_id']) ? (int) $item['ref_id'] : null,
                    'label'  => isset($item['label']) ? (string) $item['label'] : null,
                    'url'    => null,
                ];
            }

            return response()->json(['items' => $clean]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
