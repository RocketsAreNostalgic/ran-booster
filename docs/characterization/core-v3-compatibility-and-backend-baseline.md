# Core compatibility and backend baseline

**Frozen source object:**
`af48bf488e7ad6ea41bd88608aa09f6b6547d936`

**Evidence carrier:**
`191adca5a0d2ff1853e8882d4b9142f8a7e18047`, a documentation-only descendant
of the frozen source object. It changes no PHP file.

**Decision:** This is the compatibility and measurement boundary for the Core
cohesion work. It authorizes no runtime change. Provider API 8, Add-on API 14,
Admin Interaction API 2, Portability API 2 and Prospective Release API 5 remain
stable. The provider-page slice may replace the internal ownership named below,
but it may not add a public API, hook, persistent field, dependency or generic
operation layer.

## Reproducible measurement

The counter uses physical newline-delimited lines from the exact Git object.
Shipped PHP is every tracked `*.php` file except `tests/**`, `scripts/**` and
`vendor/**`. Backend PHP is shipped PHP minus only this reviewed passive
allowlist:

- `views/admin-feedback-toast.php`
- `views/packages/advanced-source-settings.php`
- `views/packages/repository-configuration.php`
- `views/packages/fields/branch.php`
- `views/packages/fields/subdirectory.php`
- `views/packages/fields/repository.php`
- `views/packages/fields/provider.php`
- `views/packages/fields/credential.php`

The reproducible shell shape is:

```sh
baseline=af48bf488e7ad6ea41bd88608aa09f6b6547d936

shipped=$(
  git ls-tree -r --name-only "$baseline" \
    | awk '/\.php$/ && $0 !~ /^(tests|scripts|vendor)\// {print}' \
    | while IFS= read -r file; do git show "$baseline:$file"; done \
    | wc -l
)

passive=$(
  for file in \
    views/admin-feedback-toast.php \
    views/packages/advanced-source-settings.php \
    views/packages/repository-configuration.php \
    views/packages/fields/branch.php \
    views/packages/fields/subdirectory.php \
    views/packages/fields/repository.php \
    views/packages/fields/provider.php \
    views/packages/fields/credential.php
  do
    git show "$baseline:$file"
  done | wc -l
)

printf 'shipped=%s passive=%s backend=%s\n' \
  "$shipped" "$passive" "$((shipped - passive))"

git grep -h -E \
  '^[[:space:]]*(abstract[[:space:]]+|final[[:space:]]+|readonly[[:space:]]+)*(class|interface|trait|enum)[[:space:]]+[A-Za-z_][A-Za-z0-9_]*' \
  "$baseline" -- '*.php' ':(exclude)tests/**' ':(exclude)scripts/**' \
  ':(exclude)vendor/**' | wc -l

git ls-tree -r --name-only "$baseline" \
  | awk '/^tests\/.*\.php$/ {print}' \
  | while IFS= read -r file; do git show "$baseline:$file"; done \
  | wc -l

git grep -h -E \
  '^[[:space:]]*public[[:space:]]+function[[:space:]]+test[A-Za-z0-9_]*[[:space:]]*\(' \
  "$baseline" -- 'tests/*.php' | wc -l

for file in \
  RAN/Dashboard.php \
  RAN/Dispatcher.php \
  RAN/Admin/ProviderSettingsPresenter.php \
  views/provider.php
do
  git show "$baseline:$file"
done | wc -l
```

| Measure                                             | Frozen value |
| --------------------------------------------------- | -----------: |
| Shipped PHP                                         | 47,094 lines |
| Passive allowlist                                   |    207 lines |
| Backend PHP                                         | 46,887 lines |
| Named shipped classes, interfaces, traits and enums |          253 |
| Test PHP                                            | 57,137 lines |
| Public `test*` methods                              |        1,454 |
| Documentation-only carrier PHP delta                |      +0 / -0 |

The current admin cluster is wholly backend under that classifier:

