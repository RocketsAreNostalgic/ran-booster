# PHP standards coverage — organisation #119

This records the proposed standards tranche against Core main
`e0f7046521ad3c3b8ab265721efa27b53360d806`. It is source qualification, not a
release, installed acceptance claim or change to the supported APIs.

## Effective contract

The locked `RANWordPressPlugin` ancestry is RAN syntax + WordPress-Extra +
PHPCompatibilityWP. Core sets WordPress 7.0 and PHP 8.2+, established namespace
and hook prefixes, and its own runtime exceptions. It does not enable the whole
WordPress-Docs standard. The original checkpoint retained PHPStan level 1;
the subsequent #127 analysis tranche raises the required floor to level 5.
Direct coverage of all 345 shipped owned PHP files is tested independently
against the release manifest. Generated Admin Shell PHP is selected and remains
immutable. Levels 6–8 and retained-exception acceptance remain separate work.

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
Existing sniff-level (three-component) and exact-message annotations are retained
pending their separate semantic review; this is not acceptance of that inventory.
The test-directory and global namespace exemptions are removed by the dependent
cohort below; existing whole-sniff annotations still require semantic review.

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
Existing sniff-level native annotations remain separate semantic audit work.

## Shared policy adoption after beta.31

The current lock adopts `ran/coding-standards` v1.0.1 at
`0248066be3f4f9476ef7095d888657001488a3de`. Its precise
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
| Public named arguments                     | Existing public factory/DTO parameter names and retained renderer slots remain stable in this standards-only change; they are not new aliases or API compatibility layers.                                                                                                       |
| Callbacks and implicit use                 | Fixed positional slots, pass-by-reference redirect arguments, include locals and compact() fields are retained with declaration-specific reasons.                                                                                                                                |
| Condition evaluation order                 | Mutable expected state must be read before a WordPress option filter can change it; exact comparison exceptions preserve this order. A small number of WPCS token-walk false positives are identified locally.                                                                   |
| Native filesystem and encoding             | Atomic same-directory rename, inode/permission/lock checks, encrypted bytes, temporary capture and exact fixture/archive work cannot be replaced mechanically with WP_Filesystem. Existing native-boundary exceptions remain; added calls in these classes still require review. |
| Security/escaping and database fixtures    | CLI output, non-rendered domain exceptions, trusted pre-escaped fragments, nonce-verifier doubles, controlled SQL and direct disposable database work have specific code/line reasons. These are not blanket security clearance.                                                 |
| Characterization fixtures                  | The existing small XML exceptions for serialized legacy records, CLI source inspection and colocated fixture classes retain their precise files/rules.                                                                                                                           |

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
This closes a reproduced local XML bypass; it does not certify every inherited
rule or dispose the remaining sniff-level annotation inventory. Unexplained or
file-wide exact/sniff-level suppressions still require separate narrowing.

## Completion and separate work

Qualification evidence belongs in the exact opened PR and the central quality
matrix. Passing checks applies to that tree and the defined rules above. It does
not mean maximum PHPStan depth, every possible WordPress sniff, absence of
security defects or completed manual product acceptance.

Organisation #120 retains the pre-1.0 compatibility-path review, including any
uncertain fixture reachability; #121 retains Migrator helper naming; #122 retains
development-tool/WordPress declaration alignment. The existing release pause,
Core #181 and deferred onboarding/UI acceptance are unchanged. The open recovery
documentation PR #225 is independent; preserve its checkpoint when integrating.
