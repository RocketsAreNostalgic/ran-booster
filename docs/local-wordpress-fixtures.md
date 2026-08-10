# Local RAN Booster fixtures

This inventory records the intended role of each Local WordPress installation used by the RAN Booster suite. It exists so that a fixture is kept because it has a deliberate test role, not merely because it already exists.

## Retention decision

| Local site            | Local ID    | Runtime                                         | Decision | Deliberate role                                                                                                                                                             |
| --------------------- | ----------- | ----------------------------------------------- | -------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| PNS Staging           | `U8ZgIGM0m` | `localhost:3001` (Local HTTP `10008`)           | Keep     | Primary working and proofing site. It is not disposable.                                                                                                                    |
| multi-site-test       | `zkGSGuz3w` | HTTP `10018`, MySQL `10019`, PHP 8.3, MySQL 8.4 | Keep     | The only subdirectory Multisite topology: main site plus `/site-two/`. Retain for the parked Multisite work (`ncrg7ayo`, `ig9e53r7`) and quarantine/uninstall verification. |
| booster-test-no-links | `Fk524o6w1` | HTTP `10028`, MySQL `10029`, PHP 8.2, MySQL 8.4 | Keep     | Canonical isolated, disposable destructive-test fixture. Its content is physical rather than symlinked and it carries `.ran-booster-disposable-test-site`.                  |
| boostertest2          | `ERgTD3KE3` | HTTP `10023`, MySQL `10024`, PHP 8.2, MySQL 8.4 | Retire   | Ad hoc WP Pusher migration proof. Its useful setup is reproducible from committed source and the retained WP Pusher archive.                                                |
| booster-test          | `PEyYAOJRj` | HTTP `10014`, MySQL `10013`, PHP 8.4, MySQL 8.0 | Retire   | Obsolete stress/Assisted Hooks proof with no retained attempts or audit evidence and a dangling stress-fixture link.                                                        |

The two sites marked **Retire** were evaluated as having no unique implementation work. Their package code is either linked from the maintained PNS plugin repositories or superseded by current Core/add-on work. Once they have been removed through Local, do not recreate either name without assigning it a distinct role here.

## Safety boundaries

- Never delete PNS Staging as part of fixture cleanup.
- Treat `booster-test-no-links` as disposable, but verify its marker, physical plugin paths, and distinct database socket immediately before destructive package tests.
- `multi-site-test` currently links Assisted Hooks and Bitbucket into PNS-owned source. Removing the Local site must never follow or delete those links.
- Do not archive Local database dumps, `wp-config.php`, Booster private sidecars, provider credentials, tokens, or raw private-storage bundles in Git.
- Use Local's site-removal flow rather than deleting installation folders by hand so its registry and services remain consistent.

## Rebuilding the retired WP Pusher proof

The `boostertest2` installation does not need to be kept as a frozen database after retirement. Rebuild it on a new isolated Local site when migration behavior must be tested:

1. Install WP Pusher from `app/public/wp-content/plugins/wppusher.zip` in PNS Staging. The audited archive SHA-256 is `4f1533b9b946afdf9d699ea54279ea236b7e25f3d3fc9182bb53cec295a52208` and corresponds to WP Pusher 3.0.13.
2. Install the current RAN Booster Core and WP Pusher Migrator packages from their maintained repositories.
3. Run `app/public/wp-content/plugins/ran-booster-wp-pusher-migrator/scripts/reseed-local-wp-pusher-fixtures.sh` from PNS Staging, targeting only the new disposable site.
4. Verify the current migration contract and fixture identities before testing; do not restore the retired site's database or credential-bearing sidecar.

Dex task `3wa0ocs8` records the original WP Pusher source provenance. The sanitized Multisite state evidence retained beside this document records the reusable proof output without secret-bearing restore material.