| Surface                                   |     Lines | Current responsibility                                                               |
| ----------------------------------------- | --------: | ------------------------------------------------------------------------------------ |
| `RAN/Dashboard.php`                       |     2,246 | Route, request projection, page assembly, messages and rendering                     |
| `RAN/Dispatcher.php`                      |     1,473 | Capability/nonce boundary and unrelated mutation dispatch                            |
| `RAN/Admin/ProviderSettingsPresenter.php` |     1,121 | Provider, profile, repository, usage and readiness acquisition                       |
| `views/provider.php`                      |     1,325 | Model construction, filtering, sorting, paging, URL/nonce construction and rendering |
| **Cluster**                               | **6,165** | **Entirely backend before separation**                                               |

Moving code from the view to another backend file is not deletion. A later
passive-view reclassification must be reported separately from physical line
deletion. The whole admin programme must eventually remove at least 15% of this
6,165-line backend baseline, but the provider-page slice has no invented
standalone percentage target: it must be physically net-negative and must name
every replacement owner.

## Public compatibility surface

### Version markers and exact facades

| Marker / delivery point                       | Frozen contract                                                                                                                                                                            |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `RAN_BOOSTER_PROVIDER_API_VERSION`            | `8`; one sealed `ProviderRegistry`; providers register before the priority-100 `plugins_loaded` seal                                                                                       |
| `RAN_BOOSTER_ADDON_API_VERSION`               | `14`; request-local ready actions and the bounded dashboard-tab registry                                                                                                                   |
| `RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION`   | `2`; `AdminInteractionFacade::{renderFormAttributes,isEnhancedRequest,respond}` plus feature-detected `TransporterRowAdminInteractionFacade::respondWithTransporterRowFragment`            |
| `RAN_BOOSTER_PORTABILITY_API_VERSION`         | `2`; `PortabilityFacade::{nonceAction,review,apply}`                                                                                                                                       |
| `RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION` | `5`, defined only when the selected updater reports the matching prospective API; `ProspectiveReleaseFacade::{nonceAction,supportedProviderCodes,listCandidates,discover,inspect,install}` |
| Add-on API 14 release tracking                | `ReleaseTrackingFacade::{status,statuses,nonceAction,preflight,enable,changeChannel,refresh,returnToBranch}`                                                                               |
| Add-on API 14 webhook assistance              | `WebhookAssistanceFacade::{readiness,target,credentialChoices,profile,assessSetup,assessCheck,assessReconfigure,assessRemove,setup,check,reconfigure,remove}`                              |
| Dashboard tab allowlist                       | Facade names `webhook_assistance` and `release_tracking` only                                                                                                                              |
| `RAN_BOOSTER_RUNTIME_MODE`                    | Current runtime support marker; incompatible multisite does not enter managed-operation bootstrap                                                                                          |

The Prospective Release lifecycle is also frozen: inspection downloads,
verifies and discards the exact release ZIP; installation freshly reacquires
the archive. The shared updater owns archive custody and verification,
WordPress owns installation, and Core adopts only after its postconditions.

### Core-owned extension hooks

The argument count and order below are compatibility facts. A provider-page
rewrite may change internal producers but not these hook calls or their bounded
payloads.

