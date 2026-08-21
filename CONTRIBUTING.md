# Contributing

Use Conventional Commits (`feat:`, `fix:`, `docs:`, `test:`, `chore:`) so
Release Please can prepare the changelog and version proposal.

Before proposing a change, install the locked Composer and pnpm dependencies,
then run:

```sh
composer check
pnpm check
```

Runtime, archive, compatibility-marker, release, and WordPress lifecycle changes
need the focused proof required by [AGENTS.md](AGENTS.md). Do not commit generated
release ZIPs, secret sidecars, WordPress runtime state, or credentials.

Follow [SUPPORT.md](SUPPORT.md) for ordinary support, non-sensitive defects, and
feature requests. Follow [SECURITY.md](SECURITY.md) for vulnerabilities; do not
submit security details in an issue or pull request.

RAN Booster is distributed through verified GitHub release artifacts rather
than WordPress.org. Do not add WordPress.org/SVN publication, a hosted licence
service, telemetry, or a second update authority without a separate decision.
