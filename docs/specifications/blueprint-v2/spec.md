# Transporter Blueprint V2 — beta specification proposal

Status: proposed normative contract, 14 October 2026. This document specifies
future behavior; it does not claim that V2, exact installation or agent access
has shipped. Roadmap: #365; delivery issues: #366–#371; related exploration: #364.
MUST, MUST NOT and SHOULD describe requirements of an implementation claiming
this proposal. The schema and examples accompany this document. Runtime code,
public APIs and storage remain unchanged by this documentation proposal.

## Purpose and boundaries

A Blueprint describes which plugin and theme sources an independent WordPress
site should manage. It may also select packages for initial installation.
Tracking configuration, package selection and observed requirements are distinct.
The format is not a site backup, package registry, approval authority, persistent
update lock, dependency solver or fleet transaction. Database content, uploads,
activation state, source-site deployment policy, hooks, worker state and local
management tokens are not transported as instructions.
Display names are presentation only; differing labels do not create management
or package-identity conflicts.

The same domain export and review logic SHOULD serve the dashboard and future
WordPress Abilities. MCP transport belongs to the WordPress MCP Adapter, not a
second Booster protocol server. Individual sites authenticate independently.

## Document and canonical representation

The top-level keys are `format`, `version`, `packages`, `credentials`:

- `format` is `ran-booster-package-blueprint`; `version` is integer `2`.
- `packages` contains at most 128 records. Identity is `(type, identifier)`;
  duplicate identities are invalid even when other fields differ.
- `credentials` contains at most 128 records and is empty for ordinary discovery
  or export. Nonempty credentials are permitted only inside the explicitly
  encrypted Briefcase workflow described below.
- The UTF-8 canonical JSON is at most 262144 bytes. Archives remain at most
  1048576 bytes, containing only the existing `blueprint.json` payload.
- Unknown keys are rejected. Optional fields MUST be omitted when not supplied;
  only fields explicitly nullable in the schema accept `null`.
- Export recursively sorts object keys lexicographically, sorts packages by
  `(type, identifier)`, and uses compact JSON with unescaped Unicode and slashes.
  Credential ordering and nested normalization retain the existing bounded
  Briefcase rules. API clients submit structured JSON; they need not reproduce
  canonical byte order. Archive reads validate shape and canonical bytes.

The JSON Schema covers shape. Runtime semantic validation additionally enforces
byte bounds, safe identifiers and locators, duplicate identities, credential
associations, cross-field identity agreement and canonicalization. Passing
schema validation is not destination admission or proof of trustworthy input.

## Package record

Every package requires `type` (`plugin` or `theme`), `identifier`, `display_name`,
`provider`, `provider_repository_id`, `repository`, and `source`.
Provider identity is portable; destination credential IDs, adoption tokens,
source revisions, cache tokens and site IDs are not.

`identifier` is a plugin basename or theme stylesheet, using current Core safe
path validation. Locators cannot contain credentials, query strings or fragments.
The destination MUST freshly resolve and verify stable repository identity.
Provider codes and repository IDs are validated through existing contracts.

`source` is exactly one of:

| `kind` | Required configuration | Meaning |
| --- | --- | --- |
| `branch` | `branch`, nullable `subdirectory` | Track that branch and existing supported subtree policy |
| `release_asset` | `channel`, `package_root`, `metadata_file` | Track releases through existing release package identity rules |

Release channels are `stable` and `prerelease`, with their existing eligibility
semantics. A theme's release metadata file is `style.css`; plugin release metadata
must match its expected main file and root. Release subdirectories are not a new
feature. Package identity and source configuration MUST agree; contradictions
are invalid records. No fictitious branch is required for a release record.

## Optional exact initial selection

Omitting `selection` means resolve the current branch head or newest eligible
release using the tracking rules at application time. Basic review MUST label
that choice unresolved until the destination resolves it. Once selected within
an installation operation, acquisition and admission must retain that exact
identity; branch freshness behavior remains source-specific.

