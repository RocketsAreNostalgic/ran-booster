# Native chosen-release install feasibility

The switch from Branch to releases now has its own proof,
[below](#branch-to-release-switch). The characterisation that follows records
Core `1.0.0-beta.49` and is kept as it was measured.

Refs #969 and #903. Re-baselined on Core `1.0.0-beta.49` with release updater
`v1.0.0-beta.21`. Exit zero means every characterization assertion held. It does
not mean the feasibility gate passed.

**Result: not equivalent on the current code.** Three gaps block the native
single-package path for #903:

1. **Equal versions are refused.** The updater's newer-only check refuses a
   chosen 1.0.0 over an installed 1.0.0 with
   `ran_wp_release_updater_unverified_pre_download`. The same check runs at
   discovery, automatic admission and twice at pre-download
   (`NativePackageUpdater::newer_than_installed()`). The Branch-to-release
   switch often installs the same version, so it cannot pass today.
2. **The marked offer survives the request.** The offer this proof injects
   reaches the stored update check, read from the raw database row, on the
   active-plugin count-one bulk route and after every install that fails after
   replacement (current or chosen version, restored or not), for both package
   types. The updater
   has no hook at the point WordPress saves the update check.
3. **Branch-source packages are not admitted.** `ManagedReleaseTargetRegistrar`
   registers release-source packages only. No request-local Branch admission
   exists, and this proof does not invent one.

The recovery evidence does not show that recovery is unequal to the supported
one-target manual Update. Restoration and the two kill points behave the same
for the current offer and a chosen release. The forced restore failure was run
on a chosen release only.

The beta.49 fallback, "Update to X" for the current offer only, is unaffected.
PU-001, PU-002, PU-003 and PU-016 are not reopened.

## Composition

- Core: published release asset `ran-booster-1.0.0-beta.49.zip`, SHA-256
  `25663edc0c57c9ed153f3ffab89e70b8f044e8c283f9b934e9e8069b22acb68e`, built from
  `main` `23b8460268245e1c50f05538707e58ccf9451719`.
- Release updater `v1.0.0-beta.21`, `6f5d66b3f5b6f7e1ece992d36da80b19f5788907`,
  bundled in that archive.
- WordPress 7.0.3 from the official zip; `wp core verify-checksums` passed on
  every site. PHP 8.2.30. A private, socket-only MySQL 8.4.0 server.
- Twelve fresh disposable sites: one for the 26 ordinary runs, one each for the
  ten restore and kill runs, and one for the negative controls.

**Attestation.** Every run phase compares every PHP file loaded from the
installed Core with its entry in the archive under test, once before the upgrade
and again after it. Killed requests, and `disabled` runs, which never upgrade,
are attested before the upgrade only. It
fails if one differs, if the updater's native class file is not among them, or
if a `RAN\` class loads from anywhere else. Each comparison matched 247 to 253
files.
The three WordPress upgrader classes must load from the disposable site; their
hashes are in `results.json`. A manual probe confirmed the check fails on an
edited Core file. Editing the updater's file was already refused earlier, at
discovery, by the updater's own runtime checks.

Remote HTTP is synthetic. Every other component is the real installed code.
The proof places a modified offer, built with the updater's existing token
format, through a `pre_site_transient_update_*` filter removed when the call
returns. Both native paths keep the default `clear_update_cache=true`.

## Case results

Both package types ran every row.

| Case | Result for plugins and themes |
| --- | --- |
| `current` (Manual) | 3.0.0 installed; one fresh download |
| `automatic` | Manual request admitted with Automatic stored; 3.0.0 installed |
| `disabled` | Discovery refused; no download |
| `active` | Activation preserved immediately and in a new process; 3.0.0 installed (plugin by count-one `bulk_upgrade`, theme by `upgrade`) |
| `equal` | **Refused**: `ran_wp_release_updater_unverified_pre_download`; files unchanged |
| `older`, `older-override-off` | Refused with the same code; files unchanged. Older installs are withdrawn from #903 |
| `newer` | Chosen 2.0.0 installed while 3.0.0 exists |
| `prerelease` | Chosen 2.1.0-rc.1 installed on the pre-release channel |
| `repository-race`, `revision-race` | `ran_booster_native_update_authority_changed`; files unchanged (sequential change, not concurrent requests) |
| `mutable` | `ran_wp_release_updater_remote_release_changed`; files unchanged |
| `assurance` (digest lost) | `ran_wp_release_updater_acquisition_failed`; files unchanged |
| `restore` (current 3.0.0 fails after replacement) | Native `WP_Error`; restored to 1.0.0 by the end of the request |
| `restore-newer` (chosen 2.0.0 fails after replacement) | Native `WP_Error`; restored to 1.0.0 by the end of the request |
| `restore-fail` (chosen 2.0.0 fails, restore forced to fail) | Native `WP_Error`; the failed 2.0.0 files remain and the 1.0.0 backup is deleted |
| `kill-current`, `kill-newer` | SIGKILL after the install result: new files installed, 1.0.0 backup left in `upgrade-temp-backup` |

### Offer lifetime, read from the stored row

Each readback checks both the filtered `get_site_transient()` value and the raw
`_site_transient_update_*` row for this run's exact marker. The two must agree.

| Native path and outcome | Plugin | Theme |
| --- | --- | --- |
| Successful direct `upgrade` (current, Automatic, newer, pre-release) | Absent | Absent |
| Active target (plugin bulk route, theme `upgrade`) | **Stored** | Absent |
| Refusals before download | Absent | Absent |
| Install failed after replacement: restored, chosen restored, restore failed | **Stored** | **Stored** |
| Killed requests | Absent | Absent |

## What WordPress reports about its own restore

From the `restore*` runs and the WordPress 7.0.3 source
(`wp-admin/includes/class-wp-upgrader.php`):

- When installation fails after replacement, `install_package()` only schedules
  `restore_temp_backup()` on `shutdown` at priority 10. The caller gets the
  install error (here `ran_wp_release_updater_unverified_install_result`, no
  error data) before any restore runs.
- `restore_temp_backup()` returns `true` when it restores or finds no backup,
  `false` when its arguments are incomplete, and a `WP_Error`
  (`fs_temp_backup_delete`) when it cannot restore. WordPress runs it through
  `do_action()`, which discards the return.
- `install_package()` also schedules `delete_temp_backup()` on `shutdown` at
  priority 100, **whether or not the install succeeded**. After a failed restore,
  WordPress therefore deletes the only copy of the previous version and leaves
  the failed new files in place.
- Nothing was written to PHP's error log in any restore case. The proof turns
  `log_errors` on for the failed request, checks the log at the restore call,
  and checks it again from the next request, after every later shutdown
  callback. WordPress's debug settings were left at their defaults.

So a failure message can say the install failed. It cannot say the previous
version was restored, and after a failed restore no backup remains.

To record the discarded value, the proof removes WordPress's own `shutdown`
registration and makes the same `restore_temp_backup()` call at the same hook
and priority, once. For `restore-fail` it makes the target directory read-only
for that call only, so WordPress cannot remove the failed version; the backup
itself is untouched. Permissions are restored straight after the call.

## Deltas needed before the native path could pass

These are not built here. Each would need its own reviewed change, and the
budgets on #969 apply.

- **Release updater:** a request-local authorisation for one target and one exact
  release that admits an equal version at the four newer-only checks, keeping
  ordinary discovery newer-only and fresh inspection, reacquisition and
  verification unchanged; and removal of the marked offer at the moment
  WordPress saves its update check, not afterwards. One public operation.
- **Core:** a request-local Branch admission bridge for the chosen release, and
  atomic completion that records the new source and clears the native record
  together. The 2026-11-16 experiment measured +35/−15 production lines for the
  completion half; the bridge is not costed.
- **Release Deployments:** none identified.

## Not covered here

Held for the owner: an active target with a chosen non-current release, a
forced install under Disabled with an injected offer, an interruption sweep
across WordPress's install stages, and competing native installs under a real
barrier. Not run: Branch-to-release end to end (it needs the Core and updater
deltas), scheduled automatic updates, a kill in the middle of WordPress's file
copy, and any CI subset (deferred to the Core production pull request). The
completion and recovery proofs from the 2026-11-19 experiment depend on
unmerged Core changes and are not on `main`.

## Reproduction

Provision a fresh WordPress 7.0.3 site with PHP 8.2, an isolated MySQL 8.4
database, `http://localhost` as its site URL and a `proof` administrator. Create
`.ran-booster-disposable-test-site` containing exactly
`RAN Booster disposable test site` and a newline. Verify `ABSPATH`,
`WP_CONTENT_DIR`, the plugin and theme roots, the marker and that the target is
absent, then install and activate the Core ZIP under test. Never point these
runners at a retained or release-only site, a symlink or a shared development
checkout. Run as a user other than root: `restore-fail` relies on file
permissions.

```sh
PHP_BIN=/path/to/php8.2 \
RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE=/absolute/disposable/wordpress \
RAN_NATIVE_RELEASE_INSTALL_PROOF_CORE_ZIP=/absolute/ran-booster-1.0.0-beta.49.zip \
bash tests/WordPress/native-release-install-proof/run.sh
```

Each target is seeded in one process, upgraded in another and read back in a
third. Use a separate fresh site for each `restore`, `restore-newer`,
`restore-fail`, `kill-current` and `kill-newer` case, for each package type, by
adding:

```sh
RAN_NATIVE_RELEASE_INSTALL_PROOF_TYPES=plugin # or theme
RAN_NATIVE_RELEASE_INSTALL_PROOF_CASES=restore # or another case above
```

The kill cases require exit 137, then read files and backup from a new process.

On another fresh site, with the same variables, run the negative controls:

```sh
bash tests/WordPress/native-release-install-proof/negative-controls.sh
```

Each control runs the real proof with one deliberately wrong observation and
must fail at the named assertion: a different refusal code, an inactive read
after an active install, a surviving offer in a successful readback, a raw
stored row that holds the marker while the filtered read is clean, and (plugin
only) the raw stored row hidden while the filtered read still shows the offer.
The controls change only test filters.

These runners are local. CI analyses the PHP but does not execute them.

## Verification

- Final matrix on fresh sites: all 26 ordinary runs, all ten restore and kill
  runs and all nine negative controls held their assertions; about two minutes
  including site provisioning. `results.json` records every observation and the
  hashes of the harness files that produced them.
- `composer check` and `pnpm check`: see the pull request.

## Branch-to-release switch

Refs #903. `ReleaseSwitchProof.php` drives the real installed Core: the
`enable()` facade an administrator's switch calls, the release updater's
exact-release offer and WordPress's own upgrader (count-one `bulk_upgrade` for a
plugin, `upgrade` for a theme). Remote HTTP is synthetic; every other component
is the installed code, attested against the Core ZIP under test before and after
each switch. Each case seeds a Branch package at 1.0.0, switches in a second
process and reads back in a third, which stands in for the next admin page
load.

