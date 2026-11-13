# WordPress-native administration composition contract

RAN Booster Core exposes a small set of WordPress actions and filters through
which compatible add-ons can contribute to existing Booster administration
surfaces.

Core also retains a separate, bounded public add-on tab API for a plugin whose
workflow genuinely requires its own dashboard surface. The hooks documented
below replace the former Documentation and Package Extension registries; they
do not replace or deprecate dashboard-tab registration.

The hooks belong to concrete Core surfaces. Core owns their routes,
capabilities, base projections, tables, wrappers, validation, escaping and
unavailable states. Add-ons may enrich the documented data or append one
bounded section; they may not replace a Core template or receive the service
container, database repositories, provider clients, credential store or
secrets sidecar.

This is not a WordPress sandbox. An installed plugin is trusted PHP. The
contract limits accidental coupling and what Core deliberately supplies; it
does not make a malicious plugin safe.

Eligible add-on-owned forms contributed through these surfaces can opt into
Core's shared HTMX lifecycle and feedback without carrying their own UI
updater. The separately versioned
[Enhanced administration interaction API](enhanced-admin-interactions.md)
documents that transport and presentation contract; it does not expand any
composition hook or authorization boundary described here.

## Component package direction

Administration component replacements use the specific `@wordpress/ui` package.
An alternative requires express owner authorization for the named component and
scope. Generic WordPress styling, `wp.components` / `@wordpress/components`,
custom reskins and prior screenshot approval do not establish that exception.
Record the actual public export, exact package version, built artifact and
shared runtime evidence before claiming adoption. Native functional and
no-JavaScript fallbacks remain required and are distinct from adoption.

The owner-approved current combined foundation and adapter ceiling is 166,090
raw bytes and 33,383 summed gzip bytes. The 8 November 2026 approval covers the
current Documentation composition and released Admin Shell 2.1.1 uptake,
including the matching build and runtime-test guards. This is 170 raw bytes and
56 summed gzip bytes above the temporary Documentation preview allowance.
It does not authorize another component family or replace the separate overlay,
Switch-only or Link pilot limits. Release, publication and consumer acceptance
remain separate decisions.

Existing source and installed previews retain their actual implementation
identity; this direction does not authorize an automatic rollback or broaden
protected action authority. Preserve current sites and recoveries. A concrete
unsupported public API or runtime constraint needs an explicit owner exception
or revised scope. Source, build, installed evidence and owner acceptance remain
separate, and all merge/publication holds continue.

### Approved exception: snackbar host

