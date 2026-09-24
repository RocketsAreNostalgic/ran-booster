# Release process

RAN Booster is released as an immutable verified GitHub artifact. Release Please owns version semantics, changelog, the release pull request, tag, and draft GitHub Release lifecycle. The pinned shared RAN Profile B workflow owns exact successful-main admission, bounded exact Release Please candidate Quality dispatch, promotion of the exact tested Core assets, immutable publication, and final readback.

Core keeps only product-specific release evidence: deterministic runtime dependency projection, the exact runtime ZIP and checksum, installed candidate readback, the WordPress/database matrix, localisation/generated-state checks, and the embedded Core release provenance marker.

Do not recreate repository-local candidate markers, generic publisher state machines, trusted-run discovery, manual Release Please lifecycle labels, mutable asset replacement, or historical replay/recovery machinery.

## Retained and deleted evidence

| Previous responsibility | Profile B disposition |
| --- | --- |
| `Runtime archive` deterministic Core ZIP, checksum, dependency allowlist and archive verification | **Retained** unchanged as repository-owned product evidence. |
| Core source quality (`composer check`, `pnpm check`) | **Retained**; Composer names are normalized to `lint:syntax`, `standards`, `standards:fix`, `analyze`, and `test` without changing analysis strictness. |
| WordPress/MySQL/MariaDB compatibility, Plugin Check, installed runtime/localisation/generated-state proof | **Retained** and required through terminal `Quality`. |
| Exact release-candidate ZIP install/readback | **Retained** as `Release candidate install readback`. |
| Release Please version/changelog/release/tag/draft lifecycle | **Shared Profile B / Release Please**; no duplicate local version engine. |
| Exact tested-asset promotion and immutable release readback | **Shared Profile B**, consuming `ran-profile-b-promotion.json` from the exact main Quality run. |
| Local candidate comments/markers, trusted-run rediscovery, changed-path admission catalogue, merged-PR release-state reconciliation, tag/asset mutation helpers | **Deleted**; they are superseded by the shared contract. |
| Mutable `--clobber` recovery / standing historical repair | **Deleted**. A bad release is corrected by a new source change, qualification, version, and immutable release. |

Protected main continues to require the repository's strong `Runtime archive`, terminal `Quality`, and `Release candidate install readback` contexts. Profile B changes release orchestration, not those product guarantees.

## Contributor release checks

Before a release proposal is merged:

1. review the Release Please pull request, including `CHANGELOG.md`, plugin header, `readme.txt`, and manifest version;
2. run `composer check` and `pnpm check` on the exact candidate;
3. build and verify the exact runtime archive with:
   ```sh
   version="$(sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' ran-booster.php)"
   bash scripts/build-release.sh HEAD "$version"
   bash scripts/verify-release.sh "build/ran-booster-${version}.zip" "$version" HEAD
   ```
4. preserve the committed runtime dependency/packaging policy and the existing WordPress/database/install evidence; and
5. do not manually create, publish, replace, or clobber a release asset.

## Merge method for Booster release proposals

After the exact candidate checks and independent review are complete, obtain the owner's explicit authorization for that specific pull request. Merge Booster's bot-owned `chore(main): release ...` proposals with **Create a merge commit**. **Do not squash or rebase-merge these release proposals.** Ordinary iterative or agent-developed pull requests still prefer squash under `AGENTS.md`.

This is the [owner-approved Booster merge policy](https://github.com/RocketsAreNostalgic/.github/issues/54#issuecomment-5818889056), not a claim that Release Please universally requires merge commits. [Release PR #172](https://github.com/RocketsAreNostalgic/ran-booster/pull/172) followed this policy: its two-parent merge `1c8283bc814ac593171d608d532226fcea83c6f4` was qualified on `main` and published as immutable `v1.0.0-beta.30`.

A merged release proposal is not itself proof of publication. Verify successful Quality on the resulting exact merged-main commit, then shared Profile B publication and immutable tag/release/asset readback. The publisher must consume that merged-main artifact, not the pre-merge candidate artifact.

## Automated release path

```text
ordinary PR / main
→ Runtime archive + repository quality + WordPress/database product proof
→ terminal Quality
→ exact ZIP + checksum + ran-profile-b-promotion.json
→ shared Profile B exact successful-main admission
→ Release Please
→ exact candidate Quality when a release PR exists
→ candidate ZIP install/readback
→ draft release bound to exact admitted main
→ exact tested asset promotion
→ immutable publication + readback
```

`workflow_dispatch` on `Quality` is intentionally input-free. The shared Profile B workflow dispatches the canonical Release Please branch when exact candidate qualification is required. Core treats a dispatched revision as a release candidate only when it is the unique open bot-owned Release Please pull request for `main`; ordinary manual dispatch remains the full quality lane.

The release workflow is a thin caller pinned to shared Profile B at `593768db30a0101e940e85b9a084b2c773322785`. It does not rebuild release bytes. Shared promotion downloads the exact run/attempt artifact named `ran-booster-runtime-<run-id>-<attempt>` and requires `ran-profile-b-promotion.json` to bind repository, admitted SHA, tag, asset names, and SHA-256 digests.

Release Please is configured with `draft: true` and `force-tag-creation: true`. The shared promoter captures the Release Please release ID and stable/prerelease classification, attaches only the expected tested assets, then requires the published release to become immutable with the same identity and target commit.

GitHub-generated source archives are not installable WordPress packages. The canonical consumer artifact is `ran-booster-<version>.zip` attached to the verified immutable GitHub release.