| Case | Result | Source after | Native custody |
| --- | --- | --- | --- |
| `same` | Published 1.0.0 installed over the Branch 1.0.0 build | Releases, revision 2 | Cleared |
| `newer` | 2.0.0 installed; Automatic reset to Manual | Releases, revision 2 | Cleared |
| `active` | 2.0.0 installed; activation preserved | Releases, revision 2 | Cleared |
| `compete` | A second switch, run while the first holds the installer, is refused; the first completes | Releases, revision 2 | Cleared |
| `older` | `release_older`; files unchanged | Branch | None |
| `disabled` | `policy_blocked`; files unchanged | Branch | None |
| `failed-download` | `wordpress_failed` before replacement; files unchanged | Branch | None |
| `offer-removed` | The offer is gone when the upgrader reads it, so WordPress reports the package up to date: `wordpress_failed`, never success; files unchanged | Branch | None |
| `conflict` | Another package from the same repository is added after the install; the shutdown commit refuses it, so the updater records an uncertain outcome. The reply said `release_enabled`; the next load settles it as `management_state_uncertain` | Branch, with the release files installed | Retained |
| `failed-install` | `wordpress_failed` after replacement; WordPress restores 1.0.0 at shutdown | Branch | Retained |
| `kill` | SIGKILL after the install result: 2.0.0 files, 1.0.0 backup left | Branch | Retained |