A branch selection contains `commit`, a full Git object ID (40 or 64 lowercase
hex characters). It requests that commit in the same verified repository,
independently of current branch head. If a provider cannot acquire it, the row
is blocked; a current-head fence is not a historical commit API. This establishes
Git source selection, not equality of provider-generated ZIP container bytes or
an arbitrary CI build. Existing subdirectory and archive checks still apply.

A release selection contains `release_id`, `tag`, `version`, `commit`,
`artifact_id`, `artifact_sha256`, and positive `artifact_size`.
These are path-free expectations, not imported provider assurances. Fresh
provider identity and trusted digest evidence plus local hashing MUST establish
the selected artifact. Tags/versions alone are insufficient. Destination-local
inspection fingerprints MUST NOT be serialized as portable selection identity:
current prospective fingerprints include runtime versions and channel.
The destination reconstructs its own inspection token before mutation.

An exact initial release MAY differ from the ongoing channel only after explicit
destination consent. Review MUST identify that difference. The provider's
existing supported inspection policy must admit the selected release; no blanket
channel or assurance bypass is authorized. If it cannot, report unsupported
selection rather than fabricate a compatible token. Saving future tracking
configuration and inspecting the initial artifact are separate responsibilities.

Explicit selection MUST NOT silently fall back to latest. Initial selection is
not a lasting lock; it does not authorize downgrades or future file replacement.
New management remains Disabled until deliberately changed on the destination.

## Optional requirement observations

`requirements` is advisory metadata for inexpensive receiver-side preflight.
It contains `basis`, `observed_identity`, and `declarations`.

- `basis: installed_headers` describes the source site's current installed
  headers. It does not prove current head, a release ZIP or unchanged files.
- `basis: selected_package` describes headers inspected from the selected
  source package. `selection` MUST exist. A branch observation MUST contain the
  matching selected commit; a release observation MUST contain the matching
  selected artifact digest. Commit equality alone does not bind release bytes.
  Any additionally supplied facts MUST agree with counterparts present in selection; other observations require their own evidence.
  This is a submitted claim, not imported verification.
- `observed_identity` may contain `version`, `commit`, `artifact_sha256` when
  supported by evidence; unsupported facts MUST be omitted, not guessed.
- `declarations` may contain `requires_php`, `requires_wordpress`,
  `requires_plugins` (plugins only), and `parent_theme` (themes only).
- An omitted declaration means not supplied/read. Explicit `null` for a minimum
  or parent means the header was read and no requirement declared. An empty
  dependency list means the header was read and declared no dependencies.

Requirements are bounded normalized header declarations, not a complete header
dump or executable code. Minimums are numeric version strings, not invented
upper compatibility bounds. Missing declarations are not proof of compatibility.
Requirement observations on moving sources MUST NOT be presented as guaranteed
requirements of the package eventually resolved. A version match alone does not
establish that observation describes identical bytes.

On export, an unreadable or unnormalizable declaration MUST be omitted and
reported as malformed/unsupported in the separate export report. It MUST NOT
be converted to `null` (not declared), nor prevent an otherwise valid
configuration export solely because that optional observation is unavailable.
On import, a supplied observation that violates the schema is document-invalid;
exporter omission is not a license for readers to silently ignore malformed data.

The destination compares these facts locally without downloading every archive
merely for basic review. An incompatibility tied to a selected package yields
an advisory blocked installation recommendation. An installed-header observation
that may differ from the requested package yields a warning requiring resolution,
not an authoritative rejection of unseen newer code. Actual acquisition MUST
validate the package and current environment using existing validators.
Supplied metadata MUST NOT weaken that validation or become verified local facts.

Selected-set summaries derive the highest declared minimum PHP/WordPress and
separate coverage/missing-information counts. They are declared floors, not
guaranteed operable ranges. Do not serialize another authoritative aggregate.

