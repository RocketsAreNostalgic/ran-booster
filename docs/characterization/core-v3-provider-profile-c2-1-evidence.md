# Core V3 provider-profile C2-1 evidence

Date: 2026-08-11

This records the source-only provider-profile request-owner result against Core
baseline `e4e177f22850ea55bd1d66eef4fcadacdab359f7`. It does not claim an
installed WordPress activation, release publication, or deployed-runtime
result.

## Ownership result

- `Dispatcher` retains the closed explicit action router, request-envelope
  acquisition, shared HTMX detection, and all unrelated routes.
- `ProviderProfileAdminController` owns exactly the four credential/webhook
  mutations, credential validation, and public-lookup preference mutation.
- `CoreAdminInteractionFacade` remains the concrete signed native and enhanced
  transport collaborator for provider-profile mutations and retains Admin
  Interaction API 2.
- `SecretsFile`, `CredentialExpiryObservationStore`,
  `PublicRepositoryLookupProfileStore`, `CredentialUsageReader`, provider
  capabilities, `ManagedPackageWebhookAuthorityResolver`, and
  `WordPressUpdaterLock` retain their application and state invariants.
- The internal `CoreProviderProfileInteraction` interface is deleted in the
  same slice. Its target key and selector move unchanged to the replacement
  controller. No shipped production implementation or consumer remains.

Secure-storage create/adopt/reset, diagnostics, debug capture, package work,
and deployment work remain outside this slice.

## Authority and outcome result

All six actions check `manage_options` before provider, profile ID, submitted
secret, sidecar, or option access. The four profile mutations and validation
retain `ran-booster-save-secrets`; public lookup retains the distinct
`ran-booster-save-public-lookup-profile` nonce. The two translated denial
messages remain exact.

Repository-access saves compare the complete saved sidecar record before
success. Repository-access deletes require exact sidecar absence before
clearing the matching public-lookup default and expiry observation. Webhook
saves compare label, scope, normalized target, derived authority, origin, and
configured state; deletes require exact absence. Validation retains the
provider result and expiry observation/sidecar updates. Public lookup renders
fresh option-backed state in the bounded HTMX region. Signed native and
enhanced outcomes keep the established target, error region, redirect, and
full-page-success behavior.

Focused dispatch evidence covers all six denied-capability and invalid-nonce
routes. It asserts the exact capability/nonce sequence and zero credential or
webhook sidecar reads and writes. The real signed facade covers the four
mutation successes and expected/unexpected failures, secret redaction, exact
sidecar/option postconditions, and lock failures. Validation and public lookup
cover native notices plus bounded HTMX success and failure responses.

## Frozen counters

The reviewed C1 passive allowlist remains unchanged at 652 lines.

| Measure | C1 | C2-1 candidate | Delta |
| --- | ---: | ---: | ---: |
| Shipped PHP | 47,074 | 46,938 | -136 |
| Passive PHP | 652 | 652 | 0 |
| Backend PHP | 46,422 | 46,286 | -136 |
| Named runtime types | 253 | 253 | 0 |

Type arithmetic is 253 minus the deleted interface plus the replacement
controller. The controller is 474 physical lines. Counting every
provider-profile-specific Dispatcher line broadly--the import, property,
constructor type, ten-line fallback construction, and three route calls--adds
16 delegate lines, for 490 replacement/delegate lines against the 492-line cap.
The slice changes no public marker, facade, hook, action, nonce, persistent
identity, dependency, package version, or release metadata.

## Source gates

- Focused provider-profile dispatch and facade suite: 58 tests and 381
  assertions pass.
- Full `composer check`: characterization 35; PHPUnit 2,042 tests and 12,537
  assertions; updater bootstrap smoke; release-uploader checks; and PHPCS pass.
- `pnpm check`: formatting, ESLint, Stylelint, and 137 asset tests pass.
- Changed PHP files pass syntax checks; `git diff --check` passes.

The candidate archive and installed WordPress activation checks require the
immutable source commit. The private execution closeout records those checks
after the source commit exists; this evidence does not substitute a working
tree or uncommitted archive for exact-commit proof.
