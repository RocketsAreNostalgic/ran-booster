# Beta.31 naming recovery — 2 October 2026

This is a source-preparation checkpoint for #167. It does not authorize a merge,
package publication, or release #181. Core main remains
`3cc0c44bb19cd8e1fe32e299067900ca3a415e07`.

## Preserved work

The receiver `naming/beta31-recovered` composes the six maximal published
recovery tips: `29baf9f`, `c3eee25`, `60ca3f8`, `b581b5e`, `8bfa60e`, and
`7c42178`. Their file sets are disjoint. The first includes the interaction,
deployment, support and current-fixture chain. `60ca3f8` contains the native
superglobal correction. Original preparation branches remain intact.

Surviving local object stores also yielded internal commit `6970840` and shared
Core/view commit `81c1e0b`. Their changes were recovered, with two test formatting
conflicts resolved in favour of the renamed bindings and current formatting.
The obsolete `quality/167-secret-recovery` was excluded: that work had already
squash-landed as `e771aac`.

The first combined source checkpoint is published at
`36ea3fcee380b0869c8ca8bd83270408f4c6f2d3`, tree
`1f6573308bc86183adbc2c24919fb83b7738ebb5`. Subsequent changes finish the test
receivers, view bindings, API assertions, archive guards, naming enforcement,
native-signature exceptions, current documentation and gettext catalogue.

## Contracts

| Boundary                 | Candidate generation |
| ------------------------ | -------------------- |
| Provider                 | 14                   |
| Add-on                   | 17                   |
| Admin Interaction        | 3                    |
| Prospective Release      | 8                    |
| Portability              | 3, unchanged         |
| Portability hash payload | 2, unchanged         |
| Updater runtime protocol | 5, unchanged         |

Owned methods, parameters, properties and locals use snake_case. Renamed PHP
view variables have matching internal producer and consumer bindings. Persisted
and wire keys, native PHP/WordPress/third-party signatures, superglobals,
credentials, behaviour, types, defaults and visibility are preserved. The
existing 15 magic getter keys remain case-insensitive; renamed backing fields
use exact snake_case fallback names. Tests cover both behaviours and the plugin
and theme header-backed fields. No compatibility aliases were introduced.

The whole owned PHP tree now uses `RANOwnedMethods` and variable-name checks.
504 obsolete production naming-sniff suppressions were removed. Remaining
exceptions name concrete PHPUnit lifecycle signatures, native MySQL/ZipArchive
fields, or deliberately historical rejection and magic-getter fixtures.
Embedded PHP in the archive builder was audited separately from `.php` files.

## Fresh source qualification

The completed source tree before this documentation-only record is local commit
`f21d8a29685c99781cbcc9ff2035393c8c094a2d`, tree
`575486b6db6dc36c3c6521b5c96b6204393ff49d`.

- Canonical `composer check` passed on PHP 8.3.6 with the exact recovered GitHub
  Provider source overlay `08d61d111c9d27119000d7a8a6b4db2d1a97eb4c`: 2,604 unit
  tests / 21,020 assertions, 36 characterization checks, updater bootstrap smoke,
  release-validator 10 valid / 15 invalid cases, i18n/generated state,
  immutable Admin Shell, syntax, PHPCS and blocking PHPStan.
- `pnpm check` passed with Node 24.11.0 and pnpm 11.7.0 after a frozen-lock install.
- Temporary positive/negative probes confirmed method and property naming is
  enforced in RAN, views, scripts and tests. All probe files were removed.
- Receiver, callback, named-argument and executable-token review found no
  remaining owned camelCase production declarations/calls or lowercase native
  superglobals. Three `listCandidates` strings remain intentional JavaScript
  contract keys.
- Security tests used a private disposable temp directory; no private-path
  validation was weakened to accommodate the execution environment.

Matching satellite preparation is on `naming/beta31-recovered-consumers`:

| Repository      | Published preparation commit               |
| --------------- | ------------------------------------------ |
| GitHub Provider | `73a3e3afb747bbb61ead306d6d6bf10d92d94665` |
| Bitbucket       | `87a2dcd46e7a8a50b56444b0a1b3166a4e681039` |
| Migrator        | `063e9230cfa2794fd4e89139869164c9200f74df` |

Each retains the originally supplied recovery branch as ancestry. Satellite
source suites passed at their recorded host checkpoint: Provider 432 tests /
3,354 assertions, Bitbucket 202 / 2,371, Migrator 128 / 1,462. Their PRs retain
exact current qualification and host-pin evidence; later heads supersede this
preparation table.

## Remaining gates and landing order

GitHub Provider source #53 and release #54 are merged. The immutable
`v1.0.0-beta.12` release (ID `401815218`) and its tag both target
`c90777b7a23b7e07244a94c7ccbf3c4faf4fdc2c`. Core now adopts that exact
Provider14 package in `composer.json` and `composer.lock`; other dependency
records are unchanged. The earlier beta.11 lock and source-overlay results
above are historical preparation evidence, not the current dependency state.

Fresh released-lock checks and clean archive qualification passed on the adoption
checkpoint. Its installed release-capability proof exposed a stale fixture call
to `PreparedArtifact::regularFileIdentity`; the corrected fixture uses
`regular_file_identity`, with a regression exercising actual artifact transfer,
Core cleanup and repeat-handoff rejection. Final native and installed qualification
must complete on the corrected head; earlier results do not establish that pass.

1. Complete final canonical checks, clean no-dev archive/readback, required native
   CI and installed WordPress/database/load-order proofs on the corrected tuple.
2. Obtain the Core source merge decision only after its exact-head gates and
   independent review pass. Source merge does not publish Core.
3. Let the existing release pipeline refresh #181, qualify its exact proposal,
   and obtain the owner's release decision before publishing Core beta.31.
4. Refresh Bitbucket/Migrator against the actual published Core release and run
   their existing release-backed host/certification gates. Their held releases
   and Migrator’s separate manual acceptance gate are not waived by this source work.

Implementation and source-composition qualification are distinct from merged,
package-published, released-host-certified and installed-site acceptance states.
