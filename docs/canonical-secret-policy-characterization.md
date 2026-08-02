# Canonical secret-policy characterization

**Status:** Phase-zero evidence began at Core
`51a9e63fe5a414c63ef5ce086739d09e690e49b8`. The bounded internal
implementation is frozen on `codex/p0-canonical-structural-hardening` at
`7ebcfdb63e674e69c358c352e928804941126939`. It is not yet integrated or
release-authorized and makes no API, schema, persistence, provider-account or
remote change.

## Decision

**GO** to a separate, bounded attempt to split schema-v2 structural validation
from provider semantic validation. The implementation must stop and be
abandoned if it cannot meet every budget and invariant below. This is a GO to
attempt the internal hardening, not authorization to merge or release it.

The current behavior gives every active credential or webhook policy plaintext
for every stored record in its provider namespace whenever the authenticated
document is read or canonically rewritten. Calls happen while Core holds the
sidecar lock. This includes display-safe reads, exact material for another
provider, storage verification, health checks, self-destruction, portability,
uninstall preflight and canonical write readback. The behavior is stricter than
structural validation requires and makes unrelated reads depend on current
provider-policy behavior.

The smallest credible replacement keeps schema version 2, the envelope, key,
path, lock, provider collections and public contracts unchanged:

1. Core alone authenticates, bounds, structurally validates and canonicalizes
   the whole document.
2. Exact credential create/replace/import is provider-normalized outside the
   sidecar lock, then committed only if the structurally read record still
   matches the prevalidation snapshot.
3. Exact file-backed credential delivery revalidates that one selected record
   under the current requested provider policy after releasing the read lock.
4. Webhook verification structurally reads once, then revalidates only the
   requested provider's bounded candidate set (maximum 16) outside the lock.
5. A constant overlay invokes only the requested provider policy with only its
   declared constant names, remains runtime-only and is never written.

No generic validator registry, schema kind, owner/pool abstraction, plaintext
cache or compatibility layer is warranted.

## Implementation checkpoint — 2026-08-02

The conditional attempt is a verified **GO** within its frozen bounds:

- authenticated document reads use Core-only structural validators and exact
  canonical equality; provider policy is not invoked by storage, health,
  deletion, file-backed display or atomic readback paths;
- explicit credentials revalidate one non-expired selected stored record;
  default resolution chooses the constant first, otherwise revalidates only one
  structurally unambiguous temporary/file candidate and returns `null` without
  stored callbacks when selection is ambiguous;
- webhook material revalidates only the requested provider's at-most-16
  candidates before signature verification;
- credential and webhook saves snapshot one exact target, normalize outside the
  lock and compare that target strictly inside the exclusive mutation. Creation
  and replacement races fail closed without retrying or overwriting the winner;
- policy drift leaves stored/display data readable but rejects exact material
  use when the current policy rejects or changes the canonical record; and
- credential rows say “Stored · Validity checked on use” unless a more specific
  expiry badge applies.

Actual production delta is +157 net PHP lines, four private helpers and zero new
type, public signature, API marker, hook, schema field, table, option, durable
state or remote call. Tests are +254 net PHP lines; production JavaScript/CSS is
unchanged.

The focused implementation suite passes 23 tests/171 assertions. Full Core
verification passes 1,833 tests/11,099 assertions with the one pre-existing PHP
8.5 deprecation, plus 123 asset tests and PHPCS. Independent review found no
remaining actionable defect.

A 100-iteration local PHP 8.5 synthetic readback recorded representative
storage-lock operation mean/p95/max of 0.135/0.235/0.680 ms, exact credential
0.134/0.254/0.626 ms and two-webhook use 0.196/0.445/0.596 ms. The maximum
16-webhook fixture recorded storage-lock mean/p95/max of
0.179/0.369/0.477 ms and total candidate use 0.516/0.725/1.440 ms, with at most
2 MiB additional process peak allocation. Each read performs one sidecar
decrypt; every observed extension callback was outside the lock.

