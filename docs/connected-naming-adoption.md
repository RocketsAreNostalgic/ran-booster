# Connected Branch and Provider naming adoption

> Historical tranche evidence: the package identities, boundaries and retained
> symbols below describe this earlier handoff. The current beta.31 naming
> contract is documented in [CONTRIBUTING](../CONTRIBUTING.md#current-php-naming-contract).
> These earlier checks do not qualify the recovered combined candidate.

This is the accepted #167 connected naming tranche, coordinated through the
[operative checkpoint](https://github.com/RocketsAreNostalgic/ran-booster/issues/167#issuecomment-5939201056).
Live exact Core candidate heads, checks and ownership belong in that checkpoint
and the receiving PR. Core merge and release remain separate approval gates.

## Verified package publication and selected lock

Both owner-approved producer release PRs were merged with merge commits on
1 October 2026. Exact-main CI and Profile A publication passed for each.

| Package | Immutable release | Tag/source/dist commit | GitHub release ID |
| --- | --- | --- | --- |
| Branch | `v1.0.0-beta.8` | `729a15c30f088236d0702b52d4a9cbe15c851508` | `401363087` |
| GitHub Provider | `v1.0.0-beta.9` | `82ad810e8cde6a2f54318448e81685a619c2cfc3` | `401363341` |

GitHub reports both releases immutable and published; their tags resolve
directly to the commits above. Core selects these real released requirements
and Composer lock identities. Unrelated dependencies remain pinned. This
supersedes preparation overlays but does not itself establish installed-site
qualification or a released Core host. The receiving Core PR must qualify its
exact committed lock, archive and WordPress/database composition independently.

## Preserved handoffs and scope

- Branch #72 at `9e2df612e073169f119187870b4f1b546128d02e` prepares
  32 methods, 10 properties and 26 parameter occurrences.
- Core #217 at `6811610bc5545f845efb02f8876e92cf87e0dff1` supplies the
  six accepted deployment consumers/tests, based on Core
  `02859a4f79353bf97af58316e9247102ca185e76`.
- Provider #47 at `798d70011077cfc0fbb8eda8f8abb78859440287` supplies the
  accepted manifest: 52 helper methods, 64 camelCase parameter occurrences and
  four reserved parameters. Three private promoted properties are already
  counted within the 64 parameters. Its successor runtime change preserves
  that manifest and adds the paired uninstall/logging consumers.

Root integration additionally updates three private-property reflection strings
in `tests/RepositoryProvider/BuiltInGitHubRegistrationTest.php`. Provider #42
already renamed that field to `maximum_artifact_bytes`; the public factory
parameter `maximumArtifactBytes` remains unchanged. This discovered adoption
dependency preserves the existing artifact-limit assertions and adds no new
runtime scope to either delegated worker.

Installed qualification also exposed a stale `nativeTargets` reflection string
in `tests/WordPress/native-lifecycle-installed-smoke.php`. That fixture now uses
Provider's private `native_targets` field; its installed lifecycle assertions
and operational sequence are unchanged. These four reflection corrections
are required consumers of the already-published private-property migration.

Direct PHP API replacement intentionally breaks old method/property/named
argument use during beta. Mixed old/new tuples are unsupported. Foreign
interfaces, unrelated same-spelled methods, wire/persisted fields, templates,
release identities, credentials, error/status semantics and runtime behavior
are unchanged. Core's broader Provider interfaces remain outside this cut.

## Qualification and publication order

There is no Composer dependency cycle: Core bundles Provider, while Provider
implements Core contracts and tests against an exact Core checkout. Provider
does not require the whole Core package. Keep that architecture boundary.

1. Freeze exact Branch, Provider and combined Core source commits. Review all
   three actual published base/head tuples. Run each producer's ordinary
   aggregate and required hosted checks; run Provider's host aggregate against
   the exact combined Core candidate. Its previously certified host remains
   separate baseline evidence, not proof of this composition.
2. Qualify matching Core source preparation with `composer check`, exact-pinned
   `pnpm check`, focused deployment/logging/uninstall checks, naming controls,
   formatter stability and canonical generated/localisation checks. Record
   every overlay and exact source commit. Do not label this evidence released
   adoption, archive qualification or installed-site acceptance.
3. Obtain separate owner approval for each exact producer source PR. Squash
   ordinary development PRs and verify successful exact-main CI. Let the
   existing Profile A lifecycle refresh the bot-owned producer release PRs
   (currently Branch #69 and Provider #43); do not select speculative tags or
   edit generated release metadata manually.
4. Review and qualify each refreshed release proposal, obtain its separate
   owner approval, and follow the repository's release merge policy. Verify
   successful main admission and actual immutable release/tag identities,
   dereferenced commits and publication evidence. Source merge alone does not
   establish package publication.
5. Update only Core's two producer requirements and their actual Composer lock
   entries to the verified releases, preserving unrelated dependency pins.
   Confirm the locked source/dist references match the immutable package
   identities. No dev alias, counterfeit version or arbitrary candidate SHA
   may substitute for a released package.
6. Requalify the final exact Core composition. Run the required PHP/frontend,
   naming, formatter, generated/POT and clean no-dev checks. Use canonical
   `scripts/build-release.sh` and `scripts/verify-release.sh` from its committed
   manifest/lock and retain runtime identity generation. Verify the final
   archive and installed bytes, Provider/runtime readbacks, localisation and
   all required WordPress/MySQL/MariaDB lanes. No old certified host or archive
   proves this new tuple. Native terminal Quality and required hosted reviews
   must pass; obtain independent review of the final actual published tuple.
7. Obtain owner approval for the exact Core integration PR, then squash and
   verify exact-main Quality. Only after the handoff is fully accounted for in
   its receiving change may superseded drafts be closed.
8. Core release PR #181 is a separate decision. The existing Profile B process
   refreshes and qualifies its release-only candidate. Obtain explicit approval
   and use a merge commit for the bot-owned Core release PR. Verify successful
   merged-main Quality and promotion of that exact tested ZIP, then immutable
   release/asset readback. Never rebuild or replace published assets.

Core's runtime dependency verifier deliberately requires released semantic
versions and canonical, matching source/dist commit references. Its archive
builder installs from the committed lock independently. Do not weaken these
checks to make a preparation overlay look like adoption. Verified publication
above unlocks real-lock adoption and its archive/native installed qualification.

## Follow-on obligations

Track separately: the 50 Provider implementations of Core-owned interfaces;
other Provider public parameters/promotions; Release Updater beta.9/protocol-5;
Bitbucket API-12 released-Core certification; Migrator facade/DTO naming and
installed acceptance; other Core cohorts including ReleaseManagement. UI
features and owner-verified interactive acceptance remain deferred. Plugin
Library remains on the backburner and the CI optimization inquiry is retired.

Keep implementation, integration, qualification, merge, package publication and
Core adoption as separate ledger states. Approval for preparation does not
authorize any merge, release or publication step above.

## Next tranche: Core-owned provider methods (API13 preparation)

The previous helper/Branch tranche landed in Core #218 at
`9b634bdf10ae2866ceca54ac6262d4845fa859d2` and passed exact-main Quality.
Ben has held beta.31 for the subsequent connected provider-interface migration.
This next tranche changes 47 declarations across 20 Core interfaces, paired
with 50 GitHub Provider implementations, to snake_case. Parameter names and
promotions remain unchanged. API13 rejects old API11/12 implementations before
loading; workflowV3, Add-on16 and unrelated protocol identities are unchanged.

The initial API13 preparation retained the beta.9/API12 lock and was not
mergeable adoption. The API13 candidate selected immutable beta.10,
verified below; final combined archive/installed qualification remains required.
Qualify the exact Core/Provider sources first; approve and publish Provider
through its normal lifecycle; adopt the verified immutable release in Core;
then requalify the complete archive/installed composition and obtain separate
Core source and beta.31 release approvals. No fabricated tags or version aliases.

Bitbucket #89/#75 are assigned to the new Bitbucket coordinator. Their API12 work cannot
certify API13; matching implementation and later immutable-host certification
remain explicit coordinated obligations. The broad ReleaseManagement naming
cohort, other Provider parameters and UI acceptance are not absorbed.

### Historical API13 beta.10 adoption

Provider release #50 regular-merged as
`d39d83747af3109a79e80fd307d50e4fcc34d412` (tree
`d2e58aaffe1d763eb26928a379cd92c67e1c8c9d`). Exact-main CI36940248825 and
ProfileA36940445426 passed; immutable `v1.0.0-beta.10`, GitHub release401427616,
was published on 1 October 2026 at23:22:40UTC. Its tag resolves directly to that
merge. Core #220 adopted this release through its Composer requirement and
source/dist lock references, then squash-merged as
`d5b35ac53692fc3f40c8cad76eef35155a360ec8`. Branch remained beta.8 and unrelated
Composer pins were unchanged; Core219's reviewed development lock was retained.
That completed adoption is historical evidence for the API13 tranche. It does
not describe the current protocol5 candidate's dependency selection below.

### Protocol 5 released dependency composition

The current candidate builds on merged Core #222 at
`290fdd164a3f483b84e61b91daba123457aac672`, preserving Provider API13,
Portability API3 and canonical Portability hash payload2. It adopts immutable
GitHub Provider `v1.0.0-beta.11`, release401580118, source/dist/tag commit
`c88045d0b6d6048599454b9549e59ddf176d56f0`, published2October2026 at06:14:38UTC.
Provider exact-main CI36972421359 and publication workflow36972571174 passed.

The paired updater is immutable `v1.0.0-beta.9`, release398230418, source/dist/tag
`27889528442fc4e49ca060959218d5ec288c3055`. Protocol5 runtime revision is
`07696b27292b1c999714e31b06f2fd0d79d0d3e19e334eb17136c37b027c9a24`.
Core and its bundled Provider use the same Composer-installed updater copy.
Separate compatible protocol5 copies may participate in canonical runtime
selection; mixed protocol4/5 copies fail closed and are not supported together.

The two-test handoff a12b2681 and shared proposal e40c34de are adopted onto the
merged baseline. Composer generates the real lock; no source overlay or
fabricated package alias is used. Final archive and installed qualification
must bind this composition. Core beta.31 publication, Bitbucket and Migrator
released-host certification, and owner interactive acceptance remain separate
gates. Historical evidence above is not proof of the protocol5 composition.
