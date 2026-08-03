# Transporter Blueprint guide

This guide documents the current Transporter Blueprint V1 implementation in
RAN Booster. It is based on the classes in `RAN/Portability/` and describes how
the code classifies, verifies, and moves package blueprints and optional
credential briefcases.

Blueprint V1 is the uploaded or exported ZIP contract. It is distinct from the
request-local [Portability API 2](portability-api.md), which lets a trusted
source bridge review and adopt one already-installed package without an archive
or credential transfer.

## What Transporter does

Transporter turns an administrator-selected subset of the current managed
plugins and themes into a canonical Transporter Blueprint, then reviews that Blueprint
against a target site before any package mutation happens. Excluded source
packages and unchecked target actions remain untouched.

The runtime flow is:

1. Build or read a canonical `PackageBlueprint`.
1. Review each package against the target local installation.
1. Optionally verify repository identity against the registered provider.
1. Classify each package as install, adopt, managed, protected, or blocked.
1. Execute the resulting actions through the normal deployment or management
   path.

Export submits package type and identifier only. The exporter rebuilds each
selected package from Booster's current non-cleaning managed-package readers and
rejects empty, malformed, duplicate, unknown, wrong-type, or stale selections.
It filters the package set before collecting credentials, so an excluded package
and its credential association cannot enter the archive. The archive format does
not change; a subset is already a valid version 1 blueprint.

The codebase currently treats Transporter as a strict single-site feature. The
mutation guard rejects multisite before deployment work proceeds.

## Core classes

The main runtime types are:

- `RAN\Portability\PackageBlueprint`
- `RAN\Portability\BlueprintPackage`
- `RAN\Portability\BlueprintCredential`
- `RAN\Portability\BlueprintArchive`
- `RAN\Portability\BlueprintReviewer`
- `RAN\Portability\BlueprintRepositoryVerifier`
- `RAN\Portability\BlueprintPlanItem`
- `RAN\Portability\TargetPackageAction`
- `RAN\Portability\TargetPackageReason`

`PackageBlueprint` is the canonical top-level format. It contains a list of
packages and, when the encrypted briefcase option is used, a list of
credential records. Its JSON representation is canonicalized and revalidated on
read so the submitted bytes must match the computed encoding.

`BlueprintPackage` is the package record. It stores the package type,
identifier, display name, provider, provider repository identity, repository
locator, branch, and optional subdirectory. Validation is strict: the provider
code must parse, the repository locator must be acceptable to the provider
layer, and the subdirectory must already be normalized.

`BlueprintCredential` is the optional encrypted credential record. It keeps the
credential provider, label, kind, configuration, secret, and the package list
that it belongs to. The record is normalized and bounded before it is written
into a blueprint.

`BlueprintArchive` writes and reads the briefcase ZIP. It supports a canonical
JSON-only archive and an encrypted AES-256 variant when the runtime can prove
that the ZipArchive AES-256 API is available.

## How the blueprint is reviewed

`BlueprintReviewer` classifies each package against the local site before any
provider access happens.

The actions are:

- `install`: the package is absent locally and has no management record.
- `adopt`: the package exists locally but is not yet managed by Booster.
- `managed`: the package is already managed and matches the exported package.
- `protected`: the package has stale, malformed, or conflicting management data.
- `blocked`: the package cannot be verified because credentials, repository
  access, provider availability, or destination policy failed.

The reasons are fixed by `TargetPackageReason` and are intentionally closed and
non-secret. They tell the operator why a row was classified a certain way
without exposing credential material or upstream payloads.

Install and adopt rows are selectable in the review. Apply still sends one
request per selected row, and every request reparses the artifact and recomputes
the current action. The browser selection is convenience only; it is never
authority. Managed, protected, blocked, and unchecked rows receive no mutation
request.

Review proves target state and repository access, but it does not download the
repository archive or predict its final ZIP size. Applying an `install` row
uses the normal target-site deployment preflight, including that site's
`RAN_BOOSTER_MAX_ARCHIVE_BYTES` policy. The default is 50 MiB compressed and
200 MiB expanded; the expanded limit remains four times any configured
compressed override. An over-limit package fails independently with its
deployment outcome and support reference, while unrelated selected rows
continue. Applying an `adopt` row does not download or replace package files.

