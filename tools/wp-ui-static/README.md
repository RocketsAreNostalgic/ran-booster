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
  (except the `reset-a` and `reset-button` resets, which the library itself
  leaves unlayered).
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

`Card`, `Badge`, `Notice`, `Link`, `Button` and the `Text` styles `Badge` and
`Notice` build on, plus the shared pieces they compose: the focus ring
(`{prefix}-outset-ring--focus-except-active`, applied on its own with no base
class), the box-sizing reset (`{prefix}-box-sizing`), and two of the library's
global-css-defense resets, each lifted on its own and left unlayered as the
library leaves them:

- `{prefix}-reset-a`, the bare-`a` reset `Link` relies on: its `unstyled` and
  focus-ring styles only set `--_gcd-a-*` variables that this reset reads.
- `{prefix}-reset-button`, the `button` reset `Button` relies on: it reads the
  `--_gcd-button-font-*` variables the Button sets, and outranks unlayered host
  rules such as wp-admin's `button { font-weight: inherit }`.

Most of these work because their CSS does not depend on state React sets at
runtime. `Button` keys on two attributes, `[data-disabled]` and
`[aria-pressed=true]`, so static markup follows a contract instead (see below).
Dialog, Menu, Tabs and Switch depend on Base UI behaviour beyond attributes and
are not supported.

## Limits to know about

- It reads the library's `registerStyle(hash, css)` calls. A change to that
  shape makes extraction fail loudly.
- A library style string is extracted whole, so unused variants (for example
  `Link`'s `is-brand`) come along. The library's global-css-defense resets for
  other elements (`input`, `p` and so on) are not extracted; the `a` and
  `button` rules are lifted on their own and, as in the library, sit outside
  the `wp-ui` layer.
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
	<svg class="wp-ui-notice__icon" aria-hidden="true" focusable="false" …>
		…
	</svg>
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
- `Notice.ActionButton` and `Notice.CloseIconButton`. Their placement classes
  (`__action-button`, `__close-icon`) come along in the shared style string and
  can position a static Button (see below). `IconButton` is not extracted.
  `__action-link` is the `Link` component, which is supported.
- The global-css-defense heading reset that reads the title's
  `--_gcd-heading-color`; a host heading takes its colour from `color` instead.

## Button

`Button` renders five classes besides its tone, variant and size: the `button`
defense reset, the box-sizing reset, the focus ring and its root part. A static
button carries the same set, and **no host button classes**:

```html
<button
	type="button"
	class="wp-ui-reset-button wp-ui-box-sizing wp-ui-outset-ring--focus-except-active wp-ui-button is-brand is-solid is-compact"
>
	…
</button>
```

- Tone is `is-brand` or `is-neutral`; variant is `is-solid`, `is-outline` or
  `is-minimal`; size is `is-compact` (32px), `is-small` (24px) or no class for
  the 40px default. Like the library, every button needs one tone and one
  variant.
- An icon is markup: give a 24px SVG `{prefix}-button__icon`, whose negative
  margin lets it sit in the 20px line box without growing the button.
- A pressed toggle sets `aria-pressed="true"`; the library styles
  `is-neutral is-minimal[aria-pressed=true]` as solid, exactly as mounted.
- Size, colour and padding are public custom properties
  (`--wp-ui-button-height`, `--wp-ui-button-background-color` and so on), which
  the manifest records. A consumer tunes or adds a tone through these on its
  own class rather than overriding declarations.

Base UI sets `data-disabled` on a disabled button, and the library keys the
disabled colours, the pointer cursor and the hover/focus colours on it
(`:not([data-disabled]):is(:hover,:active,:focus)`). Static markup has no
runtime to set it, so the extractor's `bridge` option widens every
`[data-disabled]` in the Button's style to
`:is([data-disabled],:disabled,[aria-disabled=true])`. `:is()` takes its most
specific argument, so specificity is unchanged, and everything else stays
verbatim. A native `disabled` button, an `aria-disabled="true"` button (still
focusable, as Base UI's `focusableWhenDisabled` default renders it) and a
disabled fieldset's buttons all render as the mounted disabled Button. The
manifest records the bridge, and the source hash covers the unbridged string,
so `--check` still detects upstream drift.

An `a` button needs care in a host with unlayered link rules. wp-admin's
`a { color }` and `a:focus { box-shadow }` outrank the `wp-ui` layer, so a
static anchor shows the link colour, not the button's. Adding
`{prefix}-reset-a` removes the box-shadow and transition but sets
`color: var(--_gcd-a-color, inherit)` and `border-radius: 0`; the host must
point those variables at the Button's, for example:

```css
a.wp-ui-button {
	--_gcd-a-color: var(--wp-ui-button-foreground-color);
	--_gcd-a-border-radius: var(--wpds-border-radius-sm, 2px);
}
a.wp-ui-button:not([aria-disabled='true']):is(:hover, :focus, :active) {
	--_gcd-a-color: var(--wp-ui-button-foreground-color-active);
}
a.wp-ui-button[aria-disabled='true'] {
	--_gcd-a-color: var(--wp-ui-button-foreground-color-disabled);
}
```

A disabled anchor drops `href` and sets `aria-disabled="true"`; a `disabled`
class alone is not bridged.

Host classes that style the same element must go. wp-admin's `.button` and
`.button-primary` are unlayered, so on an element that also carries them they
win every property they set (height, padding, colours, line-height).

Deliberately not extracted:

- The loading spinner. `is-loading` and `__spinner` come along in the shared
  style string, but the Spinner's SVG and keyframes live in another module and
  `loading` also announces through `wp.a11y.speak`. Static markup does not use
  `is-loading`; a host draws its own busy state on the static Button.
- `is-unstyled` as the library uses it. The library drops the root class for
  the unstyled variant, but the extracted state compounds onto the root, so the
  rootless variant cannot be expressed.
- A danger tone. The library has none; a host defines one by setting the
  `--wp-ui-button-*` properties on its own modifier class.
- `IconButton`, `ButtonLink` and the `render` prop's other elements.

This directory imports nothing from the host plugin so it can move to its own
repository unchanged.
