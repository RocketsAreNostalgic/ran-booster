# Core V3 SecretsFile physical-custody gate

Date: 2026-08-11

**Characterized source object:**
`c8671ca75417d9661f0c3cc72a45eb8d136c7e41`

**Documentation carrier before this gate:**
`d6ea89ba7b9bc61ad5314fcd50c1b833bcb04514`

This is a read-only threat model of the physical-custody responsibilities in
`RAN/Secrets/SecretsFile.php`. It changes no production or test PHP, encrypted
format, path policy, option, schema, public error, recovery behavior, installed
WordPress state or remote state.

## Decision

**NO-GO for a physical-custody extraction. Retain `SecretsFile` as the atomic
storage owner.**

There is a visible file-operation cluster, but it is not an independently
deletable responsibility. The database key, encrypted file, lock, canonical
schema validation, authenticated readback and rollback form one fail-closed
state machine. Moving the primitive path, lock and inode helpers would relocate
roughly 333 lines into a new runtime type while leaving the safety-critical
ordering and recovery decisions in `SecretsFile`. Moving the complete state
machine would instead require secret-bearing decode/validate/mutate callbacks
or would move provider/schema policy into a filesystem owner. Neither result is
simpler or safer.

This decision is not a response to the remaining programme line target. The
candidate could reduce the size of one file, but it would not produce a
whole-programme physical contraction or delete a concept. Correct custody
ordering and inspectability are the reasons to retain the current owner.

No implementation child is authorized by this gate. Any future reconsideration
requires new evidence of a second production consumer for the same physical
transaction, a real duplicated owner that the extraction deletes, or a
security defect whose smallest fix requires a separate custody type. It also
requires explicit security approval before production work begins.

## Current ownership and concentration

`SecretsFile` is 2,287 physical lines with 27 public methods including its
constructor, five protected native-operation seams and 61 private methods. It
is referenced by 24 other shipped PHP files, but Core constructs the concrete
object only once in `BoosterServiceProvider`. The other consumers ask for
provider-bound material, display-safe profiles, storage readiness, recovery or
verified deletion; none performs sidecar I/O directly.

The class currently owns three inseparable layers:

1. provider-neutral schema-v2 records, exact provider-policy revalidation and
   temporary/portable credential behavior;
2. authenticated envelope and database-key coupling; and
3. private-path, lock, inode, bounded-read, atomic-replacement, rollback and
   exact-deletion custody.

The clean existing sub-owners remain focused:

- `EncryptedSecretsEnvelopeCodec` owns envelope format 1 and Sodium AEAD;
- `SiteKeyStore` owns the one non-autoloaded database option and exact-value
  deletion;
- `PrivateLocationCandidateResolver` owns configured-location policy;
- `PosixFilesystemProbe` proves the automatic candidate's required local
  filesystem behavior before configuration;
- `SecretsStorageProvisioner` owns read-only status, privileged setup,
  recovery discovery and wp-config retargeting; and
- `SecretsFile` alone combines those facts when authenticated material is
  read, changed, reset or deleted.

No provider, add-on, controller or view receives the codec, key store, path,
lock or a generic secret-bearing callback.

## Encrypted format and canonical document

The physical file is a canonical UTF-8 JSON envelope, terminated by one line
feed, with exactly these ordered fields:

1. `format`: `ran-booster-encrypted-secrets`;
2. `version`: integer `1`;
3. `algorithm`: `xchacha20-poly1305-ietf`;
4. `nonce`: canonical RFC 4648 base64 of the 24-byte nonce; and
5. `ciphertext`: canonical base64 of the authenticated ciphertext and tag.

The fixed additional authenticated data is
`ran-booster-encrypted-secrets:v1`. Every encryption uses a fresh random nonce.
The raw 32-byte key is stored only as canonical base64 in the non-autoloaded
WordPress option `ran_booster_secrets_key_v1`. The envelope and bounded file
are each limited to 1 MiB.

