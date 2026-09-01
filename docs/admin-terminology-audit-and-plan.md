# Interface terminology: audit and implementation plan

Date: August 27, 2026.

Reachable rollback checkpoint: `e1364c9608017c0bea0351fc098eee2f79f1087e`
(`fix(admin): checkpoint repository integration rehearsal`). Checkpoint-relative
check results and worktree state are not claimed here because the earlier
checkpoint object is no longer reachable.

## Purpose

Use **Branch** and **Releases** consistently. Explain each concept once, put
remediation beside the relevant control, and preserve the existing layouts.

The product story is:

> Booster updates WordPress plugins and themes from Git repositories. Deploy
> from a branch, or install published releases. Update manually or enable
> automatic updates.

Source, update policy, and repository automation are different choices:

| Concept | Meaning |
| --- | --- |
| Branch | Install from the saved repository branch. |
| Releases | Install eligible published versions. |
| Manual | Install only when an administrator requests it. |
| Automatic, with Branch | Deploy matching signed repository pushes. |
| Automatic, with Releases | Allow WordPress to install eligible releases. |
| Push-to-deploy | The automatic Branch capability, implemented with webhooks. |
| Release workflow | Optional repository automation that produces releases. |

Branch does not require push-to-deploy. Published releases do not prove that a
workflow exists or has run. Neither mode is a synonym for GitOps or CI/CD.

## Naming contract

| Surface | Current wording | Target wording |
| --- | --- | --- |
| Package source heading, legend and accessible navigation name | Package source / Package source settings | Update source / Update source settings |
| Package source tabs | Branch / Published releases | Branch / Releases |
| Repository tabs | Status / Branch deployments / Published releases | Status / Branch / Releases |
| Source summaries and compact inventory labels | Branch deployments / Published releases | Branch / Releases |
| Source-switch actions | Use branch deployments / Use published releases | Use branch / Use releases |
| Package readiness | Branch readiness plus Saved branch setup / Published release readiness | One Branch readiness heading / Release readiness |
| Repository Branch section | Repository webhook | Push-to-deploy |
| Repository Releases section | Published releases | Release publishing |
| Optional producer setup and its history group | Release automation | Release workflow |
| Provider navigation | Status / Repositories / Webhook receiver | Unchanged |
| Mechanism-specific controls | Webhook readiness / Webhook setup | Unchanged |

Keep "published releases" in explanatory text when publication state matters.
Do not replace every occurrence of "automation," "branch," or "release."
"Publish releases" is an action, not a navigation label.

## Audit findings

The audit inspected the reachable checkpoint's owning renderers and their tests.
Paths below identify the implementation, not additional sources of policy.

| Finding | Owning code | Required change |
| --- | --- | --- |
| The source choice has Core defaults, a template fallback and a release-specific override. Changing only the template would leave different labels when the override runs. | [PackagePagePresenter](../RAN/Admin/PackagePagePresenter.php), [source choices](../views/packages/source-choices.php), [ReleaseManagementControls](../RAN/Admin/ReleaseManagement/ReleaseManagementControls.php) | Update all three label producers together. Keep disabled reasons and source keys intact. |
| Repository navigation calls the same source "Branch deployments" that package navigation calls "Branch." | [RepositoryDetailRenderer](../RAN/Admin/Component/RepositoryDetailRenderer.php) | Use the same short pair on both screens. Retain repository view keys and mixed-source indicators. |
| Collapsed summaries, package lists and repository rows repeat the longer names. | [ReleaseManagementDisplay](../RAN/Admin/ReleaseManagement/ReleaseManagementDisplay.php), [package index](../views/packages/index.php), [ProviderRepositoryRowsNormalizer](../RAN/Admin/ProviderRepositoryRowsNormalizer.php), [provider view](../views/provider.php) | Align compact labels with the tabs, including source-switch rows and buttons. |
| Branch readiness has an outer heading and a nested "Saved branch setup" heading. | [source settings](../views/packages/source-settings.php), [branch readiness](../views/packages/branch-readiness.php) | Resolve in the content-hierarchy slice. Merely renaming the nested heading creates two adjacent "Branch readiness" headings. |
| Repository section titles mix an outcome (releases) with a mechanism (webhook). Both provider content and Core fallback content supply titles. | [RepositoryWebhookManagementControls](../RAN/Admin/WebhookManagement/RepositoryWebhookManagementControls.php), [GitHubReleaseWorkflowControls](../RAN/Admin/ReleaseManagement/GitHub/GitHubReleaseWorkflowControls.php), [RepositoryDetailRenderer](../RAN/Admin/Component/RepositoryDetailRenderer.php) | Use Push-to-deploy and Release publishing for section purposes; retain the specific setup/checklist labels inside them. Change fallback and capable-provider paths together. |
| Release history classification uses stable category/kind or normalized detail keys; English labels are display text only. | `RepositoryDetailRenderer::isReleaseDetail()` and `GitHubReleaseWorkflowControls::enrichRepositoryRows()` | Coordinate the visible rename with metadata-based history grouping tests. Preserve stable `gh:release-automation-*` keys and the `release_workflow` kind; do not classify through current or legacy labels or accidentally move release facts into webhook history. |
| Eligibility, current source, transition consequences and workflow evidence appear in several neighboring explanations. | ReleaseManagementDisplay, GitHubReleaseWorkflowDisplay, provider view and branch-readiness template | Reduce repetition after labels stabilize. Keep exact remediation, failure details and facts with different meanings. |
| Documentation has its own navigation titles and technical terminology. | `ReleaseManagementControls::filterDocumentationSections()`, README, package update guides | Reconcile reader-facing names last. Preserve technical identifiers, historical evidence and the owner's unrelated copy. |
| The prospective-install JavaScript restores the old source title, accessible name and collapsed summary after PHP renders the new labels. | `assets/ran-booster-release-management.js` | Align these literal labels with Releases; retain descriptive error messages and every interaction predicate. |

