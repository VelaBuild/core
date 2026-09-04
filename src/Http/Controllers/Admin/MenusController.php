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
use VelaBuild\Core\Services\ThemeMenus;
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
                'item_count'   => $persisted[$this->storageSlot($slot)] ?? null
                    ? Menu::find($persisted[$this->storageSlot($slot)])->items()->count()
                    : null,
                'orphaned' => false,
                // Whether the theme in use keeps this one to itself.
                'own_menu' => ThemeMenus::has(ThemeMenus::currentTheme(), $slot),
            ];
        }
        foreach ($persisted as $slot => $id) {
            // Another theme's own navigation is not an orphan on this one —
            // it is in use, just not here, and listing it as something to
            // clean up would invite deleting a theme's header.
            if (str_contains($slot, ThemeMenus::SEPARATOR)) {
                continue;
            }

            // Nor is the design builder's bookkeeping. A design staged for
            // preview and the menus a kept one displaced are held in slots of
            // their own, and all six of them were listed here as menus to
            // tidy away — the "Changed your mind?" button is what reads them.
            if (\VelaBuild\Core\Services\DesignPreviewFrame::isPrivateSlot($slot)) {
                continue;
            }

            if (! isset($rows[$slot])) {
                $rows[$slot] = [
                    'slot'      => $slot,
                    'label'     => Menu::find($id)?->label ?: ucwords(str_replace(['-', '_'], ' ', $slot)),
                    'description' => '',
                    'auto_add_pages' => (bool) Menu::find($id)?->auto_add_pages,
                    'item_count' => Menu::find($id)?->items()->count(),
                    'orphaned' => true,
                    'own_menu' => false,
                ];
            }
        }

        return view('vela::admin.settings.menus.index', [
            'rows'         => $rows,
            'activeTheme'  => config('vela.template.active'),
        ]);
    }

    /**
     * Where this slot's menu is really stored for the theme in use.
     *
     * A theme may keep navigation of its own — a design build writes its
     * header into the theme it wrote, so the site's own menu survives being
     * shown a design. Everything in this screen edits what the CURRENT theme
     * shows, which is that menu where it exists and the shared one otherwise.
     */
    private function storageSlot(string $slot): string
    {
        $theme = ThemeMenus::currentTheme();

        return ThemeMenus::has($theme, $slot) ? ThemeMenus::slot($theme, $slot) : $slot;
    }

    /**
     * Give this theme navigation of its own for one slot, or put it back on
     * the site's.
     */
    public function scope(Request $request, string $slot)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        $theme = ThemeMenus::currentTheme();

        if ($theme === '') {
            return back()->withErrors(['menu' => 'There is no theme in use to give a menu to.']);
        }

        if ($request->input('scope') === 'own') {
            // Copied from what the theme shows now, so pressing this never
            // empties a header.
            ThemeMenus::claim($theme, $slot);

            return back()->with('status', __('This theme now has its own menu for that slot.'));
        }

        ThemeMenus::release($theme, $slot);

        return back()->with('status', __('That slot is back on the menu shared with every theme.'));
    }

    public function edit(string $slot)
    {
        abort_if(Gate::denies('config_access'), Response::HTTP_FORBIDDEN);

        $registry = app(Vela::class)->frontMenus();
        $config   = $registry->get($slot);

        // Opening this screen used to firstOrCreate the menu, which made a GET
        // change the site: a slot that had just been reset to defaults got an
        // empty stored menu the moment somebody looked at it, and an empty
        // stored menu is a deliberate customisation as far as the renderer is
        // concerned — so the header emptied on the public site without anybody
        // saving anything. It also turned "add new pages automatically" on by
        // itself, from whatever the theme declares for the slot.
        $menu = Menu::where('slot', $this->storageSlot($slot))->with('items')->first();

        if (!$menu) {
            // Nothing stored: show what visitors are seeing, unsaved, so the
            // editor opens on the truth and Save is what makes it stored.
            $menu = new Menu([
                'slot'           => $slot,
                'label'          => $config['label'] ?? ucwords(str_replace(['-', '_'], ' ', $slot)),
                'auto_add_pages' => (bool) ($config['auto_add_pages'] ?? false),
            ]);
            $menu->slot = $slot;
            $menu->setRelation('items', app(\VelaBuild\Core\Services\MenuRenderer::class)->items($slot));
        }

        // A menu written by a design build carries no label — set_menu has no
        // reason to invent one — and the heading then read: Edit menu items
        // for the “” slot. The slot's own name is what it is called.
        if (trim((string) $menu->label) === '') {
            $menu->label = $config['label'] ?? ucwords(str_replace(['-', '_'], ' ', $slot));
        }

        return view('vela::admin.settings.menus.edit', [
            'menu'   => $menu,
            // The slot as the URL spells it. $menu->slot may be scoped to a
            // theme ("zercurity::primary"), which no route here accepts.
            'slot'   => $slot,
            'stored' => $menu->exists,
            'config' => $config,
            // The status travels with the page because the picker shows it.
            // Two pages can share a title and routinely do: keeping a design
            // parks the homepage it replaced, unlisted, under a timestamped
            // slug and with its title untouched — so a site that has kept a
            // few designs offers several pages called "Home", and picking the
            // wrong one puts a link to a parked page in the live header.
            'pages'  => Page::orderBy('title')->get(['id', 'title', 'slug', 'status']),
            'posts'  => Content::orderBy('title')->limit(500)->get(['id', 'title', 'slug']),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, string $slot)
    {
        abort_if(Gate::denies('config_edit'), Response::HTTP_FORBIDDEN);

        // Created here rather than when the screen was opened: saving is what
        // says "this menu is mine now".
        $config = app(Vela::class)->frontMenus()->get($slot);

        $menu = Menu::firstOrCreate(
            ['slot' => $this->storageSlot($slot)],
            [
                'label'          => $config['label'] ?? ucwords(str_replace(['-', '_'], ' ', $slot)),
                'auto_add_pages' => (bool) ($config['auto_add_pages'] ?? false),
            ]
        );

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

        Menu::where('slot', $this->storageSlot($slot))->delete();

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
