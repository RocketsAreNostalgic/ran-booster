# Runtime dependency packaging policy

Booster's release archive deliberately ships only a bounded subset of its production Composer dependencies. Three sources have distinct authority and should not be collapsed into one file.

## Authority split

`composer.lock` owns the exact resolved production dependency graph. Package versions, Git source references, and matching distribution references come from the committed lock and are not repeated in the packaging policy or release scripts.

`runtime-packaging-policy.json` owns Booster's product-level packaging decision: which production packages may enter the release ZIP, the GitHub repository each package is expected to resolve from, the archive root for that package, and the top-level files/directories that are permitted to ship. Every surface declares an exact `file` or `directory` kind. Nested surfaces are deliberately unsupported so a policy entry cannot hide a symlinked ancestor, and a later dependency revision cannot silently broaden a file surface into a directory. The policy also identifies exactly one neutral release-updater package. It does not carry versions or commit SHAs.

`release-files.txt` owns only committed Core files plus the generated `ran-booster-release.json` marker. Vendor/package surfaces are intentionally absent from that file because they are owned by the runtime packaging policy.

## Consumers

`scripts/verify-runtime-dependencies.php` validates the policy schema and the committed lock together. It rejects unexpected or duplicate production packages, malformed versions/references, repository/source mismatches, invalid or duplicate top-level surfaces, and an invalid neutral-updater role. Its normal output reports the resolved package/version/reference records; `--packaging` adds the validated repository/root/typed-surface projection for build, verification, and CI readback.

`scripts/build-release.sh` and `scripts/verify-release.sh` consume that validated projection generically. They do not maintain package-specific roots, surfaces, versions, repositories, or commit constants. Both scripts require each installed surface to retain its declared kind, reject symbolic links, and reject empty declared directory surfaces. The builder stages only policy-approved surfaces from a clean `composer install --no-dev`; the verifier independently reconstructs the expected archive file set and compares every packaged runtime file byte-for-byte with the clean install.

The previous sibling Git checkout of the neutral updater is intentionally unnecessary: it was not a source of staged bytes. Exact repository/ref identity is proved by the lock-plus-policy verifier, while the clean Composer install supplies the bytes that are staged and independently compared. Installed WordPress readback still proves the neutral updater's runtime metadata and V1 marker, deriving its package root and version from the same validated policy/lock projection rather than duplicating those identities in CI. The readback also requires the updater's declared PHP and WordPress floors to be no higher than Booster's selected-commit `Requires PHP` and `Requires at least` headers, so the updater remains runnable across Booster's full declared support range without duplicating version constants in CI.

## Change discipline

A production dependency change normally changes `composer.json` and `composer.lock`. A packaging-surface change changes `runtime-packaging-policy.json`. Those are evidence/product inputs. A policy change therefore forces fresh exact-main Quality evidence; it is not, merely by being a packaging input, classified as privileged Release Please authority. Changes to the executable builder/verifier scripts remain changes to Booster's current release-control implementation and retain their existing trust-path treatment until the separate release-authority work changes that boundary.

Do not broaden the policy to copy all of `vendor/`, add package files speculatively, permit nested package surfaces without a new security review, or move this Booster-specific product contract into a generic organisation release workflow.
