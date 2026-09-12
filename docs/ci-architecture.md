# CI architecture

RAN Booster follows the same organization-level quality policy as the sibling updater packages, but its GitHub Actions topology remains local because the workflow is also part of the release and runtime-evidence trust boundary.

The common policy is:

- test the exact source revision;
- use locked dependency manifests;
- retain PHP 8.2 as the supported floor;
- use pinned third-party Actions and declared toolchain versions;
- keep pull-request source execution read-only and credential-free;
- make repository-owned aggregate checks (`composer check`, `pnpm check`) authoritative for their code surfaces;
- keep product-specific installation, compatibility, packaging, provenance, and release proofs in the owning repository.

For the updater libraries, ordinary PHP quality execution is centralized through the versioned reusable workflow in `RocketsAreNostalgic/.github`. Booster does not call that reusable library workflow because its `Quality` workflow additionally creates and admits exact runtime archives, separates full and Release Please candidate lanes, and feeds verified artifacts into installed WordPress/database proofs. Those responsibilities must remain coupled to Booster's own provenance model rather than becoming parameters of a generic reusable workflow.

Booster therefore retains the protected `Runtime archive`, `Quality`, and `Release candidate install readback` checks. Any future move to a single fan-in status must be coordinated with the repository ruleset so required checks are never silently dropped or left pointing at statuses that no longer exist.
