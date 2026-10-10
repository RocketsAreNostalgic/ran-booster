# PHP standards coverage — organisation #119

Core standards and maintained-PHP coverage are delivered through PR261, PR262
and the Level8 enforcement in PR296 on main
`16127f8eb328efae2f0a5bfcb2100ff0f773034b`. Exact post-main Quality passed.
The scoped security exceptions were [owner accepted in PR261](https://github.com/RocketsAreNostalgic/ran-booster/pull/261#issuecomment-6035893321); their current contracts and reopening triggers remain binding.
The historical counts below describe the tranches that established these guards.
This source acceptance does not establish UI, manual or Migrator acceptance.

## Effective contract

The locked `RANWordPressPlugin` ancestry is RAN syntax + WordPress-Extra +
PHPCompatibilityWP. Core sets WordPress 7.0 and PHP 8.2+, established namespace
and hook prefixes, and its own runtime exceptions. It does not enable the whole
WordPress-Docs standard. The original checkpoint retained PHPStan level 1;
subsequent #127 tranches raised the floor to levels 5, 6, 7 and now 8.
Direct coverage of shipped owned PHP is tested independently against the release
manifest (345 files at the original checkpoint). Generated Admin Shell PHP is
selected and remains immutable. Levels 9 and 10 are outside this programme. The three exact foreign
update-transient property exceptions are documented in `CONTRIBUTING.md`; broader
changes to retained exceptions still require their own evidence and review.

The default PHP selection covers root entrypoints, RAN, views, assets, scripts,
tests and fixtures. Vendor, node_modules, Workbench and generated test/cache
output are excluded; they are not omitted owned runtime PHP. The following
checks no longer depend on lists of completed migration cohorts:

- WordPress Yoda equality/inequality conditions (formerly 32 paths).
- Unused parameters, including before-last-used and inherited/interface cases
  (formerly nine paths). An implemented interface must not mask a private helper.
- Reserved parameter names (formerly two request files).
- Existing owned-method and variable naming enforcement remains enabled.

Canonical PHPCS fails on both errors and warnings. Tests feed fresh ordinary
paths through the actual locked checker to prove these rules apply beyond old
cohorts, reject unused inherited-class helpers, accept compliant code and contain
an exact annotation to its intended declaration. A token-aware inventory guard
rejects blanket disable, blanket ignore and ignoreFile annotations in maintained
PHP while distinguishing actual comments from fixture strings.

## Exemption hardening under organisation #134

The 32 existing handwritten views retain only the variable-prefix diagnostic through
file-local explanations. New files, including nested views, have no automatic
waiver. The immutable generated Admin Shell retains one exact-filename message
exception, with a tracked-inventory guard rejecting any other matching path.
A single dynamic hook invocation in `views/portability.php` has an exact-line
exception: both callers pass literal `ran_booster_portability_*` hooks. New
functions, classes, constants, hooks and unreviewed variable bindings remain checked. No executable
production tokens change.

The token-aware guard now rejects case variants and standard/category selectors,
including a broad selector mixed with an exact one. Actual-checker probes prove
these directives suppress diagnostics while the independent guard rejects them.
The earlier sniff-level annotations and test-directory/global namespace exemptions
have since been narrowed or removed. Retained allowances now name exact diagnostics;
this mechanical scope correction does not establish semantic acceptance of every occurrence.

## Test prefix hardening under organisation #134

The entire test tree now receives PrefixAllGlobals checks by default. The 24
existing CLI/harness files with unprefixed local state carry the exact variable
message exemption in source; future files have no automatic exemption. Owned
helper declarations are renamed rather than waived: 15 phase-4.4 CLI helpers,
six CLI/provider fixture classes and one harness marker, with all references.

The removed directory waiver exposed 177 retained foreign-boundary diagnostics:
112 WordPress function doubles, eight host class identities, 37 WordPress
constants, 16 lifecycle hook invocations and four selected configuration markers.
Each now has a specific-message occurrence annotation explaining the retained
identity. Tests prove an annotated host double cannot hide the next owned helper
and new root/nested tests or production paths named `tests` still fail naming.
No production executable PHP, public API, dependency or persistent-state change is included.
The global namespace severity override is also removed. Fifteen production
`namespace RAN` declarations retain exact-line explanations for the established
three-character API root. The owned Composer development namespace and its callers now use RAN\Tests;
the two owned release-contract namespaces and twelve external-fixture namespace
declarations also use compliant prefixes. Autoload-dev and fixture references
follow the renamed declarations; no compatibility aliases are introduced.
Seven exact-root RAN test-interception declarations retain the same minimum-length
checker explanation as production. Real-checker controls reject unrelated
namespaces and show an annotated root cannot hide the next declaration.
Retained occurrence-local native-operation allowances still require their specific
contract evidence and reviewed disposition.

## Shared policy adoption after beta.31

The current lock adopts `ran/coding-standards` v1.0.3 at
`28f6e7c0a758c93503a0267696245a5540e002a5` through PR295. Its consumed rulesets
and owned-method sniff retain the reviewed v1.0.1 behavior. The earlier v1.0.1
adoption introduced the precise
`WordPress.Security.EscapeOutput.ExceptionNotEscaped` exclusion recognizes that
exceptions carry diagnostic values; actual output must still be escaped at its
rendering boundary. Core removes 47 occurrences of that selector across 30
non-shipped test PHP files (37 ignores, five disables and five enables), plus the
redundant characterization-path XML rule. Eight mixed directives retain their
other selector and explanation; exception-context comments remain ordinary comments.

The 73 original annotations in 15 shipped PHP files remain necessary for the
separate required installed Plugin Check scanner, which uses its own WPCS profile
rather than Core's shared development profile. Removing them caused 76
`ExceptionNotEscaped` diagnostic instances in that independent gate. The annotations
and their original reasons remain unchanged; aligning that scanner is a separate
explicit decision, not part of this adoption. `release-files.txt` excludes the
changed `tests/` tree, including its fixture provider. No shipped PHP changes.

No executable PHP tokens, public contracts, other suppressions, required checks
or runtime dependencies change. This is a development-policy adoption and
redundant test-suppression cleanup, not whole-inventory semantic acceptance.
The tranche record below remains historical evidence.

## Findings and changes

The pre-change broad scans found 28 Yoda diagnostics, 12 production and 417 test
unused-parameter diagnostics, and 54 reserved-parameter warnings. These counts
are inspection findings, not independent runtime bugs. The implementation fixes
owned expressions and helper signatures, updates callers and documents actual
foreign/public signatures, closure argument positions and implicit use through
included templates or compact(). Public DTO property names, persisted keys,
WordPress signatures, supported API generations and wire schemas are preserved.
Removed private arguments have side-effect-free owned callers; no translator,
filter or credential work is silently removed to satisfy a sniff.

All 29 blanket-suppressed test/harness files are brought into normal enforcement.
Their unmasked scan found 663 mechanically fixable formatting diagnostics and
241 non-fixable findings with the widened rules. Formatting and owned-name or
condition corrections are separate from justified native fixture annotations.
Disposable-site, lifecycle, race, filesystem and wpdb doubles retain their actual
contracts; they are not reclassified as historical or deleted to clear checks.
No new blanket file/class exemption replaces the old blanket disables.

Two broad MissingTranslatorsComment regions in ProviderSettingsPresenter and
ProviderRepositoryRowsNormalizer are removed. Translator comments are attached
to the actual gettext calls and reconciled with shared message/context pairs.
The generated catalogue is checked by the repository's warning-fatal generator.

## Retained policy and exact exceptions

| Boundary                                   | Why it remains                                                                                                                                                                                                                                                                   |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PSR-4 class filenames                      | Shared ancestry intentionally excludes WordPress hyphenated/lowercase class-file rules.                                                                                                                                                                                          |
| RAN namespace                              | WPCS hard-codes a four-character minimum; Core retains its established three-character root with exact declaration annotations; the namespace diagnostic remains enabled for all other declarations.                                                                               |
| Test global names and included view locals | WordPress/native test doubles need real function names; included template locals are not plugin globals. Existing handwritten views explain NonPrefixedVariableFound at the file; new views have no automatic exemption. The immutable generated view retains one exact-filename rule guarded against duplicate-path collisions. The former tests exemption is removed; existing CLI locals have exact-message annotations, foreign declarations have occurrence-local reasons, and owned helpers are prefixed.                                                                                                             |
| Native methods, properties and signatures  | PHPUnit lifecycle names, WordPress/wpdb/Requests contracts, MySQL metadata and ZipArchive fields cannot be renamed as owned identifiers. Exact annotations name the boundary.                                                                                                    |
| Public named arguments                     | Owned reserved factory/DTO parameter exemptions are removed under #167: use `wp_theme`, `plugin_data` and `is_private`. Positional order/defaults, public readonly `private` properties and wire fields remain stable; no aliases are introduced. Fixed renderer callback slots retain their separate positional-contract reasons.                                                                                                       |
| Callbacks and implicit use                 | Fixed positional slots, pass-by-reference redirect arguments, include locals and compact() fields are retained with declaration-specific reasons.                                                                                                                                |
| Condition evaluation order                 | Mutable expected state must be read before a WordPress option filter can change it; exact comparison exceptions preserve this order. A small number of WPCS token-walk false positives are identified locally.                                                                   |
| Native filesystem and encoding             | Atomic same-directory rename, inode/permission/lock checks, encrypted bytes, temporary capture and exact fixture/archive work cannot be replaced mechanically with WP_Filesystem. Existing native-boundary exceptions remain; added calls in these classes still require review. |
| Security/escaping and database fixtures    | CLI output, non-rendered domain exceptions, trusted pre-escaped fragments, nonce-verifier doubles, controlled SQL and direct disposable database work have specific code/line reasons. These are not blanket security clearance.                                                 |
| Characterization fixtures                  | Former characterization XML exclusions are removed. Required historical identity, CLI inspection and colocated-fixture allowances are occurrence-local. The sole local rule-specific XML exception is the generated Admin Shell variable binding, protected by parity and duplicate-path guards.                                                                                                                           |

Inherited WPCS severity-zero overrides include intentional formatting overlaps
with WordPress-specific rules. They are not a request to re-enable contradictory
upstream conventions. Core explicitly restores the seven unused-parameter
callback/extended/interface codes as blocking errors.

Local rule-level severity overrides must remain integers at or above the default
minimum diagnostic severity of five. The existing
`StandardsCoverageTest` checks this directly and requires the exact variable-name
diagnostic across root, source, view, script and test paths. Its negative control
copies the real ruleset, sets that diagnostic to severity zero, one and four, and proves both
that PHPCS hides the violation and that the independent guard rejects the change.
The same guard pins the five reviewed local checker arguments and proves that
`sniffs`/`exclude` arguments hide diagnostics when no probe override is present.
Source-level property changes (`phpcs:set` and legacy setting directives) are
rejected, including case variants; real-checker controls demonstrate how they
can replace the approved prefix policy.
These controls reject the reproduced XML and annotation bypasses; they do not certify
every inherited rule or dispose all retained exact-diagnostic allowances. Only the
32 caller-bound handwritten views retain persistent variable-prefix suppressions;
semantic acceptance of retained occurrences remains separate from passing guards.

## Focused fragment and passive-query evidence

Four existing exact security-diagnostic occurrences were owner accepted under
#167/#65 in PR261 comment6035893321, with the bounded contracts and reopening
triggers recorded in comment6033998016. Passing checks alone did not establish
that acceptance. No broader callback-output or passive-input exception is implied.

- `RepositoryDetailRenderer::render()` emits the captured webhook fragment supplied
  by `views/provider.php` through Core `RepositoryWebhookManagementControls` and
  its fixed escaping panel template. `render_release_content()` emits the captured
  repository-release action output; the current Core registrar is
  `ReleaseWorkflowControls`, whose presenter feeds the escaping display. The action
  is mutable trusted PHP, not proof that arbitrary callbacks are safe, and this
  evidence does not declare a new public add-on surface. Existing renderer tests
  now verify preescaped fragment preservation and escaped row values, unchanged
  form/HTMX/nonce markup, empty
  output fallbacks, discarded partial output on exceptions and buffer restoration.
  Actual escaping-owner evidence is separate: `ReleaseWorkflowDisplayTest` checks
  hostile presenter fields; `RepositoryWebhookManagementControlsTest` now passes
  a hostile credential label through the real repository panel.
- `DeploymentAdminPresenter::query_has_key()` and `query_value()` read passive
  activity selectors. Presence preserves detail mode; canonical positive-integer
  and exact correlation validation precede attempt/history queries. Populated
  repository tests now record reads and writes for malformed, partial, array and
  overflow selectors, with a successful passive history read as the positive
  control. Invalid detail remains unavailable=false with no detail; invalid cursor
  remains unavailable=true. No nonce requirement or mutation authority is added.

These controls supply bounded semantic evidence for the four occurrences. They
neither certify every callback nor replace existing capability, mutation nonce,
real-checker outside-scope and exact-candidate review requirements.

## Completion and separate work

Qualification evidence belongs in the exact opened PR and the central quality
matrix. Passing checks applies to that tree and the defined rules above. It does
not mean maximum PHPStan depth, every possible WordPress sniff, absence of
security defects or completed manual product acceptance.

Core #160 and organisation #120 own the separately reviewed bootstrap/test-guard
cleanup and retained-path dispositions. Current release and ecosystem integration
status belongs in organisation #65. Deferred UI/onboarding and Migrator manual
acceptance remain separate; historical release proposals are not current gates.
