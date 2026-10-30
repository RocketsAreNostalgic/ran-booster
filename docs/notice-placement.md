# Booster notice placement pilot (#560)

Core adopts Admin Shell's explicit pre-shell destination on full shell pages:
one native `.wp-header-end` before the banner/navigation, followed by Core-owned
inline page output and contextual page content. Partial/HTMX responses do not
render another base view or marker. The old post-shell marker is removed.

## Ownership and retained policy

| Output | Owner and placement |
| --- | --- |
| Unrelated WordPress `notice`, `updated`, `error` | Native hooks and relocation, above the shell. Core does not capture or replay them. |
| Credential expiry, deployment failures, secrets-runtime/database blockers, native-update warning | Existing explicit `BoosterNoticeScope` queue on eligible shell screens; existing feature audience, capability and urgency rules elsewhere. |
| Development guidance | Existing shell-inline renderer and preference policy. |
| Signed operation outcomes | Existing interaction flow and inline page result, or the relevant package heading/row/fragment. |
| Form errors, webhook/release controls, source settings, managed-package failure rows | Existing inline feedback beside their controls. |
| Satellite missing/incompatible-Core warnings | Independent bootstrap warning; no new satellite shell dependency. |

The queue, screen/capability decisions, feature controllers, preference transport,
saved-before-close behaviour, accessible failure reporting, five-day snooze,
stale-state rejection, user/event isolation, replacement redisplay, concurrency
and incident/recovery state are unchanged. Ownership does not imply page-only
audience: urgent/expired warnings retain their global visibility.

The old foundations sibling-spacing rules from #558 are replaced by the shared
opt-in notice margins. The destination breaks the old direct-sibling relationship;
keeping Core's broad visible-sibling override would add spacing twice before
JavaScript. The new shared rule derives height from displayed notices, preserving
empty/HTML-hidden/mixed/dismissed states and covering CSS-hidden placeholders.
No new production types, public seams, persistent keys or notice framework exist.

## Dependency and promotion gate

Admin Shell PR [#32](https://github.com/RocketsAreNostalgic/ran-admin-shell/pull/32)
was independently reviewed at base `496a7d9802bf504d081532ed161f3c8b8ee9ac85`
and earlier head `0fc59293474cd32acda6c41d0235e5123509fbf3`, with no actionable
findings at that tuple. The revised head/resource candidate is
`56dc2fd75c685fa82038ea60d92608ddc2a131fd` and requires review at its own exact
PR tuple. This dependent candidate selects that shell PR
branch with Composer's normal development-branch constraint and binds its full
immutable revision in `composer.lock`, updates only the shell dependency, then
runs `composer admin-shell:sync` and immutable verification.
The synchronized PHP bytes are unchanged; CSS and provenance are regenerated.
No local/path repository, fake installed metadata or generated-file edits are used.
Strict Composer validation rejects commit-reference constraints in the manifest;
the branch constraint plus full lock reference preserves the normal gate, subject
to review of the revised candidate.

Local testing found that native floated Help and Screen Options controls could
compress the notice destination and shell on mobile. The revised Shell candidate
passively clears those floats for the opt-in destination and notices. Its regression
fixture now models native floats instead of positioning the controls absolutely.
Installed acceptance of the fix remains pending a rerun.

Keep this consumer PR a dependent draft while the shell PR is unmerged. After the
owner's shell merge, qualify its actual immutable main revision, refresh the
consumer dependency/lock and synchronized provenance through the same workflow,
and repeat the affected exact-head checks, artifact acceptance and independent
PR review. No package publication is necessary for this existing VCS development
dependency. Do not claim downstream source delivery, release or retained-site
acceptance from the dependent pilot.

## Baseline and candidate evidence

The exact baseline is Core `e3378486496319074b30304e28fb36dcc1902a29`, published
beta.41 ZIP SHA-256
`665d9545ad4609a6960121d9e317c11d2bc74e1a13b161975ec2a61f24846819`.
All 511 installed files matched on a marked disposable WordPress 7.1.4 installation.
Synthetic unrelated `notice`, `updated` and `error` output began above the shell
without JavaScript; actual native `common.min.js` moved them below it. The queued
owned `.inline` notice remained below once. Native `wp.updates.addAdminNotice`
also used the wrong post-shell target. This verifies the source-derived report.

Candidate evidence belongs to the actual PR tuple and exact installable ZIP:
record source/resource revisions, archive hash and full installed readback on
marked single-site and multisite roots. Exercise desktop/mobile, JavaScript
on/off, plugin/theme lists, managed-package edit, site/network screens, native
dynamic insertion and dismissal, contextual errors and fragments, non-shell
Dashboard/Plugins, one H1/marker and native Help/Screen Options. Retain #552's
preference/interaction tests and #558's spacing matrix; record limitations rather
than substituting source checks for installed acceptance. Run `composer check`,
`pnpm check`, archive verification and runtime proofs. The archive must exclude
the build-time shell package and development vendor tree. Do not merge, publish,
deploy to retained sites or close #560 from this pilot.
