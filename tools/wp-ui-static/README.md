# wp-ui-static

Static, unmounted styles for public `@wordpress/ui` components, for
server-rendered markup that never mounts React.

`@wordpress/ui` ships each component's CSS inside its JavaScript as a hashed
CSS module and injects it when the component mounts. PHP-rendered admin pages
that want the same look without a React root have nothing to load. This tool
lifts the registered style strings out of the installed package and writes one
stylesheet plus a manifest.

## Guarantees

- Declarations are copied verbatim, still inside the `wp-ui` cascade layer.
- Class names are renamed from hashed to stable names following the Gutenberg
  component convention:
    - `{prefix}-{component}` is the block (the library's `root` part)
    - `{prefix}-{component}__{element}` is a child element
    - `.is-{state}` is compounded onto its block, for example
      `.{prefix}-badge.is-high-intent`
- No version appears in a class name. The source version is recorded in the
  stylesheet header and in the manifest, so markup survives a library upgrade.
- The manifest lists each block, element and state, and a SHA-256 of the source
  style string, so drift is detectable.
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

`Card`, `Badge` and the `Text` styles `Badge` builds on. These work because
their CSS does not depend on state that React sets at runtime. Components whose
styles key on Base UI attributes (Button, Dialog, Menu, Tabs, Switch) are not
supported.

## Limits to know about

- It reads the library's `registerStyle(hash, css)` calls. A change to that
  shape makes extraction fail loudly.
- State classes compound onto their block, so their specificity is one class
  higher than in the library. They still sit inside the `wp-ui` layer.
- Markup is the consumer's job. Mirror the structure the React component
  renders, for example a Badge is `{prefix}-text is-body-sm {prefix}-badge
  is-{intent}-intent`.

This directory imports nothing from the host plugin so it can move to its own
repository unchanged.
