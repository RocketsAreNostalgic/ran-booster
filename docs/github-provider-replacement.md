# Choose GitHub support

**Release status:** This guide describes the implemented upcoming-release
candidate. Published Core beta.36 has the older bundled-only behavior described
in the [released-baseline reference](provider-registration-and-coexistence.md).
Use these steps only with the selected candidate, or a later release whose notes
confirm inclusion. No production replacement plugin is supplied by this guide.

## Before changing provider

Use a trusted replacement plugin that explicitly supports Booster's GitHub.com
replacement contract and the Core version you are running. An ordinary GitHub
plugin, a provider registered under another code, or a development test fixture
is not an eligible production replacement. Booster accepts one implementation
for the exact GitHub provider code; it does not combine their capabilities.

You need administrator access to manage Booster and activate plugins. Back up
the site, including the matching credential sidecar and database key. Check the
replacement's supported credential formats and features, particularly branch
archives, published releases and webhooks. Missing features stay unavailable.
Plugin names and compatibility declarations do not verify the publisher.

## Review and adopt

1. In WordPress **Plugins**, activate only the replacement you intend to use.
   Enabling a candidate makes it discoverable; it does not approve selection or
   grant supported access to retained credentials. GitHub operations pause while
   the choice needs approval. Local records remain available; Bitbucket is
   independent.
2. Open Booster's **GitHub** page and expand **Review GitHub provider**. Review
   the plugin attribution, affected Branch and Published release package counts,
   compatible/incompatible API credential counts, and webhook signing notice.
3. Confirm **I approve this implementation and future normal credential setup
   within its declared compatible formats.** Where compatible existing API
   credentials exist, also confirm **Allow this implementation to read and use
   all compatible existing GitHub API credentials, including any constant
   overlay.** This is all compatible retained material, not a per-profile picker.
   Declining reuse cancels adoption. If none can be reused, acknowledge that
   compatible credentials must be configured afterward.
4. Choose **Adopt GitHub implementation**. On the refreshed page, confirm
   **Provided by …** identifies the intended plugin. **Manage GitHub provider**
   contains switching guidance; **GitHub provider diagnostics** links to the
   current installation details.

Adoption leaves incompatible profiles stored and unchanged but unavailable.
Packages using them remain paused; Booster does not substitute another
credential. Configure a supported credential through **Add credential**, select
it for the affected package, deliberately change a site-configured constant
where applicable, or return to a compatible implementation. Switching does not
convert credential formats or move provider-side webhooks. The review explicitly
states whether approval permits reuse of compatible existing webhook signing
secrets; otherwise those secrets stay stored and verification is unavailable.
Review repository webhook setup separately.

This approval concerns replacement access to retained GitHub material. It is
separate from the [Transporter credential import choice](operator-journeys.md#open-review-and-apply-on-the-destination).
Neither choice authenticates a third-party publisher or isolates secrets from
other active PHP plugins.

## Read the paused states

| Message or state | What to do |
| --- | --- |
| **Multiple GitHub candidates are active** | Deactivate extras, leaving only the intended candidate, then review again. Booster does not elect a winner. |
| **An active GitHub candidate declares an incompatible contract** | Use a compatible version or deactivate it before reviewing another choice. |
| **The selected GitHub plugin is unavailable** | Restore the approved compatible plugin, or deactivate every replacement and explicitly review a return to bundled GitHub. |
| **Explicit approval is required** | Read the current review and make the consent choices above. |
| **Selection storage is unavailable** | Restore storage before continuing; do not edit selection records to force a choice. |
| **An interrupted native installation requires protected recovery** | Follow the recovery procedure below before switching. |

A refusal that the adoption review or credential reuse consent changed means
the old form is stale. Reload the GitHub page, inspect the current review and
approve that review only. Do not repeatedly submit an old tab. Active plugins,
compatibility declarations, credentials and selection state can change between
review and confirmation. An occupied updater or unresolved work may also prevent
switching; resolve the actual operation first.

## Switch implementation or return to bundled GitHub

Deactivate the current replacement and activate only the intended replacement.
Return to **GitHub**, review its current consequences and explicitly adopt it.
To return to bundled GitHub, deactivate **all** replacement candidates, expand
**Review return to bundled GitHub**, complete the displayed approvals and choose
**Return to bundled GitHub**. A healthy bundled selection with no candidates
has no adoption panel.

There is no silent bundled fallback when a selected replacement disappears.
Existing managed package records remain; switching is not reinstallation or
automatic re-adoption. Old queued work and native update offers cannot be
assumed valid for the new selection. Check current package state and Activity,
then make fresh checks/reviews before deliberately starting new work. Do not
expect automatic queue replay or an old Blueprint/Migrator review to remain
authorized after a switch.

## Recover an interrupted native installation

First inspect the exact plugin/theme and WordPress update result. A lost response
or partial installation does not mean nothing changed. Keep backups and the
protected state; never delete locks or recovery markers to force a retry.

When the GitHub page offers **Recover an interrupted native installation**, its
supported action is removal verification, not repair. Confirm the interrupted
process has stopped. Arrange removal of the **entire exact target** plugin or
theme identified there, preserving a backup of anything needed; removing only
its main file is insufficient. Confirm both displayed statements, then choose
**Verify removal and clear installation block**. Booster verifies absence and
disconnects that management record; the button does not delete files. An occupied
updater lock prevents recovery and is not cleared merely because it is old.
Temporary-file cleanup remains unverified.

After successful recovery, make a fresh installation/review. If the target
cannot safely be removed, or the process/lock state is uncertain, stop and use
[support](../SUPPORT.md). For branch attempt recovery and damaged management
settings, use the separate [recovery steps](operator-journeys.md#handle-partial-or-uncertain-results).

Provider authors and reviewers can consult the
[provider extension contract](provider-extension-contract.md#github-replacement-presentation)
for the technical boundary.
