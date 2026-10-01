# Connected Branch and Provider naming adoption

This is the accepted #167 preparation tranche, coordinated through the
[operative checkpoint](https://github.com/RocketsAreNostalgic/ran-booster/issues/167#issuecomment-5939201056).
It is not a release or dependency-adoption record. Live exact candidate heads,
checks and ownership belong in that checkpoint and the receiving PR.

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
checks to make a preparation overlay look like adoption. Until publication
unlocks step 5, archive/native installed qualification remains blocked.

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
