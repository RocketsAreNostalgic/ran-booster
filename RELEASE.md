# Release process

Release Please owns the Beta version proposal, changelog entry, plugin header,
`readme.txt` stable tag, and `.release-please-manifest.json`. Conventional
Commits determine the proposed release; do not edit generated version changes or
create tags manually.

## Changes to release authority

Booster uses trusted-main promotion for release control. Pull requests remain
mandatory on `main`, the repository ruleset has no bypass actors, and strict
Runtime archive, Quality, and Release candidate install readback checks remain
required before merge.

The current single-maintainer model does not claim a separate human
authorization principal. Release-control pull requests continue through the
repository's exact-head automated review process; where repository settings
require an external automated approval such as Copilot, that is a pre-merge
review gate rather than release evidence.

A merge does not itself authorize privileged release mutation. Quality must
successfully qualify that exact merged `main` revision. Changes to release
workflows, release scripts, dependency manifests, the runtime packaging policy,
and other evidence inputs continue to force a fresh main Runtime archive and
Quality run rather than reusing pull-request evidence.

Release Please runs only after a successful push-triggered `main` Quality run
for this repository. It checks out that exact Quality commit and proves the
merged lifecycle before opening or updating a release proposal or reconciling
publication. There is no mandatory second ordinary pull request after a
release-control change: successful exact-main qualification is the promotion
boundary.

Exact candidate identity, artifact provenance, immutable publication, and
post-publication readback remain unchanged.

## Release candidate fetch credentials

The Quality and Release Please workflows check out source with
`persist-credentials: false`. When either workflow must fetch an exact Release
Please base or pull request head, that individual fetch receives the
step-scoped GitHub token through an ephemeral Git credential helper. The helper
is configured only for the command: it does not persist the token in the
checkout, repository configuration, or runner-wide configuration. A missing
token fails closed before candidate network work begins.

Fetch authentication is transport only, not release identity. Quality still
validates the exact pull request and commit identities before it admits a
candidate. Candidate artifact readback also requires the recorded event to
match the current Quality run, keeping trusted dispatch and direct pull request
checks as distinct event identities. Before dispatch, Release Please still
requires the exact live bot-owned pending pull request, its expected base and
head commits, the bounded generated file set, and the signed bot commit
identity. After merge, release reconciliation re-verifies the exact merged pull
request and candidate identities before publication.

Before merging a release proposal:

1. run `composer check` and `pnpm check` from the exact candidate commit;
2. build and verify the release ZIP from that same commit;
3. confirm the archive contains only the allowlisted runtime and locked updater;
4. run the required activation and installed lifecycle proofs, including the
   installed archive localisation smoke proof; and
5. review the proposed changelog and every synchronized version source.

The main-push quality workflow builds the candidate artifact. The release
workflow may publish only that tested artifact and must read back an immutable
prerelease whose tag, target commit, asset name, and digest match the reviewed
candidate. Repository visibility, release publication, WordPress installation,
and promotion are separate operations; none is implied by a source commit or
Release Please proposal.

GitHub's generated source archives are not installable WordPress packages. The
canonical consumer artifact is `ran-booster-<version>.zip` attached to the
verified GitHub release.
