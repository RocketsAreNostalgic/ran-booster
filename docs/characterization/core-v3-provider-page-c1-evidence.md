# Core V3 provider-page C1 evidence

Date: 2026-08-10

This records the source-only C1 result against frozen baseline
`af48bf488e7ad6ea41bd88608aa09f6b6547d936`. It does not claim an installed
WordPress activation, release publication, or deployed-runtime result.

## Ownership result

- `Dashboard` remains the allowlisted GET boundary and composes the prepared
  provider route model, renderers, and the retained repository-composition
  helper.
- `ProviderSettingsPresenter::buildProfileListProjection()` owns credential and
  webhook-profile labels, usage, attention, search, filtering, sorting,
  pagination, status summaries, form values, and safe list URLs beside the
  existing profile acquisition boundary.
- `ProviderRepositoryRowsNormalizer::projectPage()` owns managed-repository
  inventory, readiness indexing, counts, task and repository URLs, base rows,
  selection, and bounded add-on row enrichment.
- `ProviderRepositoryCompositionRenderer` remains the narrow existing owner of
  assistance presentation, repository row/panel hooks, normalization fallback,
  and bounded trusted add-on rendering.
- `views/provider.php` is presentation-only. A literal source scan finds no
  service construction, request globals, URL or nonce construction, I/O, count
  or substring normalization, collection projection, filtering, sorting, or
  pagination.

No new production type or public seam was introduced. Projection values remain
raw values; contextual escaping stays at the rendering boundary and no
pre-escaped HTML is placed in a read model.

## Frozen counters

The frozen source-set counter reports physical change first, using the original
207-line passive allowlist so presentation reclassification earns no deletion
credit:

| Measure | Baseline | C1 | Delta |
| --- | ---: | ---: | ---: |
| Shipped PHP | 47,094 | 47,074 | -20 |
| Passive allowlist | 207 | 207 | 0 |
| Backend PHP | 46,887 | 46,867 | -20 |
| Named runtime types | 253 | 253 | 0 |

After that physical result is fixed, C1 adds the now presentation-only
445-line `views/provider.php` to the reviewed passive allowlist. The candidate
classification is therefore 47,074 shipped PHP, 652 passive PHP, 46,422 backend
PHP, and 253 named runtime types. The 445-line reclassification is reported
separately and is not counted as physical deletion.

The physically affected production cluster, with no passive reclassification
credit, is:

| File | Baseline | C1 | Delta |
| --- | ---: | ---: | ---: |
| `RAN/Dashboard.php` | 2,246 | 2,277 | +31 |
| `RAN/Admin/ProviderSettingsPresenter.php` | 1,121 | 1,480 | +359 |
| `RAN/Admin/ProviderRepositoryRowsNormalizer.php` | 267 | 735 | +468 |
| `RAN/Admin/ProviderRepositoryCompositionRenderer.php` | 218 | 218 | 0 |
| `RAN/Admin/Component/ProviderManagementTableRenderer.php` | 200 | 202 | +2 |
| `views/provider.php` | 1,325 | 445 | -880 |
| **Affected total** | **5,377** | **5,357** | **-20** |

## Outcome coverage

The focused route/view suite passes 92 tests and 717 assertions with warnings
displayed. It proves:

- `Dashboard::getIndex()` supplies a provider model that renders through the
  real `views/provider.php` boundary;
- accessible headings, table scopes, filter application, sort state, bounded
  pagination, and absence of a nonmatching profile;
- normalized repository selection reaches the selected-row detail outcome;
- a missing webhook secret plus an Automatic branch package projects an
  attention summary and the required warning;
- composition hooks, historical row enrichment, reserved-action locking,
  provider-neutral native forms, enhanced interactions, storage-failure copy,
  and safe contextual escaping retain their outcomes.

Public hook names and arguments, permission boundaries, nonce action scopes,
persistent identities, provider-neutral contracts, accessibility markers, and
trust disclosures are unchanged.

## Gates

- `composer check`: pass; characterization 35, PHPUnit 2,030 tests / 12,477
  assertions, updater bootstrap smoke, release-uploader tests, and PHPCS.
- `pnpm check`: pass; formatting, ESLint, Stylelint, and 137 asset tests.
- Focused PHP lint: all changed PHP files pass.
- `git diff --check`: pass.
- Exact committed-object archive build and independent verification are
  recorded in the private execution closeout after this evidence carrier is
  committed, avoiding a self-referential archive digest in shipped source.
  That verification does not install, activate, upload, publish, or promote
  the archive.
