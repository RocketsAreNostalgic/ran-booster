# Transporter and WP Pusher operator walkthrough

**Release status:** These instructions describe the implemented upcoming Core
release candidate, including its selected-commit and provider-review safeguards.
They are not a claim that every step is available in published beta.36. Use the
release notes for your installed version. The WP Pusher bridge additionally needs
a compatible, separately certified Migrator release; see its status below.

Transporter transfers selected Booster management configuration and, only when
chosen, eligible repository credentials. It does not copy installed package files,
WordPress content, database contents, deployment history, webhook secrets or
provider-side webhooks. Keep a normal database and filesystem backup. This is a
selective reconstruction journey, not a complete backup or exact-code snapshot.
The [Blueprint reference](portability-briefcase.md) retains format and policy detail.

## Create a Blueprint on the source

1. Check the source and destination satisfy Booster's
   [requirements](../README.md#requirements-and-support), including single-site
   WordPress. On the source, open Booster → **Transporter** → **Create a Blueprint**.
2. In **Blueprint contents**, select the managed plugins/themes to include.
   Review the package list even when all packages begin selected. Unselected
   packages do not travel. This exports their management configuration and readable
   installed requirement observations, not verified installed bytes or an inferred
   exact commit/release selection.
3. Under **Repository credentials**, explicitly select only the eligible saved
   profiles you want copied. They begin unchecked. Associated file-stored profiles
   can be eligible; constants, profiles scheduled for automatic removal and
   profiles unused by managed packages are shown unavailable with a reason.
   Provider permissions have not been assessed. Choosing packages alone never
   silently selects their credentials.
4. If credentials are selected, use **Generate password** or supply a high-entropy
   **Transporter Blueprint password** of 20–256 characters. Keep it separately
   from the ZIP; the supported encrypted briefcase requires AES-256 ZIP support.
   With no credentials selected, the download is a package-only, **unencrypted**
   Blueprint and a password has no effect. Treat repository configuration as
   potentially sensitive even without credentials.
5. Check the package/credential summary and choose **Download Transporter
   Blueprint**. Keep the ZIP securely. If the selection has become stale or
   ineligible, reload and choose again; a rejected credential export does not
   silently downgrade to package-only.

Credentials are copied, not revoked or rotated. The source encryption key does
not travel. Source-local automatic-removal dates are not reconstructed on the
destination; manage the target copy's lifecycle separately. If requirement
observations were omitted because headers were unreadable or invalid, do not
treat their absence as compatibility evidence.

## Open, review and apply on the destination

1. Install/activate compatible providers and prepare destination credentials when
   needed. If changing GitHub implementation, complete the separate
   [provider adoption](github-provider-replacement.md) first.
2. Open **Transporter** → **Open a Blueprint**, select **Transporter Blueprint
   ZIP**, and enter **ZIP password (optional)** when the archive is encrypted.
   Choose **Review Transporter Blueprint**. Preview does not save transferred
   credentials or apply package changes.
3. For each carried credential, choose **Import this credential**, **Use a saved
   credential** (then **Choose a saved credential**), or **Leave unchanged**.
   There is no default or fallback among these choices. Leaving it unchanged
   leaves affected packages unchanged. For a package-only row requiring access,
   choose its **Saved credential**. Missing or invalid credentials block the row;
   public access does not override an explicit credential choice.
4. Read each **Target state**, requirements, **Requested initial selection** when
   present, and **Configuration differences**. The proposed requirement summary
   covers all proposed packages before checkbox selection. Missing dependencies
   concern activation; they are not installed automatically.
5. Review the checked **Apply plan** rows and uncheck changes you do not want.
   Eligible actions begin selected. Choose **Apply selected changes** only after
   checking the current review and credential choices. Apply revalidates current
   authorization, source, destination, repository identity and provider selection;
   preview is not a reservation or permission to bypass fresh checks.
6. Read every package result and its separate credential result, then inspect
   Booster's current managed package and WordPress's installed package state.
   Verify deployment is **Disabled** before deliberately choosing a later mode.
   A newly installed package remains inactive; adoption of existing files does
   not replace them or change their existing activation state. Check dependencies
   and package behavior before separately activating it.

| Target state/control | Operator meaning |
| --- | --- |
| **Ready to install** / **Install package** | An eligible absent, unmanaged target can be installed. |
| **Available to adopt** / **Adopt with Booster** | Eligible existing files can be brought under management without replacement; deployment starts Disabled. |
| **Already managed** / **No change** | No package operation is proposed. **Import credential only** may be offered for explicit credential recovery; it does not change the package's saved credential selection. |
| **Protected** / **Leave unchanged** | Existing management is different, stale or unreadable. Do not overwrite it through this import. |
| **Blocked** / **Cannot apply** | Read the reason and resolve access, provider, destination or unsupported-operation problems before a fresh review. |

**Local configuration matches this Blueprint** compares management settings,
not installed artifacts. The page explicitly says **Whether the installed code
matches is unknown**. The same version label does not prove identical bytes.
**Not checked**, **Observation not supplied**, **No minimum declared** and an
**Unknown** declared floor are different from verified compatibility. Source
installed headers may describe a different package from the one later acquired.
Even **Declared minimum met** is not a full runtime compatibility test.

### Initial selected commit versus adoption

If a valid Blueprint supplies an exact Branch commit, inspect **Requested initial
selection** and the exact target identity. This candidate supports initial
installation only into an absent, unmanaged target with the required Branch
admitted API 3 and provider archive API 2 capabilities. It freshly acquires and
verifies the selected commit and plugin main-file/theme identity. Unsupported
capabilities or selections refuse; they do not fall back to branch head.

Normal dashboard export does not manufacture this exact selection from installed
files. Repository-access review does not prove the historical archive is still
available. A supplied selection is an initial choice, not a permanent update
lock: later operations follow the saved tracking branch and deployment policy,
with their normal checks. This is not permission for arbitrary rollback.

An exact selection targeting an already installed or managed package is not the
ordinary unpinned adoption path. Selected existing-target execution,
release-source Blueprint execution, verified exact-export evidence and explicit
configuration reconciliation remain unfinished. A valid record may therefore
show **This Blueprint record is valid, but its source or exact selection is not
supported for application yet.** Do not remove its selection to bypass refusal.
Abilities and whole-site V3 are also outside this delivery.

### Handle partial or uncertain results

Preview may return useful completed rows alongside **Not checked: this credential
scope was rate limited during this preview. Review again later.** or **Not checked:
this preview reached its time budget. Review again before applying.** Those rows
were not verified and cannot be selected. Wait for rate-limit recovery or fix
provider/network/credential availability, then review again. A repository-specific
access failure is not proof that every other repository failed.

If the page says **The selected repository provider changed. Review again before
applying.**, complete any provider selection first, then choose **Review Transporter
Blueprint** and make choices against the new review. Do the same after a review
refresh error; do not rely on choices in a stale browser tab.

Apply processes selected rows independently; the batch is not atomic. A successful
credential import can remain stored even if package work later fails. Read the
individual results, not just the last notice. After **Package apply failed. Review
the blueprint again.**, a timeout, or a lost response:

1. Check authoritative current package management, WordPress installed files and
   activation, and the matching Activity/deployment result/support reference.
   Do not assume the failed request made no change or rerun the whole batch.
2. If an attempt is running or needs attention, confirm the process has stopped
   and inspect package and maintenance state before using the protected
   **Reconcile stopped worker**, then the applicable **Allow retry** control
   only after checking the package's current state, as described in
   [deployment recovery](package-update-orchestration.md#state-failure-and-retry-rules).
   Never delete updater locks or markers to make a retry proceed. A protected
   native installation has its own [removal-verification procedure](github-provider-replacement.md#recover-an-interrupted-native-installation).
3. Resolve a definite failure, then build a fresh preview and deliberately select
   only eligible remaining actions. There is no general idempotence or automatic
   deployment retry promise. If management settings are damaged, follow
   [management-removal support steps](package-update-orchestration.md#package-management-failure-and-safe-removal);
   **Stop managing** disconnects an eligible record, preserves installed files,
   and does not repair them. Unresolved operations can still refuse removal.

## Migrate retained WP Pusher packages

**Separate release prerequisite:** The maintained
[Migrator instructions](https://github.com/RocketsAreNostalgic/ran-booster-wp-pusher-migrator/blob/main/README.md)
describe its API 4 source candidate. Published Migrator beta.10 uses API 3 and is
incompatible with this Core API 4 candidate. Candidate test success does not make
that published ZIP compatible. Wait for an explicitly compatible immutable
Migrator release, with its released-host certification and manual gates complete,
before using this as a released-product journey. A Core update alone does not
complete that prerequisite.

Once a compatible pair is available:

1. Back up the site and deactivate WP Pusher, keeping its retained records.
   Use supported single-site WordPress/PHP and the required Core API markers;
   install/activate the compatible Bitbucket Cloud add-on for Bitbucket rows.
2. Configure replacement repository credentials in Booster for private
   repositories. The bridge does not import WP Pusher credentials. Public
   repositories can use supported public access. Complete GitHub replacement
   consent separately if relevant.
3. Open **Transporter** → **Migrate from WP Pusher**. Check/review one retained
   package at a time. Apply only when the installed package and stable repository
   identity match the fresh review. A changed provider choice requires review
   again. The bridge adopts existing files with deployment **Disabled**; it does
   not download replacement package files.
4. Confirm the adopted Booster target. Source-row cleanup requires Core's verified
   target and the exact unchanged retained source row; it is not deletion of the
   whole source table. If cleanup is refused, inspect the current source and
   target before reviewing again. Already-managed rows still need the bridge's
   verified cleanup path, not a manual bulk SQL deletion.
5. **Cannot adopt** rows, including GitLab, remain for manual migration. Resolve
   or deliberately retain those packages before deleting WP Pusher. Do not take
   a partial migration as permission to discard remaining records.
6. When finished, delete WP Pusher through WordPress yourself, check its dashboard
   activation, and review/remove provider-side deployment webhooks separately.
   Its own uninstaller owns its local cleanup and attempts license deactivation.
   Verify packages, then remove the temporary Migrator. Neither Core adoption nor
   the bridge performs those uninstall/webhook steps for you.

Eligibility follows the validated retained ten-column schema and row semantics,
not an exact WP Pusher version string. Database version `1.0` alone proves
nothing. The pinned official 3.0.18 fixture was inspected; that is distinct from
schema eligibility, testing against candidate Core source, and certification
against an immutable released Core host. None certifies future WP Pusher releases.
Use the maintained Migrator guide for installation checksums and current
compatibility details; no candidate tag or future release link is implied here.
