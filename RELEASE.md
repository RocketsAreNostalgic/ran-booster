# Release process

Release Please owns the Beta version proposal, changelog entry, plugin header,
`readme.txt` stable tag, and `.release-please-manifest.json`. Conventional
Commits determine the proposed release; do not edit generated version changes or
create tags manually.

## Changes to release authority

The Quality and Release Please workflows treat their workflow definitions and
release scripts as privileged release authority. When a merged pull request
changes one of those files, Quality still verifies the merged commit, but
Release Please intentionally makes no repository or release mutation. This
prevents a newly changed workflow from immediately exercising its own release
permissions.

After an authority-changing pull request lands, merge a separate ordinary pull
request that does not change the protected workflows or release scripts. Its
successful main-branch Quality run may then open or update the Release Please
proposal using the reviewed authority. Rerunning the authority-changing commit
does not bypass this separation, and version files or tags must not be edited by
hand.

## Candidate fetch credentials

Release jobs check out source with `persist-credentials: false`. When a job must
fetch an exact Release Please base or pull-request head, that individual fetch
receives the step-scoped GitHub token through an ephemeral Git credential
helper. The helper is configured only for the command: it does not persist the
token in the checkout, repository configuration, or runner-wide configuration.
A missing token fails closed before candidate network work begins.

Fetch authentication is transport only, not release identity. Before dispatch,
the workflow still requires the exact live bot-owned pending pull request, its
expected base and head commits, the bounded generated file set, and the signed
bot commit identity. After merge, release reconciliation re-verifies the exact
merged pull request and candidate identities before publication.

Before merging a release proposal:

1. run `composer check` and `pnpm check` from the exact candidate commit;
2. build and verify the release ZIP from that same commit;
3. confirm the archive contains only the allowlisted runtime and locked updater;
4. run the required activation and installed lifecycle proofs; and
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