Synthetic rollback proof created schema-v2 credential/webhook material with the
pre-change `0ad9dba0de5ac26eb26cebd1d916f67e71f7ac84` implementation, read and
rewrote it with `7ebcfdb63e674e69c358c352e928804941126939`, then read the result again
with the pre-change implementation. All three readbacks passed and the
temporary fixture was removed.

## Traced source paths

The canonical path is concentrated in `RAN/Secrets/SecretsFile.php`:

- `fileDocument()` takes a shared lock and calls `readEncryptedDocument()`.
- `readEncryptedDocument()` authenticates/decrypts, parses JSON, calls
  `validateDocument()` and compares the re-encoded canonical plaintext.
- `validateDocument()` calls `validateCanonicalDocument()` before checking
  exact canonical equality.
- `validateCanonicalDocument()` traverses credentials and webhooks for every
  provider. `validateCredential()` and `validateWebhook()` call the active
  provider policy with plaintext. Missing policies use Core's fallback and
  leave records opaque to extension code.
- `mutate()` holds an exclusive lock across initial read, the mutation callback,
  full canonical validation, atomic replacement and authenticated readback.
  A successful write therefore normalizes the full resulting document twice
  after its initial read; exact create/replace adds one target normalization.
- `writeCanonicalFile()` encrypts a canonical JSON document, writes and fsyncs
  a mode-`0600` temporary file, renames it over the sidecar, then authenticates
  and revalidates the replacement. Failed readback restores the previous
  ciphertext or removes a failed first file.
- `credentialProfiles()` calls the bulk `credentialMaterials()` path and filters
  destroyed records only after canonical plaintext policy invocation.
- `credentialMaterial()` uses the same bulk path for file-backed material. An
  explicitly requested constant profile is the exception: it never reads the
  sidecar.
- `webhookProfiles()` calls `webhookMaterials()`. That method resolves the
  requested constant overlay, then reads and revalidates the whole document
  before returning only the requested provider's candidates.
- `verifyAndSecure()`, `hasHealthyManagedStorage()`,
  `assertManagedStorageReady()` and `assertManagedStorageDeletable()` each
  authenticate and revalidate the complete stored document.
- `deleteManagedStorage()` authenticates once in its own deletion preflight and
  once again under its exclusive deletion lock.
- `purgeExpiredCredentials()` reads every record before filtering, then a
  changed document receives prewrite and readback validation.
- `importCredentialsIfAbsent()` provider-normalizes each selected blueprint
  credential before acquiring the lock, then uses the ordinary mutation read,
  prewrite validation and readback path.

Primary consumers are provider-bound credential reads, GitHub provider and
repository browsing, managed release preflight/registration, provider settings,
repository picking, troubleshooting, credential expiry, portability,
Webhook Assistance and `SignedWebhookVerifier`. A structural split must not
move provider semantics into those callers.

## Phase-zero executable call counts and timing

The representative authenticated document contains one credential for each of
`alpha` and `beta`, two `alpha` webhook profiles and one `beta` webhook profile.
The instrumented policies reduce secret observation to booleans; they retain no
plaintext. “Under lock” was observed by a second non-blocking exclusive lock
attempt from inside each callback.

