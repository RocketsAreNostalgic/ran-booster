# CI architecture

RAN Booster follows the same organization-level quality policy as the sibling updater packages, but its GitHub Actions topology remains local because the workflow is also part of the release and runtime-evidence trust boundary.

The common policy is:

- test the exact source revision;
- use locked dependency manifests;
- retain PHP 8.2 as the supported floor;
- use pinned third-party Actions and declared toolchain versions;
- keep project-controlled build/test execution read-only and credential-free; where runtime admission must query GitHub metadata, expose only the read-only, step-scoped `GITHUB_TOKEN` permissions required for that classifier;
- make repository-owned aggregate checks (`composer check`, `pnpm check`) authoritative for their code surfaces;
- keep product-specific installation, compatibility, packaging, provenance, and release proofs in the owning repository.

For the updater libraries, ordinary PHP quality execution is centralized through the versioned reusable workflow in `RocketsAreNostalgic/.github`. Booster does not call that reusable library workflow because its `Quality` workflow additionally creates and admits exact runtime archives, separates full and Release Please candidate lanes, and feeds verified artifacts into installed WordPress/database proofs. Those responsibilities must remain coupled to Booster's own provenance model rather than becoming parameters of a generic reusable workflow.

Booster therefore retains the protected `Runtime archive`, `Quality`, and `Release candidate install readback` checks. Any future move to a single fan-in status must be coordinated with the repository ruleset so required checks are never silently dropped or left pointing at statuses that no longer exist.

## Release promotion boundary

Booster uses successful exact-main qualification as its release promotion
boundary. The Quality workflow remains read-only evidence infrastructure. Its
admission classifier forces a fresh main Runtime archive and Quality run when
release-control or evidence-input paths change, so a changed workflow, release
script, dependency manifest, or runtime packaging policy cannot promote stale
pull-request evidence.

`RELEASE.md` is the canonical human-readable inventory that separates the
release-control/release-execution paths from ordinary evidence-input paths.
Contract tests compare that inventory with Quality's executable changed-file
classifier. This document deliberately does not repeat the full catalogue.

The Release Please workflow is the bounded mutator. It is triggered only by a
successful push-triggered Quality run on `main`, checks out that exact Quality
commit, and re-establishes the merged pull-request identity before exercising
repository or release write permissions. Release-control changes do not require
a later ceremonial pull request once their own exact merged revision has passed
that trusted-main qualification.

The repository currently has one maintainer, so this model does not claim an
independent human authorization principal. Exact-head Copilot/Codex review may
be required by repository process or settings as a pre-merge review gate, but
release authorization remains bound to exact-main evidence and the existing
artifact/publication provenance chain.