## Page responsibilities

- **Plugin/theme settings:** source, branch/subdirectory or release track,
  version selection, update policy and installation. Summarize readiness and
  link to detailed repository remediation.
- **Repository settings:** shared webhook setup, release requirements,
  optional release workflow and recorded history. Do not duplicate package
  installation controls here.
- **Provider settings:** credentials, signing secrets, the shared receiver
  and repository inventory.

Package settings control installation. Repository settings control
integration. A repository can have packages using different sources; its
tabs are views, not an exclusive repository-wide mode selector.

## Implementation slices

### 0. Rollback checkpoint — complete

Commit the accumulated rehearsal changes before editing terminology. Do not
mix those changes into the naming diff. The checkpoint is recorded above. This
checkpoint is not PR approval or release approval.

### 1. Source vocabulary — implemented and verified locally

Change source labels, navigation, accessible names, compact source summaries,
source-switch button text and Release readiness headings. Keep all HTML
containers, CSS, URLs, form associations and conditional behavior unchanged.

Update tests for Core defaults and release overrides, plugin and theme,
create and edit, current source versus viewed tab, disabled controls,
subdirectory recovery, and mixed-source repository navigation. Add assertions
against the real render output rather than only changing fixture labels.

Projected delta: zero net production lines for literal substitutions, up to
60 added test lines, zero concrete types, public seams or persistent fields.
Reuse existing renderers; a terminology service or global replacement script
would add complexity or erase meaningful distinctions.

Result: nine production files contain literal-only replacements; nine test
files update expectations and add four net assertion lines. The historical
checkpoint-relative token comparison is not reproduced here because the
original checkpoint object is unreachable.

### 2. Repository feature vocabulary — implemented and verified locally

Rename Repository webhook to Push-to-deploy, the repository release section
to Release publishing, and optional Release automation to Release workflow.
Update links, capable-provider content, disabled fallbacks and history
headings together. Keep action labels constant across enabled/disabled states.

Test stable-key and legacy-label history grouping, existing external workflow
evidence, Booster setup PR evidence, unsupported providers and inactive modes.
Do not change diagnostic codes, operation names, evidence schemas or remote
behavior to achieve a wording change.

Implementation boundary: preserve the Core-owned classification of recorded
release history using the normalized detail key or `release_workflow` kind.
Labels, including translations, do not classify history. No registry or
migration is needed. Projected growth: one production line, zero concrete
types, public seams or state fields.

### 3. Repeated copy and hierarchy — implemented and verified locally

Use one introductory sentence per mode. Resolve the duplicate Branch
readiness headings without changing checklist facts or their accessible
associations. Retain the numbered lifecycle design; remove repeated
explanations rather than removing controls.

Keep one top-level blocker notice, concise readiness rows and contextual
remediation. Preserve distinctions between releases available, an existing
workflow found, a Booster setup PR recorded and a verified workflow. Unknown
must not become a green success state merely to shorten the copy.

Record before/after text for each affected panel. Check both normal and
blocked states visually before considering this slice complete.

Keep the existing styled checklist heading, rename it Branch readiness, and
remove the duplicate heading above the branch fields. Do not change the
checklist's ID, icon markup or CSS. Remove the generic "Review the requirements
below" summary, retaining the actual readiness rows and warning badge.
Shorten saved-access, local-evidence and workflow instructions without changing
their predicates. Expected production delta is negative; no new component,
type, public seam or stored field is needed.

### 4. Documentation and final reconciliation — implemented and verified locally

