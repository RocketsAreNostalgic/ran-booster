# Admin Settings composition contract

Pre-edit source baseline: Core main
`c0f127a29ab5e136986169b4b5a99f6f5d9bc546`, Settings #583
`75803863c0310c24e6371b4e32a484c00c86b07f`, Overview #581
`a78e30635b6784f9f8ee908bf151c426e2948000`, and Troubleshooting #582
`73fbc38283f3128e76841c57d88664bfe9761a48`. The latter two lanes are
preparing bounded review corrections; final membership must record their
reviewed replacement heads before publication.

## Ownership and handoff

Settings is the sole writer of composed Dashboard, Dispatcher, Booster,
BoosterServiceProvider and uninstall routing. Overview owns its presenter,
cached credential-expiry projection, views and tests. Troubleshooting owns its
installation/report projection, diagnostic history, views and tests. Both
lanes supply exact heads and approved shared hunks; integration preserves their
semantics rather than choosing either whole file. The coordinator independently
reviews all three lanes and the final combined candidate and retains exclusive
localhost:10013 installation custody. Source integration authorizes no main
merge, release, tag, dependency publication or Workbench write/sync.

## Invariants

- `build_overview()` receives safe storage summary at argument 7 and local
  provider facts at argument 8. Cached expiry facts are attached after this
  call, without starting reminder enumeration, provider queries or decryption.
- Site-wide protected Skip/Return choices remain independent of General Save
  and cannot reset configuration. Ambiguous first-use history stays neutral.
- Settings is the sole complete home for storage forms, payload, instructions,
  failures, verification, actions, assets and redirects. Old Overview GET
  anchors hand off; stale Overview storage POSTs fail before mutation.
- One passive bootstrap classifier defers Settings GET, General Save and report
  generation. Strict Dispatcher route/capability/nonce/action validation remains
  authoritative; classification never authorizes a denied request. Storage and
  explicit diagnostic actions retain their required initialization and returns.
- Installation/report projections never start storage health or recovery.
  Private configuration details remain authorized local-only. Reports use closed
  safe status/reason values and positive Unix timestamps; updater target
  last/next check stay distinct from successful observed operator checks.
- Shared retention, management transactions and provider selection remain
  provider-neutral; code overrides, persistent-cache ordering, multisite
  refusal and exact native admission fences remain intact.

## Pre-edit cost and rejected alternatives

The three input diffs independently measured against main are Settings
production +1908/-307, tests +1608/-38, generated +581/-306, docs +289/-21;
Overview production +370/-53, tests +891/-11, generated +321/-151, docs +46/-0;
Troubleshooting production +1501/-122, tests +2037/-56, generated +371/-287,
docs +165/-1. These sums include overlapping hunks and are an import envelope,
not an expected composed delta. The Overview expiry Dashboard handoff adds
exactly +7/-1 lines. Final unique production/test/generated/documentation
counts must be measured separately before publication.

Composition adds zero new concrete production types, public API names,
options, tables, schema versions, cron jobs or storage backends beyond the
reviewed lanes. Native guard-only support belongs in the shared updater:
projected existing validation logic +4/-3, zero new names/types/state, with
paired lifecycle callbacks still requiring a guard. Core must not install
unpublished dependency bytes or claim the stronger pre-discovery behavior while
still pinned to beta.15. Publication and immutable uptake are separate gates.

Rejected shortcuts: whole-file conflict resolution loses lane behavior;
duplicating storage forms creates competing authorities; triggering new expiry
or recovery scans changes passive behavior; treating global updater timestamps
as successful operator checks fabricates evidence; dummy mutation callbacks,
transport interception and vendor edits evade the actual dependency boundary.

## Proof required

Run complete owning source checks and affected composition tests, including
passive/report refusal, stale Overview storage refusal, Settings payload
privacy/route assets, Overview argument/provider/expiry wiring, site-wide
preference behavior, diagnostic returns and real Check persistence through
CoreSelfUpdateStatus, InstallationFacts and report export. Regenerate catalogue
and any derived assets from final source. Every published source/composition
PR needs independent exact-base/head review. A green source candidate with an
unpublished updater prerequisite remains held from installed acceptance.