| Hook                                                               | Kind               |                                                    Arguments |
| ------------------------------------------------------------------ | ------------------ | -----------------------------------------------------------: |
| `ran_booster_register_providers`                                   | action             |                                        1: `ProviderRegistry` |
| `ran_booster_register_admin_tabs`                                  | action             |                                      1: `AdminAddOnRegistry` |
| `ran_booster_admin_interaction_ready`                              | action             |                                  1: `AdminInteractionFacade` |
| `ran_booster_portability_ready`                                    | action             |                                       1: `PortabilityFacade` |
| `ran_booster_webhook_assistance_ready`                             | action             |                                 1: `WebhookAssistanceFacade` |
| `ran_booster_release_tracking_ready`                               | action             |                                   1: `ReleaseTrackingFacade` |
| `ran_booster_prospective_release_ready`                            | conditional action |                                1: `ProspectiveReleaseFacade` |
| `ran_booster_admin_provider_repository_assistance_active`          | filter             |                                2: active flag, provider code |
| `ran_booster_admin_provider_repository_rows`                       | filter             |    4: base rows, provider code, safe projections, return URL |
| `ran_booster_admin_provider_repository_panel`                      | action             |                  3: provider code, repository ID, return URL |
| `ran_booster_documentation_sections_after_provider_{providerCode}` | filter             |                        3: sections, documentation URL, scope |
| `ran_booster_admin_package_source_choices`                         | filter             |         5: choices, mode, type, package projection, page URL |
| `ran_booster_admin_package_advanced_source_sections`               | action             | 5: mode, type, selected source, package projection, page URL |
| `ran_booster_admin_package_advanced_source_summary`                | filter             |  5: summary, mode, type, selected source, package projection |
| `ran_booster_admin_package_management_rows`                        | filter             |              3: base rows, package type, package projections |
| `ran_booster_admin_package_management_actions`                     | filter             |                 3: actions, package type, package projection |
| `ran_booster_admin_package_settings_sections`                      | action             |                          2: package projection, settings URL |
| `ran_booster_admin_package_webhook_cleanup_actions`                | action             |                                           1: cleanup context |
| `ran_booster_documentation_sections_before_about`                  | filter             |                        3: sections, documentation URL, scope |
| `ran_booster_overview_render_migration_prompt`                     | action             |                                                            0 |
| `ran_booster_portability_render_migration_modes`                   | action             |                                                            0 |
| `ran_booster_portability_render_migration_flows`                   | action             |                                                            0 |
| `ran_booster_pro_page_body`                                        | action             |                             2: Pro URL, administration scope |
| `ran_booster_background_deployment_failure_email`                  | filter             |           2: normalized mail record, deployment outcome data |

The assistance-active filter, webhook-cleanup action and background-email
filter are live source seams even though the main administration-composition
guide does not currently enumerate all three. Treat that documentation gap as
cleanup, not permission to remove a hook during the provider-page slice.

### Observed out-of-tree consumers

This inventory covers the checked-out family repositories. It does not claim
knowledge of private third-party installations, so all published contracts
above remain protected.

| Consumer object                                                | Actual Core dependency                                                                                                                                                                                                   |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Assisted Hooks `d46d016098d0ac7a3ce7af9c446241e813ae670a`      | Add-on API 14; Admin Interaction API 2; exact webhook-assistance facade and its fitness/operation result types; provider-repository active/rows/panel hooks; provider documentation filter                               |
| Bitbucket Cloud `0a3de24a46b4f55a3d8dcbf3cf97d6591b6df8e8`     | Provider API 8 and Add-on API 14; `ProviderRegistry::registerWithCredentialStore`; provider metadata, credential, browser, archive, webhook and diagnostics interfaces/value types; provider documentation filter        |
| Release Deployments `f674226a4f8e450789082e68d63e5961150f7fad` | Add-on API 14; Prospective Release API 5; release-tracking and prospective-release ready actions; structural gateways requiring the exact facade methods listed above; package management/source and documentation hooks |
| WP Pusher Migrator `47cedf05310c4bbf49cc57d76aa9974ff5165f25`  | Portability API 2; Admin Interaction API 2 and feature-detected Transporter-row capability; portability and overview rendering hooks                                                                                     |

No checked-out production sibling directly constructs, subclasses or
implements `RAN\Dashboard`, `RAN\Dispatcher`,
`RAN\Admin\ProviderSettingsPresenter`, `RAN\Secrets\SecretsFile` or
`RAN\Package`. That is evidence for internal movement, not proof that an
unknown external clone has no implementation coupling. `RAN\Package` therefore
stays unchanged in this programme.

Core tests contain implementation coupling that is not automatically public
API: the source scan found 115 reflection references, 23
`newInstanceWithoutConstructor` references, 24 subclass references to the four
named hotspots and 74 direct constructions of those hotspots. A touched slice
must replace only the coupling made redundant by an outer outcome test; it must
not create production interfaces solely for mocks.

## Persistent state formats

No provider-page change may alter these formats.

### Core database schema 12.0

Core owns three active tables. The package table uses the resolved Booster
package prefix; the attempt and audit tables use the current WordPress prefix.