Align in-app navigation, help links and source guides with the accepted UI.
Keep necessary terms such as published release, webhook and Update URI in
technical explanations. Do not broadly rewrite the owner's accepted README.

Check remaining old labels in context. Historical plans, API identifiers,
logs, provider keys and accurate explanatory prose are not failed migrations.
Document intentional exceptions rather than chasing a zero-match search.

## Invariants and exclusions

- `branch`, `release_asset`, `repository_view`, route names, DOM IDs, data
  attributes, nonce inputs, diagnostics and stored evidence remain unchanged.
- Viewing a source tab does not switch the source. Current-source indicators
  derive from saved state, not the selected view.
- Disabled capabilities stay visible. The accepted exception remains: an
  unnecessary switch-to-current-source action is absent.
- Unsaved edits still block a source switch. Save settings and source-switch
  actions remain separate. Automatic-to-Manual resets remain enforced.
- No provider calls are added to render a label or status.
- The repository-root release restriction and existing cardinality behavior
  remain unchanged. This is not a monorepo implementation or a new guarantee
  about exclusive release ownership.
- No stylesheet changes, new UI framework, translation registry, API change,
  migration, remote mutation, PR, merge, release or Dex/workbench work.

## Acceptance

For each source-affecting slice, run the focused PHP view/contract tests and
asset tests, then `composer check`, `pnpm check`, PHP lint and `git diff --check`.
Report existing warnings/skips separately from new failures.

Inspect the live PNS package and repository views without saving package
settings: Branch active, Releases active, blocked eligibility, missing
capability, and tab navigation. Check the existing source-action row, disabled
buttons, active indicators, same-origin HTMX swaps and no-JavaScript URLs.
Use render tests for states unavailable on the live site. Do not mutate the
PNS database or remote repositories to manufacture a visual test case.

Inspect desktop and narrow layouts for overflow and unchanged spacing when
the browser supports resizing. Do not report an unexecuted responsive or
disposable activation check as passed.

### Slice 1 verification — August 27, 2026

- Full `composer check`: passed, including PHPCS and PHPStan. Core PHPUnit:
  2,651 tests, 17,981 assertions, three existing warnings and two skips.
  Isolated GitHub tests: 345 tests, 2,086 assertions and two skips.
  Characterization: 35 checks.
- Full `pnpm check`: passed, including 178 asset tests.
- PHP lint: all 18 changed PHP files passed. `git diff --check` passed.
- The first full PHP run found four old-label expectations outside the
  focused set. They were updated to the new labels; no assertion was removed
  and no production behavior was changed to satisfy them.
- Live PNS: a Branch package viewed in Releases retains its Branch active
  indicator and disabled Use releases action when its Update URI is missing.
  A Releases package viewed in Branch retains its Releases/Stable active
  summary and offers Use branch. Viewing the active Releases pane has no
  unnecessary source-switch action.
- Repository HTMX navigation retains one page/profile/detail container and
  the canonical repository URL. Inactive webhook controls remain visible and
  disabled. Release history remains grouped separately.
- Provider tabs remain Status / Repositories / Webhook receiver. The local
  release-count tile now reads Releases.
- Desktop inspection at 1,422 pixels found no horizontal overflow. No
  stylesheet was changed. Narrow-viewport and JavaScript-disabled browser
  sessions were not exercised; ordinary URL loads, fallback links and the
  automated navigation contracts were checked.
- No settings were saved, source-switch operation submitted, activation
  performed, or remote mutation requested. No push, PR, merge, release or
  Dex/workbench change was made.

### Slice 2 verification — August 27, 2026

- Focused repository/workflow/webhook views: 68 tests, 827 assertions; one
  existing warning. Stable keys and the `release_workflow` kind keep release
  history separate from webhook history; old and new labels are display-only.
- Full `composer check`: passed, including PHPCS and PHPStan. Core PHPUnit:
  2,651 tests, 17,990 assertions, three existing warnings and two skips.
  Isolated GitHub tests: 345 tests, 2,086 assertions and two skips.
- Full `pnpm check`: passed, including 178 asset tests.
- One stale fallback-heading assertion was corrected after the full run
  identified it. Its focused rerun passed: one test, 85 assertions.
- Live repository headings read Release publishing, Release readiness and
  Release workflow. The recorded external-workflow state retains its blue
  badge, enabled assessment action and separate history group.

### Slice 3 verification — August 27, 2026

- Package view tests: 74 tests, 879 assertions passed, with two existing
  direct-template fixture warnings. Repository/workflow view tests: 79 tests,
  941 assertions passed. Prospective source-label asset tests: 14 passed.
- Full `composer check`: passed, including PHPCS and PHPStan. Core PHPUnit:
  2,651 tests, 17,990 assertions, three existing warnings and two skips.
  Isolated GitHub tests: 345 tests, 2,086 assertions and two skips.
