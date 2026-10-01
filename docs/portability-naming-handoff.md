# Portability facade/DTO naming handoff

## Prepared source contract

Base: Core PR #220, commit `8956a6a81d696d01be7b6651ba9b660474881d9a`.
This is isolated source preparation for the Migrator lane under Core #167 and
Migrator #42. It is not a published-host certification, integrated composition,
merge approval or release. The main coordinator owns dependency composition,
shared metadata, final API/version decisions and landing order.

The complete five-class boundary in `RAN/AddOn/Portability/` is Core-owned.
Migrator owns its callers and test doubles. The inventory is receiver-specific:
identical spellings on Blueprint, Provider and other facades remain unchanged.

| Declaration | PHP identifier migration | Connected callers |
| --- | --- | --- |
| `PortabilityFacade` | `nonceAction` → `nonce_action`; `nonce_action` and `apply` parameter `expectedFingerprint` → `expected_fingerprint` | Native facade; Core contract/fixture overrides; Migrator service, presenter, controller and doubles |
| `PortabilityCandidate` | `toArray` → `to_array`; promoted constructor parameters/properties `displayName`, `providerCode`, `credentialId` → `display_name`, `provider_code`, `credential_id` | Both hash builders; Core application service and repository verifier; Core named-argument array spreads; Migrator DTO reads/doubles |
| `PortabilityReviewResult` | `fromResolved` → `from_resolved`; parameters `providerRepositoryId`, `repositoryPrivate` → `provider_repository_id`, `repository_private` | Native facade, application service, contract tests |
| `PortabilityApplyResult` | promoted constructor parameter/property `targetVerified` → `target_verified` | Core facade/fixture assertions; Migrator cleanup admission and presenter; all doubles |
| `NativePortabilityFacade` | constructor parameters `canManage`, `verifyNonce` → `can_manage`, `verify_nonce`; override `expectedFingerprint` → `expected_fingerprint` | Core service-provider positional construction and focused facade tests |

The local `verifiedStatus` variable in the apply DTO becomes `verified_status`.
Existing snake_case methods/properties, magic constructors, `review`, `apply`,
status/reason constants and return types are unchanged. Core reflection checks
cover all methods, parameters and properties on all five classes. Constructor
named-argument array spreads in Core tests are actual PHP identifier contracts;
the keys in those arrays therefore change. The public fixture's readiness
callback keeps its hook name and updates its exact API guard.

## Exact direct Core paths

- All five PHP files under `RAN/AddOn/Portability/`.
- `RAN/Portability/PortabilityApplicationService.php`: candidate property reads
  and `PortabilityReviewResult::from_resolved` only.
- `RAN/Portability/BlueprintRepositoryVerifier.php`: candidate property reads only.
- `tests/Portability/PortabilityContractTest.php`.
- `tests/AddOn/NativePortabilityFacadeTest.php`.
- `tests/AddOn/ExternalFixturePortabilityPluginTest.php`.
- `tests/fixtures/ran-booster-fixture-portability-addon/ran-booster-fixture-portability-addon.php`.
- `docs/portability-api.md` and this handoff record.

`RAN/BoosterServiceProvider.php` constructs the native facade positionally and
needs no naming change. `tests/Security/SecretBoundaryNegativeConformanceTest.php`
references the facade class only. `tests/Admin/AdminAssetContractTest.php`
mentions unrelated JavaScript initialization; it is excluded. There is no
facade callback string to rename besides actual method/reflection references
in the bounded tests. No interface implementation beyond the native subclass
and Core/Migrator test overrides was found in the inspected sources.

## Other add-on inspection and collisions

Read-only complete source searches inspected these exact default heads:

| Repository | Commit | Result |
| --- | --- | --- |
| GitHub Provider | `d39d83747af3109a79e80fd307d50e4fcc34d412` | No runtime Portability facade/DTO or Admin Interaction consumption |
| Bitbucket Provider | `e3bbadca96587d07f655df89bc9eda1515c6dd20` | No runtime Portability facade/DTO or Admin Interaction consumption; lifecycle tests assert zero Admin Interaction callbacks |
| Release bootstrap templates | `11bcf641b39ab5296055015404eea2bf4fe6b24c` | No Portability facade/DTO or Admin Interaction consumption |

The private Workbench clone was unavailable to the local Git transport. Connector
code search returned no results even for known Core symbols and cannot establish
absence. No claim of exhaustive inspection of inaccessible private repositories
is made. Plugin Library work remains excluded by assignment.

