# Spike: one "Validate" action for repository webhook Check and Test

Status: spike, not for merge unless the owner decides. Branch `spike/webhook-validate`, cut from `integration/beta.44` at `23a00f17`.
Evidence only: PHPUnit with fakes. No browser, no GitHub, no real webhook operation, no test site.

## Question

Can the repository webhook Check and Test (ping) operations become one action, Validate, that:

1. compares the remote hook configuration with this site's expected settings (read only);
2. only if the configuration matches and the site can receive webhooks (public HTTPS receiver ready), asks the provider to send a ping;
3. returns one merged result carrying both outcomes?

Answer: yes, with a small server-side change. The presentation layer is the larger part of the work.

## 1. Flow of Check and Test today

| | Check | Test (ping) |
|---|---|---|
| Admin-post operation name | `check` | `test` |
| Route | `WebhookManagementController::handle_admin_post()` allowlist, then nonce `ran_booster_repository_webhook_{op}_{provider}_{repo}`, then `WebhookOperationCoordinator::execute()` | same |
| Capability | `manage_options` (controller) and again in the facade `authorize()` | same |
| Inputs | provider code, repository id, saved credential id, local record (`hook_id`, `webhook_profile_id`, `webhook_profile_revision`), current target (repository, callback URL), nonce | same |
| Signing secret | not sent. Check and Test never send the secret; only setup and reconfigure resolve it | not sent |
| Facade steps | `authorize` (nonce, capability, current target equals submitted target, site readiness ready, repository eligible), exact profile and revision, credential profile available, target lock (named DB lock, one per repository), `identity_confirmed` (provider `assess_check`: one `GET /repos/{repo}`), then `provider->check` | same, with `assess_test` and `provider->test` |
| Provider requests (GitHub client) | `GET /repos/{repo}` (assess), `GET /repos/{repo}/hooks/{id}` | `GET /repos/{repo}` (assess), `GET /repos/{repo}/hooks/{id}`, `POST /repos/{repo}/hooks/{id}/pings` |
| Read or write at the provider | read only | read, then write (ping POST) |
| Permission the code shows | `assess_*` is one shared method. Classic tokens: `x-oauth-scopes` must include `admin:repo_hook` (`classic_scope_insufficient` otherwise). Fine-grained tokens: unknown by design (`fine_grained_authority_unknown`), the remediation text says "Webhooks write permission". The code does not distinguish check from test scope. | identical assessment |
| Provider outcome codes | `hook_absent` (succeeded, delivery absent), `configuration_drift`, `configuration_confirmed` (succeeded, delivery `unknown`), `hook_readback_unavailable` / `hook_readback_invalid` (ambiguous) | `hook_absent` (failed), `hook_ownership_mismatch` (failed, refuses to ping), `hook_readback_*` (ambiguous), `ping_requested` (succeeded), `ping_request_failed` (failed on 400/401/403/404/422), `ping_request_failed_ambiguous` |
| Local record (`record_check` / `record_test`) | state ambiguous or partial: status `needs_verification`; absent: `remote_missing`; mismatched: `configuration_drift`; confirmed: `needs_verification` (delivery is `unknown`, never `verified`). `checked_at` set to the provider observation. Management credential id replaced only when the provider state succeeded. Result code mapped to `remote_missing`, `configuration_drift`, `verified` or the provider code. | absent: `remote_missing`; mismatched: `configuration_drift`; `ping_requested` or `ping_delivery_failed`: `needs_verification`; otherwise no write. Management credential id replaced on every write it makes. `ping_verified` is rewritten to `ping_requested`. |
| `successful` flag | true only for `verified` or `configured_pending_delivery` with matching delivery. A clean Check (`configuration_confirmed`) is therefore not `successful` and not `inline_safe`. | always false |
| Activity | one entry: `begin` with operation `webhook.check` before the provider call, one `complete` after the local write. Facts: `webhook_state`, `webhook_delivery`, `webhook_persistence`, `webhook_local_code`. Reason `webhook.operation_succeeded/failed`, `webhook.remote_unconfirmed`, `webhook.local_persistence_*`. | one entry, `webhook.test`. A ping request counts as `succeeded`; delivery `verified` is downgraded to `unverified`. |

Two behaviours worth knowing (read from the code and pinned by a characterization test, not changed):

