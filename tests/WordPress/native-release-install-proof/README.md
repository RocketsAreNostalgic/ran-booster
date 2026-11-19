# Native chosen-release install feasibility

Refs #969 and #903. The chosen-release feasibility gate is **partial/failed** on
this candidate. Equal and older releases are refused, and the request-offer
lifetime requirement fails on specific paths below. The owner-confirmed beta.49
fallback remains **Update to X for the current WordPress offer only**: no
other-version or older-version installation and no Branch-to-release switch.
Exit zero means the characterization assertions held, not that the feasibility
gate passed. These findings do not establish general recovery inequivalence or
a need for a durable hard stop. PU-002 remains closed.

## Composition and provenance limits

Author-reported local composition for the corrected run:

- Core source: `c5b645bba754fe6e80e96a8f648814042ac17a86`.
- Locally built and archive-verified Core `1.0.0-beta.46` ZIP SHA-256:
  `90c74530b45ef8cde5b24857a6ec6d9dba663d30338353872b8ce38eeaba2e21`.
- Shared release updater: `v1.0.0-beta.19`,
  `c6aa2e6e1456b0ad4abec24bf41a870d151eb4b2`.
- WordPress 7.0.3, PHP 8.2.30, MySQL 8.4.0, single-site, direct filesystem.
- Thirty-two scenario runs across seven fresh disposable installations: 26
  ordinary runs, two current-offer restoration runs, and four killed requests.
  Six negative controls run on an eighth disposable installation. No retained
  or release-only site is used.

The harness checks WordPress's reported 7.0.3 version, PHP 8.2 major/minor, site
identity and fixture safety on each invocation. It does not attest loaded class
origins, runtime file hashes or the installed Core/updater manifest against the
ZIP. Native-runtime and Core/updater provenance therefore remain author
reported, not independent attestation. `results.json` separates this composition
from observations and binds the observations to the test-file hashes.

The experiment invokes installed Core registration, provider discovery and
WordPress upgrader classes. The candidate changes the release descriptor in the
control offer using the existing descriptor/token format and exposes it through
a `pre_site_transient_update_*` filter removed when the call returns. Both native
paths retain the default `clear_update_cache=true`. Production guards,
acquisition, installation, backup, restoration and completion are unchanged.

Remote HTTP responses are synthetic. Shared-updater inspection and fresh
acquisition use generated ZIPs. Successful installs require one fresh ZIP stream
after discovery, and every fixture file must match independently generated
expected bytes with no unexpected files. The synthetic feed includes 3.0.0
while the candidate chooses 1.0.0, 0.5.0, 2.0.0 or 2.1.0-rc.1. This tests the
offered-descriptor mechanism, not a public picker, by-tag API, admin route or
source switch.

## Case results

Both package types were executed for every runtime row. All refusal cases
assert the exact error-code list, native return type, zero fresh ZIP streams,
unchanged complete tree and inactive status. Successful cases assert the native
result, one fresh stream, exact installed tree and expected activation.
Fresh-process readback asserts tree, activation and the path-specific lifetime
observation. `results.json` preserves native return types separately from errors
observed in the download filter or upgrader skin.

