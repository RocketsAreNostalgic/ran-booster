# RAN Booster Agent Guidance

This directory is an independent nested Git repository. Run commands from this
directory and preserve unrelated work.

- Internal maintainers use the ignored nested private workbench at
  `ran-booster-workbench/` for Booster-family Dex state, plans and reviews.
  Read its `AGENTS.md` and `.agents/skills/ran-booster-dex/SKILL.md` before
  non-trivial work. Public clones without the workbench must not recreate
  private planning or Dex state in this source repository.
- Before editing, inspect the selected task, its parent, and its blockers, then
  start the task. Verify the implementation before completing it and record the
  implementation SHA and concrete verification evidence in the result.
- Treat Dex readiness as executability, not product priority. Continue the
  currently authorised plan until it is complete or the owner explicitly
  approves a pause or switch. Before starting work under another proposal or
  roadmap priority, state the transition and obtain approval; record the paused
  plan, reason and exact return point in the affected Dex parent.
- Never include Dex IDs in source, documentation, commit messages, or pull
  request text.
- Use bounded subagents for independent, inspectable work when that speeds up
  delivery without obscuring ownership.
- Never commit or print personal access tokens, Bitbucket tokens, webhook
  secrets, the site-owned secrets sidecar, logs, `vendor`, or `node_modules`.
- Keep GitHub and Bitbucket behavior behind provider contracts.
- Core owns the fixed webhook-management control surface under
  `RAN\Admin\WebhookManagement`. It resolves the selected provider's exact
  webhook fitness and management facets, reuses the existing admin-interaction
  services and retains the schema-3 `ran_booster_assisted_hooks_installations`
  option without migration. Providers own webhook operations and bounded
  remediation; they do not supply Core UI, routes or schemas.
  `RAN_BOOSTER_BUNDLED_GITHUB_WEBHOOK_MANAGEMENT_VERSION` and
  `RAN_BOOSTER_ASSISTED_HOOKS_RETIREMENT_BRIDGE_VERSION` are exact,
  request-local coexistence markers. An exact retirement bridge is inert; a
  loaded pre-retirement add-on keeps temporary runtime and uninstall custody
  while Core suppresses its bundled presentation and shows one administrator
  notice.
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
  production, test, documentation and Dex deltas separately for non-trivial
  features; test/docs/Dex deletion does not offset production growth. Before
  adding Core production code, name the current Core-owned invariant, rejected
  smaller alternatives and exact projected production-line, concrete-type,
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
- Preserve the negotiated Prospective Release API boundary: inspection
  downloads, verifies and discards the exact release ZIP; installation freshly
  reacquires it. The shared updater owns archive custody and verification,
  WordPress Core owns installation, new targets remain inactive, and partial or
  uncertain outcomes never claim adoption.
- The required development gates are `composer check` and `pnpm check`. For
  runtime-affecting work, also run PHP lint and the WordPress activation smoke
  check used by CI.
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

Run Dex commands from `ran-booster-workbench/` and verify that `pnpm exec dex
dir` resolves to that repository's `.dex` directory before sequential ledger
mutations. From this Core root, `pnpm dex <command>` is the explicit forwarding
entry point; the `workbench:*` scripts forward the workbench's other pnpm
checks without merging its independent workspace or lockfile into Core. Never
run `dex sync` without new authorization.