- Full `pnpm check`: passed, including 178 asset tests.
- Live Branch pane: exactly one Branch readiness heading, all three checklist
  rows, and the existing source-action row. Saved identity and root facts stay
  green while Releases is active; branch controls remain visibly disabled.
- Live repository: all numbered stages, checklist rows, the notice region,
  credential selector, assessment action and sticky history remain. Existing
  workflow evidence is blue, separate from the Booster setup record.
- Desktop DOM checks found no horizontal overflow. No CSS was changed.

### Final verification — August 27, 2026

- Full `composer check` passed after the final Update URI wording correction,
  including PHPCS and PHPStan. Core PHPUnit: 2,713 tests, 18,304 assertions
  and two skips. Isolated GitHub suite: 349 tests, 2,115 assertions and two
  skips. Characterization: 35 checks.
- Full `pnpm check` passed, including 186 asset tests. All 30 changed PHP
  files passed syntax checks; `git diff --check` passed.
- A separate read-only review of the package/client diff found no issues.
  It confirmed that the changes preserve source-switch actions, routes,
  nonces, disabled states, channel persistence and saved-versus-checked facts.
- Live PNS verification covered Releases active, its inactive Branch pane,
  missing Update URI, disabled repository workflow setup, retained webhook
  controls, package-to-repository navigation and the documentation deep link.
  HTMX navigation retained one page container and the saved source indicator.
- The blocked workflow keeps its credential selector, assessment action,
  provenance line and documentation links. The Update URI remedy now points
  to package settings, where the exact header is actually displayed.
- Checkpoint-relative token and line-count comparisons are not reproduced here
  because the original checkpoint object is unreachable. JavaScript changes
  only source labels and their accessible text.
  No concrete types, public seams or persistent fields were added.
- README, stylesheets, dependencies, source/policy behavior and remote state
  are unchanged. The recorded `4d67176dfde21c43598539e5e57169a699ec4fec`
  archive smoke applies only to that older source commit; it is not activation
  evidence for the reviewed `5938020d172f2d903d40cc61652fb2e90e41ab32` tree.
  Before merge, build and verify an archive from that exact reviewed commit,
  then install and activate it only on a marked disposable WordPress site.
  Record the archive checksum, embedded `source_commit`, active version and
  `RAN\Booster` runtime-class result. No PNS package or database mutation was
  performed.
- No live save, source switch, workflow assessment, push, PR, merge, release or
  Dex/workbench operation was performed.
- Desktop views were inspected at 1,422 pixels with no horizontal overflow.
  Narrow-viewport and JavaScript-disabled browser sessions were not run;
  responsive/navigation asset contracts and ordinary URL loads passed.

All implementation slices are complete. The rollback checkpoint remains
separate from this terminology pass. Next: owner wording review before
committing this terminology pass.

## Copy reductions

| Surface | Before | After |
| --- | --- | --- |
| Source-view instruction | Review each source's settings. Opening a settings view does not change the current source. | Viewing settings does not change the update source. |
| Branch introduction | Deploy a saved repository branch manually or when a signed push webhook arrives. | Deploy the saved branch manually or on a signed push. |
| Branch headings | Branch readiness followed by Saved branch setup | One Branch readiness heading inside the existing checklist |
| Saved branch, not checked | The repository identity is available locally; repository access and this branch have not been checked. | Access has not been checked. |
| Default package location | Root is used; no repository subdirectory is configured. | Repository root (no subdirectory). |
| Releases introduction | Track verified release assets and install them through WordPress. | Install published releases through WordPress. |
| Release browser | Review the latest eligible release and the version installed on this site. WordPress Updates remains the installation route. | Review the latest eligible release and the installed version. |
| Provider integration introduction | Review site delivery readiness, repositories connected to managed packages, Published release automation, and the shared webhook receiver. | Manage repositories, webhooks and release workflows. |
| Repository checklist introduction | Repository facts are shown from saved local state. Booster does not contact the provider while rendering this checklist. | Saved repository facts; no live provider check. |
| Verified workflow configuration | Booster verified the canonical release workflow and managed receipt in this repository. | A Booster-compatible workflow configuration was verified. Execution has not been checked. |
| Missing Update URI remedy | This package needs the exact Update URI shown in Published release readiness above. | Open package settings for the required Update URI, add it to the package header, then deploy the corrected package. |

The final two changes clarify the evidence boundary and actual remedy rather
than shortening them. Live verification confirmed that the repository checklist
links to package settings; it does not display the exact Update URI itself.
Specific failure remedies, diagnostic details, source-switch consequences,
credential permissions and downgrade warnings are retained.
