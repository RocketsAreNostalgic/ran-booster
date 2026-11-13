# Booster feedback policy (#755)

This is the policy for how Booster tells a person what happened after they
clicked something. It is documentation only. It changes no runtime behaviour;
the delivery plan below says which pull request changes what.

Baseline: verified against `origin/main` at `719febfa` (1.0.0-beta.45). The
shipped behaviour it describes today is in
[Enhanced administration interactions](enhanced-admin-interactions.md) and
[Notice placement](notice-placement.md). Where this policy is stricter, those
documents describe the current state until the pull request named in the plan
changes it.

## Principle

The user's click defines scope. Anything a person starts and waits on gets the
same pattern, wherever it lives:

1. A pending state while it runs: a busy control, announced to assistive
   technology.
2. Success: a snackbar that dismisses itself.
3. Failure or any result that is not a success: an inline message next to
   where it is most useful. It persists and never auto-dismisses. A short
   snackbar pointer to it, with a Show details action, always accompanies it
   when the person started the action.
4. A neutral outcome that is not a failure, such as a check that was not run
   because updates are off, is an `info` snackbar that dismisses itself, with
   the longer explanation inline.

Conditions that exist without a click (credential expiry, background
deployment failures, secrets or database blockers) are not feedback and stay
where they are.

## Component

