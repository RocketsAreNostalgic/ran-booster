#!/usr/bin/env bash
set -euo pipefail

# Run only on a freshly provisioned, marked disposable site with Core installed.
: "${RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE:?Set the isolated disposable WordPress root}"
: "${PHP_BIN:?Set the PHP 8.2 executable}"
wp_cli="${WP_CLI_BIN:-wp}"
proof="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)/NativeReleaseInstallProof.php"
export RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE
export RAN_NATIVE_RELEASE_INSTALL_PROOF_RUN="${RAN_NATIVE_RELEASE_INSTALL_PROOF_RUN:-$(date +%s)}"
for package_type in ${RAN_NATIVE_RELEASE_INSTALL_PROOF_TYPES:-plugin theme}; do
    for scenario in ${RAN_NATIVE_RELEASE_INSTALL_PROOF_CASES:-current automatic equal older older-override-off newer prerelease repository-race revision-race mutable assurance disabled active}; do
        export RAN_NATIVE_RELEASE_INSTALL_PROOF_TYPE="$package_type"
        export RAN_NATIVE_RELEASE_INSTALL_PROOF_CASE="$scenario"
        override=true
        [[ "$scenario" != older-override-off ]] || override=false
        for phase in seed run readback; do
            export RAN_NATIVE_RELEASE_INSTALL_PROOF_PHASE="$phase"
            status=0
            "$PHP_BIN" -d memory_limit=512M "$(command -v "$wp_cli")" eval-file "$proof" --exec="define('RAN_BOOSTER_ALLOW_PRERELEASE_SOURCES', $override);" --path="$RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE" --user=proof || status=$?
            if [[ "$phase" == run && "$scenario" == kill-* ]]; then
                [[ "$status" == 137 ]] || { echo "Expected SIGKILL exit 137, got $status" >&2; exit 1; }
            elif [[ "$status" != 0 ]]; then
                exit "$status"
            fi
        done
    done
done
