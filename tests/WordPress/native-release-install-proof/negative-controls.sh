#!/usr/bin/env bash
set -euo pipefail

# Run on a separate freshly provisioned disposable site, with the same safety checks.
: "${RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE:?Set the isolated disposable WordPress root}"
: "${PHP_BIN:?Set the PHP 8.2 executable}"
wp_cli="${WP_CLI_BIN:-wp}"
directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
log="$(mktemp)"
trap 'rm -f "$log"' EXIT
export RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE
run="${RAN_NATIVE_RELEASE_INSTALL_PROOF_RUN:-$(date +%s)}"
for package_type in plugin theme; do
    export RAN_NATIVE_RELEASE_INSTALL_PROOF_TYPE="$package_type"
    controls="refusal activation lifetime stored-absent"
    [[ "$package_type" != plugin ]] || controls="$controls stored"
    for control in $controls; do
        export RAN_NATIVE_RELEASE_INSTALL_PROOF_NEGATIVE_CONTROL="$control"
        case "$control" in
            refusal) scenario=equal; phases="seed run"; expected="Unexpected scenario-specific refusal or acquisition." ;;
            activation) scenario=active; phases="seed run"; expected="Unexpected fixture activation state." ;;
            lifetime) scenario=current; phases="seed run readback"; expected="Unexpected fresh-request offer lifetime." ;;
            stored) scenario=active; phases="seed run readback"; expected="Unexpected stored update-check row." ;;
            stored-absent) scenario=current; phases="seed run readback"; expected="Unexpected stored update-check row." ;;
        esac
        export RAN_NATIVE_RELEASE_INSTALL_PROOF_CASE="$scenario"
        # The stored-row controls reuse cases above, so each needs its own fixture.
        case "$control" in
            stored) export RAN_NATIVE_RELEASE_INSTALL_PROOF_RUN="${run}s" ;;
            stored-absent) export RAN_NATIVE_RELEASE_INSTALL_PROOF_RUN="${run}a" ;;
            *) export RAN_NATIVE_RELEASE_INSTALL_PROOF_RUN="$run" ;;
        esac
        for phase in $phases; do
            export RAN_NATIVE_RELEASE_INSTALL_PROOF_PHASE="$phase"
            proof="$directory/NativeReleaseInstallProof.php"
            if [[ "$phase" == "${phases##* }" ]]; then
                proof="$directory/NativeReleaseInstallProofNegativeControl.php"
            fi
            status=0
            "$PHP_BIN" -d memory_limit=512M "$(command -v "$wp_cli")" eval-file "$proof" --exec="define('RAN_BOOSTER_ALLOW_PRERELEASE_SOURCES', true);" --path="$RAN_NATIVE_RELEASE_INSTALL_PROOF_SITE" --user=proof >"$log" 2>&1 || status=$?
            if [[ "$proof" == */NativeReleaseInstallProofNegativeControl.php ]]; then
                if [[ "$status" == 0 || "$(<"$log")" != *"$expected"* ]]; then
                    cat "$log" >&2
                    echo "Negative control did not fail at its expected assertion" >&2
                    exit 1
                fi
                printf '{"type":"%s","negative_control":"%s","exit_status":%s,"expected_assertion":"%s"}\n' "$package_type" "$control" "$status" "$expected"
            elif [[ "$status" != 0 ]]; then
                cat "$log" >&2
                exit "$status"
            fi
        done
    done
done
