# RAN Booster Agent Guidance

This directory is an independent nested Git repository. Run commands from this
directory and preserve unrelated work.

## RAN quality profile and shared standards

Booster uses the RAN `wordpress-plugin` quality profile. Organisation-level PHP
coding ancestry comes from `ran/coding-standards` through `RANWordPressPlugin`,
and frontend ESLint/Prettier/Stylelint ancestry comes from
`@rocketsarenostalgic/quality-config`.

During the current proof, tracked lockfiles bind those shared packages to the
reviewed candidates `0b03e61a4bb558deeb6bc6b6399f44c0ec95e5be` and
`751edd097e3902efb93992bf47401a1a4f4b1fa8`. Do not replace those exact locked
candidates with floating, unreviewed package state. After Starter and Booster
have both proven the candidates and the shared packages receive versioned
releases, move Booster to the released versions through a reviewed dependency
change.

Booster remains the owner of its actual product contract and stronger gates:
WordPress/PHP support, its established `RAN\` production namespace family,
text domain, source paths, inherited/runtime/security exceptions, PHPStan scope,
localisation and generated-state checks, updater/release state contracts,
admin-shell parity, race/hard-stop/runtime proofs, frontend source globs and
globals, asset tests, and release verification. Shared-package adoption must
never remove or silently weaken those local guarantees.

- Work from an accepted public request or issue. Inspect the affected code and
  tests before editing, verify the result before declaring completion, and
  record concrete check evidence. Do not invent unavailable private context.
- Use bounded subagents for independent, inspectable work when that speeds up
  delivery without obscuring ownership.
- Every pull request requires independent agent review after opening, against
  its exact base and head SHAs. Prior source review, accepted commits,
  integration preflight and green CI do not substitute for reviewing the actual
  pull request tuple. Resolve or explicitly disposition every finding first.
- Merging is always an owner decision. Implementation, integration, branch
  push, pull-request creation, checks and release preparation do not authorize
  an agent to merge. Present the exact pull request, base and head SHAs, merge
  method, checks and unresolved risks, then merge only after the owner explicitly
  authorizes that specific pull request. This includes Release Please pull
  requests even when repository permissions or branch rules allow a direct
  merge. For ordinary iterative or agent-developed PRs, prefer squash so the
  reviewed PR lands as one meaningful default-branch commit; a merge commit is
  appropriate only when that ordinary PR's internal sequence is deliberately
  worth preserving. **Booster's bot-owned `chore(main): release ...` proposals
  are the explicit exception: use Create a merge commit, never squash or rebase
  merge**, as required by [the release guide](RELEASE.md#merge-method-for-booster-release-proposals).
  This is Booster policy, not a universal Release Please limitation. Rebase
  merge is not part of the normal RAN workflow.
- Never commit or print personal access tokens, Bitbucket tokens, webhook
  secrets, the site-owned secrets sidecar, logs, `vendor`, or `node_modules`.
- Keep GitHub and Bitbucket behavior behind provider contracts.
- Core owns the fixed webhook-management control surface under
  `RAN\Admin\WebhookManagement`. It resolves the selected provider's exact
  webhook fitness and management facets, reuses the existing admin-interaction
  services and retains the current `ran_booster_assisted_hooks_installations`
  state only until its ownership is explicitly reconciled under #150 Gap 4.
  Providers own webhook operations and bounded remediation; they do not supply
  Core UI, routes or schemas. Booster is pre-release: do not add or restore
  runtime adapters, coexistence markers, notices or dispatch branches for the
  retired Assisted Hooks add-on. Any historical persisted-record migration must
  be justified separately by concrete state that the controlled installations
  deliberately need to retain.
- Extend existing administration surfaces only through the documented
  WordPress-native actions and filters. Preserve the separate, bounded public
  add-on tab registry for add-ons that genuinely need their own dashboard
  surface. Do not turn either mechanism into a generic slot or whole-view
  replacement system. Core owns routes, base rows, normalization and rendering;
  external add-ons own their capability- and nonce-checked `admin_post_*`
  mutations.
- Do not treat the unavailable local `../ran-starter-plugin` sibling as a
  standards authority. Use Booster's reviewed contracts, component guidance and
  live repository evidence. Restoring a versioned, testable starter baseline
  requires a separate compatibility and ownership decision.
- Treat production lines of code and concept count as an intense reviewed
  restriction, not a delivery target or an unbounded ceiling. Record
  production, test and documentation deltas separately for non-trivial
  features; test or documentation deletion does not offset production growth.
  Before adding Core production code, name the current Core-owned invariant,
  rejected smaller alternatives and exact projected production-line, concrete-type,
  public-seam and persistent-state delta. Do not add a service, DTO, registry,
  facade or other future-proofing concept whose only consumer is hypothetical.
  Optional add-ons may receive a larger but still bounded and reviewed budget
  for their bespoke behavior; add-on convenience does not justify pushing
  complexity into Core.
- Before reopening package-update orchestration architecture, review
  `docs/package-update-orchestration-decision-register.md`. A rejected or
  deferred approach needs its named reconsideration trigger and new evidence;
  preserve the earlier rationale when recording a changed decision.
- Before changing Transporter, Portability API, or source-migration
  architecture, review `docs/portability-decision-register.md`. Keep Core
  source-agnostic and do not reopen a rejected artifact/session, migration saga,
  secret-transfer, or active-active design without its recorded trigger and new
  evidence.
- Before a disposable-site force install, uninstall, or delete proof, verify
  the marker, `ABSPATH`, `WP_CONTENT_DIR`, site URL, and exact target path from
  the intended site root. Never mutate a symlinked or shared development
  checkout.
- Use the Release Please skill before release-automation work.
- Release Please owns version/changelog/release-PR/tag/draft lifecycle. The pinned shared Profile B workflow owns exact successful-main admission and promotion of the exact tested Core ZIP; do not restore local candidate markers, generic publisher state, mutable recovery, or a second version engine.
- Preserve the negotiated Prospective Release API boundary: inspection
  downloads, verifies and discards the exact release ZIP; installation freshly
  reacquires it. The shared updater owns archive custody and verification,
  WordPress Core owns installation, new targets remain inactive, and partial or
  uncertain outcomes never claim adoption.
- The required development gates are `composer check` and `pnpm check`. `composer check` retains Core's i18n/generated-state, deterministic tests, Admin Shell, parser sweep, PHPCS/WPCS/PHPCompatibility, and blocking PHPStan evidence through the canonical `lint:syntax`, `standards`, and `analyze` commands. `standards:fix` is mutating and is never part of `check`. For runtime-affecting work, also run the focused WordPress/archive proof used by CI.
- Booster requires Node 24.11.0 and the exact pnpm version pinned by
  `packageManager`.
  Before blaming a project check, confirm `command -v node`, `node --version`,
  `command -v pnpm`, and `pnpm --version`. The workspace is configured to fail
  immediately instead of downloading a mismatched package manager or silently
  reinstalling stale dependencies. If the exact pinned pnpm or locked
  dependencies are absent, provision them once with elevated network access
  and run `CI=true pnpm install --frozen-lockfile`; do not keep retrying the
  restricted command. Do not set `pmOnFail=ignore`, weaken signature
  verification, or use `HUSKY=0` solely to bypass a pnpm bootstrap failure.
  Once dependencies are current, use the normal `pnpm check` and Husky gates
  rather than substituting partial commands.

## Blacksmith AI prohibition

Blacksmith is approved only as GitHub Actions runner infrastructure where a
repository workflow explicitly selects a Blacksmith runner.

- Never invoke, delegate work to, tag, enable, or otherwise use Blacksmith
  [code]smith, `@codesmith-bot`, Blacksmith Autofix, Blacksmith CI Tuning,
  Blacksmith Testbox agents, or any other Blacksmith AI/agent feature.
- Do not trigger "Enable autofix", ask [code]smith to investigate or repair CI,
  or call Blacksmith agent/MCP/CLI/API features that perform AI inference.
- If CI fails, inspect GitHub Actions logs directly and diagnose or fix the
  failure without delegating it to Blacksmith AI.
- This is a cost-control requirement. Do not override it for convenience, CI
  failures, review comments, or suggestions presented by GitHub or Blacksmith
  UI.
