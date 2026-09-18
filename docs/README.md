# RAN Booster documentation

This directory is the canonical home for durable Booster documentation and
sanitized Booster-specific evidence.

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
  records Provider API 10 exact-code collision, credential-custody, and
  same-vendor coexistence behavior without presenting proposed hardening as
  current protection.
- [Provider release-workflow capability](provider-release-workflow-api.md)
  records the provider-neutral API 2 v1 baseline, the pre-1.0 API 1 retirement,
  and the unchanged Provider API 10 registration seam.

Core architecture, release, portability, and security contracts remain in the
named documents beside this index.