`BlueprintPlanItem` enforces the valid action/reason pairings so the plan cannot
state something inconsistent, such as `install` with a conflict reason.

## Repository verification

`BlueprintRepositoryVerifier` checks the target package against the registered
provider using `ProviderRegistry` and the provider's `resolveRepository()`
method.

For install and adopt rows, an explicitly associated credential carried by the
encrypted blueprint, or a target credential selected by the operator, is
verified against the exact repository first. This preserves intentional
authenticated access for public repositories as well as private access. A
package with no credential intent is resolved anonymously. Preview never writes
a temporary transferred credential to the sidecar; it is persisted only when
its package is applied.

The verifier returns the same plan item when the provider repository identity
matches the blueprint and carries forward the provider-reported public/private
status independently from the credential association. When identity or
credential verification fails, the item is moved to `blocked` rather than
silently dropping the credential.

## Blueprint archive format

The canonical archive filename is `ran-booster-blueprint.zip` and the JSON entry
inside the archive is `blueprint.json`.

`PackageBlueprint::fromJson()` accepts only canonical JSON that matches the
computed output exactly. The current format is:

- format: `ran-booster-package-blueprint`
- version: `1`
- packages: a list of package records
- credentials: a list of encrypted credential records when the briefcase option
  is used

The package record fields are:

- `type`
- `identifier`
- `display_name`
- `provider`
- `provider_repository_id`
- `repository`
- `branch`
- `subdirectory`

The credential record fields are:

- `provider`
- `label`
- `kind`
- `configuration`
- `secret`
- `packages`

The code enforces canonicalization, size limits, unique identities, and the
absence of duplicate credential payloads.

## Operational constraints

The code currently enforces the following limits:

- `PackageBlueprint::MAX_BYTES` is 262144 bytes.
- `PackageBlueprint::MAX_PACKAGES` is 128.
- `PackageBlueprint::MAX_CREDENTIALS` is 128.
- `BlueprintArchive::MAX_BYTES` is 1048576 bytes.
- Encrypted briefcase passwords must be high-entropy strings between 20 and 256
  characters.
- Only AES-256 encryption is accepted for the encrypted branch.

The code also rejects unsafe repository locators, control characters, duplicate
package identities, duplicate encrypted credential payloads, and mismatched
package-to-credential associations.

## Developer methodology

When extending Transporter code, treat the Blueprint classes as data contracts,
not as planning notes.

1. Add or change the record type that actually carries the data.
1. Update the reviewer so it can classify the new record correctly.
1. Update repository verification when the target must prove something new
   before import or adoption.
1. Update the archive contract only when the serialized shape or encryption
   rules change.
1. Keep action/reason enums closed so the target UI and recovery logic stay
   honest.

If the target behavior changes, update the code first and then revise this
documentation so the doc always reflects the runtime contract.

## Troubleshooting

Use the following mapping when a Transporter operation fails:

- Blueprint rejected: the JSON is not canonical, exceeds size or shape limits,
  or contains duplicate or malformed package data.
- Credential record rejected: the optional credential payload failed
  normalization, exceeded limits, or is not associated with a valid package.
- Review returned `protected`: the target has stale or conflicting management
  data and the package should not be rewritten automatically.
- Review returned `blocked`: the provider could not resolve the repository or
  the target credential/repository state is insufficient.
- Archive write failed: the runtime cannot create or read the encrypted
  briefcase safely.
- Multisite blocked: the Transporter path is running in an unsupported site
  mode.
- Package install exceeds the target archive policy: reduce the whole
  repository archive or set a deliberate target-local
  `RAN_BOOSTER_MAX_ARCHIVE_BYTES` override, then apply that row again. Selecting
  a subdirectory does not reduce the provider ZIP.

## What to read next

- [Portability API 2](portability-api.md)
- [Deployment execution](deployment-execution.md)
- [Provider extension contract](provider-extension-contract.md)
- [Custom git vendor setup](custom-git-vendors.md)
