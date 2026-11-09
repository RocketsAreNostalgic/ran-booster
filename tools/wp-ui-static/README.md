# wp-ui-static

Static, unmounted styles for public `@wordpress/ui` components, for
server-rendered markup that never mounts React.

`@wordpress/ui` ships each component's CSS inside its JavaScript as a hashed
CSS module and injects it when the component mounts. PHP-rendered admin pages
that want the same look without a React root have nothing to load. This tool
lifts the registered style strings out of the installed package and writes one
stylesheet plus a manifest.

## Guarantees

- Declarations are copied verbatim, still inside the `wp-ui` cascade layer
  (except `reset-a`, which the library itself leaves unlayered).
- Class names are renamed from hashed to stable names following the Gutenberg
  component convention:
    - `{prefix}-{component}` is the block (the library's `root` part)
    - `{prefix}-{component}__{element}` is a child element
    - `.is-{state}` is compounded onto its block, for example
      `.{prefix}-badge.is-high-intent`
- No version appears in a class name. The source version is recorded in the
  stylesheet header and in the manifest, so markup survives a library upgrade.
- The manifest lists each block, element and state, the public custom
  properties the style declares (for example `--wp-ui-card-padding`), and a
  SHA-256 of the source style string, so drift is detectable.
- `--check` fails when the committed output differs from a fresh extraction.

## Usage

```bash
node tools/wp-ui-static/cli.mjs --prefix wp-ui --out dist/wp-ui-static.css
node tools/wp-ui-static/cli.mjs --prefix wp-ui --out dist/wp-ui-static.css --check
```

`--ui` sets the package directory (default `node_modules/@wordpress/ui`).
`--prefix` sets the class namespace. A third-party plugin should use its own
prefix; `wp-ui` is the namespace an upstream release would use.

## Supported components

`Card`, `Badge`, `Notice`, `Link` and the `Text` styles `Badge` and `Notice`
build on, plus the focus
ring `Link` depends on (`{prefix}-outset-ring--focus-except-active`, applied on
its own with no base class, together with `{prefix}-box-sizing`), and the
library's bare-`a` reset (`{prefix}-reset-a`) that `Link` relies on: its
`unstyled` and focus-ring styles only set `--_gcd-a-*` variables that this
reset reads. These work because
their CSS does not depend on state that React sets at runtime. Components whose
styles key on Base UI attributes (Button, Dialog, Menu, Tabs, Switch) are not
supported, and neither are the parts of `Notice` that render them (see below).

## Limits to know about

- It reads the library's `registerStyle(hash, css)` calls. A change to that
  shape makes extraction fail loudly.
- A library style string is extracted whole, so unused variants (for example
  `Link`'s `is-brand`) come along. The library's global-css-defense reset for
  other elements (`button`, `input` and so on) is not extracted; the `a` rule is
  lifted on its own and, as in the library, sits outside the `wp-ui` layer.
- State classes compound onto their block, so their specificity is one class
  higher than in the library. They still sit inside the `wp-ui` layer.
- Markup is the consumer's job. Mirror the structure the React component
  renders, for example a Badge is `{prefix}-text is-body-sm {prefix}-badge
is-{intent}-intent`.
- The Card frame sets `display: flex` inside the `wp-ui` layer, which outranks
  the user agent's `[hidden]` rule. Put `hidden` on a wrapper around the frame,
  or add an unlayered `[hidden] { display: none }` for it.

## Card page frames

`Card.Root` renders its `root` part together with the box-sizing reset, so a
static page frame carries both classes on one element, with the library's
header and content parts as children:

```html
<section aria-labelledby="example-heading">
	<div class="wp-ui-card wp-ui-box-sizing">
		<div class="wp-ui-card__header">…</div>
		<div class="wp-ui-card__content">…</div>
	</div>
</section>
```

`Card.Title` is the `Text` component's `heading-lg` variant
(`wp-ui-text is-heading-lg`). The library's inset comes from
`--wp-ui-card-padding` and the header-to-content gap from
`--wp-ui-card-header-content-gap`; the manifest records these properties so a
consumer that tunes them notices a rename. A consumer with its own inset profile
can also use a plain child element in place of `__content`.

## Notice

`Notice.Root` renders its `root` part, the intent state and the box-sizing reset
on one element, its children, and then the intent icon. `Notice.Description` is
`Text` `body-md` and `Notice.Title` is `Text` `heading-md`:

```html
<div class="wp-ui-notice wp-ui-box-sizing is-error">
	<span class="wp-ui-text is-heading-md wp-ui-notice__title">…</span>
	<div class="wp-ui-text is-body-md wp-ui-notice__description">…</div>
	<div class="wp-ui-notice__actions">…</div>
	<svg class="wp-ui-notice__icon" aria-hidden="true" focusable="false" …>…</svg>
</div>
```

Intents are `is-info`, `is-warning`, `is-success` and `is-error`; neutral has no
state class and no icon. The icon is markup, not CSS: copy the 24px SVG the
library uses for the intent (`info`, `caution`, `published`, `error` from
`@wordpress/icons`) and keep it `aria-hidden`. When a script changes the intent
in place it must swap the state class and the icon together. The grid places
each part by column, so source order does not move them.

The style keeps native CSS nesting, `:has()`, `container-type: inline-size`
with an `@container (max-width: 320px)` query, and `mix-blend-mode` with a
`forced-colors` reset; each needs a browser that supports it, as the library
does. Like the Card, the root sets `display: grid` inside the layer, so a
consumer that toggles `hidden` on the root needs an unlayered
`[hidden] { display: none }` for it. A host whose own unlayered rules style the
same element (WordPress core's `.notice`, for example) outranks the layer and
must reset those properties itself.

Deliberately not extracted:

- `Notice.List` announcing. It is a live region driven by script, not CSS; a
  static notice announces through whatever the host uses (for example
  `wp.a11y.speak`), and carries no `data-wp-ui-notice-politeness`.
- `Notice.ActionButton` and `Notice.CloseIconButton`. They render the library
  `Button` and `IconButton`, which are not supported. Their placement classes
  (`__action-button`, `__close-icon`) come along in the shared style string and
  can position a host's own button. `__action-link` is the `Link` component,
  which is supported.
- The global-css-defense heading reset that reads the title's
  `--_gcd-heading-color`; a host heading takes its colour from `color` instead.

This directory imports nothing from the host plugin so it can move to its own
repository unchanged.
