# RAN Booster documentation

This directory is the canonical home for durable Booster documentation and
sanitized Booster-specific evidence.

## Operator journeys

- [Choose GitHub support](github-provider-replacement.md): review a replacement,
  approve compatible credential access, resolve paused states and return to bundled.
- [Transporter dashboard walkthrough](operator-journeys.md): select/export,
  optional protected credential transfer, destination review, apply and recovery.
- [Migrate retained WP Pusher packages](operator-journeys.md#migrate-retained-wp-pusher-packages):
  prerequisites, adoption, exact source-row cleanup and operator-owned uninstall,
  with links to the maintained Migrator guide.
- [Transporter Blueprint reference](portability-briefcase.md): detailed format,
  credential choices, comparison and supported-operation boundaries.

The new journeys describe the implemented upcoming-release candidate, not a
certified published Core/Migrator pair. Published Core beta.36 retains the older
bundled-only GitHub behavior; published Migrator beta.10/API 3 is incompatible
with candidate Core API 4. Check the guides' status notices and release notes.
Verified exact export, selected existing-target/release-source Blueprint execution,
reconciliation, Abilities and whole-site V3 remain outside this delivery.

## Technical references

- [Administration composition contract](admin-composition-contract.md) covers
  Admin Shell continuity, page-local navigation and supported extension surfaces.
- [Translating RAN Booster](translating.md) explains how to create, build, test,
  and submit PHP and JavaScript translations.
- [Runtime dependency packaging policy](runtime-packaging-policy.md) records the
  authority split between the Composer lock, Booster's shipped package-surface
  policy, and the release builder/verifier.
- [Multisite quarantine evidence](evidence/multisite-quarantine-2026-07-28/README.md)
  is retained proof output, not a restore fixture.
- [Core V3 C2-C3 operator-journey map](characterization/core-v3-c2-c3-operator-journey-map.md)
  freezes the post-C1 request, operation, readback and page boundaries and the
  only bounded follow-up packets currently proposed for the two admin hotspots.
- [Provider registration and coexistence](provider-registration-and-coexistence.md)
  preserves the historical Provider API 15/beta.36 released baseline; use the
  replacement operator guide above for the upcoming candidate's selection flow.
- [Provider release-workflow capability](provider-release-workflow-api.md)
  records the provider-neutral initial-only API 3 contract, the pre-1.0 API 2 retirement,
  and the Provider API 14 registration seam.

Core architecture, release, portability, and security contracts remain in the
named documents beside this index.