| Case / selector | Evidence class | Verdict for plugins and themes | Limit |
| --- | --- | --- | --- |
| Manual / `current` | Executed runtime | Pass: 3.0.0 installed; one fresh acquisition | Manual request; inactive target |
| Automatic policy / `automatic` | Executed runtime | Manual request admitted with Automatic stored; 3.0.0 installed | Scheduled automatic update not exercised |
| Disabled / `disabled` | Executed runtime | Discovery refused; zero ZIP streams | Forced install with an injected offer not exercised |
| Activation / `active` | Executed runtime | Pass: activation preserved immediately and in fresh process; 3.0.0 installed | Plugin uses count-one `bulk_upgrade`; theme uses `upgrade`; active noncurrent selection not exercised |
| Equal version / `equal` | Executed runtime | Failed feasibility: 1.0.0 rejected with `ran_wp_release_updater_unverified_pre_download` | Original tree unchanged; no successful reinstall |
| Older version / `older` | Executed runtime | Failed feasibility: 0.5.0 rejected with the same code | Override on, but #888 policy unimplemented |
| Older, override off / `older-override-off` | Executed runtime | Same refusal and unchanged tree | Not proof of policy refusal: override-on also fails |
| Other newer release / `newer` | Executed runtime | Pass for tested mechanism: chosen 2.0.0 installed while 3.0.0 exists | Request-scoped synthetic selection |
| Prerelease / `prerelease` | Executed runtime | Chosen 2.1.0-rc.1 installed on prerelease channel | No #891 onboarding or #888 environment-policy qualification |
| Repository / `repository-race` | Executed runtime | `ran_booster_native_update_authority_changed`; unchanged tree | Sequential stored identity change after discovery, not competing requests |
| Source revision / `revision-race` | Executed runtime | Same exact authority refusal; unchanged tree | Sequential revision change, not concurrent-request race coverage |
| Mutable replacement / `mutable` | Executed runtime | `ran_wp_release_updater_remote_release_changed`; unchanged tree | Mutable publication's asset identity changes after discovery |
| Missing digest / `assurance` | Executed runtime | `ran_wp_release_updater_acquisition_failed`; unchanged tree | Fresh provider metadata loses digest; no separate assurance-checker rejection exercised |
| Failed current-offer install / `restore` | Executed runtime | Original complete tree restored by next request after synthetic post-replacement error | Current 3.0.0 offer only; noncurrent restoration and restore failure not exercised |
| Killed current update / `kill-current` | Executed runtime | SIGKILL exit 137; installed 3.0.0 and old 1.0.0 backup remain | One point after installation result, before completion |
| Killed chosen update / `kill-newer` | Executed runtime | Same point: installed 2.0.0 and old 1.0.0 backup remain | No mid-copy/backup-move sweep or overall recovery equivalence established |
| Branch to release | Source-only; runtime not exercised | Gate incomplete: current native registration admits only release-source rows | No request-local Branch authority bridge exists or was invented |
| #888 environment admission / site-address binding | Source-only; runtime not exercised | Gate incomplete at the recorded revision: no production reader of the override, now `RAN_BOOSTER_ALLOW_PRERELEASE_SOURCES` | Declaring it cannot prove unimplemented policy |

Branch source evidence is `ManagedReleaseTargetRegistrar::register_packages()`;
`RAN/`, `views/` and `assets/` contain no reader of the #888 constant at the
reported source revision. The `active` selector uses the supported count-one
bulk plugin path. An earlier exploratory direct active-plugin `upgrade()` run
failed with `runtime_liveness_lost`; that path is not qualified here.

### Offer lifetime with native cleanup

The unique marker distinguishes the injected offer from ordinary discovery.
These observations replace the earlier run with `clear_update_cache=false`.
They are assertions of the observed behavior, including the failing cases;
expecting persistence here does not satisfy the no-surviving-offer requirement.

| Native path and outcome | Plugin readback | Theme readback |
| --- | --- | --- |
| Direct `upgrade`: current, Automatic setting, other newer, prerelease | Offer absent | Offer absent |
| Active target: count-one plugin `bulk_upgrade`, theme `upgrade` | **Marked offer persists** | Offer absent |
| Direct pre-download refusals: equal, older, older override off, repository/revision change, mutable replacement, missing digest | Offer absent | Offer absent |
| Direct failed current-offer install followed by restoration | **Marked offer persists** | **Marked offer persists** |
| Direct killed current and chosen-newer requests | Offer absent | Offer absent |
| Disabled discovery only | No offer; none injected | No offer; none injected |

Bulk failure cleanup, active noncurrent cleanup and noncurrent restored-failure
cleanup were not exercised. No conclusions are inferred for those paths.

### Restoration and recovery limits