- Test already validates before pinging. `RepositoryWebhookClient::test()` reads the hook, compares the configuration, and refuses to ping on mismatch (`hook_ownership_mismatch`, mapped locally to `configuration_drift`). Its success result carries the same four-field configuration map. What Test does not do is tell the person "configuration confirmed" alongside "ping requested".
- Site readiness is already a precondition of both. `facade->target()` returns null unless the site is ready and the repository eligible, and the coordinator then returns `invalid_request`. So "receiver not ready" cannot normally occur after a successful Check; the prototype re-reads readiness between the steps as a time-of-check guard.
- By code reading, `configuration_confirmed` has no heading or message entry in `WebhookDisplayModel`; it falls to the provider remediation text, and `result_notice_class()` gives it `notice-error` because it is not in the successful list. The Activity status for such a Check is `failed` for the same reason (`successful` is false). Not rendered or run here; verify before relying on it. If true, it is an existing defect independent of Validate.

## 2. Prototype

Server side only. No presentation file touched.

`WebhookOperationCoordinator::execute( 'validate', ... )` runs the same pre-checks as every non-setup operation (valid target, local record present and identified, saved credential), then:

1. `begin_activity('validate')`: one Activity entry for the one person action.
2. `facade->check()` then `apply_result('check')`, which reuses `record_check` unchanged.
3. Configuration confirmed means the check outcome code is exactly `configuration_confirmed` and the provider state is `succeeded`. Drift, absent, uncertain, failed, or a failed local write all mean "not confirmed" (conservative).
4. If not confirmed: ping skipped, reason `configuration_not_confirmed`.
5. If confirmed: re-read the local record (the check just wrote a new revision; the test's compare-and-swap needs the current one). Gone, unidentified or a different hook: skip, reason `record_changed`, code `record_conflict`.
6. Re-read readiness: site ready, repository still eligible, callback starts with `https://`. Otherwise skip, reason `receiver_not_ready`.
7. `facade->test()` then `apply_result('test')`, which reuses `record_test` unchanged. The provider re-reads the hook and re-compares before pinging, so a change between the steps is still caught.
8. `complete_activity` once, with the last remote result.

Nonce: the facade verifies a nonce per action (`...check...`, `...test...`), and the controller verifies the operation nonce, which for Validate is `...validate...`. One WordPress nonce cannot satisfy both. The prototype lets `AssistedWebhookFacade::authorize()` also accept the Validate nonce for the `check` and `test` actions only (a test proves reconfigure and remove still reject it). This touches Core's authorization and needs owner review. Alternative: the button submits two nonces; heavier on the presentation side.

### Merged outcome shape

`execute()` returns the existing five keys plus an optional `validation` key (absent for every other operation):

```
code         string   ping outcome code when a ping was attempted, else the configuration code
successful   false    always: a ping request is a request, never "delivery verified"
inline_safe  bool     from the last step that ran
remediation  string   from the last step that ran
validation   array{
  configuration: { code: string, confirmed: bool },
  ping:          { status: 'requested'|'failed'|'unknown'|'skipped', code: ?string, reason: ?string }
}
```

`ping.reason` is one of `configuration_not_confirmed`, `receiver_not_ready`, `record_changed` when skipped, else null. `ping.status = unknown` means the provider outcome was ambiguous (a ping may or may not have been sent).

The controller adds one redirect argument, `webhook_management_ping` (for example `requested`, `skipped:receiver_not_ready`), alongside the existing `webhook_management_result`. The redirect is the only channel for the non-enhanced path, so the structured part cannot ride on the code alone without inventing a compound code per case.

### Recording decisions

- Activity: one entry, operation `webhook.validate`. The Activity facts are a strict allowlist (`ActivityStore::encode_facts`), so no new fact keys; both results ride in `webhook_local_code` as `configuration+ping` (for example `configuration_confirmed+ping_requested`, `configuration_drift+ping_skipped_configuration_not_confirmed`). Status `succeeded` only when a ping was requested; otherwise `failed`. Existing reason strings are reused, so no new Activity message strings. One entry mirrors one authorized action and avoids a half-complete pair if the second step throws.
- Local record: `record_check` then `record_test`, each unchanged. Both land on `needs_verification` for a confirmed configuration. A previously `configured` record is downgraded to `needs_verification` by either operation today, so Validate does not change that.

## 3. Tests (all new tests pass)

`tests/Admin/WebhookManagement/WebhookValidateSpikeTest.php` (20 tests) plus one facade test in `tests/AddOn/WebhookAssistance/AssistedWebhookFacadeTest.php`.

| Case | Result asserted |
|---|---|
| hook absent | `remote_missing`, ping skipped (`configuration_not_confirmed`), record `remote_missing`, only check called |
| configuration drift | `configuration_drift`, ping skipped, record `configuration_drift` |
| readback uncertain | `hook_readback_unavailable`, ping skipped, record `needs_verification` |
| confirmed, receiver blocked after the check | `configuration_confirmed`, ping skipped (`receiver_not_ready`) |
| confirmed, callback not HTTPS | ping skipped (`receiver_not_ready`) |
| confirmed, ready, ping requested | code `ping_requested`, `successful` false, two record writes, record `needs_verification` |
| confirmed, ping request fails | `ping_request_failed`; configuration still reported confirmed |
| confirmed, ping ambiguous | ping status `unknown`, not `failed` |
| ping step lacks permission (`repository_identity_unconfirmed`) | partial: configuration confirmed, ping failed |
| provider throws on the ping step | `operation_failed`, configuration confirmed, ping `unknown` |
| record changed between steps | `record_conflict`, ping skipped (`record_changed`) |
| site not ready at the start (no target) | `invalid_request`, no provider calls (same as Check and Test today) |
| credential missing | `invalid_token`, no provider calls, no `validation` key |
| Activity, ping requested | exactly one insert; one terminal update, `succeeded`, `configuration_confirmed+ping_requested` |
| Activity, skipped | one insert; terminal `failed` with the skip facts |
| controller | `validate` accepted only behind its own nonce; redirect carries `webhook_management_result` and `webhook_management_ping`; `check` with that nonce is rejected |
| regression: separate Check | no ping, no `validation` key, one record write |
| regression: separate Test | no extra check, no `validation` key, `successful` false |
| regression: Test refuses mismatch | `configuration_drift`, record `configuration_drift` |
| characterization: clean Check | `successful` false, `inline_safe` false (existing) |
| facade: Validate nonce | accepted for `check` and `test`; rejected for `reconfigure` and `remove` |

Gates: phpcs zero errors and warnings on changed files; PHPStan (`scripts/analyze-development.php`) 0 `[ERROR]`, final `[OK] No errors`, exit 0. Full `tests/Admin`, `tests/AddOn`, `tests/WordPress/ManagedReleaseRuntimeTest.php`: 1838 tests pass. `tests/Webhook`, `tests/RepositoryProvider`, `tests/Activity`, `tests/Troubleshooting`, `tests/Quality`: 5 failures, identical on an untouched `integration/beta.44` worktree (environmental: `StandardsCoverageTest`, two `ProductionAnalysisCoverageTest`, `GitHubExternalExtensionParityTest`, `GitHubReplacementFixtureGuardTest`).

## 4. Size

Server side (this spike): 3 production files, about 120 added lines (coordinator about 119, controller 5, facade 5), no new constants or classes. 1 new test file (about 460 lines, includes two small fixtures) and 1 added facade test (about 20 lines). Zero new translatable strings server side. New codes: operation `validate`; ping skip reasons `configuration_not_confirmed`, `receiver_not_ready`, `record_changed`; ping statuses `requested`, `failed`, `unknown`, `skipped`; redirect arg `webhook_management_ping`; Activity operation `webhook.validate`.

Presentation layer must add (not done here; owned by the other branch):

- `WebhookDisplayModel::operation_models()`: a `validate` label (for example "Validate webhook") and, if Check and Test are kept as advanced actions, ordering; `available_operations()`: same availability as Check and Test (credential present, record identified); nonce for `validate` created by the same `wp_create_nonce` path.
- `panel_context()` / result projection: read `webhook_management_ping`; `result_presentation()` must show two lines (configuration result, ping result) and avoid the single `notice-error`/`notice-warning` class for a partly good result.
- Strings (estimate 8 to 10): button label; configuration confirmed; ping requested, delivery unverified; ping not sent because configuration did not match; ping not sent because this site cannot receive webhooks yet; ping not sent because the local record changed; ping request failed after a confirmed configuration; ping outcome unknown; heading for the merged result; help text for the action.
- Inline (HTMX) response: `notice()` takes one code today; it needs the ping state to build the second line.
- Settings/help copy that mentions Check or Test, and a pot regeneration.

## 5. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Repeated pings: every Validate sends a ping when the configuration matches; GitHub keeps a delivery per ping | Make it clear in the label; the facade target lock already serializes per repository; ambiguous ping reported as `unknown`, no automatic retry |
| Rate limits and duplicate reads: composed Validate makes 5 provider requests (assess and hook read twice, one POST) versus 3 for Test alone | Alternative cheaper design below |
| Token scope differences: code shows one assessment for both; fine-grained read-only tokens would pass Check and fail the ping (403) | Partial result is a first-class outcome (tested); remediation text names the write permission |
| Audit semantics: one `webhook.validate` entry replaces a check entry plus a test entry; reports that count `webhook.check` or `webhook.test` miss it | None found in `RAN` (only the shared reasons are listed); owner decides whether one entry is the wanted audit shape |
| Local record consistency: two compare-and-swap writes; `checked_at` ends at the ping observation | Re-read between steps; skip with `record_changed` on any change; last writer wins only after the first write succeeded |
| Automatic deployment gates | Validate touches no deployment policy. Neither Check nor Test changes the package's Automatic update setting today; Validate keeps that. A ping reaches the receiver as a `ping` event, not a push, so no deployment is expected; not verified against the webhook receiver here |
| Nonce contract | Facade accepts the Validate nonce for check and test only; owner review of the Core-side change |
| Activity facts allowlist | Compound `webhook_local_code` avoids touching `ActivityStore`; a cleaner fix is two allowlisted facts (change in Core) |
| Existing Check display quirk (see section 1) | Independent of Validate; verify and fix separately |
| i18n | 8 to 10 new strings plus pot regeneration in the presentation branch |

### Cheaper design (not built)

Because `test()` already reads and compares the configuration before pinging, and its result carries the configuration map, a Validate that calls only `facade->test()` and projects "configuration: matched" plus "ping: requested" from that one result would use 3 provider requests, one lock, one Activity entry and one local write, and needs no nonce change and no readiness re-read (the facade already enforces readiness). It would lose only the separate `record_check` write on a confirmed configuration. Worth choosing if the owner ships Validate; the composite built here is the literal "check then test" reading of the question.

## 6. Recommendation

Do not merge into beta.44; revisit in beta.45 (or later) as the `test()`-only variant.

Reasons:

- The server change is small and safe, but Validate is mostly a presentation and copy change (button, two-line result, about 10 strings, help text, pot), and the presentation files are in flux on another branch.
- It changes audit shape (one entry) and Core authorization (nonce acceptance), both of which deserve their own review rather than a late beta.44 change.
- Today's Test already refuses to ping a mismatched hook, so the safety property the owner wants exists. The remaining gain is clearer feedback, not safety.
- An existing defect (clean Check shown as an error) is a better beta.44 use of the same attention, if confirmed.

Effort (relative, tidy bullets):

- Server side, `test()`-only variant: small (coordinator branch, controller allowlist and redirect arg, tests).
- Server side, composite as built here: done in this spike; add the facade nonce change review.
- Presentation: medium (operation list and availability, two-line result projection for both redirect and inline paths, strings, help text, pot, view tests).
- Verification: small to medium (display model tests, one inline-response test, an in-browser pass by the owner later).

### Keep-separate plan (if the owner defers or declines)

Nothing needs to change: Check and Test stay as separate buttons and the coordinator, controller and facade stay exactly as on `integration/beta.44`. To keep only the knowledge, merge nothing but this document (and optionally the characterization test for the clean-Check flags). To adopt later, cherry-pick `57fac4e6` (code and tests) onto a branch cut from the then-current integration branch; the only conflicts to expect are in the coordinator if it changes. Roll back by dropping that commit; no schema, option or stored-record format changes were made, so no migration or cleanup is needed.

## 7. Not determined

- Real GitHub behaviour: whether a fine-grained token with read-only Webhooks permission passes Check and fails the ping with 403 (code and docs in the repo do not state per-permission behaviour; no live call was made).
- Rate-limit headroom for the 5-request composite.
- Whether the receiver treats a ping as ignorable under Automatic deployment (receiver code not read in depth).
- The rendered look of the clean-Check result (read from code only).
- Issue #733's recorded reasoning: not present in the local docs, comments or code (searched `docs`, `RAN`, `tests`); `gh` was not used.

## 8. Shipped design (2026-11-10)

Option C shipped in beta.44: one Validate button that is a thin wrapper over the unchanged Check and Test operations, not the coordinator-level design prototyped in `57fac4e6`.

- `WebhookValidationWrapper` calls `execute('check')` and, only on `configuration_confirmed`, `execute('test')`; each keeps its own authorization, activity entry (`webhook.check`, `webhook.test`) and local record write, and stays callable alone.
- Two nonces: the Check nonce stays in the button's `formaction`, the Test nonce is a hidden `_wpnonce_test` field. The controller verifies both before anything runs.
- The redirect carries `webhook_management_result` (settings) and `webhook_management_ping` (the test code or `skipped`); the panel shows two lines, Settings and Ping.
- No coordinator, facade or Core authorization change. The second call re-reads the record the first one wrote, so its compare-and-swap succeeds.