The source changes only when the updater's finalisation reports a verified
install, in the same transaction that clears native custody. That commit runs
at shutdown, after the reply, so the page the administrator lands on reads the
source back: every readback settles the reply as the landing page does, and
only a package on Releases is reported enabled. In `conflict` the reply is
`release_enabled`, the commit is refused, and the landing shows
`management_state_uncertain`. Retained custody is the existing recovery path
for an interrupted Update.

Every readback reads the raw `_site_transient_update_*` row and fails if the
one-off offer reached it. Each case seeds a still-fresh stored check holding an
ordinary offer. After a refused or failed switch, the readback runs WordPress's
`_maybe_update_*()` and requires a check newer than the readback's start that
holds the ordinary offer again. Run against a build with Core's end-of-request
recheck removed, `older` and `disabled` fail that assertion for both package
types.

```sh
PHP_BIN=/path/to/php8.2 \
RAN_RELEASE_SWITCH_PROOF_SITE=/absolute/disposable/wordpress \
RAN_RELEASE_SWITCH_PROOF_CORE_ZIP=/absolute/ran-booster.zip \
bash tests/WordPress/native-release-install-proof/switch.sh
```

The site is the marked disposable WordPress 7.0.3 site described above, with
an administrator named by `RAN_RELEASE_SWITCH_PROOF_USER` (default `admin`).
CI runs the default cases for both package types on the critical MySQL 8.4 and
MariaDB 10.11 rows. `conflict`, `failed-install` and `kill` keep native custody, which
blocks later native work on that site, so they run locally only, each on its own
fresh site:

```sh
RAN_RELEASE_SWITCH_PROOF_TYPES=plugin RAN_RELEASE_SWITCH_PROOF_CASES=kill \
bash tests/WordPress/native-release-install-proof/switch.sh
```