After decryption, the plaintext is canonical JSON plus one line feed. Its root
is schema version 2 with exactly `schema_version`, `credentials` and
`webhooks`. Providers and record IDs are sorted bytewise. Structural
validation and exact re-encoding must match before the document is accepted.
Provider policy is deliberately outside storage locks and is applied only to
the requested or changed record set under the completed canonical-policy
contract.

No extraction may change the envelope field order, version, algorithm, AAD,
nonce behavior, size bound, plaintext schema, canonical ordering, newline,
key encoding or provider-policy timing.

## Canonical path and reachability boundary

The directory constant `RAN_BOOSTER_ENCRYPTED_SECRETS_DIR` wins and appends the
fixed `secrets.json` filename. The legacy
`RAN_BOOSTER_ENCRYPTED_SECRETS_FILE` exact-file constant remains accepted when
the directory constant is absent. Raw values must be absolute and canonical:
no empty value, trailing slash, NUL, CR/LF, doubled separator or dot segment.

For the production default-path construction, every use revalidates the
configured candidate against canonical WordPress, content, plugin, document
root and discovered VCS boundaries. Existing path components may not be links.
World-writable host ancestors are rejected. A group-writable host ancestor is
accepted only when its owner/group is outside the PHP process identity and PHP
cannot write it. The private anchor and descendants may not be group- or
world-writable. The immediate secrets directory must be a real, process-owned,
readable/writable mode-`0700` directory.

An explicitly injected path deliberately skips the ambient WordPress-boundary
check. Production uses that mode only for a recovery candidate after
`SecretsStorageProvisioner` has independently inspected and authorized the
candidate; tests use it for isolated temporary stores. It is not a general
runtime bypass for callers.

`configured_path_unsafe` is a policy verdict, not proof of public
reachability. Conversely, passing the known-root checks does not prove that an
arbitrary web-server alias, container mount or host control-plane mapping
cannot publish the directory. Operator deployment evidence remains necessary
for uncommon layouts.

## File and lock custody

The encrypted file and `secrets.json.lock` are one storage set beside each
other. Before use:

- the lock path is not a symbolic link;
- the opened lock handle and path must name the same device and inode;
- the lock is a regular, single-link, process-owned mode-`0600` file;
- reads take `LOCK_SH`; mutations, reset and deletion take `LOCK_EX`;
- the sidecar is a regular, single-link, process-owned mode-`0600` file;
- the opened sidecar handle and path must name the same device and inode; and
- the exact stat size must be between one byte and the envelope bound and must
  equal the bytes actually read.

PHP does not expose a portable `O_NOFOLLOW` flag for these calls. The design
instead combines link checks, handle/path device and inode equality, single
links and a process-owned mode-`0700` directory. That protects against
accidental replacement and other local users under the supported POSIX model.
It is not isolation from root, a malicious process with the same Unix identity
or another WordPress plugin executing as the same PHP user.

## File, lock and database-key state machine

`F`, `L` and `K` below mean sidecar file, lock file and valid database key.

| F | L | K | Current meaning and allowed outcome |
| -: | -: | -: | --- |
| 0 | 0 | 0 | Pristine configured storage. Reads return the empty schema without creating material. First mutation may initialize all managed material. |
| 0 | 1 | 0 | Lock-only residue. Health is false; verified deletion may remove the exact safe lock. A later first write may reuse it. |
| 0 | 0 | 1 | Key without the managed lock. Fail closed as missing-lock material; recovery may offer exact key reset only after the full state is re-evaluated. |
| 0 | 1 | 1 | Orphaned key with managed lock. Fail as `storage_file_missing`; explicit reset deletes only the unchanged exact key and retains the lock. |
| 1 | 0 | 0 | Unauthenticated ciphertext without its lock. Preserve it and fail closed; automatic reset is not offered. |
| 1 | 0 | 1 | Plausible complete material without its lock. Preserve both and fail as `storage_lock_missing`. |
| 1 | 1 | 0 | Orphaned ciphertext. Fail as `storage_key_missing`; explicit reset may delete only a secure unchanged exact file after sibling recovery has been ruled out. |
| 1 | 1 | 1 | Authenticate the envelope and canonical schema. Wrong key, tamper, invalid structure, unsafe metadata or policy-use drift fails closed. |

