# Native chosen-release install feasibility

Refs #969 and #903. Outcome: **not equivalent on the locked runtime**.
The owner-confirmed beta.49 fallback remains current WordPress offer only.
These tests characterize the tested candidate; exit zero does not mean the
chosen-release feasibility gate passed.

## Tested composition

- Core source: `416353943d2251b56c0cd8921b7b2b03e64ffe45`.
- Locally built, verified Core `1.0.0-beta.46` ZIP SHA-256:
  `3b09dbfa9809e0e84ac62565e5156a65037617bdc1b1e951d0ce27005a0bfd22`.
- Shared release updater: `v1.0.0-beta.19`,
  `c6aa2e6e1456b0ad4abec24bf41a870d151eb4b2`.
- WordPress 7.0.3, PHP 8.2.30, MySQL 8.4.0, single-site, direct filesystem.
- Thirty-two scenario runs across seven disposable installations: 26 ordinary
  runs, two restoration runs, and four killed requests. No retained site.

The real installed Core registers targets. Real provider discovery supplies the
control offer. The candidate changes the release descriptor in that offer using
the existing descriptor/token format and exposes it through a request-only
`pre_site_transient_update_*` filter. It does not change production guards,
acquisition, installation, backup, restoration or completion.

Only remote HTTP responses are synthetic. Shared-updater inspection and fresh
acquisition execute normally against generated release ZIPs. Successful installs
require one fresh ZIP stream after discovery, and every installed fixture file
must match independently generated expected bytes with no unexpected files.
The synthetic release feed includes 3.0.0 while the candidate chooses 1.0.0,
0.5.0, 2.0.0 or 2.1.0-rc.1. This is a test of the offered-descriptor mechanism,
not an implemented public picker, by-tag API, admin route or source switch.

## Case results

Both package types were executed for every runtime row. `results.json` preserves
sanitized observations, including the native return type separately from errors
observed in the download filter or upgrader skin.

| Case / runner selector | Evidence class | Result for plugins and themes | Limit |
| --- | --- | --- | --- |
| Manual / `current` | Executed runtime | 3.0.0 installed; one fresh acquisition | Manual request; inactive target |
| Automatic policy / `automatic` | Executed runtime | Manual request admitted with Automatic stored; 3.0.0 installed | Does not execute a scheduled automatic update |
| Disabled / `disabled` | Executed runtime | Discovery refused; no ZIP acquired | Forced install with an injected offer was not exercised |
| Activation / `active` | Executed runtime | Activation preserved; 3.0.0 installed | Plugin uses native one-item `bulk_upgrade`; theme uses `upgrade` |
| Equal version / `equal` | Executed runtime | 1.0.0 rejected with `ran_wp_release_updater_unverified_pre_download`; original tree unchanged | Feasibility failure, not successful reinstall |
| Older version / `older` | Executed runtime | 0.5.0 rejected with the same code; original tree unchanged | Override declared on, but its policy is not implemented |
| Older, override off / `older-override-off` | Executed runtime | Same rejection and unchanged tree | Does not establish policy refusal: override-on also fails |
| Other newer release / `newer` | Executed runtime | Chosen 2.0.0 installed while 3.0.0 exists; one fresh acquisition | Request-scoped synthetic selection |
| Prerelease / `prerelease` | Executed runtime | Chosen 2.1.0-rc.1 installed on prerelease channel; one fresh acquisition | No #891 onboarding or environment-policy qualification |
| Repository race / `repository-race` | Executed runtime | Core `authority_changed`; no acquisition; unchanged tree | Stored repository identity changes after discovery |
| Source revision race / `revision-race` | Executed runtime | Core `authority_changed`; no acquisition; unchanged tree | Revision changes after discovery |
| Mutable replacement / `mutable` | Executed runtime | `remote_release_changed`; no acquisition; unchanged tree | Mutable publication's asset identity changes after discovery |
| Assurance / `assurance` | Executed runtime | `acquisition_failed`; no ZIP acquisition; unchanged tree | Fresh metadata loses its trusted digest |
| Failed installation / `restore` | Executed runtime | Post-replacement error; original complete tree restored by next request | Synthetic error at native post-install hook; successful restore only |
| Killed current update / `kill-current` | Executed runtime | SIGKILL exit 137; installed 3.0.0 and old 1.0.0 backup remain | One kill point after native install result, before completion |
| Killed chosen update / `kill-newer` | Executed runtime | Same kill point: installed 2.0.0 and old 1.0.0 backup remain | No mid-copy/backup-move kill sweep or recovery qualification |
| Offer lifetime / `readback` phase | Executed runtime | Marked injected offer persists after successful installation and restored failure; absent after pre-download refusal and tested kills | Feasibility failure; the marker distinguishes this offer from ordinary discovery |
| Branch to release | Source-only | Current native registration admits only release-source rows | No request-local Branch authority bridge exists or was invented |
| #888 override admission / site-address binding | Source-only; not exercised | No production reader of `RAN_BOOSTER_ALLOW_BRANCH_AND_PRERELEASE_SOURCES` in this Core revision | Declaring a constant cannot prove an unimplemented policy |

