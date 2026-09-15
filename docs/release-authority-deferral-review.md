# Release authority deferral review

This document is a placeholder for a focused follow-up review of Booster's release-authority self-deferral model.

## Current model

Booster treats its Quality and Release Please workflows and selected release scripts as privileged release authority. When an authority-changing pull request is merged, the merged commit is still verified, but privileged release reconciliation is intentionally deferred. A later ordinary pull request that does not change protected release authority must then merge and pass main-branch Quality before Release Please may resume mutation.

The model currently relies on duplicated, manually maintained protected-path lists in `.github/workflows/quality.yml` and `.github/workflows/release-please.yml`.

## Why revisit it

The mechanism provides a deliberate separation between changing release authority and exercising that changed authority, but it also creates maintenance coupling and a mandatory follow-up merge. PR #116 exposed one example of drift: a newly introduced executable release verifier, `scripts/verify-runtime-dependencies.php`, became part of release construction without initially being added to both protected-path lists.

This review should not assume the safeguard is wrong. It should determine whether the current implementation is the simplest reliable way to preserve the guarantees Booster actually needs.

## Questions to answer

- Is self-deferral after release-authority changes still necessary for Booster given its current branch rules, exact-head Quality checks, artifact provenance checks, immutable release requirements, and release-publisher design?
- If self-deferral remains valuable, can release authority be defined from one source of truth rather than duplicated workflow lists?
- Can the mandatory later ordinary pull request be replaced by a clearer independent approval or promotion boundary without weakening release safety?
- Which files or executable entry points truly constitute release authority, and can that boundary be derived or tested rather than maintained by convention?
- Should Booster remain intentionally stricter than `ran-wp-release-updater`, `ran-wp-branch-updater`, and `ran-updater-support`, or should its release flow converge on their simpler exact-main-CI/release-publisher model while preserving Booster-specific artifact and WordPress integration proofs?

## Constraints

- Preserve exact-head CI and exact artifact provenance.
- Preserve immutable release publication/readback guarantees.
- Do not weaken repository branch protection or allow unverified release publication.
- Do not normalize away Booster-specific release or WordPress integration checks merely for consistency.
- Prefer eliminating duplicated maintenance and hidden coupling where the same guarantees can be retained more simply.

No release workflow or runtime behavior is changed by this placeholder document.