An unsafe or linked lock is never treated as harmless residue. A malformed or
autoloaded key is not replaced. A key that changes before exact deletion is
preserved. File and key backups are not independently interchangeable: restore
ciphertext, lock and the matching database key as one set.

## Atomic mutation and rollback

Credential and webhook saves first read a structural snapshot, normalize only
the exact target outside the lock, then acquire the exclusive storage lock.
The mutation is accepted only when the stored target still equals the
prevalidation snapshot. Competing create or replace operations therefore fail
closed rather than overwriting the winner.

Under the exclusive lock, the ordinary write sequence is:

1. load the current key and file-presence state;
2. authenticate and structurally validate the current document;
3. apply and canonically validate the domain mutation;
4. create or elect the database key only when the first write needs one;
5. encrypt with a fresh nonce;
6. create a same-directory temporary file, secure it to `0600`, verify its
   handle/path identity and owner, write all bytes, flush and, when the PHP
   runtime supplies it, `fsync` the file;
7. rename it over the final path;
8. reopen, authenticate, structurally validate and canonically compare the
   replacement; and
9. on readback failure, atomically restore and reauthenticate the prior
   ciphertext, or remove the failed first file. An exact newly created key is
   removed only when no final file remains.

Rename supplies process-level all-old-or-all-new visibility on the required
single local filesystem. The implementation does not `fsync` the containing
directory and the database option cannot participate in the filesystem
transaction. It therefore does not claim power-loss atomicity across the
database and directory entry. A crash can leave one of the explicit incomplete
states above; those states are diagnosed and require restore or confirmed
reset rather than being silently repaired.

Managed deletion likewise remains deliberately ordered and fail-closed:
authenticate and snapshot the exact file, delete that unchanged inode, delete
only the matching key value, then remove the still-matching lock. An
interruption can leave key-and-lock or lock-only residue, but cannot justify
deleting a changed key or unauthenticated ciphertext.

## Recovery and TOCTOU controls

Automatic recovery is limited to the managed `.ran-booster/<16 hex>/` sibling
shape and at most 64 directory entries. A candidate must have safe directory,
file and lock metadata, authenticate under the current database key and pass
current provider credential fitness. Adoption requires exactly one candidate,
an opaque SHA-256 revision over its path and metadata, an automatically owned
wp-config definition and the same local filesystem as wp-config.

The protected POST supplies only that revision token. Adoption recomputes
status, candidates, uniqueness, token, path policy, provider fitness,
configuration ownership and filesystem device before retargeting wp-config,
then requires a fresh WordPress request to trust the new definition. It moves
no ciphertext and changes no key.

Orphan reset similarly separates offer from execution. Execution repeats the
expected-path, file/key state, lock, metadata and exact-value or exact-inode
checks under the exclusive lock. The exact `RESET STORAGE` confirmation cannot
waive a changed state.

The remaining TOCTOU boundary is the documented same-identity threat: a
malicious actor that can run as the PHP user can replace directory entries or
database values between checks and can inspect plaintext in process. The
current inode and exact-value checks make such changes fail closed where they
are observable; they cannot create same-process confidentiality.

## Non-disclosure and failure vocabulary

`SecretsStorageUnavailable` exposes the fixed diagnostic ID
`local_secret_store_unavailable` and a bounded reason. Sidecar code converts
codec, JSON, provider and key-store failures to fixed pathless messages rather
than propagating nested exception text or secret-bearing arguments.

The provisioner maps the closed storage reasons and selected fixed messages to
stable operator codes such as `storage_key_missing`, `storage_file_missing`,
`storage_lock_missing`, `storage_authentication_failed`,
`storage_document_invalid`, `storage_file_unusable` and
`storage_lock_unusable`. Unknown failures become `storage_unavailable`.