The owner approved one exception to the `@wordpress/ui` rule (#755):

- **Component and export:** `wp.components.SnackbarList`, the WordPress global
  (script handle `wp-components`, style handle `wp-components`).
- **Scope:** the single Booster feedback host described in the
  [feedback policy](feedback-policy.md), and nothing else. No other
  `wp.components` or `@wordpress/components` component is approved by it.
- **Reason:** `@wordpress/ui` 0.26.0 exports no Snackbar or Toast. Its `Notice`
  and `Notice.List` are in-flow, not a viewport overlay.
- **Versions checked:** `@wordpress/ui` 0.26.0 (the pinned version); the
  `wp.components` build shipped with WordPress 7.0.4, which is the runtime
  source of truth because Booster does not bundle it. WordPress 7.0.4's
  `Snackbar` hard-codes a 6 second timeout and has no pause. WordPress 7.0
  and 7.0.3 were not checked; the 6 second timeout is assumed unchanged there.
  Booster's host owns the timer and the queue.
- **Not adoption:** this is a recorded exception, not package adoption. The
  native fallback and the static no-JavaScript result remain required.
- **Revisit trigger:** if `@wordpress/ui` gains a snackbar or toast, swap the
  host to it. Check on every `@wordpress/ui` version change. The host,
  timer and queue mechanics are kept swappable for that reason.

## Admin Shell continuity and page navigation

Booster adopts build-time `ran/admin-shell` **2.1.1**, source
`c5f57782b24301474133b7b25e11bd50477da683`. Change its exact Composer pin and
lock together, run `composer admin-shell:sync`, then
`composer admin-shell:check`. The PHP/CSS copies and provenance are generated;
product styling belongs in `assets/ran-booster/00-foundations.css` after the
shell stylesheet. The development dependency is not a plugin runtime package.

The inverse identity renders Booster with Rockets Are Nostalgic below it and
the decorative cream nozzle asset. The consumer supplies the 135-degree
`#10364E` to `#1E4D6B` gradient and removes bottom padding from the identity row when section navigation
follows it. A header without section navigation keeps the Shell spacing.
Current destinations keep their indicator;
hover and keyboard focus receive an underline, with the keyboard outline
retained. Forced colours use system colours. No branding script or React
mount is required, and native rendering remains available without JavaScript.

The sticky region contains one H1, the identity and section navigation. Keep
the shell outside the page `.wrap`, below the native notice destination.
Help/Screen Options controls must stack above the shell on Booster screens;
owned dialogs keep their existing higher layer. Use the shell's toolbar
breakpoints and short-viewport fallback. Verify its default 192px focus/anchor
allowance against long translations and zoom before changing the offset;
do not add a cloned heading or a second navigation lifecycle.

| Surface | Retained interaction |
| --- | --- |
| Shell sections | Destination links and `aria-current="page"`; Settings and other destinations outside this menu select no unrelated item. |
| Provider tasks and Troubleshooting sections | Real URLs with current-page indication; existing enhanced replacement, focus, error and history owners remain. |
| Package source while editing | Destination links, current text and unavailable choices; preserve capability and dirty-state checks. |
| Package source while creating | Existing `aria-pressed` choice buttons; these are not an ARIA tab widget. |

Provider navigation sets htmx's existing `refreshOnHistoryMiss` policy for its
document. Ordinary task clicks remain enhanced; a Back/Forward cache miss uses
a native page load instead of an unreported failed history fetch. WordPress
clears the htmx history marker when it writes the canonical admin URL on
a full load. On a later unmarked Back or Forward within that provider,
reload if the destination query differs from the rendered view. Fragment-only
changes keep their native behavior. Keep
`hx-history="false"` and do not enable snapshots of credential-bearing pages.
This policy does not add custom network recovery or change Settings save/leave
protection; native failure, retry and cancelled-navigation proof remain separate.

This native-shell slice does not complete the wider #625 component programme
or #635 composed acceptance. WordPress floor/current, missing-enhancement,
overlay, notice, keyboard and repeated-navigation acceptance remain required.
Shell #36's wider maintained-consumer/template audit also remains open:
the named Turnstile and EmailOctopus consumers have exact v1 pins, but the
bounded public starter/template inspection does not establish exhaustive
coverage. Keep that evidence gap explicit in adoption and release decisions.

Booster's Admin Shell provides continuous product branding and navigation across
its normal administration pages, including standalone WordPress submenu destinations
such as Extensions. Keep the shell's logo, header navigation and footer consistent
when a page supplies its own content or local navigation.

Failure and recovery output may use a minimal heading, explanation and recovery
links when the normal administration stack cannot safely render. Examples include
the Extensions fallback error screen and the standalone unsupported-Multisite
recovery guide. These paths must remain usable without initializing the full
shell or operational services; this exception does not apply to ordinary pages.

A standalone destination does not automatically become a shell navigation item.
The WordPress submenu identifies that destination; the page heading identifies
its content. When the destination is outside the shell menu, leave that menu
without a selected item rather than marking an unrelated section as current.
Existing provider availability and visibility rules still apply.

A page that needs local navigation places it below its heading, visually
subordinate to and distinct from the shell navigation. Use ordinary links with
stable URLs and an accessible current-page indication. For a settings page,
folder-style tabs may connect the selected destination to its content panel.
Do not label navigation links as an ARIA tab widget unless the full associated
keyboard and interaction behaviour is implemented. Keep navigation usable on
narrow screens.

Use the shared page panels, typography, spacing, controls and feedback patterns.
Extensions contribute inside that layout rather than replace the product chrome.
Breadcrumbs are optional when deeper navigation needs them; they are unnecessary
when the submenu, heading and local navigation already establish location.

Page identity uses `ran-booster-page-heading__title` (20px, semibold,
1.3 line height) and `ran-booster-page-heading__description` (13px,
1.5 line height, muted, 6px after the title). Managed Plugins/Themes and
Install/Edit variants use the same roles as dashboard pages. Keep heading
alignment relative to its content grid, existing page actions beside the title
where space permits, and a 20px heading-to-task separation. Do not invent
missing descriptions or shorten existing copy. Remove redundant page decoration
(Ignition, Guidance, Add-ons, Diagnostics and the standalone Transporter
eyebrow); retain useful provider and repository context and panel-interior labels.

Containment follows the task. Install Plugin/Theme keeps its separate white
Repository configuration, Advanced settings and Package operation sections on
the grey page. Provider overview tasks use separate white groups with 20px grey
gutters. Do not add a universal white outer wrapper. Keep each group's wording,
controls, statuses, help, disclosures, internal borders, columns and arrangement
intact; outer separation does not authorize an interior redesign.

Edit Plugin/Theme shows the saved Git provider and repository read-only,
because both are fixed once a package is installed. The repository name links
to the repository on its Git provider when the stored provider supplies a
repository URL base, and stays plain text otherwise; the repository picker is
not offered. Hidden inputs carry the saved values so a save still validates
them. Credential, branch, subdirectory and update settings stay editable, and
release-managed packages keep their existing locks. Install Plugin/Theme keeps
both fields editable. To use a different provider or repository, choose
**Stop managing** in the Danger zone, then install or adopt the package again.
A save that still names a different provider or repository is refused with
HTTP 409 and a notice saying the same.

Overview, Troubleshooting, Documentation and Transporter place their page
heading and description on the grey background. Overview uses separate task
and credential-storage panels; Troubleshooting may retain a single white surface
below its heading. Documentation keeps its index and each disclosure white,
including expanded content. Transporter's chooser and active flow retain their
own task surfaces. Preserve its export limitations in the export context.

A subordinate **Back to…** link belongs above the page heading and outside white
content panels. Keep it within its existing enhanced replacement boundary when
required, with ordinary keyboard/no-JavaScript navigation. Do not move forms,
IDs, protected operations, safety notices or state to achieve visual consistency.
Page identity rules do not change headers inside Diagnostics panels. Normal
package recovery uses the shared title/description roles; the minimal failure
paths described above retain their independent rendering contract.

These rules refine [Core #478](https://github.com/RocketsAreNostalgic/ran-booster/issues/478)
and the continuity guidance delivered through
[Core #462](https://github.com/RocketsAreNostalgic/ran-booster/pull/462).
The fixed Settings destination follows the same continuity rules; see
[Fixed Settings destination](#fixed-settings-destination) below.

This is a product composition rule, not a new shell API. Reuse the generated
Admin Shell through its existing integration and source workflow; do not hand-edit
generated output or change the shared shell package merely to add a local page.
The fixed Core Settings screen is described in [Settings](settings.md).
Provider Settings tabs and a Settings contribution API remain deferred.

### Standard and wide content frames

Core owns two outer content frames in `00-foundations.css`: the default
`--ran-booster-width-standard` maximum of 1050px and the
`--ran-booster-width-wide` collection maximum of 1400px. `views/base.php`
assigns `ran-booster-admin--wide-collection` only to Managed Plugins/Themes
and Extensions. Existing package and Extensions feature classes remain available.
Both frames retain WordPress's responsive `.wrap` gutters; the full-width Admin
Shell sits outside that wrapper and keeps its own geometry.

| Surface | Previous outer constraint | Frame |
| --- | --- | --- |
| Overview and onboarding | 1050px | Standard |
| GitHub and Bitbucket | 1050px | Standard |
| Documentation | 1400px section inside 1050px wrapper | Standard reference |
| Settings | 1050px | Standard |
| Troubleshooting | 1200px section inside 1050px wrapper | Standard |
| Managed Plugins and Themes | 1400px | Wide collection |
| Install Plugin and Theme | 1400px wrapper; 790px inner form | Standard |
| Individual package settings | 1400px wrapper; main and 290px sidebar | Standard |
| Extensions | 1400px | Wide collection |
| Transporter | 1050px | Standard |

Documentation's effective source limit is its 1050px outer wrapper, rather than
the former nested 1400px declaration. Its index and 860px prose limit remain
inner reading constraints. Package installation retains its 790px inner form;
package settings retain their responsive main/sidebar grid. Transporter retains
its prose limits and deliberate horizontal review-table scroller, including
compatible add-on contributions. These inner constraints do not define more
outer page layouts.

The shared frame does not change Card padding, headings, table columns,
Extensions grid/card interiors or More Details. Width work under
[Core #682](https://github.com/RocketsAreNostalgic/ran-booster/issues/682)
owns the outer rules and page classification; the component, Settings and
generated Shell owners retain their existing boundaries. Installed direction
review and composed responsive/accessibility acceptance remain separate from
source checks.

### Page frame geometry

The outer widths above say how wide a page may be. This section says where
things sit inside it, so titles, tabs and cards stop drifting between pages.
Values come from measuring every Booster menu page at a 1280px viewport on
beta.44. Rows marked **Decision** need an owner choice and are not yet applied. D1, D2 and D4 are accepted and applied.

| Role                                                 | Standard                                                        | Owner in CSS                                                   | Status                                                                         |
| ---------------------------------------------------- | --------------------------------------------------------------- | -------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| Admin Shell bottom to page title                     | 24px (`.wrap` offset); real notices add their own height        | WordPress `.wrap`                                              | Matches on all pages                                                           |
| Left edge of title, description, tabs and first card | The wrap's left edge; no inline inset on a top-level page shell | `.ran-booster-admin > .ran-booster-page-shell` header and body | Applied in `be3ef723`                                                          |
| Page subtitle (or tabs) to first Card | 24px (`--ran-booster-card-gap`), the same as the Card gap; the first block adds no top margin | `.ran-booster-page-shell__header`, the package intro and heading rules, the Troubleshooting tabs and the provider management header | Applied on every page; was 20px (16px on Credentials and Webhook secrets) |
| Space between consecutive Cards | 24px (`--ran-booster-card-gap`), as in Jetpack | Card margin and stack gap | Applied; was 20px |
| Top margin of a first heading in any container       | 0; the container inset owns the space                           | `:is(h2, h3, h4):first-child`                                  | Applied in `c158dca8`                                                          |
| Card inline inset                                    | 24px                                                            | Card content layer                                             | Matches, except legacy nested panels                                           |
| Nested panel inside a Card                           | Takes the Card's inline inset and adds none                     | Panel rules scoped to the Card                                 | Capture panel fixed in `93a643c3`                                              |
| Card block-start inset (to first heading) | 24px (`--ran-booster-card-inset-block-start`); with the 1px border the heading sits 25px from the edge | Card content layer | Applied; was 18-25px by page |
| Card block-end inset (last block to edge) | 32px (`--ran-booster-card-inset-block-end`), 8px heavier than the sides; a trailing block adds no margin at any depth | Card content layer | Applied; was 20-24px plus trailing margins up to 37px |
| Heading to first content inside a Card | 8px (`--ran-booster-card-heading-gap`); a bare Card heading takes `.ran-booster-section__title`, so the WordPress `h3` margin never applies | `.ran-booster-section__description`, the shared heading-plus-paragraph rule, and the first block after a heading in Settings, Installation, Logging and Transporter | Applied; was 4, 8, 12, 13, 15, 16, 20 or 34px |
| Page title to description                            | 6px                                                             | `.ran-booster-page-heading__description`                       | 10px on package pages                                                          |
| Shell navigation text to content edge                | **Decision D3**                                                 | Generated Shell; coordinate with its owner                     | Nav text 60px, content 58px                                                    |

Jetpack is the reference for rhythm. Measured on its Settings page at the same
viewport, a Jetpack Card has a header with 16px block and 24px inline padding, a
20px/500 heading on a 28px line, and a 1px divider, then a body with 16px block
padding. Cards are 24px apart and a section heading sits 24px above its first
card. Jetpack does not pair a heading and description inside a Card header, so D2
has no direct equivalent.

Accepted answers (D1, D2 and D4 applied; D3 left to the Shell owner):

- **D1:** 16px, Jetpack's header padding. The 1px Card border makes the
  heading sit 17px from the Card's outer edge. Overview and GitHub Cards (19px
  today) tighten by about 2px; Settings and Troubleshooting (25px) and Extensions
  (17px) move to the same value.
- **D2:** 8px, with no Jetpack equivalent.
- **D3:** leave to the Shell owner. Booster cannot edit generated Shell assets.
- **D4:** 24px between Cards, as in Jetpack, up from 20px. This is the visible
  change on every page that stacks Cards.

Card inline inset is 24px and the block-start inset is 24px. Block-end is 32px: a frame reads balanced when its bottom is slightly heavier than its sides, and the heading's line box already adds optical space above it. This diverges deliberately from Jetpack's 16px, which relies on a divider under its Card headers; if Booster adds that divider later, revisit the block insets together. Two measured exceptions remain: Overview cards in a row stretch to the tallest, and Extensions keeps the WordPress plugin-card footer band, whose bottom padding stays at the native 20px. Extensions heading inset and the grid gap follow the Card tokens. At 782px and 375px only the inline inset shrinks; the top and bottom insets stay the same. Heading size and
weight (Jetpack 20px/500) belong to the separate typescale work in #726.

The trailing-block reset matches the last visible child at any depth, not only
blocks on the Card's bottom edge, so a margin on the last child of a nested
group always computes to 0. Space after such a block must come from its
container's padding or from the next sibling's top margin. Each Activity
record's details cell keeps 12px (`--ran-booster-space-12`) of bottom padding
below its disclosure (#765). The Support report evidence disclosure takes the Logging
disclosure's 16px (`--ran-booster-space-16`) top margin after its action
(#746).

Insets and gaps are measured box to box. When a Card ends with a framed panel,
such as a provider status summary or the public lookup panel, the 32px
block-end inset runs to that panel's border; the panel's own 16px padding and
1px border sit inside it and are content, not extra inset. Repository detail
pages still place 18px between their header, view links and first Card; they
are not yet aligned with the 24px page frame.

Rules that follow from the table:

1. A container's own inset owns the space inside it. A child never adds a
   second inset, and a nested legacy panel inside a Card drops its own side
   padding.
2. The page header owns the gap to the first block. Page content never adds a
   top margin to compensate.
3. A top-level page shell has no inline padding. The 24px inset exists only when
   a page shell is nested inside a Card or panel.
4. A new page uses the existing shell, header and Card classes. It does not
   introduce a local width, inset or heading margin.
5. A page Card is static server-rendered markup, never a mounted React
   component. The host keeps its section or div, the `ran-booster-card` class
   and its `data-ran-booster-card` kind, and its first child is the public Card
   frame from the generated `ran-booster-wp-ui-static.css`:
   `<div class="ran-wp-ui-card ran-wp-ui-box-sizing ran-booster-card__surface"><div class="ran-booster-card__content">`.
   The host draws nothing; the frame draws the border, radius and surface, and
   the content layer holds the inset profile above. A hidden Card puts `hidden`
   on the host, because the frame's layered `display: flex` outranks the user
   agent's `[hidden]` rule.

Troubleshooting Activity is a native table, not ARIA roles on a list. It
follows the Managed Plugins pattern of a summary row followed by a full-width
details row, with one `tbody` per record so the load-older append moves a
whole record. The table has a screen-reader `caption`, `th scope="col"`
headers and `data-label` cells that stack at 782px and below. Nothing in it
can scroll, so its wrapper is not a focusable region. The Activity heading sits
8px (`--ran-booster-card-heading-gap`) above its description.

The provider Credentials and Webhook secrets tables, drawn for every provider
by `ProviderManagementTableRenderer`, use the same collection frame:
`.ran-booster-data-table-wrap` around a `.ran-booster-data-table`, without the
`--rows` modifier or the core `widefat striped` classes. Like Activity, each
record is its own `tbody`: the summary row, then, for a credential that can be
validated, a `__result` row whose single `colspan` cell holds the validation
result Notice at full width. The result row is `hidden` until a result exists;
the Validate response swaps the result host inside it and replaces the Health
cell's short label out of band, and the enhanced-mutation script shows the row
while its Notice is visible. Webhook secrets print no result row. Headers are
13px/600 on the Card surface, record groups alternate the subtle surface with
one divider between groups, so a hidden result row never shifts the stripe,
and the wrapper takes the inset radius. The frame uses `overflow: clip`, so a
striped row never paints over its rounded corners. It is `clip` and not
`hidden` because the frame contract test forbids `hidden` on the frame (the
commit that added the test records no further reason), and `clip` does not
make the frame a scroll container. Because the frame clips at its edge, the
focus ring of any focusable child at the edge must stay inside it: the
focusable table region on the Transporter Package list takes
`outline-offset: -2px`, and a new focusable child needs the same inset ring.
Cells carry `data-label` and stack through the shared data-table rules at 782px
and below, with the result cell a full-width block under the stacked fields;
only the actions cell stays out of the label grid, with its Buttons in an inner `.ran-booster-actions` row so the
cell remains a real table cell. Column widths stay on the `--access` and
`--webhook` modifiers, and their cells are vertically centred like Activity;
other data tables keep the shared top alignment.

The Repository detail "Packages using this repository" table and the
Troubleshooting diagnostics table use the same frame and the `--rows`
modifier, without `widefat`. Every data-table header is 13px/600, start-aligned
on the Card surface; a `th scope="row"` in a body row reads as a normal cell.
The diagnostics table keeps one `tbody` per result group with the strong
divider between groups, and each group restarts the row stripe. Its results
Card holds the heading, the count and the frame directly, with the frame 24px
(`--ran-booster-card-gap`) below the heading block as in Activity. In stacked cells every child sits in
the value column.

The Managed Plugins and Managed Themes table keeps its native `wp-list-table
widefat plugins` structure: two rows per package, the row-header checkbox
spanning both, `tbody#the-list` and every script hook. It takes the frame
only: `.ran-booster-data-table-wrap` (with `.ran-booster-package-table-wrap`)
around the table, inset 16px inside the Card below the toolbar like the
toolbar's own inset. It does not take the `.ran-booster-data-table` class, so
its own stacking at 782px and below is unchanged. Headers are 13px/600 on the
Card surface with the shared header border; the inline padding stays 16px so
headers line up with the cells. The stripe alternates per package, summary and
details rows together, and one divider separates packages. A package active in
WordPress keeps its stripe and shows a 3px inline-start accent bar in the
interactive brand stroke, on the checkbox cell from 783px and on the stacked
rows below that; the former full-row tint and the hover tint are retired. The
first-run "Add your first" empty state is not a collection yet and keeps the
bare Card; the filtered-empty row stays in the frame.

`tests/browser/page-frame.mjs` measures these values on a rendered page. It is
a private runner helper like the other browser checks, and it is how the
status column above stays honest.

### Static notices

Booster's server-rendered notices are static markup drawn with the public
Notice's own CSS from `ran-booster-wp-ui-static.css` instead of a mounted React
`Notice.Root`. Every `data-ran-booster-ui-notice` host rendered by PHP in `views/`
and `RAN/` is static except the ones listed below;
`tests/assets/notice-static-markup.test.mjs` fails when a host lacks the static
markup and is not on that list.

```html
<div class="notice notice-error inline ran-wp-ui-notice ran-wp-ui-box-sizing is-error" data-ran-booster-admin-mutation-error data-ran-booster-ui-notice …>
	<div class="ran-wp-ui-text is-body-md ran-wp-ui-notice__description" data-ran-booster-ui-notice-content>…</div>
	<svg class="ran-wp-ui-notice__icon" aria-hidden="true" focusable="false" …>…</svg>
</div>
```

- Core's `notice notice-{intent}` classes (and `inline`, `is-dismissible`) stay,
  because core relocates and dismisses notices by them. Core's unlayered `.notice`
  frame outranks the `wp-ui` layer, so `25-admin-primitives.css` hands padding,
  border, radius, shadow and background back to the layer with `revert-layer`,
  and restates the Notice's `border-box`, which a Card's `content-box` would
  otherwise pass down through the `wp-ui` box-sizing utility.
- The Notice grid outranks the user agent's `[hidden]` rule; an unlayered
  `.ran-wp-ui-notice[hidden] { display: none }` restores it. Scripts that reveal a
  notice toggle `hidden` and set its text, as before.
- These rules match inside `.ran-booster-admin` and, for a notice that carries
  `data-ran-booster-ui-notice-scope="global"`, anywhere: on core screens, in a
  dialog portal, or where core moves a notice that is not `inline` above the
  shell. Off Booster screens `load_global_notice_assets()` loads the static sheet
  with the primitives sheet.
- A dismissible notice's `.notice-dismiss` button, Booster's own or the one core's
  `common.js` appends, is a direct child of the host and sits in the Notice's
  close-icon slot (third grid column, first row) instead of core's absolute
  position over the text.
- The content host stays a `div` and becomes the Notice description, so producer
  scripts find `[data-ran-booster-ui-notice-content]` and its paragraphs unchanged.
- `RAN\Admin\Component\NoticeIcon` prints the intent icon after the content, as
  `Notice.Root` does: `print_intent()` in templates, `markup()` in string-built
  output. Where the intent comes from a `notice-*` tone, the `is-*` class, the
  icon and `data-ran-booster-ui-notice-intent` are derived from the same value.
- `ran-booster-cards.js` never mounts a `.ran-wp-ui-notice`, so there is no
  flash and no focus move, and the page looks the same without JavaScript.
- A static notice has no `Notice.List` live region and no
  `data-wp-ui-notice-politeness`. Announcements go through `wp.a11y.speak`.
- The fallback rules in `25-admin-primitives.css` and
  `ran-booster-notice-compat.css` exclude `.ran-wp-ui-notice`; the compatibility
  sheet stays until Booster requires the WordPress 7.1 `wp-theme` styles.

Hosts that stay mounted by `ran-booster-cards.js`:

| Host | Why |
| --- | --- |
| Unsupported Multisite notice (`RAN/Runtime/UnsupportedMultisiteBootstrap.php`) | The safety bootstrap loads no Booster styles or scripts; it renders as a core notice |
| Plugins failure rows (`RAN/Admin/ManagedPluginFailureRows.php`) | Their screen loads the Notice assets only while the failure notice is unacknowledged, and `native-update-warning.js` rewrites the row |

A static notice whose intent a script changes in place (the Transporter export
message and preview notice, the repository branch check, the credential
validation result and the bulk update queue summary) changes it with
`window.ranBoosterNotice.setIntent( host, intent )` from `ran-booster-notice.js`.
The helper sets the `is-*` class, core's `notice-*` class and
`data-ran-booster-ui-notice-intent`, and replaces the icon with a clone from the
one `<template id="ran-booster-notice-icons">` that
`NoticeIcon::print_template()` prints after the shell on the screens with such a
host; neutral has no icon. It only redraws: announcements stay with
`ranBoosterAnnounce` and focus with the calling script. Notices that scripts
build from strings or templates are still mounted. The signed
interaction outcome notice and the extension update warning are static on Booster
screens only, which always load the static sheet; elsewhere they keep the mounted
markup.

### Status badges

Status and label pills are the public Badge only. Every short status or label
pill is static markup from `RAN\Admin\Component\StatusBadge` (`print_tone()`,
`print_intent()`, `markup()` or `for_intent()`), drawn by the Badge's own CSS in
`ran-booster-wp-ui-static.css`. A script that changes one in place calls
`window.ranBoosterBadge.update()`.

- Intents: `none` for neutral or off, `stable` for ready or on, `medium` for
  needs attention, `high` for failed, `informational` for a deliberate choice
  that is neither, such as manual updates.
- A Badge carries its own text, so status is never colour alone. Coloured dots
  before text, tinted pill spans and middot status suffixes in headings are
  retired; the provider status summaries, package row states and the active
  update source tab use the Badge.
- Booster CSS may lay a Badge out (margin, width, wrapping, alignment) but does
  not repaint it. The owner-selected General settings spacing and accent are
  set through the `--wpds-*` properties the Badge reads.
- A sentence, a control state (a live selection count, a switch, a radio, a tab
  decoration), a step marker beside a heading or a `kbd` key cap is not a Badge.
- `AdminAssetContractTest` rejects pill and dot radii in Booster stylesheets
  outside a short allowlist of non-label shapes, rules that repaint the Badge,
  and the retired `ran-booster-status`, `ran-booster-status-dot`,
  `ran-booster-package-row__state-value`, `ran-booster-tile` and
  `ran-booster-pill` classes.

### Type scale

Booster uses the WordPress design-system type scale, the same one Jetpack Boost
renders: three text sizes plus a caption. The sizes are the `--wpds-typography`
tokens, so they follow the design system. Booster's own tokens in
`assets/ran-booster/00-foundations.css` are named after them.
`test_headings_take_their_size_from_the_shared_type_scale` fails on any raw size
outside the listed icon and numeral exceptions.

The `--wpds-*` tokens come from WordPress's `wp-theme` style, which exists from
WordPress 7.1. Booster supports 7.0, so when `wp-theme` is not registered it
loads a bundled copy of the same tokens, `assets/lib/ran-booster-wpds-tokens.css`.
`scripts/build-wpds-tokens.mjs` generates it from the `@wordpress/theme` release
that the pinned `@wordpress/ui` depends on (`pnpm ui:build`, checked by
`pnpm ui:check`), and `tests/assets/wpds-tokens.test.mjs` fails if any stylesheet
reads a token the bundle does not define, including the component styles
embedded in the bundled scripts. WordPress 7.1.4's own set is older than the
bundle (167 tokens against 171); the few newer tokens are read only with a
neutral-surface fallback, so nothing breaks there. Without a token source the type scale,
colour and spacing are all undefined and the screens render unstyled, so any
change to how the styles load must keep a source on WordPress 7.0.x.

| Role                                              | Token                          | Size / weight |
| ------------------------------------------------- | ------------------------------ | ------------- |
| Page title, count numerals                        | `--ran-booster-font-size-xl`   | 20px / 600    |
| Card title, prominent disclosure header, `h2`/`h3` | `--ran-booster-font-size-lg`   | 15px / 600    |
| Sub-heading (`h4`-`h6`), table header             | `--ran-booster-font-size-md`   | 13px / 600    |
| Compact disclosure header, labels                 | `--ran-booster-font-size-md`   | 13px / 500    |
| Body and help text                                | `--ran-booster-font-size-md`   | 13px / 400    |
| Caption                                           | `--ran-booster-font-size-sm`   | 12px / 400    |

Weights are `--ran-booster-font-weight-semibold` (and `-heading`, the same value),
`-medium` (500) and the default 400. Bare `h2` to `h6` elements inside
`.ran-booster-admin` take their size from this scale, so unclassed headings no
longer fall back to WordPress's 1.3em. Package names are 13px/600 and their
Deployment activity disclosure header is 13px/500, so identity stays the scan
anchor of its row; `test_package_row_disclosure_header_never_outranks_the_package_name`
asserts that relationship through the tokens. Icon glyph sizes and the dialog
close icon are the only raw-size exceptions, plus the notice compatibility
sheet, which loads only where the design-system tokens are absent.

### Corner radius

Every corner comes from a token in `assets/ran-booster/00-foundations.css`, and
every framed surface takes its radius from the `surface-radius` block in
`assets/ran-booster/25-admin-primitives.css`, not from its own rule.

| Role                                         | Token                          | Value |
| -------------------------------------------- | ------------------------------ | ----- |
| Card, top-level frame, disclosure, dialog    | `--ran-booster-radius-card`    | 8px   |
| Frame nested inside a Card or disclosure     | `--ran-booster-radius-inset`   | 4px   |
| Control, selectable choice tile, inline code | `--ran-booster-radius-control` | 2px   |
| Status dot, radio mark                       | `--ran-booster-radius-circle`  | 50%   |
| Switch track                                 | `--ran-booster-radius-pill`    | 999px |

- The card and inset values are the WordPress design-system `lg` and `md`
  radii. A nested frame takes the smaller inset radius so its corners sit
  inside the outer Card's curve.
- Badges and notices are drawn by the public Badge and Notice CSS (8px); Booster
  does not restate their radius. Buttons keep their own button rules.
- A native `.ran-booster-disclosure` fallback takes the Card radius, so a
  disclosure looks the same before and after the public Disclosure mounts and
  without JavaScript.
- A choice tile (`.ran-booster-source-choice`, the repository picker modes and
  the Transporter Export/Import modes) is a control and takes the control radius.
- `--ran-booster-radius-surface` (3px) is retired for new work. It stays defined
  only for one legacy rule with no rendered consumer.
- `test_every_framed_surface_takes_its_radius_from_the_shared_block` fails on a
  full border outside the shared block, and
  `test_every_radius_value_comes_from_a_shared_token` fails on a raw length or
  the surface radius. The exceptions are the admin shell sheet, which keeps its
  own fallbacks; the notice compatibility sheet, which loads only where the
  design-system tokens are absent; the status dot; and that legacy rule.

### Buttons

A server-rendered button on a Booster screen is the public Button drawn as
static markup with its own CSS from `ran-booster-wp-ui-static.css`, never
WordPress core's `.button`. Markup takes its classes from
`RAN\Admin\Component\ButtonClasses`, so the composition cannot drift:

```php
<button type="submit" class="<?php echo esc_attr( ButtonClasses::primary() ); ?>">…</button>
<a class="<?php echo esc_attr( ButtonClasses::secondary( false, true ) ); ?>" href="…">…</a>
```

- `primary()` is brand solid, for the one main action of a form or panel;
  `secondary()` is neutral outline, for every other action.
  `for( $tone, $variant )` takes `brand`, `neutral` or `danger` and `solid`,
  `outline` or `minimal`.
- The default is the library's 40px, the height of WordPress 7.1's own buttons.
  Pass `$compact` (32px) only where the button sits with 32px controls: the bulk
  action Apply and the provider table pagination. At 782px and below a compact
  Button grows to 40px, as wp-admin grows the controls beside it.
- Pass `$anchor` for an `a`. It takes the library's anchor reset instead of the
  button reset, and `10-buttons.css` gives it the Button's colour, corners and
  box-sizing, which wp-admin's unlayered link rules would otherwise take over.
  A disabled anchor sets `aria-disabled="true"`.
- The element must not also carry `button`, `button-primary`,
  `button-secondary` or `button-delete`. Core's rules are unlayered and win
  every property they set over the layered Button.
- `danger` is Booster's own tone: `ran-booster-button--danger` in
  `10-buttons.css` sets only the Button's colour custom properties, on brand
  solid (destructive confirmations) or neutral outline (the arming step). It
  takes the design system's interactive error colours, so the solid label
  keeps 4.5:1 contrast at rest and when active; disabled, it draws as the
  shared disabled Button.
- Native `disabled`, `aria-disabled="true"` and a disabled fieldset all draw the
  disabled Button; the stylesheet bridges them to the library's `data-disabled`.
- The Button sets `display` inside the layer, so `10-buttons.css` restores
  `[hidden]`, and keeps a Button at its label's width in flex rows.
- Busy update buttons keep their hooks (`button-update-package`,
  `ran-booster-enhanced-mutation__submitter--busy`,
  `ran-booster-portability__progress-button`, `ran-booster-update-is-active`):
  the stripes are Booster's, not the library's loading spinner.
- `test_converted_markup_draws_buttons_with_the_static_button_and_never_core_classes`
  scans every view, PHP class and admin script and fails on a core button class.
  Its allowlists name what stays native and why: the generated Shell header
  actions, global notices that also render on core screens without the static
  stylesheet, the icon-only copy controls and password tools, the segmented
  release track options, and the no-JS fallback for the mounted Skip onboarding
  button. `button-link` is Link, not Button, and is out of scope.

#### Action placement

Where an action group sits follows the surface it finishes. All positions use
logical properties (`justify-content: flex-start`, `margin-inline-*`,
`text-align: start`), so the layout mirrors in right-to-left languages. This
matches the WordPress admin form convention of the primary submit button under
the form at the start edge, keeps actions on the reading path under the content
they apply to, and keeps Save and Apply in one predictable place.

1. Actions that finish a page, form or Card are start-aligned and sit below the
   content they apply to. Where the surface is a Card, the actions live in a
   full-width footer band: a divider above, 16px of block padding, the Card's
   inline inset, and no bottom padding on the content area, so the band ends
   flush with the Card's bottom edge. The primary action comes first, then the
   secondary actions, 12px apart. Actions that belong to a disclosure are that
   disclosure's footer band: the last child of its body, using the body's 16px
   inset and ending flush with the disclosure border, so they open and close
   with it.
2. A heading-row action sits at the end of the heading row, vertically centred
   on the heading block (title and description together) and aligned to the
   content column's end edge. Where the row holds only a title, as on Managed
   Plugins and Themes, the block is the title row. At 782px and below it stacks
   under the title, start-aligned.
3. Dialog and modal footers keep end-aligned actions, as the WordPress Modal and
   platform dialogs do. This is the only end-aligned page-level exception.
4. Table row actions stay in the table's Actions column at the end of the row.
   Toolbar controls (filters, search, bulk actions, item count and pager)
   follow the collection frame contract, not this rule.
5. A button never stretches to fill its action group on wide screens. At 782px
   and below a group may stack and its buttons may fill the width.
6. A danger action (Delete capture, Remove webhook) comes last in the
   start-aligned row, in its danger outline style.
7. A single-field Card whose Save sits beside its field (Public repository
   lookup) has no band: an action that belongs to a field stays beside it.
8. State-dependent actions stay outside the band standard: Settings Secure
   credential storage setup, adopt and reset, and the Activity remediation
   forms. The Extensions card Activate stays beside the description, the
   WordPress plugin-card pattern.
9. Onboarding step actions stay in their step: they are navigation links to
   other screens, not a form that finishes the Card, and a band would add
   visual weight to a guidance list.
10. Release workflow forms (Assess, Open draft pull request, Check pull request
    outcome) stay in their disclosure body: each button belongs to the
    credential field in its own form, several forms can show at once, and there
    is no single primary action to finish the disclosure.

| Surface | Screen | Placement |
| --- | --- | --- |
| Card footer | Edit plugin and theme, Save plugin settings and Install another | Start, primary first |
| Card footer band | Settings, History Save changes | Start, in the footer band |
| Card footer band | Transporter Open and Create | Start, in the footer band |
| Card footer band | Install plugin and theme, Package operation | Start, primary first; shown only for a Branch source |
| Card footer band | Troubleshooting Logging capture actions | Start, Delete capture last |
| Card footer band | Troubleshooting Diagnostics and Support report | Start, primary first |
| Disclosure footer band | Repository Webhook management, inside the Webhook setup disclosure | Start, Remove last |
| Heading row | Managed Plugins and Themes, Install another | End of the heading row |
| Heading row | Overview, Return to onboarding | End of the heading row |
| Dialog | Add credential, Add webhook secret, Delete confirmation | End, the exception |
| Table row | Managed packages and provider credentials | Actions column, end of row |

Exceptions are deliberate and small: dialogs end-align their actions, and row
actions stay in the Actions column. A new screen that needs another exception
changes this section first.

#### Source switch bar (state row)

The Update source tabs on Edit plugin and theme use one state row to move
between Branch and Releases (`.ran-booster-source-switch-bar`). The message
sits at the start of the row and one action at the end. It is a state row, an
intentional exception to start-aligned action placement, because it reports a
state rather than finishing a form. It wraps on narrow widths.

- The bar shows only on the tab of the source that is not active, directly
  under the pane description. The active source's tab never shows it; the green
  dot on the active tab label is the active signal.
- Success makes the target source active, so the bar is gone and the success
  notice takes its place at the top of the pane. An error keeps the bar, with
  the notice above it.
- An unavailable action stays visible, `aria-disabled="true"` (never the native
  `disabled` attribute, so it stays focusable), with a reason message in the
  row and the reason tooltip on hover and keyboard focus. It is never hidden.

A disabled or unavailable brand solid button draws as a white fill with a 1px
`--ran-booster-control-border` stroke (3:1 or more against white and the grey
surface), muted text at 4.5:1 or more on the fill, `cursor: not-allowed`, no
opacity and no hover change. Meaning never rests on a grey fill or opacity.

## Add-on generation and service delivery

An administration add-on must check the exact Add-on API generation before
attaching its callbacks:

```php
if ( ! defined( 'RAN_BOOSTER_ADDON_API_VERSION' )
	|| 17 !== RAN_BOOSTER_ADDON_API_VERSION ) {
	return;
}
```

Add-on API 17 publishes only the named service documented for its surviving
ready action. Core does not deliver an add-on logging facade, generic resolver
or container.
Provider API 14 remains a separate contract. Provider add-ons must continue to
perform the exact checks described in the
[Provider extension contract](provider-extension-contract.md).

Core fires the portability service-ready action once on `plugins_loaded` at
priority 100, after provider registration and before it resolves the dashboard
and request dispatcher:

```php
do_action(
	'ran_booster_portability_ready',
	$portability
);
```

The argument is a `\RAN\AddOn\Portability\PortabilityFacade`. This is a
request-local delivery point for one exact, already-public safe facade. It is
not a view hook or service locator. An add-on must attach its listener during
normal plugin loading, retain the supplied facade for the current request only,
and perform no remote work in the ready callback. A late listener is not
replayed. Core catches a failed ready listener and continues without exposing
its exception to the administrator.

Release administration is bundled in Core. Add-on API 17 does not publish a
release-tracking ready action, a prospective-release ready action, or a
prospective-release version marker. Core's fixed release controls call its
internal typed coordinators after resolving the selected provider's exact
release facets. A plugin must not treat those internal coordinators as a public
service-delivery API.

Portability API 4 is an independently versioned adoption-only contract for a
trusted source bridge. Its consumer checks exact Portability API 4, not Add-on
API 17. The separate
[Portability API contract](portability-api.md) documents its candidate, nonce,
review, Apply, source-ownership, recovery, and cleanup boundaries.

Core exposes three zero-argument presentation hooks for removable migration
bridges:

- `ran_booster_overview_render_migration_prompt` after the Overview's primary
  setup choices.
- `ran_booster_portability_render_migration_modes` after Transporter's Create
  and Open choices.
- `ran_booster_portability_render_migration_flows` after Transporter's native
  Create and Open flows.

A source bridge renders nothing unless it can verify its exact supported source
evidence. It owns and escapes a bounded prompt plus one matching mode/flow pair;
Core remains source-agnostic and exception-contains each hook independently.
These hooks are not a registry, dashboard tab or whole-view replacement, and
they supply no Core service or data.

Core's release-management controls obtain each mutation's purpose-specific
nonce action from the internal `ReleaseTrackingFacade`. The action is bound to
the operation, package type, exact identifier and current source revision. The
controls create the WordPress nonce, render it only in the matching form and
verify the protected `admin_post_*` request before passing the same nonce to the
coordinator. Core independently derives the action again and rechecks the
administrator capability and nonce before it considers package identity,
eligibility or a source transition. Obtaining an action string or rendering a
nonce authorizes nothing.

Release tracking uses explicit channels:

```php
$preflight_nonce = wp_create_nonce(
	$release_tracking->nonce_action(
		'preflight',
		$type,
		$identifier,
		$expected_source_revision,
		$channel
	)
);
$preflight = $release_tracking->preflight(
	$type,
	$identifier,
	$expected_source_revision,
	$channel,
	$preflight_nonce
);
$release_tracking->enable(
	$type,
	$identifier,
	$expected_source_revision,
	$channel,
	$nonce
);
$release_tracking->change_channel(
	$type,
	$identifier,
	$expected_source_revision,
	$channel,
	$nonce
);
```

`preflight()` is the read-only counterpart to enablement's exact verifier. It
requires the current administrator capability and a nonce bound to the package
type, identifier, source revision and channel. It accepts only an eligible
branch-managed plugin or theme at that exact revision, performs no source
transition, cache invalidation or package mutation, and returns `null` when
authorization or binding cannot be proved. A non-null bounded result reports
the existing verifier outcome; it does not authorize later enablement.

The internal typed contract is
[`ReleaseTrackingFacade`](../RAN/AddOn/ReleaseTracking/ReleaseTrackingFacade.php),
whose `preflight()` return type is `?ReleaseTrackingPreflight`, together with
the immutable
[`ReleaseTrackingPreflight`](../RAN/AddOn/ReleaseTracking/ReleaseTrackingPreflight.php)
value object. Its stable result codes are `ready`, `release_unavailable`,
`invalid_release_assets`, `preflight_unavailable`,
`release_version_mismatch`, `release_header_missing`,
`release_header_invalid` and `release_archive_unreadable`. Consumers read only
`code()`, `ready()`, `package_root()`, `latest_version()`, `release_url()`,
`release_tag()`, `package_header_version()` and `reason_code()`. The stable `code()`
remains the workflow category; `reason_code()` is an optional, allowlisted,
display-safe cause for actionable diagnostics and never contains provider
response bodies, URLs, paths, tokens or exception messages. Unknown or unprovable
authorization, package, revision, channel or result binding fails closed as
`null`; it must not be interpreted as a new result code or as permission to
enable release tracking.

`change_channel()` changes future eligibility only. It increments the source
revision, resets Automatic to Manual and invalidates native update state; it
does not install, downgrade or alter package files. `ReleaseTrackingStatus`
exposes the canonical `stable` or `prerelease` channel.

Prospective installation is an optional Core control. Core exposes it only when
one registered provider aggregate supplies the complete release capability set
required by the operation. It does not publish a prospective-release marker or
deliver the internal facade to another plugin.

The internal `ProspectiveReleaseFacade::API_VERSION` is **10** for destination selection
continuity and explicit initial installation intent; this is not a public plugin-delivery marker.

The facade accepts exactly:

```php
$provider_codes = $prospective->supported_provider_codes( $type );
$prospective->list_candidates( $type, $repository, $channel, $nonce );
$prospective->inspect( $type, $repository, $release_id, $tag, $channel, $nonce );
$prospective->install(
	$type,
	$repository,
	$release_id,
	$tag,
	$expected_fingerprint,
	$channel,
	$nonce,
	$provider_expectation
);
```

`install()` and `nonce_action()` additionally accept optional `$expected_identifier`
and `$initial_policy` arguments, defaulting to `null` and `DeploymentPolicy::MANUAL`.
Only Manual and Disabled are supported; Disabled requires a valid explicit plugin
or theme identifier. Both values are bound into the installation nonce. Non-install
nonce operations reject nondefault installation intent. Existing dashboard calls
retain Manual adoption.

The acquired identifier must match the expected identifier before updater locking,
artifact handoff or filesystem mutation. A mismatch returns
`package_identity_mismatch` and still discards the acquisition. Successful adoption
persists the requested policy through the existing verified release repository
operation. This does not enable Blueprint release execution or exact artifact
selection. A future Blueprint caller must also compare inspected package-root and
metadata-file configuration; a theme identifier alone does not prove its metadata
filename. Installation remains inactive and this initial selection is no update lock.

`supported_provider_codes()` returns the bounded complete-product provider list
for `plugin` or `theme` using request-local configuration only. It performs no
repository resolution, credential access, remote request, discovery or
mutation. Core keeps the prospective source unavailable when the selected
provider is absent from that list. The complete-product list requires
the exact listing, inspection, acquisition, metadata and native-target facets.
Each operation also checks its purpose-specific facet before resolving the
repository or invoking provider work and returns `unsupported_provider` when it
is absent.

`$channel` must be exactly `stable` or `prerelease`; Core rejects any other
value before resolving credentials or invoking the updater. The channel is
carried through candidate listing, exact inspection and exact acquisition, and a
successful installation persists it for subsequent native WordPress update
registration. `stable` accepts stable releases only. `prerelease` accepts
prereleases and a later stable promotion. Stable remains the default for
existing saved configuration.

All three operations recheck `manage_options`, the applicable WordPress install
capability and the operation/type nonce derived from `nonce_action()`.
Candidate listing returns at most eight display-safe summaries without
downloading a ZIP. Inspection downloads, validates and discards the exact ZIP,
then returns bounded metadata and an opaque `v2:` continuity fingerprint.
Installation freshly reacquires the exact release, verifies the fingerprint and package,
refuses an already installed, managed or active target, delegates mutation to
WordPress Core, verifies that the new install remains inactive, and reports
adoption separately. The facade never exposes
credentials, signed URLs, internal descriptors, archive paths or provider
responses. Selections retained from the earlier `v1:` contract must be
inspected again; Core does not translate or accept the old fingerprint. A new
inspection is also required after the release or its archive changes. A partial provider capability set exposes no prospective choice and
grants no repository, credential, remote, download, or mutation authority.

## Add-on dashboard tabs

An add-on may register one self-contained dashboard tab during the existing
registration lifecycle:

```php
add_action(
	'ran_booster_register_admin_tabs',
	static function ( \RAN\Admin\AdminAddOnRegistry $registry ): void {
		$registry->register(
			new \RAN\Admin\AdminAddOnTab(
				'example-addon',
				'example',
				__( 'Example', 'example-addon' ),
				static function ( \RAN\Admin\AdminAddOnContext $context ): void {
					// Render escaped, add-on-owned administration markup.
				},
				7,
				7,
				7,
				7
			)
		);
	}
);
```

Core invokes the registration action on `plugins_loaded` at priority 100 and
then seals the registry. It rejects unsafe or duplicate keys, more than one tab
from the same add-on, late registration, incompatible API bounds and requests
for undeclared facades. Registered tabs appear after Core and provider tabs.

An add-on translates its visible tab label and rendered markup through its own
text domain before it registers or renders them. Core projects that already
localized display data and retains its own escaping boundaries; it never passes
add-on copy through the `ran-booster` domain.

When selected, Core creates an immutable `AdminAddOnContext` only after the
`manage_options` check. The context supplies the tab key, canonical Booster
URL, `site` or `network` administration scope, Core/Add-on API versions,
and only the explicitly requested allowlisted facade. It
does not expose the service container, repositories, credentials, secrets or
arbitrary Core models. Core contains a renderer failure and keeps the remaining
dashboard usable.

Release management and repository webhook-management placement are Core code,
not add-on composition consumers. Bitbucket uses the provider-tab contract.
This does not remove the public tab capability for other add-ons.

## Fixed Extensions page

Core owns the **RAN Booster > Extensions** submenu at
`ran-booster-extensions`. The `manage_options`-gated page renders the remaining
release-bundled cards for RAN-maintained extensions in deterministic order. Its catalogue, local
placeholder images, installed-plugin reads, compatibility labels and failure
shell are all Core-owned.

The page uses WordPress plugin-card presentation language without invoking the
WordPress.org Plugins API. Rendering the catalogue performs no remote request,
mutation, entitlement check or add-on callback. Each card names the qualified
satellite release and API tuple it represents. Core maintainers update that fixed
metadata in a Core pull request after the satellite release is qualified; the
page never discovers releases or polls repositories. More Details opens the
card's server-rendered details section in the shared WordPress UI Dialog and
returns it to the page on close; without JavaScript the fragment link reveals
the same section in place. The section links to the selected release page.

### Extension details dialog

The dialog follows the WordPress plugin-information layout: a banner header
with the extension name on a dark bar and the close button at its top end, a
tab row, the tab panel beside a key-facts sidebar, and a footer with the
card's action. `ranBoosterOpenDialog` builds it from server-rendered nodes in
the hidden details section, moving each into place and back on close:

- `…-banner` becomes the header behind the Dialog title;
- each `…-section-<key>` listed in the section's
  `data-ran-booster-dialog-sections` becomes a public WordPress UI Tabs panel,
  labelled by its `data-ran-booster-dialog-section-label`;
- `…-aside` becomes the sidebar column, which stacks under the panel at 782px
  and below;
- `ran-booster-extension-action-<id>`, the card's own Install or Activate form
  or its state, moves into the Dialog footer, so the dialog submits the same
  nonce-bound form as the card.

The view declares the tabs in `$details_sections`. One **Details** tab is
intended for now; a new section adds an entry there and its panel markup, and
renders a second tab with arrow-key, Home and End navigation without script
changes. A single section still renders a tab list with one selected tab that
labels its panel. Without JavaScript the section shows the banner, title,
summary, content and sidebar in place, with no tab row.

The sidebar lists only catalogue facts: the selected release version, the
installed version when one is installed, availability, maturity, state,
compatibility and the source repository, then the documentation, release and
support links. It never shows compatibility ranges, authorship, ratings or
install counts that the catalogue does not hold.

#### Banner artwork

Banners follow the WordPress.org plugin banner sizes. Drop the files in
`assets/extensions/<extension-id>/`:

| File | Size | Use |
| --- | --- | --- |
| `banner-772x250.<webp\|png\|jpg>` | 772 × 250 | Required for a banner |
| `banner-1544x500.<webp\|png\|jpg>` | 1544 × 500 | Optional high-density variant |

Core looks for WebP, then PNG, then JPEG, and uses the high-density file only
when the 772 × 250 file exists. The image renders with an empty `alt`, because
the title bar carries the name, and with explicit dimensions so it does not
shift the layout. The frame keeps the 772:250 ratio, caps its height at 250px
or a third of the viewport, and crops with `object-fit: cover`, so keep text
and logos inside the central area and clear of the lower-left title bar and
the upper-right close button. Phones show at least a 120px-tall slice.

Without artwork the banner draws the Booster shell gradient from design tokens
with the extension icon, which hides on phones. No artwork ships in the
repository until marketing supplies it.

After provider registration, Core may register an installed, active or inactive
catalogue extension for manual native updates only. The shared updater must first
offer its descriptor-admission capability; Core then re-reads the same catalogue
entry and accepts only its exact version, digest, canonical URI, repository and
basename. Any incompatible, absent or already Booster-managed package loses its
offer. A missing capability keeps the catalogue manual and shows a bounded notice;
it creates no registry, adoption, persisted verdict or installer path.

An administrator with plugin-install permission may explicitly install a
compatible, absent extension. The nonce-protected POST selects only an ID from
the fixed catalogue; it does not accept an arbitrary package URL. Core downloads
the selected release archive and checks its pinned digest, package identity and
compatibility before handing it to the WordPress plugin installer. Existing
destinations are not overwritten. Core checks the installed result and attempts
to remove the temporary archive, and reports an uncertain result when it cannot
confirm the outcome or cleanup. Installation leaves the extension inactive;
activation is a separate administrator action. The page stores no sponsorship or
entitlement state.

Installed and active state comes only from local WordPress plugin state. Exact
runtime compatibility remains owned by each installed extension's fail-closed
API guard. The install action adds no public filter, facade, registry or
entitlement state, and the local stylesheet is limited to the Extensions screen
hook.

## Provider repository surface

Core owns and renders the provider repository table. Its internal webhook
controls enrich rows and render the selected-repository panel only when the
selected provider resolves both `RepositoryWebhookFitness` and
`RepositoryWebhookManagement`, plus `WebhookNormalizer`, to the same registered
aggregate. Core validates the resulting rows and preserves the fixed
`core:webhook-management` action. Providers and administration add-ons may
extend those normalized rows through one structured filter:

```php
$rows = apply_filters(
	'ran_booster_provider_repository_rows',
	$rows,
	$provider_code,
	$branch_projections,
	$return_url
);
```

Callbacks return the incoming keyed row list. Existing rows and Core-owned
fields are immutable. A callback may append bounded `details` and namespaced
structured `actions`; it must preserve the existing detail prefix and every
Core action. Only `core:webhook-management` may change its `url`, `disabled`
and `described_by` state. The branch projection map is keyed like the current
rows and contains only bounded local repository, package-policy, endpoint,
eligibility, reason-code and secret-coverage facts. `$return_url` is the
canonical selected-repository or repository-list destination.

New rows are review-only history. Their key must be namespaced, `historical`
must be `true`, and `provider_code` must match the selected provider. They
cannot claim `core:webhook-management`; Core removes their detail and review
URLs, then renders bounded evidence and actions inline. A row may contain at most 20 details. Detail keys
and labels are limited to 96 characters, values to 255, and optional date and
state values to 64; tones use the fixed Core set and `recorded` is Boolean.
Historical actions are link-only. Other historical fields
are normalized through the same bounded row schema before rendering.

Core contains a throwing callback, a non-array result or any invalid rewrite.
It logs the extension failure and renders the already normalized Core and
webhook rows. The filter supplies no provider HTML or selected-repository panel
callback.

Absent webhook assistance omits its optional setup controls. Claimed but partial
or incompatible facets retain the stable disabled component and a configuration
notice, without operation authority. A complete non-GitHub provider receives the
same fixed Core placement; its bounded metadata supplies the provider code and
label, while its facet implementation owns remote calls, credentials and
provider-specific remediation. Branch deployment and manual guidance do not
depend on the optional helper.

Provider status summaries describe local configuration and supplied observations,
not remote capability, permission or health. Empty optional release usage and
workflow aggregates may be omitted; missing observations never mean zero findings.
Unreadable or incomplete inventory remains explicit, and retained records and
existing recovery links remain available independently of current facet support.
Receiver readiness, signing profiles and remote webhook management are separate
facts. An absent or failed rendering callback does not establish provider incapability.

## Package screen anatomy

Plugin and theme installation and management use the same information
architecture. Core renders, in order: page identity, **Repository
configuration**, **Advanced settings** with **Package source**, source-specific
settings and readiness, **Package operation**, any bounded management-only
sections, and **Danger zone**. Add-ons contribute inside those Core-owned
regions; they do not create a parallel installation or management layout.

The lifecycle changes available controls, not their order:

- prospective published-release installation selects and inspects one exact
  release; a managed package instead selects the Stable or Preview track for
  future WordPress updates;
- branch and published-release readiness differ, but occupy the same
  source-specific position;
- saved-package workflow assistance, status, reinstall and source-transition
  controls appear only when their required package state exists; and
- the management summary and Danger zone have no installation equivalent.

Plugin and theme screens may differ only where WordPress package identity,
activation or theme-state rules require it. Their headings, regions and source
flow remain shared.

## Managed-package surfaces

Core exposes display-safe package data through
`\RAN\Admin\AdminPackageProjection`. Its accessors are:

```php
$package->type();             // "plugin" or "theme".
$package->identifier();
$package->display_name();
$package->provider_code();
$package->source();           // "branch" or "release_asset".
$package->source_revision();
$package->subdirectory();     // Repository subdirectory, or "" for the root; at most 255 characters.
$package->deployment_policy(); // "disabled", "manual" or "automatic".
$package->settings_url();
```

An add-on may add bounded badges and status text to the managed Plugins or
Themes table:

```php
$rows = apply_filters(
	'ran_booster_admin_package_management_rows',
	$rows,
	$surface,
	$package_projections
);
```

`$surface` is `plugin` or `theme`, and `$package_projections` is keyed by package
identifier. Returned rows may address only those identifiers. Each row accepts
a bounded `status` string and a `badges` list whose tones are `neutral`, `ok`,
`pending`, `warning` or `error`.

Core obtains structured actions separately for each package:

```php
$actions = apply_filters(
	'ran_booster_admin_package_management_actions',
	$actions,
	$surface,
	$package
);
```

`$package` is an `\RAN\Admin\AdminPackageProjection`. The filter may append
namespaced link or POST actions. Core validates and escapes them before
rendering. Core package views do not contain release-specific action keys,
labels, forms, nonce values or handlers. The Core-owned release facade only
defines the nonce action scope that both authorization layers must verify.

An add-on may append a bounded settings section to an individual managed
package:

```php
do_action(
	'ran_booster_admin_package_settings_sections',
	$package,
	$settings_url
);
```

`$package` is an `\RAN\Admin\AdminPackageProjection` and `$settings_url` is its
canonical settings URL. Core output-buffers this action and contains failure at
the section. The add-on owns escaping inside its markup and mutations belong in
its own `admin_post_*` handlers.

Package-source add-ons may additionally extend Core's source selector through
two narrow hooks:

```php
apply_filters(
	'ran_booster_admin_package_source_choices',
	$choices,
	$mode,
	$type,
	$package,
	$page_url
);

do_action(
	'ran_booster_admin_package_advanced_source_sections',
	$mode,
	$type,
	$selected_source,
	$package,
	$page_url
);
```

The advanced action is only for source-specific fields such as branch,
subdirectory, release track and exact candidate selection. Add-ons may filter
`ran_booster_admin_package_advanced_source_summary` with the same mode, type,
selected source and projection arguments, but must return one bounded
plain-text summary.

Core's `RepositoryWebhookManagementControls::render_package_webhook_setup`
callback remains registered on `ran_booster_admin_package_advanced_source_sections`
at priority 30 as an intentional no-op. The Edit page no longer renders a
Webhook setup disclosure (removed in beta.44); webhook management lives on the
repository page. The callback currently renders nothing and is under review for
beta.45 (#772).

Add-ons may filter the structured advanced-source summary projection:

```php
apply_filters(
	'ran_booster_admin_package_advanced_source_summary_projection',
	array(
		'heading' => __( 'Published releases', 'ran-booster' ),
		'badges'  => array(
			array( 'label' => __( 'Stable', 'ran-booster' ) ),
		),
		'status'  => __( 'Active', 'ran-booster' ),
	),
	$mode,
	$type,
	$selected_source,
	$projection // ?\RAN\Admin\AdminPackageProjection
);
```

The baseline has a heading, a list of badge arrays with a `label`, and a
status. Core trims and strips tags. A heading is required and must be plain
text, non-empty and no more than 80 characters. Core keeps at most three
plain-text badge labels of no more than 255 characters each, and a plain-text
status of no more than 40 characters. An invalid or throwing projection falls
back to the Core baseline; invalid badge labels and status values are dropped.

Repository-scoped release workflow management belongs on the selected repository
page. Core composes `ReleaseWorkflowControls` directly with the repository
renderer; there is no provider HTML action or competing provider-rendered shell.
The optional `RepositoryReleaseWorkflowManagementV3` capability supplies bounded
immutable status, preview and result values to the current workflow helper. It is
the initial-only release-workflow contract in the API13 draft; the earlier API 1
facet was retired before 1.0 after the maintained-repository audit found no
current consumer. Core keeps
the heading, setup-status line, credential selector, labels and help links
stable, varying only values, notices and enabled states. Rendering uses local
evidence only.

Core validates the exact provider, repository, package and source revision before
each fixed operation. Package settings retain source, track, candidate selection,
installation and update-policy controls. Repository history uses normalized
`webhook` and `release_workflow` categories, not provider key prefixes or English
headings; only exact current summary projections contribute to review counts.
See the [workflow capability contract](provider-extension-contract.md#optional-release-workflow-management)
and the [initial-only V3 and current Provider API contract](provider-release-workflow-api.md).

## Structured administration actions

Provider-row and package-management actions use a keyed structure:

```php
array(
	'example-addon:refresh' => array(
		'label'         => __( 'Check published releases', 'example-addon' ),
		'type'          => 'post',
		'url'           => admin_url( 'admin-post.php' ),
		'hidden'        => array(
			'action'   => 'example_addon_refresh',
			'_wpnonce' => $nonce,
			'package'  => $package_identifier,
		),
		'disabled'      => false,
		'external'      => false,
		'described_by'  => '',
		'screen_reader' => $package_identifier,
	),
);
```

Keys must be bounded and namespaced with exactly one colon. `type` is `link` or
`post`. Links must not contain hidden fields. POST actions must target exactly
`admin_url( 'admin-post.php' )` and include bounded scalar `action` and
`_wpnonce` hidden values. Core fixes the method to POST and escapes every
rendered value.

Normalization proves only that Core can render the control safely. The
add-on-owned handler remains responsible for its first capability, nonce,
expected identity, expected source revision and operation validation before
calling a mutation. The release facade independently repeats the capability
and nonce checks against its Core-owned action scope before mutating anything.

Core automatically enhances normalized structured POST actions with its shared
busy and feedback lifecycle. Structured links remain navigation, and raw forms
rendered by an add-on are not intercepted. An add-on-owned raw form that needs
an allowlisted partial refresh must use the
[Enhanced administration interaction API](enhanced-admin-interactions.md)
explicitly.

## Documentation filters

Documentation uses append-only structured WordPress filters:

```php
$sections = apply_filters(
	'ran_booster_documentation_sections_after_provider_' . $provider_code,
	array(),
	$documentation_url,
	$scope,
	$provider_metadata
);

$sections = apply_filters(
	'ran_booster_documentation_sections_before_about',
	array(),
	$documentation_url,
	$scope,
	null
);
```

The dynamic provider filter runs immediately after the matching registered
provider's Core guide. Its optional fourth argument is the exact readonly
`\RAN\RepositoryProvider\ProviderMetadata` object published by that
registration, or `null` for callers that do not have provider metadata. An
add-on that needs to prove ownership may compare that object by identity with
the metadata it registered. It must not infer ownership from a provider code,
label, or reconstructed metadata. The general filter runs before Core's About
section and receives `null` as its fourth argument because it has no provider
owner.

`$documentation_url` is the canonical Booster Documentation URL and `$scope` is
`site` or `network`. Each callback accepts and returns the section list;
WordPress callback priority supplies ordering when more than one plugin uses a
hook. Existing callbacks registered for three accepted arguments remain
compatible; a callback that needs `$provider_metadata` must register for four.

Each appended section supplies a stable `id`, plain-text `summary`, string or
callable `content`, and optional Boolean `open`. Core owns the disclosure
markup and includes every valid rendered section in the page's On this page
index. IDs must be unique across the whole Documentation page; the first valid
section owns an ID and a later contribution using it is not rendered.
Documentation content must not contain forms, nonces, AJAX or REST handlers,
remote calls, asset enqueueing, settings or deployment operations.

Core validates every section, captures callable content, sanitizes it with the
normal `wp_kses_post()` allowlist minus the `id` attribute and renders it only
when non-empty. Contributed content cannot own nested IDs: the Core-owned outer
section `id` is the section's only page anchor. If a content callback throws,
Core discards the captured output, logs a redacted failure and displays one
local unavailable message.

Deactivating an add-on removes its contributions on the next request; every
Core route and administration surface remains usable.

The WP Pusher Migrator contributes `ran-booster-wp-pusher-migration` through the
general sections filter, in Reference. Other extensions must not claim this ID.
Core no longer reserves or pre-indexes it: after accepting contributions, Core
supplies a short discovery entry at the same anchor only if no valid article
owns it. An accepted unavailable-message article also suppresses that fallback.
Existing first-valid duplicate precedence and sanitization remain unchanged.
Deliver the Migrator guide before the Core ownership change; no compatibility
adapter or new documentation API is involved. The Documentation URL and fragment
remain unchanged when the extension is inactive, absent or incompatible.

### Documentation chapters

Documentation has one level of chapters containing articles. Core supplies
**Booster Core concepts**, one chapter per registered provider, and **Reference**.
The same chapter/article order appears in the side navigation and document.
Chapter headings are plain text, not links or disclosures. Existing article
anchors, the shared disclosure group and print behavior remain unchanged.
Requirements still follows Quick start, and About remains the last article.

An extension registers an additional chapter with this append-only filter:

```php
add_filter(
	'ran_booster_documentation_chapters',
	static function ( array $chapters, string $documentation_url, string $scope ): array {
		$chapters[] = array(
			'id'    => 'example-guides',
			'title' => __( 'Example guides', 'example-extension' ),
		);

		return $chapters;
	},
	10,
	3
);
```

The filter receives the chapter list, canonical Documentation URL and `site`
or `network` scope. Registration is request-local and must not perform remote
requests, read credentials or mutate settings. Use a plugin-prefixed stable
identifier: at most 128 bytes, starting with a lowercase letter and containing
at least two hyphen-separated lowercase alphanumeric segments. Titles are
trimmed, nonempty plain-text strings of at most 200 bytes; raw control
characters are rejected and rendered titles are escaped. Malformed definitions
are ignored. The identifiers `core`, `reference` and the `provider-` prefix are
reserved; the first valid custom definition owns a repeated identifier.

Add articles with the existing general filter and an optional chapter ID:

```php
add_filter(
	'ran_booster_documentation_sections_before_about',
	static function ( array $sections ): array {
		$sections[] = array(
			'id'      => 'example-first-guide',
			'summary' => __( 'First guide', 'example-extension' ),
			'content' => '<p>Read-only guidance.</p>',
			'chapter' => 'example-guides',
		);
		$sections[] = array(
			'id'      => 'example-second-guide',
			'summary' => __( 'Second guide', 'example-extension' ),
			'content' => '<p>More read-only guidance.</p>',
			'chapter' => 'example-guides',
		);

		return $sections;
	}
);
```

Custom chapters follow the provider chapters in registration-list order;
WordPress callback priority determines how callbacks build that list. Articles
retain their contribution order within their selected chapter. Only general
articles may select a registered custom chapter. Provider-hook articles stay
immediately after their matching Core guide in that provider's chapter,
regardless of a supplied `chapter` field. Their optional fourth argument still
carries the exact registered provider metadata object; general callbacks still
receive `null`, and three-argument callbacks remain compatible.

Missing, malformed, unknown or reserved chapter references fall back to
Reference, with existing general contributions in their original relative
order before About. Chapter assignment does not change collection precedence
for duplicate article IDs. Chapters with no accepted articles are omitted.
Core allocates heading IDs after accepting articles so chapter headings cannot
take over an existing article or internal anchor. Content callbacks run once,
including when one article supplies both the index and body; a callback failure
retains the article's assigned chapter and existing unavailable message. The
first requested open article is selected in final page order.

Deactivation removes an extension's definitions and articles on the next
request. If a surviving article refers to a removed chapter, it falls back to
Reference. No chapter preferences or registration records are stored. There
are no subchapters or nested article-registration records.

On older Core versions without the chapter filter, the filter has no caller
and an extra `chapter` field is ignored by existing article normalization, so
otherwise compatible articles render flat. An extension must still honor the
Core/Add-on API versions it declares; chapter support does not bypass admission
or require a change to those existing markers.

Prospective API9 carries the destination-local `provider_expectation` from exact
inspection to installation. GitHub uses Core's provider code, selection generation
and implementation fingerprint; unaffected providers carry null. Installation
requires this argument and an install nonce bound to the same context. Core checks
it before repository resolution or credential/provider work. Inspection rechecks
its captured selection before returning evidence. Review transport never invents
a current context or exports this destination authority.

### Interrupted GitHub native installation

The GitHub settings page exposes Core's protected removal recovery while an
uncertain native mutation blocks further installation or implementation switching.
It shows only the target type and installed identifier. Recovery requires both
`manage_options` and `activate_plugins`, a fresh review-bound nonce, and explicit
confirmation that the interrupted process stopped and the whole target was removed.
A missing plugin entry file alone is insufficient. Core does not delete files or
certify a retained installation through this action.

Recovery takes the existing updater lock without reclaiming an occupied lock,
verifies physical absence, disconnects only the reviewed management record, and
clears only the exact reviewed native marker after fresh storage and operation
checks. Ambiguous management or queued work within the affected plugin folder
refuses recovery. Selection generation, implementation approval and credential
material are preserved. Changed authority, uncertain storage outcomes, failed cache
invalidation or lost lock ownership do not produce a success notice.

Successful removal recovery does not verify cleanup of temporary files left by the
interrupted process. Current provider availability and fresh installation review
remain mandatory before another operation. Browser acceptance and installed
qualification against the feature-bearing immutable dependencies are separate gates.

## Fixed Settings destination

`ran-booster-settings` is the plain WordPress submenu after Extensions. It uses
the base shell with no selected horizontal item. General is one explicit Save
form; Core update checking and secure storage actions use separate forms/nonces.
There is no provider Settings tab registry or contribution API.

Settings owns the privileged storage presenter, manual instructions and local
operation failures. Overview retains its old storage anchor as a bounded
summary/link. Installation may show authorized configured locations without
starting authentication or recovery. No report may serialize that private
on-screen projection. See [Settings](settings.md) for operator behavior.
