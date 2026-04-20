# Writing a Vela Plugin

This guide is the canonical reference for building a Vela plugin. The pattern is deliberately small: a Laravel package that depends on `velabuild/core`, boots a service provider, and hooks into Vela's registries. No core changes required. No custom DSL.

**The reference implementation is [`velabuild/snippets`](https://github.com/VelaBuild/snippets).** Read that repo's service provider + README alongside this document — together they cover everything.

---

## When to build a plugin

Build a plugin when your feature should be:

- **Installable** — `composer require yourvendor/yourplugin` and it works
- **Site-agnostic** — ships against core, not against a specific Vela site
- **Self-contained** — its own tables, views, routes, permissions
- **Optional** — other Vela sites don't need it to function

Things that should **not** be plugins: site-specific templates (those are their own Composer packages), one-off admin tweaks (put them in the host app's `App\Providers\AppServiceProvider`).

---

## Package skeleton

```
your-plugin/
├── composer.json
├── src/
│   └── YourPluginServiceProvider.php
├── routes/
│   └── admin.php
├── resources/
│   └── views/
└── database/
    └── migrations/
```

`composer.json`:

```json
{
    "name": "yourvendor/yourplugin",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "velabuild/core": "*"
    },
    "autoload": {
        "psr-4": { "YourVendor\\YourPlugin\\": "src/" }
    },
    "extra": {
        "laravel": {
            "providers": [
                "YourVendor\\YourPlugin\\YourPluginServiceProvider"
            ]
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

---

## Service provider lifecycle

A Vela plugin's `boot()` does four things:

### 1. Load own resources

```php
public function boot(): void
{
    $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    $this->loadViewsFrom(__DIR__ . '/../resources/views', 'your-namespace');
    // Admin routes inside core's middleware stack (web, auth, 2fa, gates, locale)
    Route::group([
        'middleware' => config('vela.middleware.admin', ['web', 'vela.auth', 'vela.2fa', 'vela.gates']),
    ], function () {
        $this->loadRoutesFrom(__DIR__ . '/../routes/admin.php');
    });
}
```

### 2. Register with Vela's registries

All registration goes through the `Vela` singleton. Defer to `$this->app->booted()` so it runs after core's own boot completes:

```php
$this->app->booted(function () {
    try {
        $vela = $this->app->make(\VelaBuild\Core\Vela::class);
    } catch (\Throwable $e) {
        return; // core not present — nothing to register
    }

    $vela->registerMenuItem('yourthing', [
        'label' => 'Your Thing',
        'icon'  => 'fas fa-cube',
        'group' => 'content',           // 'general' | 'content' | 'admin'
        'order' => 50,
        'route' => 'vela.admin.yourthing.index',
        'gate'  => 'yourthing_access',
    ]);
});
```

Every Vela registry (blocks, menus, templates, widgets, tools, profile-menu) has a matching `register*()` method on the `Vela` singleton — see [Vela.php](../src/Vela.php) for the full list.

### 3. Permissions

Seed your permissions into `vela_permissions` on boot. Core's `VelaAuthGates` middleware will wire them up dynamically:

```php
$this->app->booted(function () {
    if (!\Schema::hasTable('vela_permissions')) return;   // no-DB deploy
    foreach ([
        'yourthing_access' => 'View Your Thing',
        'yourthing_edit'   => 'Edit Your Thing',
    ] as $title => $desc) {
        \VelaBuild\Core\Models\Permission::firstOrCreate(['title' => $title], ['description' => $desc]);
    }
});
```

Then use `abort_if(Gate::denies('yourthing_access'), 403)` in controllers.

### 4. Page Builder block (if applicable)

Two halves: the **server-side registry** (used for public rendering) and the **client-side JS** (used by the admin Page Builder).

**Server side:**

```php
$vela->registerBlock('yourthing', [
    'label'  => 'Your Thing',
    'icon'   => 'fas fa-cube',
    'group'  => 'content',
    'view'   => 'your-namespace::public.block',    // public render
    'editor' => 'your-namespace::admin.block-form', // admin form
    'defaults' => [
        'content'  => ['some_field' => null],
        'settings' => [],
    ],
]);
```

**Client side** — push into the `vela-page-editor-blocks` stack (which emits *after* core's `page-editor.js`, so `PageEditor` is defined):

```php
\View::composer('vela::admin.pages.partials.block-editor', function () {
    // Renders our partial — the partial contains @push('vela-page-editor-blocks')
    // so its <script> lands in the right spot in the admin layout.
    view('your-namespace::admin.page-editor-block', [/* data */])->render();
});
```

The partial itself:

```blade
@push('vela-page-editor-blocks')
<script>
    PageEditor.registerBlockType('yourthing', {
        icon: 'fa-cube',
        label: 'Your Thing',
        defaults: { content: { some_field: null }, settings: {} },
        renderPreview: function(block) { return '<em>preview</em>'; },
        renderEditor:  function(block) { return '<input id="some-field-input">'; },
        initEditor:    function(block) {},
        collectData:   function(block) {
            return {
                content:  { some_field: document.getElementById('some-field-input').value },
                settings: block.settings,
            };
        },
    });
</script>
@endpush
```

The `@stack('vela-page-editor-blocks')` in `core/resources/views/layouts/admin.blade.php` emits **after** `@stack('scripts')` — so by the time your `<script>` runs, `page-editor.js` has loaded and `PageEditor.registerBlockType` is available. A plain call works. No DOMContentLoaded, no polling.

---

## Available extension points

| Registry | Purpose | Example |
|---|---|---|
| `registerBlock($name, $cfg)` | Page Builder block type | Snippet, Accordion, Gallery |
| `registerMenuItem($name, $cfg)` | Admin sidebar entry | Snippets admin page |
| `registerTemplate($name, $cfg)` | Public site theme | default, corporate, editorial |
| `registerWidget($name, $cfg)` | Dashboard card | Recent content, Traffic |
| `registerTool($name, $cfg)` | AI chatbot tool | Create content, Publish page |
| `registerProfileMenuItem($name, $cfg)` | Top-right user menu entry | Profile, Settings |

See [Vela.php](../src/Vela.php) for the exact method signatures and [BlockRegistry.php](../src/Registries/BlockRegistry.php) for config key names.

---

## Vela conventions (plugins must follow)

From the root `/www/vela/CLAUDE.md`:

- **DB tables**: prefix with `vela_` (e.g. `vela_snippets`, not `snippets`)
- **Namespace**: `Vendor\PackageName\` (e.g. `VelaBuild\Snippets\`)
- **Auth guard**: `vela` (not Laravel's default `web`)
- **Models**: always `SoftDeletes`; explicit `$table`; override `serializeDate()` to `Y-m-d H:i:s`
- **AI**: use `AiProviderManager` — no SDK packages, Laravel `Http` facade only
- **Tests**: `DatabaseTransactions`, never `RefreshDatabase`
- **Images**: every `<img>` through `vela_image()` or `vela_optimize_imgs()`; link tags via `vela_image_url()`
- **Fonts**: Bunny Fonts, never Google Fonts (GDPR)
- **Links**: `route()` / `url()` / `asset()` — never bare `/path`
- **Code style**: 4-space indent, LF, final newline, no trailing whitespace. See [code-style.md](code-style.md).

---

## Safe-by-default on no-DB production

Vela supports static-cache production deploys with no database. Your plugin must not 500 in that mode. Wrap every DB access in a try/catch **or** guard with `Schema::hasTable()`:

```php
try {
    $value = \VelaBuild\Core\Models\VelaConfig::where('key', 'foo')->value('value');
} catch (\Throwable $e) {
    $value = null;
}
```

Core ships a `vela_config($key, $default)` helper that does this — use it for config reads.

---

## Publishing assets

Public CSS/JS that your plugin ships:

```php
$this->publishes([
    __DIR__.'/../public' => public_path('vendor/your-namespace'),
], 'your-namespace-assets');
```

Host apps run `composer update` which auto-publishes via `post-update-cmd`.

For CSS/JS that should be bundled into Vela's hashed bundles, contribute to `config('vela.assets.bundles')` — either by merging in your service provider's `register()` or by documenting the host-app override in your plugin's README.

---

## Testing

```php
use VelaBuild\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class YourPluginTest extends TestCase
{
    use DatabaseTransactions;

    public function test_admin_page(): void
    {
        $this->loginAsAdmin();   // helper from core's TestCase
        $this->get('/admin/yourthing')->assertOk();
    }
}
```

---

## Reference: `velabuild/snippets`

The Snippets plugin is the canonical reference. Read it end to end:

- `src/SnippetsServiceProvider.php` — every hook we document here, in one file, heavily commented
- `src/Services/CssScoper.php` — a non-trivial service (auto-prefix snippet selectors so styles don't bleed)
- `resources/views/admin/page-editor-block.blade.php` — the `@push('vela-page-editor-blocks')` pattern
- `resources/views/admin/form.blade.php` — live-preview iframe during edit
- `database/seeders/SnippetsExampleSeeder.php` — how to ship example data without forcing it

If you find yourself writing something that feels like it should be in core, open a PR — extensibility gaps are bugs.