- `ran_booster_packages`: `id`, `package`, `repository`, `branch`, `type`,
  `deployment_policy`, `source`, `source_revision`, `source_previous`,
  `source_changed_at`, `source_changed_by`, `provider`,
  `provider_repository_id`, `private`, `credential_id`, `subdirectory`,
  `release_configuration`. Primary key `id`; unique `(type, package)`; index
  `(provider, provider_repository_id)`. When present, `release_configuration`
  is canonical JSON in the exact key order `channel`, `package_root`,
  `metadata_file`; channel is `stable|prerelease`.
- `ran_booster_deployment_attempts`: `id`, `correlation_id`, `source`,
  `operation`, `package_type`, `package_slug`, `package_source`,
  `package_source_revision`, `provider`, `provider_repository_id`,
  `requested_ref`, `resolved_ref`, `delivery_id`, `delivery_digest`, `state`,
  `mutation_started_at`, `outcome_code`, `request_json`, `created_at`,
  `finished_at`, `resolved_at`, `resolved_by`. Primary key `id`; unique
  `correlation_id`; unique webhook target `(provider, delivery_id,
package_type, package_slug)`; queue and package-history indexes.
- `ran_booster_rejected_admission_audit`: `id`, `event`, `attempt_id`,
  `correlation_id`, `package_type`, `package_slug`, `actor_id`, `operation`,
  `occurred_at`. Primary key `id`; deduplication, activity and attempt-activity
  indexes.

`ran_booster_native_update_activity` is legacy uninstall cleanup only; it is
not created or read by schema 12.0.

### Options, user metadata and scheduled state