## Dependencies and environmental limits

Reuse WordPress `Requires Plugins` slugs and theme parent declarations.
Review distinguishes present/active, installed/inactive, planned in the selected
subset, omitted and unresolved identities. It MUST NOT map an ambiguous slug
to an arbitrary private repository or automatically fetch missing dependencies.
No version constraints are inferred from WordPress dependency declarations.
Packages planned for installation are not yet installed or active.

Missing activation dependencies need not prevent supported inactive installation
or reading configuration. Activation readiness is reported separately; WordPress
retains its activation enforcement. No automatic activation, dependency editor,
solver or deployment-order engine is introduced. Arbitrary plugin interactions,
undeclared PHP extensions and custom dependency code remain unsupported checks.
Existing Booster extension compatibility may be reported where available; no
general Booster-specific package requirement system is added.

Destination archive-size limits, filesystem policy, runtime support, provider
capabilities, permissions and storage readiness remain destination-owned.
Source policy is not imported. Known checks can be `passed`, `failed`,
`not_checked`, or `unsupported`; missing declarations use their own evidence
meaning. Passing known checks is not a runtime compatibility guarantee.

## Review, subset admission and Apply

Review returns actions and reasons per package, with management differences,
selection comparison, compatibility observations and activation readiness kept
distinct. No review writes credentials, management or package files. Provider
reads are allowed and reported. Optional deeper inspection may acquire, validate
and dispose of a temporary archive under the existing source owner; no retained
archive or planning session is implied. Basic review does not require it.

Malformed documents, duplicate identities or contradictory source/selection
shapes reject the document. Valid entries with missing access, source conflicts,
unknown identity or unsupported acquisition remain individually reportable.

Repository-wide source exclusivity still applies: the same site/repository may
have multiple branch packages or one release package, not both and not multiple
release targets. Mixed Blueprints across different repositories are supported.
Review evaluates the explicitly selected subset against current destination
management and the other selected entries. Conflicting selected groups are
reported before mutation; do not make success depend on Apply order. Omitted
entries do not reserve repositories. Re-review after subset changes.

| Destination state | Configuration action | Exact selection result |
| --- | --- | --- |
| Absent, no management | Offer inactive install and Disabled management | Install selected package, or block unavailable/unsupported selection |
| Installed, unmanaged | Offer explicit adoption without replacing files | Equal/different/unknown independently; adoption alone does not fulfill reproduction |
| Managed, same configuration | No management change | Equal/different/unknown independently; no implicit replacement |
| Managed, different configuration | Protected difference; explicit reconciliation under #369 | Show file selection difference separately |
| Stale/malformed management, unresolved recovery | Protected/blocked | No automatic repair or retry |
| Valid record, missing provider/access | Block affected action | No credential or source fallback |
| Declared PHP/WP mismatch for selected package | Configuration readable; installation recommendation blocked | Actual package remains final validation authority |
| Dependency absent/inactive | Report activation readiness | Inactive installation may proceed if otherwise admitted |

Apply consumes explicitly selected package/action identities and current
destination credential choices. It MUST freshly reauthorize, resolve source and
destination state, enforce source exclusivity under existing locks and verify
postconditions. A review report is not lasting consent or a reservation.
Known changes since review require a new review or explicit current decision;
unresolved latest selections MUST resolve before mutation. Existing-file
replacement/configuration reconciliation is #369 scope, not implicit import.
Older release replacements are blocked pending a separate downgrade policy;
branch commits are not presumed ordered upgrades/downgrades.
Existing branch package-version downgrade guards still apply; unordered commit
identity does not authorize bypassing a lower-version rejection.

Results distinguish selected action success, management reconstruction, exact
selection installation and full requested-set verification. Partial success,
skipped entries, unknown equality or uncertain mutation MUST NOT claim complete
reproduction. Retrying an uncertain operation follows existing recovery policy.
No cross-package atomicity or database rollback is promised.

