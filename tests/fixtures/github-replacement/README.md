# Installed GitHub replacement fixture

Copy this directory to the disposable site's `wp-content/plugins/github-replacement`.
Entries `a.php` and `b.php` are real active WordPress plugins with passive adjacent
manifests. They declare real `gh` candidates at Core's registration action. The valid A/B entries
implement `RepositoryPathInspector` and `ProviderCredentialPolicySupplier`;
credential validation reuses the installed bundled GitHub credential policy.
No provider is constructed by the declaration callback. Core constructs only an
approved selection. No fixture code writes the selection row.

Use separate invocations for every phase:

```sh
php "$WP_CLI_BIN" eval-file tests/WordPress/github-replacement-installed-smoke.php activate-a --user=fixture-admin --path="$RAN_BOOSTER_WORDPRESS_PATH"
php "$WP_CLI_BIN" eval-file tests/WordPress/github-replacement-installed-smoke.php select --user=fixture-admin --path="$RAN_BOOSTER_WORDPRESS_PATH"
```

`setup` verifies inactive fixture entries and resets counters. `activate-a` and
`activate-b` deactivate both entries, then activate the requested entry. `select`
uses current `adoption_review()` and protected `transition_selection()` with
explicit reuse/incompatible-profile confirmation. Read availability only in the
next process. `conflict` activates both; `missing` deactivates all entries. `incompatible` activates the passive API-14
entry, which intentionally has no registration callback. `core-first`
and `replacement-first` reorder the real active plugin list for the next process.
`reset-counters`, `counters` and `status` reset/read instrumentation or read Core's
redacted status. `expect-unavailable` requires unavailable Core and zero counters.
`expect-bundled` requires an available bundled selection, no active candidates,
the actual bundled provider instance and zero replacement counters. It makes no
network request and does not establish live GitHub acceptance.
`exercise` performs one real provider repository lookup; `exercise-temporary`
uses `SecretsFile::with_temporary_credential()` and asserts one actual bound
credential call and one non-null material return, without persisting the secret.

Persistent option `ran_booster_replacement_fixture_counts` contains only integer
keys `a.factory_calls`, `a.provider_calls`, `a.credential_calls`,
`a.credential_material_returns` and the corresponding `b.*` keys. Consumers can
use `Fixture::counters()` after loading the active plugin. Provider calls increment
at method entry, before the context guard; credential calls increment immediately
before Core's bound `credential_material()` call; material returns increment only
when that call actually returns material. This includes temporary credentials
passed by `RepositoryLookupRequest::credential_id`. No credential values or IDs
are recorded. Factory callbacks are counted separately; each approved fresh
request naturally constructs its provider. Reset in a separate process before
measuring a refusal. Counters are sequential request oracles, not atomic race
counters. No remote endpoint exposes instrumentation.

All reads/writes require exact non-symlinked WordPress/content/plugin roots,
`home` and `siteurl` exactly matching the expected origin, and `.ran-booster-disposable-test-site` containing
`RAN Booster disposable test site`. CLI phases also require admin permissions.
The shell runner validates these before copying. It leaves inert fixture files
for consumer proofs. The provider implements archive API 2 with controlled local
ZIP responses for the historical admitted-install proof. The separate
`github-historical-installed-smoke.sh` runner checks selected-commit bytes,
subdirectory relocation, Disabled adoption and refusal paths through the actual
Core and Branch installation pipeline. This fixture does not contact GitHub.

Installed execution requires compatible satellite dependencies and a disposable
WordPress/database host. Static checks do not establish installed acceptance.

## Local sites with an explicit port

The default expected origin remains `http://localhost`. For a disposable site at
`http://localhost:10013`, explicitly set this in its `wp-config.php` before
WordPress loads (so both browser requests and WP-CLI see the same value):

```php
define( 'RAN_BOOSTER_REPLACEMENT_FIXTURE_ORIGIN', 'http://localhost:10013' );
```

Only HTTP `localhost`, with no port or a canonical decimal port from 1 to 65535,
is accepted. Paths, credentials, query strings, fragments, other hosts, HTTPS,
zero and leading-zero ports are refused. Both stored `home` and `siteurl` must
match exactly; this is not automatic origin discovery. All existing exact-root,
marker, symlink, target and administrator checks still apply. Never set this on
a shared development or production site. `expected-origin` is an authenticated
CLI read for paired consumers; historical, Bitbucket and portability proofs use
the same origin. The portability runner also passes that verified origin to the
separately guarded Migrator helper; its other prerequisites remain mandatory.

Use the exact reviewed fixture files for CLI and the copied plugin. Do not patch
the installed fixture locally to bypass its guard. Port-compatible source checks
do not establish browser or installed acceptance: the local acceptance owner
must run the reviewed artifact on the marked site and record the origin, source
head and observed UI states without recording credential secrets.
