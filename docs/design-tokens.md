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

| `--vela-band`, `--vela-band-ink` | — | a full-width strip and the text on it: a hero, a quote, a closing call to action |

### Type and measure

| Token | Block alias | What it is |
|---|---|---|
| `--vela-font-body` | — | body copy, navigation, buttons; blocks inherit it from the page |
| `--vela-font-display` | `--block-heading-font` | the face headings are set in |
| `--vela-font-mono` | `--block-mono-font` | code |
| `--vela-page-width` | `.row-contained` max-width | how wide content runs before it stops growing |

A theme states its two faces once and both halves follow. Before that, five of
the six wrote their fonts out as literals — thirty-one of them, spread between
each layout's inline CSS and its stylesheet — so a heading inside a text block
inherited the body face while the theme's own headings did not, and no setting
could reach either.

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

## Rows and blocks

A row or a block can carry a background colour, a text colour, spacing and an
alignment of its own. Those used to be stored as whatever the author picked on
the day — `#ffffff`, out of a colour picker. That records a value and not an
intention, and it is the pages somebody cared enough to style by hand that
break when the site changes theme: white text on a white ground, forty pages
deep, with nothing to do but open each one and pick again.

A colour may now be a palette name instead, stored as `token:surface` and
resolved on render to the custom property behind it. The names are in
`DesignTokens::PALETTE`; the editor offers them as chips under each colour
field, and the chatbot is told to prefer them (`ChatToolRegistry::COLOUR_HINT_*`).

Literals still work and are still offered — sometimes a section really is meant
to be that exact green — but they are validated now, which they were not
before. These values are written into a `style` attribute where a background of
`red; position: fixed; inset: 0; z-index: 9999` is a page-covering overlay, and
`text_alignment` went in unchecked. `DesignTokens` drops anything that is not a
colour, a length or one of four alignment words.

The admin's other colour inputs keep the site palette row from
`design-system-global.blade.php`, which writes a literal hex. That is the right
answer where a literal is what is wanted — theme settings, the design builder.
It stays out of the way of any field that offers theme chips, so the two never
appear together offering the same choice with different consequences.

## Colours the site's owner can change

A theme declares `options` in its `template.json`; that is what puts a colour
picker on Settings → Appearance. `DesignTokens::SITE_OPTIONS` is the list of
colour options Vela understands and the `--vela-*` property each one sets, and
`theme-colors.blade.php` renders every one of them. An option outside that list
draws a picker that changes nothing, which `ThemeOptionsReachTheOwnerTest`
refuses.

Three separate faults met on this screen, and together they produced one
symptom — "my colour settings have disappeared":

- **The form posted the wrong names.** The view groups options for display, and
  Laravel's `groupBy` reindexes unless passed `true`, so every field was posted
  as `theme_0`, `theme_1`, `theme_2`. Those were stored under those names and
  read by nothing: setting Primary Colour appeared to save and changed nothing
  at all, on every theme Vela has ever shipped.
- **Generated themes offered nothing.** A theme written by the design builder
  declared `"options": {}`, and a theme with no options gets no Theme Options
  panel — not a smaller one, none at all. `ThemeAuthor::refreshOptions()` now
  publishes the theme's palette, reading each default off its own `:root` so
  the picker opens on the colour the design chose. It runs on create and after
  `setTokens`.
- **A deleted theme said nothing.** A site set to a theme that is no longer
  installed showed no theme selected, no options panel (a theme that does not
  exist declares none), and a public site that looked fine because
  `vela_template_view()` quietly falls back to `default`. The Appearance screen
  names the missing theme and what is being drawn instead.

Only the active theme's declared keys are stored, so a form posting something
else no longer fills `vela_configs` with rows nothing reads.

## The example homepages

Every theme ships a `home-template.json` — the rows and blocks installed by
"Install as Homepage". They named their section colours as hexes, so a site that
installed one and later switched theme kept a navy band across a design that had
stopped being navy, with nothing on screen explaining why.

They name roles now: `token:band` for the full-width strip a page opens with,
`token:surface` for the light ground under its middle sections, `token:ink` for a
dark closing band. Each theme declares what those roles are worth to it, read off
the page it already shipped, so every one renders exactly the colour it did
before and re-skins from then on.

One row is still a literal: the dark theme's second band, `#16213e`, a navy a
shade off its first one and matching no role. Naming it `token:band` would
flatten a two-tone the theme meant to have.
