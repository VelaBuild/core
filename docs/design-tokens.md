# Design tokens

A Vela site is painted from one set of CSS custom properties, the `--vela-*`
tokens. A theme states its palette in them once, and everything follows: the
theme's own chrome, every block on every page, and whatever the owner later
chooses in **Settings → Design**.

## Why there is a contract at all

There used to be four separate ways of naming a colour, and none of them knew
about the others:

| Where | Names | Who read them |
|---|---|---|
| `template.json` options | `primary_color`, `secondary_color`, `background_color` | `theme-colors.blade.php`, as `--vela-*` |
| Each theme's stylesheet | `var(--vela-primary, #1e40af)` | the theme's own header, buttons, links |
| `page-blocks.css` | `--block-accent`, `--block-border`, … | every block on every page |
| `ThemeSkeleton::TOKENS` | `--accent`, `--ink`, `--line`, … | themes written by the design builder |

The second and third never met. Setting a brand colour turned the header that
colour and left every CTA, pricing tier and icon box the colour the theme had
been shipped with, because each theme re-declared `--block-accent` as a literal
hex. `default` and `minimal` declared nothing at all, so both shipped the stock
blue `#2563eb` — over a navy theme and a black-and-white one respectively.

## The shape

```
--vela-*        the contract. A theme sets these.
   ↓
--block-*       aliases in page-blocks.css. Nothing else may set them.
   ↓
theme-colors    the owner's choice, included last, so it outranks the theme.
```

`page-blocks.css` never gives a `--vela-*` token a value. Each alias carries the
old block default as its fallback:

```css
--block-border: var(--vela-line, #e5e7eb);
```

That matters: a theme states its palette partly through its own `var(--vela-primary,
#1e40af)` fallbacks, and a value defined at the `page-blocks.css` level would
silently outrank every one of them. It also means a theme that sets nothing
renders exactly as it did before the contract existed.

## The tokens

### Colour

| Token | Block alias | What it is |
|---|---|---|
| `--vela-primary` | `--block-accent` | the brand as text — a link, a category label, an icon |
| `--vela-primary-fill` | `--block-accent` | the brand as a solid behind white text; defaults to `--vela-primary` |
| `--vela-primary-hover` | `--block-accent-hover` | derived by `color-mix` if unset, so a new brand brings its own hover |
| `--vela-ink` | `--block-text-primary` | body text and headings |
| `--vela-ink-soft` | `--block-text-secondary` | secondary copy |
| `--vela-muted` | `--block-text-muted` | dates, captions, descriptions |
| `--vela-line` | `--block-border` | borders and rules |
| `--vela-input-line` | `--block-form-border` | the border of a form field |
| `--vela-surface` | `--block-bg-light` | cards and panels sitting on the page |
| `--vela-surface-hover` | `--block-bg-hover` | the same, hovered; also the ground behind inline `code` |
| `--vela-card-bg` | `--block-bg-white` | the ground a card is drawn on |
| `--vela-background` | — | the page behind everything |
| `--vela-secondary` | — | the theme's supporting colour |
| `--vela-overlay`, `--vela-overlay-soft` | `--block-overlay*` | the film over a hero image |
| `--vela-success-bg` / `-line` / `-ink` | `--block-success-*` | a form's success notice |
| `--vela-error-bg` / `-line` / `-ink` | `--block-error-*` | a form's error notice |

`--vela-error-*` rather than `--vela-danger-*`: the admin stylesheet already owns
`--vela-danger` and `--vela-danger-bg` for its own alerts.

### Type and measure

| Token | Block alias | What it is |
|---|---|---|
| `--vela-font-display` | `--block-heading-font` | the face headings are set in |
| `--vela-page-width` | `.row-contained` max-width | how wide content runs before it stops growing |

## Two accents, when one will not do

`--vela-primary` and `--vela-primary-fill` are separate because they have
different jobs and sometimes need different values. The dark theme's `#14b8a6`
reads well as a link on black but gives white button text a contrast ratio of
2.1:1, so it carries `#0d857b` as the fill. A theme that needs only one colour
sets only `--vela-primary`, and the fill follows it.

## Writing a theme

Declare the palette in a `:root` block in the layout's `<head>`, before the
`theme-colors` include:

```blade
<style>
    :root {
        --vela-primary: #7c3aed;
        --vela-primary-hover: #6d28d9;
        --vela-ink: #1e1b4b;
    }
</style>
@include('vela::templates._partials.theme-colors')
```

Never set a `--block-*` variable in a theme. It bypasses the alias, and the
value can then no longer be overridden from Settings. `ThemePaletteReachesBlocksTest`
fails the build if a shipped theme does.

## Themes the design builder writes

`ThemeSkeleton` has its own unprefixed vocabulary (`--accent`, `--ink`,
`--page-width` — see `ThemeSkeleton::TOKENS`), chosen so that dressing a theme
is a dozen decisions rather than four hundred lines of CSS. Its layout bridges
those onto the contract in one place, so a generated theme reaches every block
alias, including any added later.

## Colours from Settings

`theme-colors.blade.php` is included last in every layout, so it outranks the
theme. A brand colour has to carry its two companions with it — the fill and the
hover — or a red brand would darken to the previous theme's purple on hover.

Values there are written by whoever can reach Settings and are interpolated into
a `<style>` element on every public page, so they are matched against a colour
grammar first (a hex, a `rgb()`/`hsl()` function containing only numbers, or a
bare keyword) and dropped if they do not fit.