The snackbar host is the WordPress global `wp.components.SnackbarList`. It is
the single approved exception to the `@wordpress/ui` rule, because
`@wordpress/ui` 0.26.0 exports no Snackbar or Toast. The exact exception, scope,
versions checked and revisit trigger are recorded in the
[component package section of the composition
contract](admin-composition-contract.md#component-package-direction).
This is not package adoption. Booster owns the host, the timer and the queue;
the mechanics stay swappable so the component can be replaced if
`@wordpress/ui` gains a snackbar or toast.

## Rules

Each rule is written to be implemented and tested.

| Rule | Statement | Test |
| --- | --- | --- |
| R1 Scope | A user-initiated action that the person waits on follows the principle above. Scope is decided by the click, not by the page or the kind of control. | Every producer in the inventory is classified; a producer outside the inventory fails review. |
| R2 Confirmed success only | A success snackbar is shown only for an outcome the server confirmed (persisted or accepted). A click, an HTTP 200 or a generic success event is not confirmation. | Unit test: no snackbar without a closed success code carried by the confirmed response. |
| R3 Failure never snackbar-only | Every failure that can produce a snackbar pointer also renders an inline message beside the control, field or row it concerns. The inline message persists until the person resolves or dismisses it. | For each failure code that can reach the snackbar, assert the inline region exists in the same response. |
| R4 Failure never auto-dismisses | A failure or warning pointer has an explicit dismiss control and no timer. A pointer is the default for every user-initiated failure or non-success result; the inline message is the record. Success and info have the one timer. | Config test per kind: success and info have a timer, a pointer does not; every non-success result of a moved producer enqueues a pointer. |
| R5 One duration | Success snackbars use one universal duration, a single constant of 10 seconds. It is not set per call site and can be tuned later by changing that one value. | Static test: exactly one definition; no call site passes its own duration. |
| R6 Placement | Bottom of the viewport, horizontally centred in the content column beside the admin menu, as the inline notices fill that column; at 782px and below, centred in the viewport. Width is at most 600px and keeps a gutter of at least 16px each side. Positioning uses logical CSS properties so RTL mirrors it. It never moves the layout and never depends on the Admin Shell's height. Centring is an owner decision (#880); reading it as the content column, and the 600px cap, are this document's choice. | CSS contract test: no `left`/`right` in the host rules, centring and gutter declarations; browser check at desktop, 768px and 375px widths in LTR and RTL. |
| R7 Pending state | While the action runs the initiating control is busy (`aria-busy`, duplicate submit blocked) and one polite announcement says it is working. The pending state ends in exactly one of: success snackbar, or inline failure. | Test: busy set on submit, cleared on both outcomes, one announcement. |
| R8 Queued or accepted | A queued or accepted result (for example bulk "Queued N updates") is a success snackbar that includes one action linking to Activity. There is no separate message history or recent-messages list. | Test: the action is present and its URL is Core-built. |
| R9 Stay local | Copy-to-clipboard confirmations and migration or Blueprint apply results stay where they are. Progress that has its own region (package update progress, Transporter) keeps its in-progress display local; its final success still goes to the snackbar (R1). | Allowlist test: these in-progress displays never call the host; the final success does. |
| R10 Stay notices | Background or system conditions never use the snackbar: credential expiry, background deployment failure, secrets-runtime and database blockers, native update warning, unsupported multisite, extension update warning, Core self-update guidance, Needs attention. | Allowlist test: no `admin_notices` callback calls the host. |
| R11 Dialogs | Destructive or irreversible confirmations stay in dialogs (#782). The snackbar shows only the result afterwards. | Not applicable; routed by #782. |
| R12 Qualified success | Owner decision: a warning suppresses the success snackbar. A result that carries a warning or partial outcome (saved-before-reinstall, repository check skipped) is therefore not reduced to a success snackbar; it gets a warning pointer and stays durable and inline. An `info` item does not qualify a success. | Producer test: a result with a warning produces no success snackbar. |
| R13 One copy source | The snackbar text comes from the same Core copy source as the inline text for that outcome. It is never a second string. Provider and add-on text reaches the snackbar only through the existing bounded-message screener. | Test: the snackbar message equals the string from the shared source for the same code. |
| R14 Static fallback | Without JavaScript the same result renders server-side as a Core notice. With JavaScript the host converts that element into the snackbar without leaving a duplicate. | No-JS and JS-on browser checks; exactly one visible result per event. |
| R15 Never lose a confirmed result | If the host cannot render (component or its styles missing), the confirmed result stays visible in its static form. | Test: with the host unavailable, the static result remains. |
| R16 No data in URLs | Redirect URLs carry no display text. Closed codes and identifiers only. | Test: no redirect query value contains message text. |

Check releases is the model case. Its success reads, for example, "Release
check complete. No newer eligible release was found." The outcome clause comes
from the same source as the inline release status, not from a second string.
On `origin/main` the notice string (`refreshed_result_message()`) and the status
string (`release_status_message()`) in `ReleaseManagementDisplay` are separate.
P1 derives both from one source for this producer.

A check that did not succeed never uses success intent or claims a release was
or was not found. It gets a pointer and an inline notice that share one copy
source (`FeedbackFlash::check_result()`): the snackbar shows the summary and
the notice shows the summary and a detail line.

| Result code | Updater status and reason | Intent | Summary |
| --- | --- | --- | --- |
| `refresh_skipped_disabled` | skipped, `policy_disabled` (package setting) | info | Check not run. Updates are off for this package. |
| `refresh_skipped_forced_off` | skipped, `policy_forced_off` (site configuration) | info | Check not run. Site configuration turns updates off. |
| `refresh_unchecked` | skipped for any other reason, or no check observed (for example a filter or another plugin blocked it) | warning | Releases were not checked. Use Check releases to retry. |
| `refresh_rejected` | rejected | warning | The newest release cannot be used. |
| `refresh_check_failed` | failed | error | The release check failed. |

The result also carries the updater's closed reason (`check_reason`, for
example `policy_disabled`) so a caller other than the page can read it.

Reinstall, update and remove follow the same pattern as saves. Release,
webhook and extension successes follow it too.

## Behaviour

| Aspect | Specification |
| --- | --- |
| Queue | At most 3 visible, newest last. When a fourth arrives, the oldest success is dropped first. A pointer to a persistent error is never dropped by the queue (activating its Show details action removes it). What happens when three pointers are visible and a new item arrives is not yet decided; until it is, a new success is announced politely and its static form is kept (R15), and a new pointer is omitted because its inline message is the record. To be revisited after use (see Open decision). |
| Duplicates | Each item has a key (the closed code). The same key never produces two snackbars. |
| Duration | Decided: one constant, 10 seconds (R5), tunable later in that one place. WordPress's own `Snackbar` hard-codes 6 seconds and cannot be configured or paused, so the host renders success items with the explicit-dismiss presentation (which also gives the dismiss control) and owns the timer that removes them. |
| Pause | The timer pauses while the pointer is over the item or focus is inside it, and resumes with the remaining time. This is Booster-owned. |
| Dismiss | Every item has a dismiss control reachable by keyboard. Escape while focus is inside the item dismisses it. |
| Focus | The host never moves focus. If the item that holds focus is removed, focus returns to the control that started the action, or to the nearest sensible control (existing behaviour). |
| Politeness | Success and accepted: polite, using the component's own announcement. A failure pointer is silent in the snackbar (empty `spokenMessage`) because the inline message owns the assertive announcement. Booster never announces the same event twice. |
| Pending | Busy control plus one polite announcement through `ranBoosterAnnounce`. |
| Reduced motion | No animation runs under `prefers-reduced-motion: reduce`. |
| Mobile | At 782px and below the host is the viewport width minus a 16px gutter on each side and sits above the safe-area inset. |
| RTL | Logical properties only; the host mirrors in an RTL locale. |
| History | The transient host is removed before HTMX serialises history and on `pagehide`, so a restored page never replays a confirmation. |
| No JavaScript | The result is a Core notice in the notice destination before the shell (see Notice placement). With JavaScript the host consumes that element by its `data-feedback-key` and removes it. |
| Multisite | The host works on network admin screens that use the Booster shell. Any stored result is keyed per user and per site. |

## Visual treatment

The snackbar is not the stock black pill with white text. It is styled by
intent so it reads as part of the same system as the `@wordpress/ui` Notice and
the Badge, even though `SnackbarList` is the approved component exception.
The four intents map to the Notice and Badge token families: success, error
and warning one to one, and `info` to the info family, so a snackbar and its inline Notice share a hue. This is CSS over the
`SnackbarList` host (class and `icon` passed per notice, selectors scoped to
the Booster host class). It adds no component. P2 owns it.

WordPress's default `.components-snackbar` is `rgba(0, 0, 0, 0.85)` with
`#fff` text, a 4px radius and a hard-coded shadow. P2 overrides all of these
with the tokens below. It uses the same `--wpds-*` tokens as Notice
(`.ran-wp-ui-notice.is-*` in `assets/lib/ran-booster-wp-ui-static.css`, and the
`[data-ran-booster-ui-notice-intent]` mapping in
`assets/ran-booster/25-admin-primitives.css`). Booster bundles these tokens
(`assets/lib/ran-booster-wpds-tokens.css`, `@wordpress/theme` 4.0.0) for
WordPress before 7.1. Every token name below was checked in that file.

| Role | Success | Info | Failure pointer (error) | Warning |
| --- | --- | --- | --- | --- |
| Surface | `--wpds-color-background-surface-success-weak` | `--wpds-color-background-surface-info-weak` | `--wpds-color-background-surface-error-weak` | `--wpds-color-background-surface-warning-weak` |
| Border (1px) | `--wpds-color-stroke-surface-success-strong` | `--wpds-color-stroke-surface-info-strong` | `--wpds-color-stroke-surface-error-strong` | `--wpds-color-stroke-surface-warning-strong` |
| Text | `--wpds-color-foreground-content-success` | `--wpds-color-foreground-content-info` | `--wpds-color-foreground-content-error` | `--wpds-color-foreground-content-warning` |
| Icon | `--wpds-color-foreground-content-success-weak` | `--wpds-color-foreground-content-info-weak` | `--wpds-color-foreground-content-error-weak` | `--wpds-color-foreground-content-warning-weak` |

The Badge uses the non-weak surface tokens
(`--wpds-color-background-surface-success`,
`-error`, `-warning`) with the same text tokens. The snackbar uses the weak
surface like Notice because it carries a sentence, a border and an icon, as
Notice does. Both resolve to the same hue per intent.

- **Warning** is a pointer to its inline warning, never a standalone message.
- **Info** is blue, the Notice info tokens, and is the only intent besides
  success that dismisses itself. Success, error and warning pairings are
  measured below; the contract test measures info the same way.
- **Focus.** The dismiss control and the action link use
  `--wpds-color-stroke-focus` with a `--wpds-border-width-focus` ring, the same
  token the bundled `@wordpress/ui` styles and `25-admin-primitives.css` use.
  The ring has 5.2 to 5.4:1
  contrast against each weak surface. Do not use the WordPress component's
  own accent ring.
- **Shape and spacing.** Radius `--wpds-border-radius-lg`; padding
  `--wpds-dimension-padding-md`; gap between glyph, text and controls
  `--wpds-dimension-gap-md`. Stacked items keep WordPress's own 8px list
  spacing. Pixel literals: the 1px border, the 600px width clamp, the 24px
  and 16px bottom offsets (as spacing tokens) and the 2px focus offset.
- **Icon.** A decorative CSS `::before` glyph on the item content, not the
  `SnackbarList` `icon` prop, whose layout assumes WordPress's own icon. Each
  intent has its own shape so intent never relies on colour alone: a check for
  success, `i` for info, `!` for warning, a cross for error. Alternative text is empty
  (`content: "x" / ""`), after a plain `content` declaration for browsers
  without alternative text. Colour comes from the icon token above.
- **Contrast (WCAG AA).** Computed from the token values in the bundled file.
  Text on surface: success 15.2:1, info 15.6:1, error 15.6:1, warning 15.7:1 (needs 4.5:1).
  Icon on surface: 4.9:1, info 4.85:1, 5.3:1, 4.9:1 (needs 3:1). Focus ring on surface:
  5.2 to 5.4:1 (needs 3:1).
  The border is a deliberate deviation from Notice. Notice uses the plain
  `stroke-surface-*` token (1.7 to 1.8:1 against its own surface). A floating
  snackbar sits on the page background, not only on its own surface (the weak
  surface against the `#f0f0f1` page is 1.07:1), so its edge must meet 3:1
  against the page. The `-strong` stroke does: 4.46 (success), 4.97 (error) and
  4.58 (warning) and 4.51 (info) against `#f0f0f1`, and 4.9 to 5.3 against the snackbar's own
  surface. Text, icon and fill stay the same as Notice.
- **Dark and light.** The bundled token file defines no dark values and
  there is no dark content theme (the Midnight admin colour scheme changes the
  chrome, not the content palette), so there is one palette today.
  P2 uses only `--wpds-*` tokens and no colour literals, so a dark theme
  delivered by `wp-theme` applies without a change here.
- **Forced colours.** A minimal `@media (forced-colors: active)` block sets the
  border to `CanvasText`; the surface, text, glyph and focus ring are left to
  the user agent, so nothing relies on a background colour. Intent stays
  readable without colour through the glyph shape and the message text. A
  contract test covers the block. It is not yet verified in a browser.
- **Motion.** Booster adds no animation or transition and uses no motion
  token. Enter and exit are `SnackbarList`'s own, which it disables when the
  user prefers reduced motion. A contract test keeps the host rules free of
  `transition` and `animation`.
- **Gaps in the token set.** No token fits the elevation. The token file has no
  shadow or elevation token, so the host uses one soft shadow
  (`0 2px 8px` of `--ran-booster-color-black-a15`) in addition to the
  `-strong` border. No other intent lacks a token.
- **Check.** P2 records a visual check per intent in light, at desktop, 768
  and narrow widths and in RTL, with the computed contrast, in the pull
  request. The forced-colours check is not done.

## Copy and the shared failure vocabulary

Core owns all feedback copy. A feedback event is a kind, a closed code and an
optional subject. One `code` to copy function serves the inline error region,
the snackbar, the Activity row and the notice. This policy adds no second
catalogue.

Failure reasons follow the shared standard in #856 and #868: providers supply
reason codes and typed facts, never display text, and Core maps reason to copy.
Provider or updater detail goes through `FailureText` in display mode and
appears only in Activity or diagnostics detail, never in a snackbar title. A
failure pointer repeats the short inline title only.

Today the code to copy map is `DeploymentOutcomeMessage::for_code()` and
`for_activity()` plus `__()` strings in controllers. The shared vocabulary
(#856, #859, #868) is not in this tree: `ran/updater-support` is locked at
`v1.0.0-beta.4`, and Core source contains no `FailureText` or `ProviderFailure`.
The failure-copy integration therefore follows #859 (P5). The success
vocabulary is internal to Core and does not wait for it.

## Inventory of existing producers

Counts come from the repository at the baseline above and are minimums: a
helper is counted once. The Disposition column states where each category goes.

| # | Category | Count | Disposition |
| --- | --- | --- | --- |
| A | Dashboard message queue (`Dashboard::add_message`, `add_failure_message`, `add_message_with_context`, rendered by `views/notices.php`) | 31 call sites in 6 files | Success moves to the snackbar. Errors, warnings, `already-managed`, storage and provider unavailable stay inline. A user-action failure or non-success result also gets a pointer. |
| A1 | Package install, update, edit, unlink, delete success (`add_success_notice`) | within A | Moves to the snackbar. |
| A2 | Bulk update summary (`add_bulk_notice`) | within A | Moves; R8, links to Activity. |
| B | Global `admin_notices` registrations | 9 registrations plus the signed-outcome fallback | Stay as notices (R10), except the release operation notice, which is user-initiated: its success moves and its failure becomes inline. |
| C | Signed operation outcomes (`SignedAdminInteractionFlow`, Admin Interaction API 3) | 1 flow; kinds SUCCESS, ACCEPTED, VALIDATION_FAILURE, UNEXPECTED_FAILURE | SUCCESS and ACCEPTED move. Failures stay in the declared inline region. Message text leaves the URL (R16). Add-ons get this through `respond()` with no new API. |
| D | Redirect-with-query-arg results | 6 producers, 5 query vocabularies | Success moves, failures stay inline. Transport is decided: per-user one-shot flash (see Server-to-page transport). |
| E | Existing snackbar (`presentGeneralSnackbar`) | 1 host, 3 consumers | Kept and generalised into the single host. |
| F | Inline live regions and result markup in views | 54 matches in 27 files under `views/` and `RAN/` (the JavaScript in `assets/` adds more) | Stay. They are field, row and per-control feedback. Confirmation paragraphs duplicated next to a save become redundant. |
| F1 | Copy-to-clipboard confirmations | within F | Stay local (R9). |
| G | `ranBoosterAnnounce` callers | 15 in 6 files, plus one direct `wp.a11y.speak` | Stay as the fallback and pending announcer. The host announces itself; never both. |
| G1 | `ranBoosterNotice.create` and `setIntent` | 10 in 5 files | Stay (contextual info and warnings). Portability results stay local (R9). |
| H | Dialogs, `window.confirm`, leave protection | 3 custom dialogs, 5 `window.confirm`, 2 `beforeunload` | Stay (R11); migration is #782. |
| I | Status badges, Overview, Needs attention, credential health | several | Stay. |
| J | Activity, diagnostics, email, logs | several | Stay. Activity is the destination for queued and accepted links (R8). |
| K | Add-on contributed feedback | via API 3 | Eligible outcomes use the host through `respond()`. Add-ons' own `admin_notices` are outside Core. |

### Category notes

- **A.** Producers are `Dashboard` (4 uses), `Dispatcher` (2),
  `BoosterServiceProvider`
  (1), `DeploymentAdminController` (4), `PackageAdminController` (12) and
  `ProviderProfileAdminController` (8). The three definitions in `Dashboard`
  are not counted. Messages live for one request.
- **B.** The nine registrations are: credential expiry, background deployment
  failure, secrets runtime and database compatibility notices, and the native
  update warning (`Booster.php`); `CoreSelfUpdateDevelopmentNotice`;
  `ReleaseManagementControls::render_operation_notice`; the unsupported
  multisite notice (network admin only); and the extension update warning
  (`ExtensionUpdates`). The signed-outcome fallback is registered at runtime by
  `SignedAdminInteractionFlow`.
- **C.** `signed_outcome_url()` currently puts the message (up to 255 characters)
  in the redirect query string. That is the one display text in a URL and is
  removed by R16.
- **D.** Extension install and activate (`ran_booster_extension_result`),
  package results (`ran_booster_result`), bulk results (`ran_booster_bulk_*`),
  release management and release workflow redirects, webhook management
  redirect, and the dispatcher `redirect_to`. Package and bulk results already
  carry closed tokens only.
- **E.** Consumers are General Settings saves, package plugin and theme settings
  saves, and provider profile saves. It is HTMX-only, shows one item at a time
  (id `general-save`), is positioned with physical `left`/`right` and a
  JavaScript-measured `--ran-booster-snackbar-center`, and falls back to local
  text if the component or its styles are unavailable.

## Delivery plan

The owner merges each pull request. Per owner decision 8, the transport (P1) and
the host (P2) are built in parallel against the interface contract below, so two
pull requests are open at once. Budgets are estimates. Each
pull request names its Core invariant, the rejected smaller alternative and the
projected production-line, type, public-seam and persistent-state delta before
coding, per `AGENTS.md`.

| PR | Content | Production | Tests | Docs | Depends on |
| --- | --- | --- | --- | --- | --- |
| P0 | This policy and the exception record | 0 | 0 | this document | none |
| P1 | Transport: the per-user one-shot flash, one consume point on Booster screens, the static notice fallback, Check releases wired end to end as the first producer. It does not yet remove message text from the signed-outcome URL (R16 moves to P3 and P4) and does not yet emit `data-feedback-target` or the Activity link (P3 adds them with its first failure-pointer and queued producers) | about +110 PHP, +27 CSS | about +310 | about +26 (`notice-placement.md`) | P0 |
| P2 | One client host (`ranBoosterFeedback.show()`): generalise `presentGeneralSnackbar`, queue of 3, Booster-owned timer and pause, CSS-only logical positioning, mobile gutters, dedupe against the static element, remove the JS centre measurement, and the intent styling in Visual treatment (success, error pointer, warning; Notice and Badge tokens, CSS over the host, no new component, visual check per intent) | about +80 / -110 JS, +30 CSS | about +180 | about +20 | P0 and the interface contract (parallel with P1) |
| P3 | Move existing producers on current strings: package install, update, edit, unlink, delete, reinstall, update and remove operations, bulk with Activity link, other release results, extension install and activate, webhook results. Adds `data-feedback-target` and the Activity link to the contract. Failures stay inline | about +60 / -120 | about +150 | about +10 | P1, P2 |
| P4 | Signed outcome and add-on path: SUCCESS and ACCEPTED use the transport, and message text leaves the signed-outcome URL (R16); document that add-ons get the snackbar through `respond()` | about +30 / -90 | about +80 | about +60 (`enhanced-admin-interactions.md`) | P1 |
| P5 | Copy integration: success titles from the one code to copy function; failure copy through it; `FailureText` display mode for Activity detail only | about +60 | about +120 | about +30 | #859 landed and the `ran/updater-support` bump |
| P6 | Evidence only: installed-ZIP acceptance on desktop and mobile, RTL, JavaScript on and off, reduced motion, keyboard, screen reader, single site and multisite | 0 | 0 | about +80 (`docs/evidence/`) | P1 to P5 |
| P7 | Retire presenters and styles once no consumer remains | about -80 | about -40 | about +5 | P3, P4 |

Order: P0 first. P1 and P2 run in parallel, then P3 and P4. P5 follows #859. The
failure-copy integration is P5 only.

### Tests and evidence required per pull request

Every pull request runs `composer check` and `pnpm check`.

- P1: PHPUnit for capability and nonce on the producing request, closed codes
  only (no secret-shaped input survives), expiry or single use as the chosen
  transport requires, per-user and per-site isolation, and no display text in
  any URL.
- P2: Node tests for queue limit and eviction order, dedupe by
  `data-feedback-key`, timer
  and pause, dismiss and Escape, focus return, `pagehide` and
  `htmx:beforeHistorySave` teardown, no double `wp.a11y.speak`, and the
  host-unavailable fallback. CSS contract tests for logical properties and
  reduced motion.
- P3 and P4: a test per moved producer that success calls the host, failure
  renders the inline region, and a warning suppresses the success snackbar. A
  no-JavaScript submit for each moved producer. The R10 and R9 allowlist tests.
- P5: the snackbar message equals the shared copy for the same code; no
  provider-supplied display string reaches a template.
- P6: screenshots at desktop, 782px and 320px, LTR and RTL; axe on a screen with
  a visible snackbar; keyboard dismiss and Escape; a screen reader check of a
  success and an inline failure; Needs attention unchanged; the exact installed
  archive and its hash.

## Server-to-page transport (decided)

A server result reaches the next page load through a per-user one-shot flash.
As implemented in P1 (#876), the handler stores one user-meta row,
`_ran_booster_feedback_flash`, holding `{site, at, codes}`: the site, a time,
and at most three closed codes, with a 60 second lifetime. The next Booster
shell render reads and deletes it, and Core resolves each code to fixed copy
at render time. There is no event id and no subject. Codes are deduplicated,
so two events with the same code in one render collapse to one message; that is
accepted. The flash holds no message text and nothing about it appears in the
URL. No secret, provider response, path or exception text may enter it, and
unknown codes are dropped on write and on read. A later `FeedbackFlash::enqueue`
replaces an unread flash, and `render()` requires `manage_options`.

### Interface contract between the flash (P1) and the host (P2)

This section is the single source for the contract.

- The server emits at most one container,
  `.ran-booster-feedback[data-ran-booster-feedback]`, on a Booster admin screen.
- Each result is an item, `.ran-booster-feedback__item`, with
  `data-feedback-key` (the closed code), `data-intent` (`success`, `info`, `error`
  or `warning`) and `role` (`alert` for error, `status` otherwise). Its text is
  fixed Core copy.
- `data-feedback-target`: the id of the inline notice a pointer leads to. An
  error or warning item without a present target stays static. Check releases
  emits it; the other producers adopt it in P3.
- Optional: a `.ran-booster-feedback__activity` link to Activity for queued or
  accepted results (P3).
- Without JavaScript the items are the visible static fallback, styled as a
  normal notice. The host converts them into snackbars (success, info, and
  the failure or warning pointer) and removes the static element, leaving no
  duplicate (R14). A failure item is never snackbar-only (R3).
- The host never evicts a pointer to a persistent error (see Behaviour).

If this proves arduous or becomes another subsystem to maintain, P1 falls back
to signed data-free tokens without message text, as package and bulk results
use today, and the owner is told.

## Open decision

Revisit the queue order (see Behaviour) after it has been seen in use. No other
decision is open: the duration (10 seconds, one constant), the transport, the
exception and rules R1 to R16 are decided.

Undecided until then: what happens when three pointers to persistent errors are
visible and another item arrives. Until it is decided, a new success is
announced politely and its static form is kept (R15), and a new pointer is
omitted because its inline message is the record. The host must not evict a
pointer to make room.
