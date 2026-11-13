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
3. Failure: an inline message next to where the error is most useful. It
   persists and never auto-dismisses. A short snackbar pointer to it is
   optional.

Conditions that exist without a click (credential expiry, background
deployment failures, secrets or database blockers) are not feedback and stay
where they are.

## Component

The snackbar host is the WordPress global `wp.components.SnackbarList`. It is
the single approved exception to the `@wordpress/ui` rule, because
`@wordpress/ui` 0.26.0 exports no Snackbar or Toast. The exact exception, scope,
versions checked and revisit trigger are recorded in the
[component package section of the composition contract](admin-composition-contract.md#component-package-direction).
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
| R4 Failure never auto-dismisses | A failure pointer has an explicit dismiss control and no timer. A pointer is optional; the inline message is the record. | Config test per kind: success has a timer, a pointer does not. |
| R5 One duration | Success snackbars use one universal duration, a single constant of 10 seconds. It is not set per call site and can be tuned later by changing that one value. | Static test: exactly one definition; no call site passes its own duration. |
| R6 Placement | Bottom of the viewport, in every Booster surface, aligned to the Booster content column. Positioning uses logical CSS properties so RTL mirrors it. It never moves the layout and never depends on the Admin Shell's height. | CSS contract test: no `left`/`right` in the host rules; browser check in LTR and RTL. |
| R7 Pending state | While the action runs the initiating control is busy (`aria-busy`, duplicate submit blocked) and one polite announcement says it is working. The pending state ends in exactly one of: success snackbar, or inline failure. | Test: busy set on submit, cleared on both outcomes, one announcement. |
| R8 Queued or accepted | A queued or accepted result (for example bulk "Queued N updates") is a success snackbar that includes one action linking to Activity. There is no separate message history or recent-messages list. | Test: the action is present and its URL is Core-built. |
| R9 Stay local | Copy-to-clipboard confirmations and migration or Blueprint apply results stay where they are. Progress that has its own region (package update progress, Transporter) stays inline. | Allowlist test: these producers never call the host. |
| R10 Stay notices | Background or system conditions never use the snackbar: credential expiry, background deployment failure, secrets-runtime and database blockers, native update warning, unsupported multisite, extension update warning, Core self-update guidance, Needs attention. | Allowlist test: no `admin_notices` callback calls the host. |
| R11 Dialogs | Destructive or irreversible confirmations stay in dialogs (#782). The snackbar shows only the result afterwards. | Not applicable; routed by #782. |
| R12 Qualified success | A result that carries a warning or partial outcome (saved-before-reinstall, repository check skipped) stays durable and inline. It is not reduced to a success snackbar. | Producer test: a result with a warning produces no success snackbar. |
| R13 One copy source | The snackbar text comes from the same Core copy source as the inline text for that outcome. It is never a second string. Provider and add-on text reaches the snackbar only through the existing bounded-message screener. | Test: the snackbar message equals the string from the shared source for the same code. |
| R14 Static fallback | Without JavaScript the same result renders server-side as a Core notice. With JavaScript the host converts that element into the snackbar without leaving a duplicate. | No-JS and JS-on browser checks; exactly one visible result per event. |
| R15 Never lose a confirmed result | If the host cannot render (component or its styles missing), the confirmed result stays visible in its static form. | Test: with the host unavailable, the static result remains. |
| R16 No data in URLs | Redirect URLs carry no display text. Closed codes and identifiers only. | Test: no redirect query value contains message text. |

Check releases is the model case. Its success reads, for example, "Release
check complete. No newer eligible release was found." The outcome clause comes
from the same source as the inline release status, not from a second string.
Today the notice string (`refreshed_result_message()`) and the status string
(`release_status_message()`) in `ReleaseManagementDisplay` are separate; P3
derives both from one source.

Reinstall, update and remove follow the same pattern as saves. Release,
webhook and extension successes follow it too.

## Behaviour

| Aspect | Specification |
| --- | --- |
| Queue | At most 3 visible, newest last. A fourth evicts the oldest success. If no success is visible, it evicts the oldest failure pointer. Evicting a pointer loses nothing because the inline message remains. |
| Duplicates | Each event has an id. The same id never produces two snackbars. |
| Duration | One constant, 10 seconds (R5). WordPress's own `Snackbar` hard-codes its timeout and cannot be configured or paused, so the host renders success items with the explicit-dismiss presentation (which also gives the dismiss control) and owns the timer that removes them. |
| Pause | The timer pauses while the pointer is over the item or focus is inside it, and resumes with the remaining time. This is Booster-owned. |
| Dismiss | Every item has a dismiss control reachable by keyboard. Escape while focus is inside the item dismisses it. |
| Focus | The host never moves focus. If the item that holds focus is removed, focus returns to the control that started the action, or to the nearest sensible control (existing behaviour). |
| Politeness | Success and accepted: polite, using the component's own announcement. A failure pointer is silent in the snackbar (empty `spokenMessage`) because the inline message owns the assertive announcement. Booster never announces the same event twice. |
| Pending | Busy control plus one polite announcement through `ranBoosterAnnounce`. |
| Reduced motion | No animation runs under `prefers-reduced-motion: reduce`. |
| Mobile | At 782px and below the host is the viewport width minus a 16px gutter on each side and sits above the safe-area inset. |
| RTL | Logical properties only; the host mirrors in an RTL locale. |
| History | The transient host is removed before HTMX serialises history and on `pagehide`, so a restored page never replays a confirmation. |
| No JavaScript | The result is a Core notice in the notice destination before the shell (see Notice placement). With JavaScript the host consumes that element by event id and removes it. |
| Multisite | The host works on network admin screens that use the Booster shell. Any stored result is keyed per user and per site. |

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
| A | Dashboard message queue (`Dashboard::add_message`, `add_failure_message`, `add_message_with_context`, rendered by `views/notices.php`) | 31 call sites in 6 files | Success moves to the snackbar. Errors, warnings, `already-managed`, storage and provider unavailable stay inline. User-action failures may add a pointer. |
| A1 | Package install, update, edit, unlink, delete success (`add_success_notice`) | within A | Moves to the snackbar. |
| A2 | Bulk update summary (`add_bulk_notice`) | within A | Moves; R8, links to Activity. |
| B | Global `admin_notices` registrations | 9 registrations plus the signed-outcome fallback | Stay as notices (R10), except the release operation notice, which is user-initiated: its success moves and its failure becomes inline. |
| C | Signed operation outcomes (`SignedAdminInteractionFlow`, Admin Interaction API 3) | 1 flow; kinds SUCCESS, ACCEPTED, VALIDATION_FAILURE, UNEXPECTED_FAILURE | SUCCESS and ACCEPTED move. Failures stay in the declared inline region. Message text leaves the URL (R16). Add-ons get this through `respond()` with no new API. |
| D | Redirect-with-query-arg results | 6 producers, 5 query vocabularies | Success moves, failures stay inline. Transport is the open decision below. |
| E | Existing snackbar (`presentGeneralSnackbar`) | 1 host, 3 consumers | Kept and generalised into the single host. |
| F | Inline live regions and result markup in views | 54 matches in 27 files | Stay. They are field, row and per-control feedback. Confirmation paragraphs duplicated next to a save become redundant. |
| F1 | Copy-to-clipboard confirmations | within F | Stay local (R9). |
| G | `ranBoosterAnnounce` callers | 15 in 6 files, plus one direct `wp.a11y.speak` | Stay as the fallback and pending announcer. The host announces itself; never both. |
| G1 | `ranBoosterNotice.create` and `setIntent` | 10 in 5 files | Stay (contextual info and warnings). Portability results stay local (R9). |
| H | Dialogs, `window.confirm`, leave protection | 3 custom dialogs, 5 `window.confirm`, 2 `beforeunload` | Stay (R11); migration is #782. |
| I | Status badges, Overview, Needs attention, credential health | several | Stay. |
| J | Activity, diagnostics, email, logs | several | Stay. Activity is the destination for queued and accepted links (R8). |
| K | Add-on contributed feedback | via API 3 | Eligible outcomes use the host through `respond()`. Add-ons' own `admin_notices` are outside Core. |

### Category notes

- **A.** Producers are `Dashboard` (4 uses), `Dispatcher` (2), `BoosterServiceProvider`
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

One pull request at a time, merged by the owner. Budgets are estimates. Each
pull request names its Core invariant, the rejected smaller alternative and the
projected production-line, type, public-seam and persistent-state delta before
coding, per `AGENTS.md`.

| PR | Content | Production | Tests | Docs | Depends on |
| --- | --- | --- | --- | --- | --- |
| P0 | This policy and the exception record | 0 | 0 | this document | none |
| P1 | Server-to-page transport of a result (per the open decision), one consume point on Booster screens, static notice with event id, remove message text from the signed-outcome URL, keep the API 3 signature | about +120 / -90 | about +260 | about +40 | P0 and the transport decision |
| P2 | One client host (`ranBoosterFeedback.show()`): generalise `presentGeneralSnackbar`, queue of 3, Booster-owned timer and pause, CSS-only logical positioning, mobile gutters, dedupe against the static element, remove the JS centre measurement | about +80 / -110 JS, +30 CSS | about +180 | about +20 | P0 (can run against the existing event before P1) |
| P3 | Move existing producers on current strings: package install, update, edit, unlink, delete, reinstall, update and remove operations, bulk with Activity link, Check releases and other release results, extension install and activate, webhook results. Failures stay inline | about +60 / -120 | about +150 | about +10 | P1, P2 |
| P4 | Signed outcome and add-on path: SUCCESS and ACCEPTED use the transport; document that add-ons get the snackbar through `respond()` | about +30 | about +80 | about +60 (`enhanced-admin-interactions.md`) | P1 |
| P5 | Copy integration: success titles from the one code to copy function; failure copy through it; `FailureText` display mode for Activity detail only | about +60 | about +120 | about +30 | #859 landed and the `ran/updater-support` bump |
| P6 | Evidence only: installed-ZIP acceptance on desktop and mobile, RTL, JavaScript on and off, reduced motion, keyboard, screen reader, single site and multisite | 0 | 0 | about +80 (`docs/evidence/`) | P1 to P5 |
| P7 | Retire presenters and styles once no consumer remains | about -80 | about -40 | about +5 | P3, P4 |

Order: P0 first. P1 and P2 follow (in either order on the one checkout), then
P3 and P4. P5 follows #859. The failure-copy integration is P5 only.

### Tests and evidence required per pull request

Every pull request runs `composer check` and `pnpm check`.

- P1: PHPUnit for capability and nonce on the producing request, closed codes
  only (no secret-shaped input survives), expiry or single use as the chosen
  transport requires, per-user and per-site isolation, and no display text in
  any URL.
- P2: Node tests for queue limit and eviction order, dedupe by event id, timer
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

## Open implementation decision

**How a server result reaches the next page load.** This policy does not
depend on the answer; P1 implements whichever is chosen. Two options remain:

- **Per-user one-shot flash.** The handler stores closed codes only (kind, code,
  subject, event id) in a per-user, per-site slot with a short lifetime,
  redirects to a clean URL, and the next Booster screen reads and deletes it.
  Adds one persistent key family and a last-write-wins case when two tabs
  submit.
- **Signed data-free tokens.** The redirect carries a signed token naming the
  closed code and subject, with no message text, as package and bulk results do
  today. Adds no stored state but keeps several query vocabularies unless they
  are unified.

No choice is made here. The rule that binds either option is R16: no display
text in a URL and no secret, provider response or path in a stored or signed
payload.