| Operation                                                           | Exact phase-zero baseline policy calls                                               | Timing                                                                                             |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------- |
| Empty `credentialProfiles(alpha)`                                   | `alpha credential constants` 1; no normalization                                     | Outside lock; no sidecar/key/lock created                                                          |
| Empty `webhookProfiles(alpha)`                                      | `alpha webhook constants` 1; no normalization                                        | Outside lock; no sidecar/key/lock created                                                          |
| File-backed credential profiles or exact credential                 | requested credential-constant policy 1; all stored records normalized `1/2/1/1`      | Overlay outside lock; all stored plaintext callbacks under shared lock                             |
| File-backed webhook profiles or candidates                          | requested webhook-constant policy 1; all stored records normalized `1/2/1/1`         | Overlay outside lock; all stored plaintext callbacks under shared lock                             |
| Explicit credential constant                                        | requested credential-constant policy 1 plus requested credential normalization 1     | Both outside lock; zero sidecar decrypt/write                                                      |
| Maximum requested-provider webhook set                              | requested webhook-constant policy 1 plus exactly 16 requested webhook normalizations | Overlay outside lock; 16 stored callbacks under shared lock; collection bound enforced             |
| Readiness, health, permission verification or deletion preflight    | all stored records normalized `1/2/1/1`; no constant callback                        | Under the method's shared or exclusive lock                                                        |
| Replace representative credential                                   | credential/webhook calls `4/6/3/3`                                                   | Initial full read + exact changed record + full prewrite + full readback, all under exclusive lock |
| Replace one of two representative webhooks                          | credential/webhook calls `3/7/3/3`                                                   | Initial full read + exact changed record + full prewrite + full readback, all under exclusive lock |
| First portability import with one existing same-provider credential | target credential normalization 6                                                    | 1 before lock; 1 initial read + 2 prewrite + 2 readback under exclusive lock                       |
| Idempotent portability retry with two stored credentials            | target credential normalization 3                                                    | 1 before lock; 2 initial-read callbacks under exclusive lock; no write/readback                    |
| Display read with one expired and one live credential               | credential normalization 2                                                           | Both under shared lock before expired record is withheld                                           |
| Purge one expired record while one remains                          | credential normalization 4                                                           | 2 initial read + 1 prewrite + 1 readback under exclusive lock                                      |
| Tampered ciphertext                                                 | 0                                                                                    | Authentication fails before policy code                                                            |
| Authenticated malformed collection shape                            | 0                                                                                    | Core shape check fails before policy code                                                          |
| Authenticated, semantically valid but noncanonical top-level order  | all stored records normalized `1/2/1/1`                                              | Under shared lock before canonical-order rejection                                                 |

The focused 13-test characterization currently completes in less than one
tenth of a second on the local PHP 8.5 CLI fixture. That suite duration is not a
production latency claim. The security result is call order and lock timing;
the later implementation must record operation-level decrypt count, peak
memory and lock duration for representative and maximum authenticated
documents before merge.

## Canonical, inactive and drift behavior

- Provider maps and record IDs are sorted bytewise before encryption. Successful
  replacement changes the final inode, retains one link and mode `0600`, and
  decrypted plaintext equals the single canonical JSON encoding.
- Atomic write readback invokes policies again while the exclusive lock is
  held. A policy upgrade can therefore reject an otherwise authenticated old
  record during readback and trigger ciphertext rollback.
- An active upgraded policy that rejects a formerly valid record makes display,
  health, verification and unrelated reads fail closed. The failed read does
  not rewrite the ciphertext.
- If that provider policy is inactive, Core's fallback structural shape retains
  its records. An active provider can still read and rewrite its own records;
  inactive provider credential and webhook subtrees survive byte-for-byte.
- This opaque preservation is necessary but not semantic validation. Display
  must not call such a record provider-verified or healthy.
- Authenticated noncanonical documents can disclose stored plaintext to active
  policies before Core rejects ordering. Authenticated malformed outer shapes
  and unauthenticated/tampered ciphertext fail before callbacks.

## Constant overlays

Credential and webhook overlays are distinct. `declaredConstants()` validates
each policy-owned name and builds a new map containing only those exact names.
The tests include requested-provider, other-provider and undeclared synthetic
values and prove:

- only the requested provider is called;
- it receives exactly its declared names, including absent names with `null`
  values;
- credential overlays take `credentialFromConstants()` plus one normalizing
  call; webhook overlays take `webhookFromConstants()` plus one normalizing
  call;
- exact credential-constant resolution holds no sidecar lock;
- a webhook overlay is resolved before the sidecar read, while stored-record
  normalization remains under the shared lock; and
- sidecar ciphertext is byte-identical before and after overlay reads, and the
  decrypted document contains neither overlay values nor a `constant` record.

## Implementation budget and abandonment rule