The `active` selector deliberately uses the supported count-one bulk plugin path.
An exploratory direct `Plugin_Upgrader::upgrade()` active-plugin run failed with
`runtime_liveness_lost`; it is not presented as an activation-preserving path.
The final matrix uses clean fixtures after that exploratory failure.

### Restoration observations

The injected error happens after new files have been copied. The shared updater
returns `ran_wp_release_updater_unverified_install_result`; WordPress's native
return is `WP_Error`, its error data is null, and the shutdown restoration
callback is scheduled. These are the reported facts before shutdown. Separately,
the next process verifies all original files and their bytes. No restoration
result is carried in that observed error. Do not turn this filesystem proof for
a synthetic site into a general product claim that the old version is intact.

### Decision boundary

Equal/older versions are refused by the locked updater's newer-than-installed
checks, and the simple transient-read filter is persisted by WordPress during
successful installs. The tested candidate therefore fails equivalence even
though newer selections can install. PU-002 remains closed. No durable hard
stop, second installer, receipt, source-switch implementation, recovery bypass
or production policy has been added. The missing Branch and policy evidence also
prevents a full feasibility pass. Exact production deltas for Updater, Core and
Release Deployments are intentionally not proposed: that planning is conditional
on a passing gate. Any further candidate must receive its own bounded authority.

## Reproduction

Provision a fresh synthetic WordPress 7.0.3 installation with PHP 8.2, an isolated
MySQL 8.4 database, `http://localhost` as its site URL and a `proof` administrator.
Install the verified Core ZIP, then create `.ran-booster-disposable-test-site`
containing exactly `RAN Booster disposable test site` plus a newline. Verify
ABSPATH, WP_CONTENT_DIR, plugin/theme roots, marker and absent destination before
installing the ZIP. Never point this runner at a retained or release-only site.

```sh
PHP_BIN=/path/to/php8.2 \
RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE=/absolute/disposable/wordpress \
bash tests/WordPress/native-release-install-proof/run.sh
```

The runner declares the owner-selected override constant before WordPress loads:
on for ordinary cases, off only for `older-override-off`. It does not implement
that setting. Each target is seeded in one process, upgraded in another, and read
back in a third. Its exact management row is retired only after the readback;
synthetic files and databases remain available for inspection and disposal.

Use a separate fresh site for each `restore`, `kill-current` and `kill-newer`
case, for each package type. Their failure/recovery state must not contaminate
another case. Set these additional variables with the same command:

```sh
RAN_NATIVE_RELEASE_INSTALL_PROOF_TYPES=plugin # or theme
RAN_NATIVE_RELEASE_INSTALL_PROOF_CASES=restore # or kill-current / kill-newer
```

Export those variables, or prefix the invocation with them. The kill runner
requires exit 137 and then reads the filesystem from a new process. It never
runs shutdown manually or clears recovery state to obtain a passing result.

These runtime proofs are invoked explicitly. Existing CI configuration and
harness entrypoints are unchanged; ordinary gates analyze the new PHP but do not
automatically execute this WordPress runner. This is local synthetic runtime
proof, not hosted CI runtime, browser, installed-release acceptance or an
independent exact-PR review.

## Verification

- `composer check`: passed, including 6,650 PHPUnit tests / 83,974 assertions,
  PHPCS, syntax, production and automatic development PHPStan analysis. The
  aggregate uses development PHP 8.5; disposable runtime proofs use PHP 8.2.30.
- `pnpm check`: passed, 749 tests, Node 24.21.0 / pnpm 11.7.0. Both executable
  directories were placed before Volta's shims so nested commands use the pin.
- `composer analyze:changed`, focused PHPCS, shell syntax and evidence hashes:
  passed. Source catches up to the unchanged `origin/main` SHA above.
- Existing catalog, lockfiles, production, scripts and CI configuration:
  unchanged. Only new files in this test subtree are proposed.