The injected error occurs after replacement. For the tested current-offer
failures, the runner asserts native return `WP_Error`, the observed
`ran_wp_release_updater_unverified_install_result` code, null error data, one
fresh ZIP stream and a registered shutdown restoration callback. These are the
reported facts before shutdown. Separately, a new process verifies every
original file and its bytes. The observed error does not report restore success;
filesystem inspection does. This proves only those two synthetic restorations,
not that an arbitrary failed install leaves the previous version intact.

Equal/older refusal and the observed lifetime failures support the partial/failed
chosen-release gate and confirmed fallback. Missing Branch, policy, assurance
checker and noncurrent restoration coverage also prevents a full pass. The two
kill selectors compare one matching point only; they neither prove nor disprove
overall recovery equivalence. PU-001, PU-002, PU-003 and PU-016 are not reopened.
No production delta plan for Updater, Core or Release Deployments is proposed:
that planning requires a passing gate and separate reviewed authority.

## Reproduction

Provision a fresh synthetic WordPress 7.0.3 installation with PHP 8.2, an isolated
MySQL 8.4 database, `http://localhost` as its site URL and a `proof` administrator.
Create `.ran-booster-disposable-test-site` containing exactly
`RAN Booster disposable test site` plus a newline. Verify ABSPATH,
WP_CONTENT_DIR, plugin/theme roots, marker and absent destination before
installing the Core ZIP. Never point this runner at a retained or release-only
site, symlink or shared development checkout.

```sh
PHP_BIN=/path/to/php8.2 \
RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE=/absolute/disposable/wordpress \
bash tests/WordPress/native-release-install-proof/run.sh
```

The runner declares the override constant on before WordPress loads, except for
`older-override-off`. Each target is seeded in one process, upgraded in another
and read back in a third. Its exact management row is retired after successful
readback; synthetic files/databases remain available for inspection and disposal.

Use a separate fresh site for each `restore`, `kill-current` and `kill-newer`
case, for each package type. Their failure/recovery state must not contaminate
another case. Prefix the command with these additional variables:

```sh
RAN_NATIVE_RELEASE_INSTALL_PROOF_TYPES=plugin # or theme
RAN_NATIVE_RELEASE_INSTALL_PROOF_CASES=restore # or kill-current / kill-newer
```

The kill runner requires exit 137, then checks files and backup from a new
process. It never runs shutdown manually or clears recovery state to pass.

On another fresh disposable site, run the negative controls with the same
required PHP/site variables:

```sh
bash tests/WordPress/native-release-install-proof/negative-controls.sh
```

For each type, these execute the real proof with one deliberately wrong
observation: a different refusal code, an inactive read after active-target
installation, or a surviving offer during current-success readback. They require
nonzero exit and the corresponding assertion message. The controls alter only
test filters; they do not alter production files or persistent activation state.
The wrong observation must fail the proof even though it could have been merely
printed or accepted as any `WP_Error` previously. Both shell runners stop on
unexpected results.

Existing CI configuration and entrypoints are unchanged. Ordinary gates analyze
the new PHP but do not execute these WordPress runners. These are local synthetic
runtime observations, not hosted CI runtime, browser acceptance, released-artifact
acceptance or independent exact-PR review.

## Verification

- Corrected `run.sh`: all 32 scenario runs matched their assertions with default
  native cleanup; chosen-release feasibility remains partial/failed.
- `negative-controls.sh`: all six deliberately wrong observations caused the
  expected assertion failure (refusal/lifetime exit 1; activation exit 255).
- `composer check`: passed, including 6,649 PHPUnit tests / 84,045 assertions,
  syntax, PHPCS and production/development PHPStan (four development processes).
  The aggregate uses PHP 8.5; disposable runtime proofs use PHP 8.2.30.
- `pnpm check`: passed, 749 tests, Node 24.21.0 / pnpm 11.7.0, with the exact
  executable directories ahead of the package-manager shims.
- Focused changed-file analysis, shell syntax and evidence hashes passed.
  Final POT regeneration follows latest-main catch-up without rewriting pushed
  history; its result is recorded in the PR.
- No production, lockfile, existing harness/script, CI or catalog edits relative
  to the new base. All additions remain in this test subtree.
