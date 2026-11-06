# Booster Settings

Open **Booster → Settings** after Extensions. It remains available with skipped
onboarding or unavailable credential storage. It is not a horizontal shell tab.

General groups Appearance, Booster updates, History and Download limits. With
JavaScript, each switch saves only its own preference automatically. History and
Download limits retain explicit **Save changes**. One bottom action area keeps a
persistent pending indicator beside Save; toggle confirmations do not clear it.
The indicator clears after confirmed numeric persistence or reversion to the
confirmed baseline. It does not time out or dismiss edits.

The two General switches use the native checkbox controls and their existing
labels, help and validation messages. Adoption of WordPress `ToggleControl` is
deferred pending an owner decision on its appearance.

Without JavaScript or HTMX, use **Save setting** beside each switch and **Save
changes** for numeric settings. Check for updates and storage actions remain
separate forms. Code-locked controls retain their configuration explanation;
Save is unavailable when its entire group is locked.

General requests run one at a time in the current document. Rapid switch changes
retain the latest intended value, while an explicit numeric save waits with its
submitted fields held. An unsuccessful or unconfirmed operation stops queued
operations, restores editable numeric controls and requires an explicit retry.
A lost response never proves rollback. Its retry retains the original fence;
a stale result shows current saved values and needs deliberate review before a
new attempt. A post-commit concurrent change likewise requires review rather
than automatically overwriting that administrator's value. Confirmed current
state does not identify which uncertain request committed.

The existing nonautoloaded General option contains three fixed scope revisions.
Every accepted save advances the affected revision, including an unchanged
request. Legacy full-form submissions advance all three; confirmed management
clearing a saved GitHub-hide request advances that scope. Scope tokens preserve
raw value presence/type. The existing management and option locks, strict input
validation, full-document SQL compare-and-set and unrelated option keys remain.
Reads do not initialize revisions; malformed or exhausted revisions fail closed.
Older running Booster code preserves unknown metadata but cannot advance these
fences. Mixed-version concurrent writes, including old-runtime ABA, are not
qualified; deploy after old requests have drained and retain rollback material.
No deployment lock or automatic migration is introduced here.

A failed submission keeps safe editable attempted values and current saved
values beside its error. A committed preference with failed update-offer cache
cleanup retains that durable warning; it is not described as rolled back.
Confirmed saves use the public WordPress Snackbar near the bottom of the current
viewport without moving the form. It follows WordPress's reduced-motion setting.
Pending
numeric edits and durable errors have separate presentation. Native leave
protection and enhanced navigation confirmation protect unfinished or uncertain
changes. Restored history is marked unconfirmed and requires review/retry with
the retained fence; cached markup is not persistence evidence. Saving does not
prune history, validate credentials or install.

A defined code constant overrides the saved preference and locks its field,
even when false or invalid. Removing the constant restores the dormant saved
value/default. Settings names the constant without assuming its source file.
Invalid history code retains per-field defaults; an invalid download constant refuses
repository archive admission. Existing queued operations keep their admitted archive limit.
The compressed repository ZIP control does not govern installed size or History retention.
It accepts MiB and rounds a decimal value to bytes. Existing
exact byte limits round trip unchanged. Four times that limit is the derived
branch ceiling, while hosting, ZIP safety and the release updater's independent
expansion limit may be stricter. GitHub release inspection, installation and
managed updates also receive the compressed limit. Extension and Blueprint archive limits remain
independent.

Hide GitHub UI requires another locally admitted provider, no managed GitHub
packages (including Disabled/release/branch records), no active custom replacement
and no recovery/unknown condition. It hides ordinary navigation/setup suggestions;
GitHub deep links, credentials, package choices/import and operations remain.
Confirmed management clears an existing saved hide request, even under a code
override; it never creates a preference for a constant-only installation.
Removing the last package restores eligibility, not a prior saved hide request.
A code true request may take effect again when eligibility returns.

Secure credential storage is the sole setup/adoption/reset/manual-instruction
home. Operations keep their existing stronger capabilities, purpose nonces,
fresh backend rechecks, matching file/key custody and typed reset safeguards.
General Save cannot operate on storage. Failures stay in the protected Settings
response; fresh verification redirects return to the same section. Old Overview
GET anchors hand off safely; stale operation forms must be resubmitted from
Settings. A Blueprint is not a complete storage backup.

Storage status may authenticate encrypted material under its lock, once per
request; bounded recovery inspection is only for authorized Settings operations.
Overview gets bounded facts with a link. Installation gets configured facts or
an explicit not-checked state without starting authentication/recovery. Opening
Settings defers schema upgrades and sidecar hardening. Existing security expiry
and expired-capture housekeeping still apply; this is not filesystem immutability.

General/check options are nonsecret, nonautoloaded and removed by verified
uninstall, retained on deactivation, and excluded from Blueprint/Briefcase export.
Multisite support and provider/add-on Settings tabs remain outside this feature.

This beta rename does not migrate the old `max_archive_bytes` saved preference or
recognize `RAN_BOOSTER_MAX_ARCHIVE_BYTES`. Before updating, record any custom
value, replace the constant with `RAN_BOOSTER_MAX_DOWNLOAD_BYTES` or re-enter
the value in Settings. The old saved key remains inert; existing queued branch
operations retain their admitted byte snapshots. Newly admitted work uses the
new constant, saved value, or 50 MiB default, in that order.
