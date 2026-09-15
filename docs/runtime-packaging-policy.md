# Runtime dependency packaging policy

Booster's release archive deliberately ships only a bounded subset of its production Composer dependencies. Three sources have distinct authority and should not be collapsed into one file.

## Authority split

`composer.lock` owns the exact resolved production dependency graph. Package versions, Git source references, and matching distribution references come from the committed lock and are not repeated in the packaging policy or release scripts.

`runtime-packaging-policy.json` owns Booster's product-level packaging decision: which production packages may enter the release ZIP, the GitHub repository each package is expected to resolve from, the archive root for that package, and the files/directories that are permitted to ship. The policy also identifies the single neutral release-updater package needed by the release build environment. It does not carry versions or commit SHAs.

`release-files.txt` owns only committed Core files plus the generated `ran-booster-release.json` marker. Vendor/package surfaces are intentionally absent from that file because they are owned by the runtime packaging policy.

## Consumers

`scripts/verify-runtime-dependencies.php` validates the policy schema and the committed lock together. It rejects unexpected or duplicate production packages, malformed versions/references, repository/source mismatches, unsafe or overlapping surface paths, and an invalid neutral-updater role. Its normal output reports the resolved package/version/reference records; `--packaging` adds the validated repository/root/surface projection for the release scripts.

`scripts/build-release.sh` and `scripts/verify-release.sh` consume that validated projection generically. They do not maintain package-specific roots, surfaces, versions, repositories, or commit constants. Both scripts still fail closed on missing package surfaces and symlinks. The builder stages only policy-approved surfaces from a clean `composer install --no-dev`; the verifier independently reconstructs the expected archive file set and compares every packaged runtime file byte-for-byte with the clean install.

The release updater's sealed/runtime-copy model is unchanged: the release updater remains a normal policy-approved package surface, and the exact release-updater revision used by the build environment is derived from the committed lock rather than a second hard-coded commit.

## Change discipline

A production dependency change normally changes `composer.json` and `composer.lock`. A packaging-surface change changes `runtime-packaging-policy.json`. Those are evidence/product inputs. Changes to the executable builder/verifier scripts remain changes to Booster's current release-control implementation and retain their existing trust-path treatment until the separate release-authority work changes that boundary.

Do not broaden the policy to copy all of `vendor/`, add package files speculatively, or move this Booster-specific product contract into a generic organisation release workflow.
