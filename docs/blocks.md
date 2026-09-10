# Blocks

A block is a piece of page content: a heading and some copy, a hero, a pricing
table, a snippet of code. Rows hold blocks; a theme holds rows. See
`docs/design-tokens.md` for how a block is coloured and `docs/plugins.md` for
registering one from a package.

## A block needs two halves

**A view**, which draws it on the public site, registered as `'view'`.

**A form**, which lets its owner change it in the admin. There are two ways to
have one, and every block must have one of them:

| Declared as | Where the form lives | Use it when |
|---|---|---|
| `'editor' => 'js'` | a `registerBlockType()` in `public/js/page-editor.js` | editing is genuinely bespoke — a rich-text canvas, a media browser, a repeater |
| `'fields' => [...]` | built by the admin from the schema | the form is a handful of ordinary inputs |

A block with neither renders on the site and cannot be edited: its owner opens
the page and reads "Unknown block type". Five shipped that way — `app_download`,
`code`, and the three review blocks — because the registry answered "can this be
edited?" with a regex over `page-editor.js`, reading back whatever the JS
happened to register. A block was editable by accident rather than by
declaration, and one that was neither looked exactly like one that was fine.
`BlockManifestTest` now fails the build for any block without a form, and checks
that a block claiming `'editor' => 'js'` really has one.

## Field schemas

```php
$vela->registerBlock('code', [
    'label' => 'vela::global.block_type_code',
    'icon'  => 'fas fa-code',
    'view'  => 'vela::public.pages.blocks.code',
    'defaults' => [
        'content'  => ['code' => '', 'filename' => '', 'caption' => ''],
        'settings' => ['language' => 'bash', 'theme' => 'dark', 'show_copy' => true],
    ],
    'fields' => [
        ['key' => 'code',      'in' => 'content',  'type' => 'code',   'label' => 'Code'],
        ['key' => 'filename',  'in' => 'content',  'type' => 'text',   'label' => 'Filename',
         'help' => 'Shown above the code. Leave empty for none.'],
        ['key' => 'language',  'in' => 'settings', 'type' => 'select', 'label' => 'Language',
         'options' => ['bash' => 'Shell', 'php' => 'PHP']],
        ['key' => 'show_copy', 'in' => 'settings', 'type' => 'toggle', 'label' => 'Show a copy button'],
    ],
    'editor_note' => 'Optional sentence shown above the form.',
]);
```

- `in` is `content` or `settings`, matching where the view reads the key.
- `type` is one of `text`, `textarea`, `code`, `select`, `toggle`, `number`.
  `select` takes `options`; `number` takes `min` and `max`.
- Every key must also appear in `defaults` under the same bag. A control writing
  a key the view never reads is a control that does nothing, and
  `BlockManifestTest` refuses it.
- `editor_note` is for what the form cannot say for itself — `app_download`
  renders nothing until the store links are filled in under Settings → Tools,
  which otherwise looks like a broken block.

The schema also decides the block's preview in the editor's column: the first
`text`, `textarea` or `code` field, or the block's label if it has none yet.
