# Beta.44 integration accumulator

The coordinator-owned `integration/beta.44` branch retains the already installed composition as its initial runtime baseline. The reconciled changes are represented in its history; topic PR head commits are source provenance, not asserted merge ancestry. Component branches and PRs remain separate and unmerged. This manifest records included changes and queued work without automatically accepting or closing component PRs.

Released base: [v1.0.0-beta.43](https://github.com/RocketsAreNostalgic/ran-booster/releases/tag/v1.0.0-beta.43), commit `2f68b86795052d9006cd983914f98257c38b74a4`. Integration baseline: `5c992c497168e764483e757e83b36ca15fc413e7`, tree `f302e3b0980e171e4f89d639b8228a855e32a158`. Its first-parent lineage starts at that released commit and includes reconciled composition commits through `6a39daeb` and `5c992c49`.

## Core inputs

The following 19 topic heads identify the reviewed source inputs, not merge ancestry or a claim that their original PRs have merged.

| PR and bounded purpose | Included source head |
| --- | --- |
| [#636](https://github.com/RocketsAreNostalgic/ran-booster/pull/636) — Numeric Settings widths | `29b14b9a66c8376ff13512830dfe5d659e853d5d` |
| [#637](https://github.com/RocketsAreNostalgic/ran-booster/pull/637) — Managed-package links and inherited composition | `28f71e4a7c24144265a70f624c2195d44a9b8ef6` |
| [#638](https://github.com/RocketsAreNostalgic/ran-booster/pull/638) — Copy controls and native fallbacks | `8fe44a7a614f45d61378985b652d8e891e58c718` |
| [#645](https://github.com/RocketsAreNostalgic/ran-booster/pull/645) — Nozzle menu identity | `804d7103f3a1e71019f8a63a4d854e65cd27665b` |
| [#646](https://github.com/RocketsAreNostalgic/ran-booster/pull/646) — Migrator-owned guide integration | `18c7d36df9b70f1916ab858deef65e4d37b18641` |
| [#650](https://github.com/RocketsAreNostalgic/ran-booster/pull/650) — Shell v2 adoption | `77f2b901e0f5c58a4a8702807eed9f91e34b98b3` |
| [#652](https://github.com/RocketsAreNostalgic/ran-booster/pull/652) — Native disclosure surfaces | `e4b545a6b5dfe81d498b0705c2f6e9cdaba189bb` |
| [#656](https://github.com/RocketsAreNostalgic/ran-booster/pull/656) — Troubleshooting sidebar placement | `d49263d9383ab79910645d90b675cd8d8f5c52c6` |
| [#657](https://github.com/RocketsAreNostalgic/ran-booster/pull/657) — Package breadcrumbs and allowlisted return context | `65363268da29a24d972e5f5a83252f773e8294b3` |
| [#659](https://github.com/RocketsAreNostalgic/ran-booster/pull/659) — Recent Activity row presentation | `7b542e0eebad43677ea4e2452ae34ebc477ad72f` |
| [#660](https://github.com/RocketsAreNostalgic/ran-booster/pull/660) — Enhanced-feedback document guard | `d6992e00f52a07a920da136d668c5cba32430e49` |
| [#661](https://github.com/RocketsAreNostalgic/ran-booster/pull/661) — General Settings Snackbar | `7287a723e002efb2bc461775e78409d23cdf7ef9` |
| [#662](https://github.com/RocketsAreNostalgic/ran-booster/pull/662) — Storage disclosure spacing | `63ebbe5cb2712ed8c59d3f3f6fc80654af44198b` |
| [#663](https://github.com/RocketsAreNostalgic/ran-booster/pull/663) — Fresh source-checkout notice | `d44276a57684b9adb281f390bc53de121daada25` |
| [#666](https://github.com/RocketsAreNostalgic/ran-booster/pull/666) — Scoped preference autosave at the selected earlier head | `15f055359552e706a427bd386b8d407690c8f940` |
| [#667](https://github.com/RocketsAreNostalgic/ran-booster/pull/667) — Responsive menu icon alignment | `37e7a5c0c76441d123fd358c69a6ce37c25e256e` |
| [#668](https://github.com/RocketsAreNostalgic/ran-booster/pull/668) — Compact Managed Plugins rows | `b16006061dcda7fbea8497f89b82175431730ae1` |
| [#669](https://github.com/RocketsAreNostalgic/ran-booster/pull/669) — Local-development guidance and docs | `f2b0f55eea6e2042f43ed5d06fa5ce69bdcdf60c` |
| [#671](https://github.com/RocketsAreNostalgic/ran-booster/pull/671) — Overview/onboarding header alignment | `c73cae36cad77c09c428439f543bbc452dd97e57` |

Source metadata was refreshed on 6 November 2026: all listed PRs remain open, draft and unmerged. Eighteen current heads still match the selected inputs. **#666 has advanced remotely to `86cd3a46083a35b126d9fe2675975b3b6204d521`; that newer head is not included.** Its selected earlier input remains `15f055359552e706a427bd386b8d407690c8f940`. Reconcile later Settings work explicitly instead of merging the current PR wholesale.

## Separate inputs and last verified durable-baseline installation

Build-time Shell resources follow [Shell #39](https://github.com/RocketsAreNostalgic/ran-admin-shell/pull/39) `61e3543eecf047cfd5e4cb51079162c79593ef81` and [Shell #41](https://github.com/RocketsAreNostalgic/ran-admin-shell/pull/41) `5b8d58986503f2e00bcbc418214161db3ff6d3b2`. These are unreleased source inputs, not package publication. [Migrator #89](https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator/pull/89) `b8b82f19757b43e17c3df8c569d6f575672107ca` is a separate inactive external input; extension-first ordering remains a release concern.

The last verified installed direction preview on the stable accumulated site is `5c992c497168e764483e757e83b36ca15fc413e7`, tree above, ZIP SHA-256 `29fca11f7a9a86ff0275b57786fe9ddefa8cae9cffb962602a765565b1f4ee8a`. Its installation receipt and subsequent bounded readback verify 529 Core files, 600 modes, and 18 inactive Migrator files. The later readback observed General settings drift; it does not establish actor or cause. Immediate file exchange preserved fresh bounded data; the later General-settings hash change is a separate observation that was left untouched. Direction preview and source checks do not establish release acceptance. The source branch may advance for its manifest and queued changes; this installed identity must not be silently replaced with the latest branch head.

## Direction decisions and qualification

- Responsive icon alignment and the first compact Managed Plugins row direction were accepted. The new collection Card container/toolbar is a separate next preview.
- The owner selected Overview B and the revised native Extensions anatomy for upcoming direction previews; those new sources are not in this baseline. The coordinator has now selected the common public Card profile and sole shared-file writer. A styling decision does not establish runtime, lifecycle or whole-programme acceptance.
- Requested breadcrumb/sidebar and disclosure-gap contracts have focused source/CI/installed evidence. Broader component conversion, native leave dialogs, native 200% zoom, screen-reader audio, final composed acceptance and release/dependency decisions remain separately held. Do not infer blanket owner acceptance for every inherited visual detail.
- Baseline source `5c992c49` passed the full clean Core Composer check (3,902 tests / 64,318 assertions), both analysis scopes and pinned frontend checks (322 tests). Canonical ZIP verification linted 478 packaged PHP files. Source checks, input CI, local ZIP provenance, installed/browser proof and owner acceptance are distinct. The newly opened integration PR still needs exact-tuple independent review and its own required CI.

## Local direction candidate overlay — not admitted

A separate local `preview/beta44-shared-card` candidate starts from integration head `2570cdd87802ad920a45a347112970b37377f461`. It is not another accumulator and has not been pushed into the durable integration branch. The queued/admitted distinctions below refer to that durable branch; this overlay is a separately authorized, reversible direction-preview candidate.

- Common Card profile/adapter and one native-anatomy Extensions representative use source input `440277794023c12d82d81933a881a0710683c6c6`, reconciled through the exact baseline-relative runtime patch SHA-256 `761242786516108d1640715a137be4a62fdf3ca5cdd7c59bb653a4a20c9c5be8`. Existing global component styles and all Settings/menu/notice wiring are retained. The new Card script is the only added runtime file. No obsolete prototype script is deleted from a baseline that never shipped it.
- Managed Plugins uses [#677](https://github.com/RocketsAreNostalgic/ran-booster/pull/677), base `2570cdd87802ad920a45a347112970b37377f461`, head `336681c5d313b058462bbbc03d8c43b43c90c788`. Its native forms/table/compact rows and focused regression tests are retained; Themes and selection-restoration behavior are outside the slice.
- Overview B uses source `b66b8c178691086b563df53ef2ffa77e29342b79`: 20px blue managed totals, four existing package destinations, truthful unknowns, one authoritative Bitbucket Cloud name, and 2px extra Activity-row padding on each side. Header, secure storage, notice and backend behavior remain untouched.

The first local candidate `949281e3b9e0f250861e6099deb9a2e32c2b2e42` retained native content but mounted zero public Cards on the actual site: WordPress combined its registered component stylesheet into `load-styles.php`, so the standalone-link readiness check did not qualify it. The first failed smoke and rollback are retained. A JavaScript-only correction from source `21456a4c4354783af84221e5b549b6422f42254a` accepts enabled, readable same-origin WordPress stylesheet bundles only when their decoded load lists contain the exact `wp-components` handle; missing, disabled, empty and unreadable styles retain the fallback. Focused concatenation regressions passed before the corrected candidate. No site configuration, profile, body, action or asset-registration change is part of that correction.

The shared profile and Overview B direction are selected; new collection/adapter visual acceptance and full qualification remain pending. The shared adapter's HTMX host-innerHTML cleanup defect was corrected and passed focused real-HTMX WordPress 7.0/7.1.4 regression proof before this overlay. Canonical translation regeneration and focused combined checks are part of candidate preparation. Actual installation, Card adoption, single-surface CSS, actions and browser proof require the exact built candidate and its separate receipt. Static navigation and Settings #675 are not batched into this candidate.

## Queued, excluded and held

- [#672](https://github.com/RocketsAreNostalgic/ran-booster/pull/672) rejected `b958` redesign and superseded A/B alternatives are excluded. Revised `95da0e17540fce04873fc5d800222f5357a53b2c` remains queued; shared Card treatment now directed as white surface, `#c3c4c7` border, 3px radius, subtle shadow, 14px/1.5 typography. #629 owns the shared primitives file and Card adapter/enqueue; no claim of inclusion or installation.
- Card work is first, then the static pilot. The published Settings Snackbar focus refinement [#675](https://github.com/RocketsAreNostalgic/ran-booster/pull/675), base `eab00838399be2a8382b88047cae167adf76cdf8`, head `76033e7fe233dd7e1317d92303e4e4f1f542176b`, remains pending after the Card preview; it is not batched into this baseline. Its exact opened-PR review is reported clear; source CI/full PHP checks remain separately pending. #633 next container direction is not implemented. Overview B awaits its exact owner source handoff. Static Core `95856f8aa8afcdf181eaec583db2f3b7f9cef425`, its separately reported local Shell `f647b125` input, and archive SHA-256 `79d766c3c75b84cba4e6718337ae5a45229beb5a3575c7c6977cd43954550923` are queued, not installed.
- Direction approval is distinct from complete source, CI, archive, installed accessibility/compatibility and owner acceptance. All component PRs remain open and unmerged; no merge, release, publication, issue closure or acceptance-hold clearance follows from this manifest.
- A future integration PR requires its own independent review after opening against its exact base/head and its required CI. Recheck all input PR heads and their dispositions; independent owner authority remains required for a specific merge. Detailed evidence is available to the owner.

## Uncertainties

The current component-status snapshot is not a permanent guarantee. Refresh actual base/head tuples, checks and owner dispositions when advancing or retargeting. The static Shell input above is reported by its owner as an unpublished local development commit and is not claimed remotely fetchable or included. The exact next Overview input, any later Settings revision and every new installation window still require coordinator acknowledgement. No main merge, component closure or package/release publication is authorized.