## Export guarantees and local observations

Configuration export uses current non-cleaning managed readers and does not
substitute remote discovery for installed truth. Exporting a known prior
deployment selection requires evidence bound to repository, target and management
lifetime. Branch attempt `resolved_ref` is useful historical evidence, not proof
that no later update/edit occurred. A plugin version or source revision alone
does not identify a release artifact. Source history may be unavailable.

Exact export MUST return per-entry missing/uncertain evidence in a separate
report. It MUST NOT label an unpinned entry reproducible. A partial proposed
Blueprint can be returned as a draft with explicit incomplete status, but MUST
NOT become a transport-ready exact archive until the operator explicitly accepts
the identified reduced subset. That accepted subset becomes the new requested
set; the exporter must explain that the original set was not reproduced. This
avoids losing omission warnings when the separate report is not transported.
A user can separately choose configuration-only export. Do not downgrade export
mode or silently truncate a reproducible archive.

Two distinct claims are possible: a known package selection can recreate the
selected deployment; proving a copy of current installed files additionally
requires supported file-state evidence. Header checks, fingerprints used for
management and last-success records do not establish installed file equality.
Without such evidence, expose equality as unknown and make no complete-site or
byte-for-byte installed-copy promise. No new permanent file-manifest store is
approved by this spec; implementation must justify missing evidence separately.

Operational status, current runtime, local credential references, last outcome,
recovery blocks and completeness belong to report envelopes, not the portable
document. Report schemas are follow-up contracts under #367/#369/#370; they
must implement these meanings without a second persistent truth store.

## Credentials, encryption and V1 reading

Credential records retain provider, label, kind, bounded configuration, secret
and package associations. Nonempty credentials require the existing AES-256
Briefcase and explicit import/use-target/leave decisions. Canonical JSON does
not itself prove encryption or approval; that is an archive/transport admission
rule. Agent read/review starts credential-free. Preserve constant-backed and
self-destruct export exclusions, explicit provider verification and target-local
profile handling. Do not copy source credential IDs or lifecycle assumptions.
Every credential association MUST resolve to an existing package with the same
provider. A package MUST NOT receive two carried credential associations, and
duplicate credential material is invalid. Preserve current nested configuration
limits (depth 4, 128 nodes, 16 entries per container, 512-byte string values and
64-byte map keys). Never log credential-bearing document or validation payloads.

Export V2 only. If inexpensive, a new reader strictly validates original V1
shape/canonical bytes and normalizes it at the boundary to branch records with
no selection/requirements. It then uses the single V2 model; no parallel runtime.
Do not accept malformed unidentified V2 records as legacy input. If conversion
is costly, explicit beta retirement is preferable to distorting V2. No promise
is made that old software can read V2.

## Contract map and implementation gaps

Evidence checkpoint: Core `5743df3af0a33c35839aec0bb369a72d084b3d7a`;
Branch Updater `c03aa9e49f8938701bebd3249a257ae084afaff2`;
Release Updater `16b28307cd200e0338eb4de431ff75db234dcda9`.
Refresh active consolidation claims and live contracts before implementation.