| Identity                                             | Format / meaning                                                                                                                                                     |
| ---------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ran_booster_db_version`                             | Non-autoloaded string, currently `12.0`                                                                                                                              |
| `ran_booster_credential_expiry_observations`         | Non-autoloaded version-1 document: `version`, then provider/profile maps containing bounded `manual_expires_on`, `provider_expires_at`, `provider_checked_at` fields |
| `ran_booster_public_repository_lookup_profiles`      | Non-autoloaded provider-code to credential-profile-ID map                                                                                                            |
| `ran_booster_secrets_key_v1`                         | Non-autoloaded canonical base64 encoding of the exact 32-byte sidecar key; never presentation data                                                                   |
| `ran_wp_gh_op_v1_21aa4aa53bf3b6f4ff5e4af259c4014a`   | Shared-updater authority option for exact Core target `plugin\0ran-booster\0ran-booster.php`; updater-owned and removed by verified Core uninstall                   |
| `_ran_booster_development_safety_notice_dismissed`   | Per-user literal dismissal marker                                                                                                                                    |
| `_ran_booster_credential_expiry_notice_fingerprint`  | Per-user bounded notice fingerprint                                                                                                                                  |
| `_ran_booster_background_failure_notice_fingerprint` | Per-user bounded failure fingerprint                                                                                                                                 |
| `ran_booster_run_deployment`                         | Single-event WP-Cron wakeup; durable deployment truth remains in the attempt table                                                                                   |

The provider-page slice must not absorb the updater-owned authority option.

### Filesystem state

- The secrets sidecar uses canonical schema version 2 with exactly
  `schema_version`, `credentials` and `webhooks` at the root. Credential records
  contain `label`, `kind`, `configuration`, `secret` and optional bounded
  self-destruction fields. Webhook records contain `label`, `scope`, `target`,
  `authority_id`, `revision`, `origin` and `secret`.
- `RAN_BOOSTER_ENCRYPTED_SECRETS_DIR` is the current configured location;
  `RAN_BOOSTER_ENCRYPTED_SECRETS_FILE` is the accepted legacy exact-file
  overlay. The encrypted file and its `.lock` remain one custody set.
- `ran-booster-debug.php` and its `.lock`, beside the secrets sidecar, use
  capture format 1 with `owner`, `format`, `active_until`, `expires_at` and
  bounded `entries`. They are temporary diagnostic state, not a general log.

## Frozen operator journeys

| Journey                                           | Request / adapter                                                                                 | Operation owner and canonical input                                                                                                 | Bounded result                                                                                             | Authoritative readback                                                             | Status                                                                                              |
| ------------------------------------------------- | ------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------- |
| Render provider overview                          | `Dashboard::getIndex()` selects a provider tab and normalizes `view`, `panel` and list query keys | `ProviderSettingsPresenter::build(providerCode)` acquires safe provider/profile/repository/readiness data                           | Core-owned provider page                                                                                   | Fresh safe presenter projection plus rendered semantic page state                  | Browser-bound; no mutation                                                                          |
| Search, filter, sort or page credentials/webhooks | Native GET on the provider route                                                                  | `Dashboard::requestedProviderListState()` supplies bounded search/kind/scope/status/order/page input; the view currently applies it | Matching rows, counts and page state                                                                       | Rendered table, controls and empty state                                           | Browser-bound; must become one internal read-model operation in the provider-page slice             |
| Review managed repositories                       | Provider `panel=repositories` GET                                                                 | Presenter repository/readiness payload; the view currently builds source, policy, secret-coverage, consequence and action rows      | Safe Core rows plus bounded add-on enrichment                                                              | Rendered Core table and exact selected repository                                  | Browser-bound; extension hook contract remains stable                                               |
| Save/delete a credential or webhook profile       | Core form posts `ran_booster[action]` under `ran-booster-save-secrets`                            | `Dispatcher::dispatchPostRequests()` repeats `manage_options`, nonce, provider/profile validation and calls `SecretsFile`           | Success or bounded validation/unexpected failure; native PRG or the same Core region for enhanced requests | Fresh `ProviderSettingsPresenter::build()` projection from sidecar and usage state | Browser-bound; mutation authority remains in Dispatcher/application owners, never in the read model |
| Validate a saved credential                       | `validate-access-profile` on the same protected Core form                                         | Dispatcher resolves the provider-owned validator and the exact saved profile                                                        | Bounded validation result; no plaintext                                                                    | Fresh provider/profile status and expiry observation                               | Browser-bound                                                                                       |
| Change public lookup default                      | `save-public-lookup-profile` with its exact nonce                                                 | Dispatcher validates provider/profile and writes `PublicRepositoryLookupProfileStore`                                               | Bounded success/failure and one Core region                                                                | Fresh option-backed profile projection                                             | Browser-bound                                                                                       |
| Assisted repository webhook operation             | Assisted Hooks `admin_post` adapter                                                               | Add-on checks its request, then calls the exact nonce-coupled `WebhookAssistanceFacade`; Core re-derives target/profile authority   | Fixed fitness or operation result                                                                          | Core facade/provider authoritative readback plus add-on installation record        | Browser-bound because the published facade independently requires the WordPress nonce               |
| Release tracking and prospective install          | Release Deployments adapters                                                                      | Exact release-tracking or Prospective Release API 5 facade methods                                                                  | Bounded status/preflight/result                                                                            | Core source revision and shared-updater/WordPress postconditions                   | Published compatibility fixture; outside provider-page ownership                                    |

## Exact provider-page replacement boundary

The current `views/provider.php` is 1,325 backend lines. It contains five
component constructions, URL and REST construction, nonce emission, request
value normalization, profile and repository projection, filter/sort/page
algorithms, extension-hook orchestration and markup. The provider-page slice
must make it a passive renderer under the frozen counter, not merely move the
file.

### Responsibilities that leave the view

One internal **profile-list projection owner** may replace all of these named
responsibilities:

- credential/webhook label, scope, usage, health, attention and search-value
  projection;
- search, kind/scope/status filtering, natural sorting, page clamping and page
  counts for both profile lists;
- safe list-control, sort and pagination URLs;
- safe credential/webhook form action, nonce and enhanced-interaction values;
  and
- overview counts and status-summary inputs derived from those rows.

One internal **managed-repository projection owner** may replace all of these
named responsibilities:

- site and repository readiness indexing by stable ID and normalized locator;
- managed repository/package/automatic counts;
- repository identity fallback, source/policy/status/secret-coverage and
  consequence projection;
- package, cleanup, provider-webhook and local-secret action construction;
- bounded assistance projections, Core base rows, extension filtering and
  selected-row resolution; and
- safe provider, task, repository, documentation and settings URLs.

These owners use existing arrays and existing value types. They are internal,
add no registry or DTO family, and exist only if the implementation deletes the
corresponding view/Dashboard branches. `ProviderSettingsPresenter` remains the
data-acquisition boundary; it must not simply absorb both new roles.

All five renderer constructions leave the view. Existing
`ProviderRepositoryCompositionRenderer`, `AdminStatusSummaryRenderer`,
`ProviderManagementTableRenderer` and `RepositoryTableRenderer` remain narrow
render helpers supplied by Core composition. The view may invoke them and must
retain contextual escaping, markup, accessibility relationships, native GET
controls and native POST fallback.

### Responsibilities that do not move

- `Dashboard` remains the stable Core route and normalizes the allowlisted GET
  request. C1 does not create a public read-model facade.
- `Dispatcher` remains the stable capability/nonce and request boundary for
  Core-owned provider-profile mutations. C1 does not move mutation or sidecar
  I/O into a presenter.
- `ProviderSettingsPresenter` retains provider registry, sidecar, usage,
  repository and readiness acquisition until a later complete-journey slice
  proves a smaller owner.
- Add-ons retain their own `admin_post_*` mutations. The provider rows/panel
  hooks retain their exact names, argument order, Core base rows and bounded
  projections.
- Secret material, persistent formats, public methods, semantic copy,
  capability and nonce scopes do not change.

### Required deletion proof

At the C1 candidate:

1. `views/provider.php` contains no `new`, request read/normalization, URL or
   nonce construction, I/O, row projection, filtering, sorting, pagination,
   repository indexing or domain-state inference.
2. Every new internal type names which of the two owners above it replaces;
   there are no more than two new projection owners and the Core candidate
   returns to at most 253 named runtime types.
3. The affected cluster is physically net-negative. Reclassification into a
   passive view is reported separately and earns no deletion credit.
4. Provider route outcomes prove overview, credentials, webhooks, filters,
   ordering, page bounds, repository selection, extension enrichment,
   inaccessible-storage state, native forms and enhanced-response parity.
5. The public markers, facade method lists, hooks, persistent-state identities
   and checked-out consumer compatibility above remain unchanged.

## Cohesive exceptions and residual risk

Do not split these owners merely because they are large:

- `DeploymentArchivePreflight`: one ordered fail-closed archive and cleanup
  pipeline.
- `WpConfigSecretsPathWriter`: one atomic configuration edit and rollback.
- `TemporaryDebugCapture`: one bounded capture, expiry and cleanup lifecycle.
- `Database`: one schema lifecycle and migration transaction.
- `AbstractPackageRepository`: one persistence/hydration transaction boundary.
- `DeploymentCoordinator`: one durable deployment, lock, rollback and outcome
  path.
- `RepositoryBrowser`: one GitHub paging and normalization adapter.
- `NativeReleaseTrackingFacade`: one explicit published extension boundary.
- `BoosterServiceProvider`: the composition root, while it remains free of
  domain behavior.
- `CoreContainer`: the small concrete request-local, Core-only composition
  mechanism. It is not a public or add-on API; reopen only if hidden
  cross-owner access, lifecycle ambiguity or service resolution escapes the
  composition boundary.

`DeploymentAttemptRepository` remains a watch item because reads and
transitions share one attempt state machine. Secrets storage provisioning also
remains a watch item for the later physical-custody threat model. Neither is a
provider-page dependency.

Residual risks and gates:

- checked-out siblings do not prove absence of unknown external consumers;
  therefore no public-surface deletion is authorized;
- current tests contain substantial implementation coupling, so C1 requires
  outer provider-route outcomes before redundant internals can be retired;
- the Transporter-row interface comment still refers to an earlier base API 1
  wording even though the executable marker and `AdminInteractionFacade`
  constant are API 2; executable compatibility is unambiguous, but the comment
  should be corrected in a separate non-runtime cleanup;
- the three live but incompletely enumerated hook docs identified above should
  be reconciled separately; and
- the later encrypted-document custody decision remains a separate threat
  model and may not borrow authority from this evidence.

The next source gate is the bounded provider-page slice above. It must run the
focused provider/admin outcomes, `composer check`, `pnpm check`, the exact
counters and a Git diff check. Later Dashboard/Dispatcher journeys require a
fresh boundary map after this slice lands.
