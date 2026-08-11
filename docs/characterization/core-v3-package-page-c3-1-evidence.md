# Core V3 package-page C3-1 evidence

Date: 2026-08-11

This records the source-only package create/edit/index result against exact Core
baseline `071510071ee0461c8ae53d804ff1405b39d50108`. It does not claim an
installed WordPress activation, candidate archive, release publication or
deployed-runtime result.

## Ownership result

Before this slice, the 88-line `PackageViewConfig` carried only plugin/theme
labels, slugs and action vocabulary. `Dashboard` separately owned package
selection, provider-form acquisition, list filtering, source choices,
extension projections, webhook-cleanup projection, activity composition and
page model assembly.

The internal stateless `PackagePagePresenter` replaces `PackageViewConfig` and
is now the singular read-only package-page projection owner. It retains the
closed plugin/theme vocabulary and composes already-acquired package,
provider, readiness, retention and normalized request data into the exact
create/edit/index models. It owns filtering and bounded source, extension,
webhook-cleanup and activity projections. It does not read `$_GET` or `$_POST`,
resolve a repository, call `ProviderSettingsPresenter`, acquire database state,
or invoke a mutation owner.

`Dashboard` keeps the four public menu callbacks, database and package-
repository reads, read-only request normalization, all
`ProviderSettingsPresenter` acquisition, success/bulk notices, message
collection and final rendering. `DeploymentAdminPresenter` keeps authoritative
activity reads and now uses the replacement's exact page slugs for activity
settings links. Package mutation remains wholly in `PackageAdminController`
and the existing application services.

No production DTO, hierarchy, registry, facade, hook, action, option, table
field, dependency or persistent state is added. Deleting one runtime type and
adding one runtime type retains exactly 253 named shipped types.

## Exact route and projection outcomes

The public `Dashboard::{getPluginsCreate,getThemesCreate,getPlugins,getThemes}`
callbacks and exact WordPress page slugs are unchanged. Plugin and theme create
routes require database readiness before acquiring the provider form. Edit
routes resolve exactly one matching package and acquire its provider form,
branch readiness and webhook retention before projection. Missing selection
falls back to the matching index. Package-storage failure renders the existing
safe notice and empty matching index; database incompatibility renders the
existing disabled create model.

Index reads remain fresh on every call. The presenter validates a requested
provider against the current displayed inventory, applies the existing bounded
search/provider/source/policy filters, then projects current deployment
activity and extension rows/actions only for the filtered packages. A repeated
outer route test changes repository results between calls and observes the
second result, proving no presenter cache.

The exact add-on boundaries remain:

- `ran_booster_admin_package_settings_sections` receives one
  `AdminPackageProjection` and its identical canonical settings URL;
- `ran_booster_admin_package_source_choices` receives base choices, mode,
  package type, nullable projection and page URL;
- `ran_booster_admin_package_advanced_source_sections` receives mode, type,
  selected source, nullable projection and page URL;
- `ran_booster_admin_package_advanced_source_summary` receives the base
  summary, mode, type, selected source and nullable projection;
- `ran_booster_admin_package_management_rows` receives the exact keyed base
  rows, type and keyed projections;
- `ran_booster_admin_package_management_actions` receives an empty action map,
  type and one projection; and
- `ran_booster_admin_package_webhook_cleanup_actions` receives only the
  existing bounded `WebhookCleanupContext`.

Outer create/edit/index fixtures exercise the five hooks consumed by Release
Deployments with exact argument order. The existing normalizers remain the
trust boundary for source choices, management rows and actions; rendered
settings, advanced-source and webhook sections remain isolated output-buffered
add-on boundaries. Hook failure degrades to Core choices or no extension
projection without breaking the page.

Create still begins at Branch even when a release choice is hydrated. A saved
release source remains selected and visibly unavailable when its add-on is
absent; it is not silently reinterpreted as Branch. Create add-on source
content remains inside the install form, while edit source/add-on content
remains outside the Core edit form so add-on-owned forms are never nested.

## Multisite route correction

The baseline package index generated site-admin install, filter, search, edit
and activity links even though the package pages register under
`network_admin_menu` on multisite. Create/edit and hook projections already
used the network base. The replacement presenter now supplies one canonical
site/network package-admin base to every passive package view, closing that
split. Outer plugin/theme routes and rendered index controls prove the network
base for create, edit, filter/search and hook-projection URLs. Single-site URLs
remain unchanged.

## Frozen counters and explicit deviation

The reviewed passive allowlist remains unchanged at 652 lines.

| Measure | C2-4 baseline | C3-1 candidate | Delta |
| --- | ---: | ---: | ---: |
| Shipped PHP | 46,739 | 46,684 | -55 |
| Passive PHP | 652 | 652 | 0 |
| Backend PHP | 46,087 | 46,032 | -55 |
| Named runtime types | 253 | 253 | 0 |

Physical production diff arithmetic is 699 added and 754 deleted lines: net
-55. `Dashboard` contracts from 1,548 to 930 lines, deleting 618 lines. The
641-line replacement presenter is 553 lines larger than the deleted 88-line
configuration object because it now owns the complete bounded projection
family. Counting the four retained three-line public menu callbacks with the
presenter yields 653 lines. The broadly affected production cluster contracts
from 3,015 to 2,960 lines.

This result misses the planned 300-line local deletion signal by 245 lines and
the planned 597-line presenter-plus-wrapper signal by 56 lines. The measured
result is retained under the owner direction that correctness and cohesive
ownership are primary and line targets must not distort a sound boundary.

Smaller alternatives were rejected where they would make the result less
honest: leaving filters or hook projection in Dashboard would preserve split
ownership; injecting provider/repository/database collaborators into the
presenter would absorb acquisition; moving composition into passive views
would create business logic and false reclassification credit; a new DTO,
helper type or generic hierarchy would violate the one-for-one type boundary;
and deleting normalizers or outer outcomes would weaken the add-on trust and
fresh-readback evidence.

The programme arithmetic is now C1 -20, C2-1 -136, C2-2 -145, C2-3 -65,
C2-4 +11 and C3-1 -55: 410 physical production lines removed. A visible 390
lines remain to the 800-line programme floor and 430 remain to the original
46,254 shipped / 45,602 backend final target. Those are visibility targets,
not automatic abandonment triggers. The C3-2 read-only base re-inventory must
measure the remaining cohesive opportunities and consult the owner before any
abandonment, scope or programme-exit decision; it must not manufacture credit
by moving logic into views or weakening evidence.

## Source gates

- Focused outer Dashboard route/projection suite: 69 tests and 332 assertions
  pass.
- Focused package presenter vocabulary suite: 3 tests and 22 assertions pass.
- Focused package index view suite: 6 tests and 66 assertions pass.
- Focused shared create/edit view suite: 11 tests and 300 assertions pass.
- Full `composer check`: characterization 35; PHPUnit 2,076 tests and 12,729
  assertions; updater bootstrap smoke; release-uploader checks; and PHPCS pass.
- `pnpm check`: formatting, ESLint, Stylelint and 137 asset tests pass.
- Changed PHP syntax checks and `git diff --check` pass.

Candidate archive and installed WordPress activation checks require an
immutable source commit and separate runtime authorization; this uncommitted
source evidence does not substitute for those later exact-commit gates.