| Evidence | Current fact | Consequence |
| --- | --- | --- |
| Core `BlueprintPackage`, `ManagedPackageBlueprintExporter` | Eight branch fields; releases explicitly rejected | V2 contract and mixed-source export are new work |
| Core `PortabilityApplicationService`, Portability API 3 | Branch adoption checks Disabled management tuple; public API cannot install | Reuse validation policy, do not claim release adoption or installed equality already exists |
| Core `ManagedReleaseConfiguration` | Channel/root/metadata configuration | Reuse semantics; Blueprint does not invent release ranges |
| Core `NativeProspectiveReleaseFacade` | Inspect/acquire exact release with destination fingerprint; install only absent targets; uses Manual policy | Destination token recreation, requested identifier agreement and Disabled adoption need bounded integration |
| Core `NativeReleaseTrackingFacade` | Enables release source on branch-managed package with checks | Installed-unmanaged release adoption is a gap; do not fake a temporary branch lifecycle |
| Core `DeploymentAttempt` / `DeploymentRequest` | Requested/resolved branch refs and target adoption snapshot | Historical evidence needs lifetime/currentness checks; not file equality |
| Core `RepositorySourceGuard`, PU-035/036 | One repository deployment model, one release package | Preflight subset conflicts; no multi-package release proposal |
| Branch `ProviderArchiveSource` / `ArchiveValidator` | Current-head fencing; PHP/WP and archive checks | Historical commit acquisition requires an explicit supported contract, not expected-head reuse |
| Release `IdentityDescriptor`, `ProspectiveReleaseInspection` | Artifact facts available inside updater; prospective fingerprint includes runtime/channel | Portable selection must expose bounded facts through correct owner; never copy local token |
| Release `PackageIdentityValidator` | Identity, declared PHP/WP and manifest checks | Reuse installation validation; no Blueprint validator duplicate |

Decision registers PT-001/004/006/015/019 and PU-001/002/007/024 remain relevant.
This proposal does not override their rejected retained sessions, public generic
installer, secret handoffs or second artifact custody paths. New exact-selection
cases require bounded feasibility/ownership review, not automatic resurrection.

## Examples and acceptance scenarios

The example JSON is readable rather than canonical archive bytes. IDs/digests
are fictional. `branch.json`, `pinned-branch.json`, `release.json`,
`pinned-release.json` and `mixed.json` validate the proposed shape.

| Scenario | Expected review/application behavior |
| --- | --- |
| Installed headers need PHP 8.2; destination 8.1; unpinned source | Warn observation mismatch/uncertain future requirements; resolve actual package before installation |
| Selected package declares PHP 8.2; destination 8.1 | Basic installation recommendation blocked; selected package revalidated if later resolved on a changed environment |
| Requirements absent | Not supplied; no compatibility guarantee; normal final validation |
| Installed header minimum cannot be normalized | Omit that observation, report malformed/unsupported; configuration export remains possible |
| Exact commit unavailable or current branch advanced | Acquire historical commit only if supported; otherwise block, no latest fallback |
| Exact release same version but changed digest | Reject changed selection; no version-only match |
| Destination PHP differs from source | Rebuild local inspection token; portable selection remains unchanged |
| Release initial selection outside stable tracking | Explicit warning/consent; provider policy must support inspection; no implicit bypass |
| Two selected release packages from same repository | Report source conflict before mutation |
| Branch companion and release from same repository | Conflicting selected group blocked; subset may be re-reviewed |
| Package B requires A; A omitted but active locally | Report dependency satisfied locally without fetching A |
| A selected but not yet installed/active | Planned dependency, not activation-ready |
| Equal version headers but unknown artifact/files | Unknown equality; no reproduction claim |
| One identity unknown during exact export | Incomplete draft/report; no transport-ready exact archive without explicit acceptance of a newly scoped subset |
| Recovery block or target changes after review | Block/re-review through existing policy; never auto-retry uncertainty |
| One package fails among several selected | Per-entry outcomes; requested-set incomplete, no rollback/atomicity claim |
| Valid V1 archive | Strict legacy validation then small normalization if delivered |
| Credential-bearing document through ordinary agent API | Reject at transport admission; do not expose secrets |

Each field has a present consumer: identity for destination/repository matching,
source for ongoing management, selection for reproducible initial acquisition,
requirements for inexpensive preflight, credentials for existing encrypted
handover. No fleet IDs, lock flags, signatures, environment overlays, producer
approval claims or opaque session references are added.

Design and documentation can proceed during consolidation. Implementation PRs
must wait for relevant overlapping work to land or be explicitly deferred,
quantify deltas and qualify exact connected contracts. The JSON Schema is a
proposal/validation artifact, not runtime enforcement or evidence of shipped V2.