Candidate paths, discarded-directory evidence and recovery paths are separate
fields displayed only on the protected Overview. They do not enter redirects,
global notices or log context. Dispatcher failures log fixed event, operation,
outcome and step values, while the result returned to the page is fixed copy.
Saved credential and webhook plaintext is not a presentation field.

An extraction may not broaden exception text, expose a pathname through a
reason/message, replace reason codes with filesystem errors, log secret-bearing
arguments or introduce a generic secret callback.

## Rejected extraction shapes

The apparent primitive cluster consists of 333 current lines across
`hasFile()`, `readBoundedFile()`, configured-path derivation and validation,
locking, inode matching, permission repair, ciphertext replacement, stat-safe
deletion and five protected native-operation seams. Extracting only that
cluster is rejected because:

- `mutate()` still has to couple the exclusive lock, key election, structural
  snapshot, domain mutation, encryption, authenticated readback and exact-key
  cleanup;
- `writeCanonicalFile()` and `restorePreviousCiphertext()` still have to couple
  old ciphertext, codec, schema validation, replace/restore and first-write
  cleanup;
- recovery and uninstall still have to couple path, file, lock and exact
  database-key state;
- the new object would need secret-bearing callbacks, a raw lock handle or a
  widening set of low-level public methods; and
- the moved code plus delegation and wiring would add a 254th runtime type and
  would not delete an owner or durable concept.

Expanding `PosixFilesystemProbe` into the runtime store is also rejected. A
destructive pre-configuration capability probe and live credential custody
have different lifetimes and authority. Folding the codec or key store into a
new file owner would erase two existing, well-tested cryptographic and database
boundaries merely to keep the type counter flat.

Unifying `SecretsStorageProvisioner::inspectReadyPath()` with runtime I/O is
not a safe deletion. The provisioner performs metadata-only diagnosis before
an operator action and deliberately returns privileged corrective detail;
`SecretsFile` must open, lock, authenticate and fail with pathless runtime
errors. Sharing their outcome vocabulary is useful, but sharing authority or
turning observational preflight into proof of later use would create a TOCTOU
bug.

The five protected native-operation methods have no checked-out override.
Inlining them could remove a handful of lines, but would change a protected
surface, remove useful deterministic failure seams and provide no ownership
contraction. It is not a C4 child.

## Verification and residual evidence limits

The focused physical/security suite passes 148 tests and 1,476 assertions on
PHP 8.5. It uses real mode-`0700` temporary directories, mode-`0600` files,
native `flock`, inode replacement and Sodium-encrypted synthetic material. It
proves canonical envelope round trips and tamper rejection, six-process key
election, path/ancestor/link policy, POSIX lock exclusion, structural/provider
lock timing, exact record-race refusal, atomic inode replacement, uninstall
deletion and orphaned key/ciphertext state rechecks. Fixtures remove their
temporary material.

The current suite does not inject short writes, rename failure, post-rename
authenticated-readback failure, failed rollback or a process crash at each
file/key transition. The protected native-operation seams describe that
testing intent but are not currently overridden by checked-out tests. This is
an evidence limit, not authority to refactor the owner. Any later storage
mutation must first add deterministic synthetic coverage for the exact changed
failure paths and must preserve existing encrypted fixtures in both directions.

Directory-entry durability after sudden power loss is also not proved, and the
known-root location policy cannot prove arbitrary host/web-server reachability.
These limits must remain explicit; neither is corrected by moving the same code
to another class.

Full `composer check` reruns characterization 35, 2,076 PHPUnit tests / 12,729
assertions, updater bootstrap smoke, release-uploader checks and PHPCS
successfully. `pnpm check` passes formatting, ESLint, Stylelint and 137 asset
tests. `git diff --check` passes. This gate performs no release build or
activation because it changes documentation only.

## Programme closeout consequence

C4 closes with the custody exception above. The Core C1-C3 programme remains a
410-line physical production contraction at 46,684 shipped / 652 reviewed
passive / 46,032 backend PHP and 253 named shipped runtime types. The visible
390-line remainder to the 800-line programme floor and 430-line remainder to
the original target are unchanged. They are measurements, not authority to
split an atomic secret-storage state machine.
