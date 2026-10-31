# Overview implementation contract (#568)

Source baseline: `c0f127a29ab5e136986169b4b5a99f6f5d9bc546` (live main, 31 October 2026).
Accepted public design: [Core #568](https://github.com/RocketsAreNostalgic/ran-booster/issues/568), read 31 October 2026; cross-page ownership follows [#561](https://github.com/RocketsAreNostalgic/ran-booster/issues/561).

## Invariants frozen before production edits

- Presentation never gates public installation, management, import or credentials. Existing operation authority/integrity checks remain authoritative.
- One non-autoloaded site option, `ran_booster_overview_presentation`, stores only `dashboard` or `onboarding`. Invalid/missing means automatic. No GET writes, user metadata, completion/history flag or configuration reset.
- Explicit choices win. Confirmed installed managed records count regardless of activation or Disabled update policy. Installed files, Preview and uncertain adoption are not management.
- Each package repository is read once. A failure leaves that type unknown, never zero; completeness is independent of positive known records.
- Existing source cannot prove lifetime first use. Empty current inventories plus empty retained Activity also describe previous use, pruning, reset or loss. Database activation exposes no reliable later-request first-use fact. Automatic unproven-empty uses the accepted neutral dashboard and Return opens guidance. This cannot prove automatic first-use onboarding and must remain an explicit delivery limitation unless the owner revises the evidence constraint.
- No recovery scans, remote checks or credential-document reads for summaries. Existing protected storage inspection/result is captured once and reused. Storage recovery remains solely in its operational home.
- Admission is not checked access. Without current identity-bound evidence, access and credential expiry are unknown with provider-management links. Observations alone cannot establish a current credential census.
- Activity uses the existing bounded query with limit five. Unavailable differs from available-empty. Only safe projected event fields reach the view.
- Site options remain scoped to the current supported single site. Existing multisite refusal remains unchanged; no network-support claim.

## Ownership and projected cost

Overview owns `OnboardingPresenter.php`, `views/onboarding.php`, one optional dashboard partial, Overview tests and this contract. Settings owns all common Dashboard/Dispatcher/Booster/service-provider/bootstrap/routing integration and the storage cutover. Agree exact payload signatures before integration; do not concurrently edit shared files. Existing storage forms/results stay intact until the Settings owner moves the complete flow.

Projected production additions before editing: `OnboardingPresenter.php` 180 lines, `views/onboarding.php` 80 lines, `views/overview-dashboard.php` 140 lines, uninstall option allowlist 1 line. These are reviewed estimates, not actual measurements. Zero new concrete production types, public extension seams, database tables/schema migrations, JavaScript or CSS; exactly one scalar persistent option. Shared integration cost belongs to the Settings owner and is recorded separately. Tests/documentation are measured separately at delivery.

Rejected smaller alternatives: always-on onboarding ignores managed state and the accepted dashboard; using storage readiness as completion misclassifies preparation; treating empty Activity/inventory as first use invents history; displaying raw expiry observations presents stale identities as current. Rejected larger alternatives: new settings framework, completion tracker, generic registry/DTO/service, cache/event store or network abstraction.

## Verification required

Baseline Overview routing, storage presenter/dispatcher and view tests; projection states (managed including inactive/Disabled, empty, partial, unknown); protected POST method/capability/nonce/value/readback, repeated and failed writes; two administrators sharing choices; unchanged operational configuration; storage response precedence; uninstall cleanup; bounded read counts; provider/expiry unknowns; available-empty versus unavailable Activity and output escaping. Run required `composer check`, `pnpm check`, PHP lint, CI activation/database smoke on a verified disposable site and browser checks (desktop/narrow, keyboard, JavaScript off). Source tests, archive provenance and installed/browser acceptance are separate evidence. No retained-site mutation, merge, release or deployment is authorized.

## Source implementation and relocation handoff

The Settings owner supplied the shared Overview wiring/action/uninstall patch and explicitly approved its application in this branch. On 31 October the owner of that lane confirmed #568 should qualify independently, retaining the complete existing Overview storage flow. Do not switch remediation links to Settings until that lane delivers its coherent cutover. At cutover, pass the same captured `storage_summary()` result into `build_overview()`, remove the privileged Overview payload build, and replace the original operational block with the bounded status/message/Settings URL and retained old anchor. Existing storage failure results remain visible until that replacement is complete.

The source classifier deliberately makes no first-use claim: an absent/unrecognized presentation option uses the neutral dashboard; an explicit `onboarding` choice selects storage-first guidance even with a managed package. A confirmed managed record therefore always defaults to the dashboard unless the site explicitly returned. No preparation state writes a milestone or changes the choice. Reliably established lifetime first use is unavailable in current source, so automatic fresh-site onboarding is not claimed.

Actual authored production delta before review: 291 additions / 53 deletions (net +238), including 40/6 from Settings-owned shared integration; zero new concrete production types or public extension seams. The single option and no-schema/no-JavaScript/no-CSS constraints hold. Tests add 672 lines and remove 11; this is separate from production. Generated translation catalog adds 229/removes 115. Documentation and local verification artifacts are separate; counts are not delivery targets.

Verification so far: baseline focused suites 216 tests / 3178 assertions; pinned frontend gate 265 asset tests; new presenter/view/action/uninstall focused checks pass; installed direct projection proof passed using real WordPress repositories, including inactive Disabled management and shared choices under two users. The installed failure-inventory case uses an explicitly synthetic repository. CI database-storage smoke passed unchanged on disposable MySQL 8.4 / PHP 8.2 after genuine GNU timeout was provisioned. Full source gate, immutable archive verification, browser/native/enhanced/no-JavaScript proof and independent exact-PR review are separate requirements; the PR records their exact results.

The frozen production files passed disposable browser proof with two authenticated administrators, native forms with JavaScript disabled, protected enhanced POST and fixed HX-Location, invalid nonce and malicious GET rejection, explicit Return with an inactive Disabled managed plugin, later visits, keyboard reachability and a 390px viewport. All non-transient options except the presentation scalar had the same snapshot before/after. The actual private-path failure on this fixture remained visible in both views. This is source-copy integration evidence, not retained-site or published-artifact acceptance, remote public installation, recovery mutation or complete assistive-technology qualification.

## Exact-review provider correction (before production edits)

The opened PR review identified that an allowlisted GitHub review tab can exist without an admitted provider. A link alone is not admission/selection evidence. The agreed correction adds an internal `ProviderSettingsPresenter::overview_provider_facts()` projection from registry metadata and the existing passive GitHub selection status only. Expected additional authored production cost: 24 lines there, 1 Dashboard argument, 18 presenter projection lines, and 30 view lines. Zero new types, persistent state or public extension seams. Passive selection checks existing local option/active-plugin/header facts; it constructs no provider, enumerates no credentials and performs no adoption review, remote check or recovery scan. Overview maps those facts to the existing tab allowlist and retains admission unknown when absent. Selection repair stays linked to the owning provider screen. This supersedes the initial provider-links-only presentation.

Final post-review authored production delta: +370/-53; tests +890/-11; generated translation catalog +321/-151. The provider correction reuses the same facts in guidance, dashboard and attention; known selection repair states are linked in both views, and an allowlisted repair tab never implies admission. No new production type, state or extension seam was added.