| Budget                         | Hard limit for the later schema-v2 attempt                                                                                                                                                                                   |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Production PHP                 | At most 180 net new lines, confined to `SecretsFile` and existing direct wiring; zero production JavaScript/CSS                                                                                                              |
| Production concepts            | Zero new classes/interfaces/DTOs/services/registries; at most four private helper methods                                                                                                                                    |
| Tests                          | At most 800 net new/changed PHP lines beyond this characterization, using synthetic fixtures only                                                                                                                            |
| Documentation                  | At most 200 net lines across the canonical secret-storage/provider contract updates                                                                                                                                          |
| Public seam                    | Zero new or changed public method signatures, API markers, hooks, facades or provider interfaces                                                                                                                             |
| Persistence                    | Zero schema-version, field, table, option, sidecar-meaning or migration changes                                                                                                                                              |
| Remote calls                   | Zero; validation remains local and provider operations keep their existing remote budgets                                                                                                                                    |
| Read/decrypt                   | Display/storage checks: at most one sidecar decrypt. Exact credential: at most one decrypt and one requested-policy normalization. Webhook verification: at most one decrypt and at most 16 requested-policy normalizations. |
| Extension callbacks under lock | Zero after the split, including create/replace, readback, portability, self-destruction, health and deletion paths                                                                                                           |

The implementation must use optimistic exact-record comparison when validation
needs existing secret material: structurally read and snapshot the record,
release the lock, normalize outside the lock, then fail closed if the record
changed before the exclusive commit. It must not trade away atomic replacement
or silently overwrite a concurrent change.

Abandon the split and retain the characterized current behavior if it requires
schema 3, record repair, credential re-entry, a generic two-phase transaction
framework, more than the budgets above, provider callbacks under lock, an
unbounded retry loop, or any webhook-owned runtime edit. Rollback after a
landed attempt is a normal code/test/docs revert: no state or migration exists,
and pre-change schema-v2 fixtures must remain readable by both revisions.

## Required implementation proof

Before the later GO can land, prove all of the following:

- existing authenticated schema-v2 fixtures round-trip without record loss or
  meaning change;
- malformed, oversized, noncanonical and tampered input still fails closed;
- inactive records remain opaque and do not block unrelated providers;
- display, readiness, health, verification and deletion checks invoke no
  extension policy with plaintext;
- exact credential and bounded requested-provider webhook use revalidate under
  the current policy outside the lock, including policy-drift rejection;
- create/replace/import preserve semantics and reject concurrent snapshot
  changes without a retry loop;
- constant overlays preserve the exact requested-name and non-persistence
  contract above;
- canonical ordering, `0600`, single-link, atomic rename, authenticated
  readback and rollback remain unchanged; and
- representative/maximum decrypt, callback, peak-memory and lock-duration
  measurements fit the recorded budgets.

## Residual risks and deferred work

- Credential-bearing provider code remains trusted with plaintext in its own
  namespace at exact use boundaries; same-process WordPress cannot confine a
  malicious provider or plugin.
- WordPress HTTP hooks, direct database/filesystem access and raw request data
  remain outside this internal split.
- An inactive policy preserves opaque records but cannot establish current
  semantic validity.
- Exact revalidation can make a previously valid stored record unusable after a
  provider-policy upgrade. That fail-closed dependency is intentional and must
  be presented as “stored; validity checked on use.”
- Schema 3, generic extension records, owner/pool identity, automatic repair,
  plaintext caches and external secret brokers are deferred and receive no seam
  here.
- `SecretsFile::credentialMaterials()` and ordinary add-on secret exposure are
  owned by the separate secret-boundary lane. Webhook controller, processor,
  route, HMAC ordering and request-cost files are owned by the webhook stream.
  This lane requires no edit to either surface.

## Evidence

Executable evidence lives in
`tests/Secrets/SecretsFileCanonicalPolicyCharacterizationTest.php`. It uses only
synthetic values, records no plaintext and makes no database, browser, provider,
release or Local WordPress mutation.
