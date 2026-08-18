# Release process

Release Please owns the Beta version proposal, changelog entry, plugin header,
`readme.txt` stable tag, and `.release-please-manifest.json`. Conventional
Commits determine the proposed release; do not edit generated version changes or
create tags manually.

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
