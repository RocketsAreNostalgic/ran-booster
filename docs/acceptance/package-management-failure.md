# Package-management failure: retained-site acceptance

This procedure is for the site's exclusive local acceptance owner. It verifies
actual WordPress admin startup and package-specific failure pages while preserving
the owner's existing packages, history, settings and files. It does not authorize
installation, fixture mutation, management removal or a release by itself.

Do **not** run `adoption-recovery-smoke.sh`, `adoption-recovery-smoke.php` or their
race helpers against a retained site. Those CI proofs create, activate, mutate,
unlink and delete their own fixtures and can change the current theme. Their
disposable-site opt-in is not a substitute for this procedure.

## Prerequisites and custody

1. Obtain the integration owner's current explicit runtime admission: exact Core
   head/tree, completed successful run/attempt, archive identity and independent
   review. Verify the installed file manifest and generated marker match that
   packet. Historical beta version text alone is insufficient. Do not patch the
   installed source to make a scenario work.
2. Verify the real, non-symlinked site root, `ABSPATH`, `WP_CONTENT_DIR`, plugin and
   theme roots, exact home/site URLs and the owner's disposable-site marker.
   Operate from that intended root only. Ben's `localhost:10013` is his local
   environment, not another controller's cloud localhost.
3. Take and verify fresh private database/files recovery snapshots. Capture the
   current genuine management, deployment-journal and Activity rows; product
   options/schema/retention/private-storage fingerprints; activation/current-theme
   state; and pre-existing file hashes. Preserve current values rather than
   resetting counts to a historical checkpoint. Keep private manifests, secrets,
   cookies and raw database records out of public issues/artifacts.
4. Use the owner's existing authenticated administrator browser. Do not fabricate
   authentication cookies, create a new user or expose session tokens for this
   proof. A Scenarios helper may be used only after its exact pairing is qualified;
   old helper metadata must not be repinned or bypassed.
5. Damaged-state work additionally requires the owner's already reviewed fixture
   manifest and full-row compare-and-swap fault/restoration runner. Review that
   exact runner against the contract below before execution. If it is unavailable,
   stop at healthy checks; this document is not permission to invent an ad-hoc SQL
   repair script or leave a persistent fault for later inspection.

## Healthy requests first

Choose one exact retained, inert, fixture-owned managed plugin or theme. Its
manifest must identify the installed target, full management-row preimage,
adoption lifetime, valid source revision, installed file inventory and package
data. Do not infer a theme identifier from a plugin fixture's naming convention.

For example, `acceptance471-20261020-e08-plugin-review/entry.php` was a prepared
plugin target; its name does not establish current ownership or runtime identity.
Re-verify it against the current local manifest before using it.

Using the existing authenticated browser:

- Open `admin.php?page=ran-booster-plugins&package=EXACT_PLUGIN_MAIN_FILE` or the
  corresponding `ran-booster-themes` route with its exact stylesheet identifier.
  Check that the ordinary settings page loads. An unavailable provider should
  retain a readable unavailable-provider state, rather than claim corrupt files.
- Open the same target through `recovery=1&recovery_target=EXACT_IDENTIFIER`.
  A healthy target should offer **Open package settings**, with no emergency
  removal confirmation, generic installed-package picker or premature re-link.
- Open the recovery route without a target. It must not suggest choosing an
  unrelated installed package to disconnect.
- Observe a genuine authenticated WordPress Heartbeat request to
  `/wp-admin/admin-ajax.php` in the browser's network tools. Record the actual
  successful response and absence of a fatal error. Do not replay a fabricated
  nonce or treat a login-page response as authenticated AJAX evidence.

Actual admin-page and admin-ajax requests execute WordPress `admin_init`; direct
PHP template rendering alone does not establish this lifecycle proof. Optional
CLI callback checks must use the same actual site/current admitted source and
authorized administrator, and must not replace these HTTP requests. Normal
WordPress session/observation writes are distinct from package-management changes;
record them honestly instead of claiming the entire database is byte-identical.

## One bounded damaged-settings unit

The fixture runner must implement all of the following as one bounded unit:

1. Refuse a genuine/shared target, symlinked/unexpected path, absent marker,
   unverified runtime, changed fixture files, duplicate row, changed lifetime or
   any unexpected full-row preimage. Refuse queued, running or unresolved work
   for the target. Capture the complete original row and exact file/data state
   privately before writing anything.
2. Change **only** that fixture row's valid `source_revision` from `1` to `0`,
   using a prepared full-row compare-and-swap. Match every captured field,
   including nulls and exact strings, not just package name or row ID. Require
   exactly one affected row and verify the complete expected fault postimage.
3. Establish restoration in a `finally`/exit path **before** the fault write.
   Keep a short owner-defined deadline and restore on success, assertion failure,
   exception, timeout or cancellation. Abrupt process/machine loss can prevent
   cleanup callbacks: retain an independently invocable, reviewed exact-postimage
   restoration path and the private manifest before starting. If interrupted,
   verify the site/row guards again and restore before any further scenario.
   Do not leave the fault active while waiting for another controller.
4. While that exact fault is present, open the ordinary exact-package URL. It
   should identify that target, explain the invalid saved update revision and
   show only its eligible **Stop managing** confirmation. Check the exact bound
   target, current confirmation and nonce; do not submit removal for screen
   preparation. No generic picker or **Link this installed package** action
   should be offered.
5. Open the exact-target recovery URL and observe genuine authenticated Heartbeat
   again. Both actual startup routes must remain available with the faulty row.
   Record status/content, desktop/narrow/zoom/keyboard observations and sanitized
   errors. A screenshot alone does not prove HTTP, authentication or mutation
   safety. A recovered page must not silently repair the saved revision.
6. Restore **only** `source_revision` from `0` to the captured `1`, conditioned on
   the complete expected fault postimage. Verify the entire row equals its
   original preimage. If the row is already exactly original, verify that fact;
   if any other value changed, refuse to overwrite it, contain the fixture and
   coordinate immediately. Do not force a stale preimage or restore the whole
   database over concurrent legitimate work.
7. Verify the ordinary settings page loads again. Compare all genuine row,
   product-option, private-file, activation/theme and pre-existing file
   fingerprints with the captured baseline. Report any difference; do not wipe
   history, normalize it away or call a changed baseline a pass.

Execute plugin and theme units separately, with restoration/readback complete
between them. Missing identity, invalid ownership, unhealthy database or blocked
work requires a truthful refusal state and no enabled removal promise; do not
produce these cases by corrupting genuine records or shared database structure.

## Evidence and remaining acceptance

Record the exact installed tuple and fixture identity, executed request/callback
paths, expected versus actual state, restoration/readback, preservation hashes and
passes/failures. Keep unexecuted cases explicit. This read-only screen/lifecycle
unit does not certify HTTP removal POST authorization, stale-form refusal,
files-retained removal/re-adoption, provider credentials, Migrator or arbitrary
data compatibility. Those need their separately authorized guarded proofs.

Leave the site on its verified healthy baseline. The local owner retains all
installation, browser, fixture, cleanup and physical acceptance custody; the
integration owner retains final combined qualification/admission. No source
controller may clear those holds using CI or template-rendering results alone.
