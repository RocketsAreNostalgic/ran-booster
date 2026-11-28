# Choose GitHub support

**Release status:** This guide describes GitHub supply from Core
v1.0.0-beta.51. Earlier betas used a review-and-approve selection that this
release removes. Check your installed release before following these steps. No
production replacement plugin is supplied by this guide.

Booster includes a GitHub provider. A plugin built for Booster's GitHub
contract can provide GitHub in its place. Booster uses one GitHub provider at a
time; it does not combine their features.

## Before activating a GitHub plugin

Use a trusted plugin that states support for the Core version you run. Plugin
names do not verify the publisher, and Booster does not isolate secrets from
other active PHP plugins: an active GitHub plugin can use the GitHub
credentials and webhook secrets stored on the site.

Check which credential formats and features the plugin supports, particularly
branch archives, published releases and webhooks. Missing features stay
unavailable. Back up the site, including the matching credential sidecar and
database key.

## Activate, deactivate or switch

Activating the plugin in WordPress **Plugins** takes effect on the next page
load. There is no approval step. Booster shows a one-time notice on the Plugins
and Booster screens naming the plugin that now provides GitHub, and the
Overview and **GitHub** pages show it as the source.

Deactivating the plugin returns GitHub to the bundled provider on the next page
load, with a one-time notice. To switch plugins, deactivate the current one
before activating the next.

Managed packages, credentials and webhook records stay as they are when the
supplier changes. Booster does not convert credential formats or move
provider-side webhooks; a credential the new provider does not support stays
stored but unusable until you add a supported one. Check package state and
Activity before starting new work after a switch.

## When GitHub is unavailable

| Message | What to do |
| --- | --- |
| **GitHub is unavailable because more than one plugin provides it** | Deactivate every GitHub plugin except the one you want. Booster does not choose between them. |
| **GitHub is unavailable because … could not load** | The plugin is active, but Booster could not create its provider, for example because it was built for a different Booster release. Install a version built for this release, or deactivate it to return to the bundled provider. |

Booster never falls back to the bundled provider while a GitHub plugin is
active. GitHub packages show the same cause and cannot be deployed until GitHub
loads.

Provider authors can consult
[provider registration](provider-registration-and-coexistence.md#supply-github-from-a-plugin)
for the technical boundary.