API13 shares `BlueprintRepositoryVerifier.php`: preserve the current
`resolve_repository` call and the Provider descriptor's foreign `credentialId`
and `providerRepositoryId` properties. BlueprintPackage's `displayName` and
`providerRepositoryId` likewise remain unchanged. This patch changes only the
`PortabilityCandidate` receiver. Updater protocol-5 has no direct symbol overlap;
shared dependency locks, API documentation/catalogues and bootstrap/release
metadata must be composed by the main coordinator.

## Wire behavior and API proposal

PHP call/property compatibility is broken, so the candidate facade advertises
Portability API 3 and matching Migrator admission must require exact API 3.
Admin Interaction API 2 is unchanged. The migration must not claim an old
certified Core host supports API 3.

Canonical candidate keys remain `type`, `identifier`, `display_name`, `provider`,
`repository`, `branch`, `subdirectory`, `credential_id`. Existing hash payload
version `2` is retained explicitly in both digest builders, independently of the
PHP API generation. Nonce action prefixes and `v1:` fingerprints are unchanged.
This is a proposed compatibility choice for coordinator review: renaming PHP
identifiers alone should not change cryptographic authority inputs. All fresh
authorization, review and apply checks remain required. No database/options,
source row data, wire keys, error/status meanings, output or persisted state
change is intended.

## Coordinator-owned edits and remaining debt

Required shared-file proposals before integration:

- `ran-booster.php`: update the conflict diagnostic from Portability API 2 to 3;
  the published global constant already derives from the facade constant.
- `scripts/verify-release.sh`: update the exact facade constant assertion and its
  diagnostic from API 2 to 3 after the coordinator accepts the API decision.
- `docs/admin-composition-contract.md`: update current Portability API 2 contract
  and exact-admission references to API 3, without changing unrelated facades.
- `.phpcs.xml`: add `PortabilityCandidate.php` and `PortabilityApplyResult.php`
  include patterns to `WordPress.NamingConventions.ValidVariableName`; include
  the candidate in `RANOwnedMethods` for `to_array`. Facade, review result and
  native facade are already opted in. Retain other foreign DTO exceptions.
- Contributor guidance, generated catalogues, language artefact regeneration,
  dependency manifests/locks and compatibility claims remain coordinator-owned.

Do not edit historical certification/characterization/decision-register evidence
to imply it was performed against this candidate. Admin Interaction facade/DTO
naming, Blueprint DTOs, other public facade methods, application service
`reviewCandidate`/`applyCandidate`, repository verifier `resolveCandidate`, and
foreign named parameters are excluded debt.

The receiving implementation PR and exact commit must be recorded in Core #167
before handoff completion. Automated source-candidate evidence does not fulfill
published Core certification or deferred owner-verified installed UI acceptance.

## Local paired checks

Under PHP 8.3.6, using a private mode-0700 `TMPDIR` for the existing encrypted
secret-store filesystem policy, the same `Portability|Blueprint` PHPUnit scope
passed on the exact base (235 tests, 1042 assertions) and this candidate (236
tests, 1136 assertions). The additional test exercises named parameters and
reflects the completed five-class boundary. The three direct facade/contract
suites pass independently (35 tests, 207 assertions). Canonical PHPCS passes on
the scoped production/test files after PHPCBF, and `git diff --check` is clean.
An initial run using ambient `/tmp` failed the same five secure-storage cases
on both base and candidate; provisioning the private temporary directory resolved
them without a source change. These checks are source qualification only.

## Exact shared-file proposal

`docs/portability-integration-proposal.patch` contains unapplied edits for the
main coordinator: diagnostic/archiver API 3, current composition documentation,
complete five-class naming enforcement, Migrator catalogue API 3 and its four
matching installed-extension test fixtures. Apply only after accepting the API
and hash-version choice. The matching fixture uses API 3; its incompatible API
case remains separately asserted through the other required marker.

Then run `bash scripts/make-pot.sh` with WP-CLI 2.12.0 and review the generated
`languages/ran-booster.pot` diff; line-number moves currently block `composer check`.
No translation text changes are intended. Run full Composer/frontend/archive and
installed qualification on the combined integration commit. Update contributor
guidance for the completed cohort during that shared-file composition.

Migrator manifest now proposes required Portability 3. Its dependency lock is
unchanged: automatic approval review rejected `composer update --lock` because
lock ownership remains with the overall coordinator. Refresh its content hash
canonically with no package-record changes before strict validation/merge. The
retained beta.22 certification tuple is historical and must only be replaced by
a real matching immutable published Core tuple followed by all existing gates.
