# CI architecture

RAN Booster follows the organization-level quality policy while retaining a local Quality workflow because Core owns substantially stronger product evidence than a generic PHP package: deterministic runtime dependency projection, a verified installable ZIP, WordPress/MySQL/MariaDB execution, Plugin Check, localisation/generated-state proof, and exact installed-archive readback.

The common policy remains:
- test the exact source revision;
- use locked dependency manifests;
- retain PHP 8.2 as the supported floor;
- use pinned third-party Actions and declared toolchain versions;
- keep project-controlled build/test execution read-only and credential-free;
- make repository aggregates (`composer check`, `pnpm check`) authoritative for their code surfaces; and
- preserve product-specific installation, compatibility, packaging and provenance evidence in Core.

Protected `main` continues to require `Runtime archive`, terminal `Quality`, and `Release candidate install readback`. Terminal `Quality` fans in the full repository and WordPress/database lanes for ordinary changes, or the exact candidate install/readback lane for a Release Please candidate. The Profile B migration does not weaken those contexts or change their enforcement.

## Exact artifact boundary

`Runtime archive` checks out the exact source revision, builds the deterministic Core ZIP from the committed dependency lock and runtime packaging policy, verifies the archive, and uploads one run/attempt-bound artifact: `ran-booster-runtime-<run-id>-<attempt>`.

That artifact contains the installable ZIP, its checksum, repository-local runtime metadata, and `ran-profile-b-promotion.json`. The promotion manifest binds the repository, exact tested source/Quality commit, expected tag, public asset names, and SHA-256 digests. Quality never publishes or mutates a GitHub Release.

The canonical Release Please branch is handled as a candidate lane only when it is the unique open bot-owned release proposal for `main`. Candidate Quality remains read-only and installs/reads back the exact ZIP. Generic candidate comments, lifecycle markers, trusted-run rediscovery, changed-path evidence catalogues, and main-push artifact-reuse fallback are no longer local architecture.

## Release promotion boundary

The release workflow is a thin caller to the pinned shared Profile B contract in `RocketsAreNostalgic/.github`. The shared workflow admits only the exact successful push-triggered `main` Quality revision from the canonical workflow, invokes Release Please, ensures the exact release candidate has successful Quality, resolves the exact draft release ID and prerelease classification, downloads the exact main Quality artifact, validates every promotion digest, publishes, and reads back the immutable release.

Release Please remains the sole generic version/changelog/release-PR/tag/release lifecycle authority. A failed artifact is fixed through source → fresh qualification → new version; Core retains no standing mutable recovery path.

The repository currently has one maintainer, so this model does not claim an independent human authorization principal. Exact-head code/security review remains a pre-merge review gate; privileged publication remains bound to successful exact-main evidence and the shared Profile B contract.
