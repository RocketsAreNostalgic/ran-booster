#!/usr/bin/env bash
set -euo pipefail

# Run only on a marked disposable site with the Core archive under test installed.
# The held cases leave the native hold; their release phase releases it in place and the switch is retried.
: "${RAN_RELEASE_SWITCH_PROOF_SITE:?Set the isolated disposable WordPress root}"
: "${RAN_RELEASE_SWITCH_PROOF_CORE_ZIP:?Set the Core archive under test}"
PHP_BIN="${PHP_BIN:-php}"
WP_CLI_BIN="$(command -v "${WP_CLI_BIN:-wp}")"
RAN_RELEASE_SWITCH_PROOF_USER="${RAN_RELEASE_SWITCH_PROOF_USER:-admin}"
RAN_RELEASE_SWITCH_PROOF_RUN="${RAN_RELEASE_SWITCH_PROOF_RUN:-$(date +%s)}"
export PHP_BIN WP_CLI_BIN RAN_RELEASE_SWITCH_PROOF_SITE RAN_RELEASE_SWITCH_PROOF_CORE_ZIP RAN_RELEASE_SWITCH_PROOF_USER RAN_RELEASE_SWITCH_PROOF_RUN
proof="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)/ReleaseSwitchProof.php"
for package_type in ${RAN_RELEASE_SWITCH_PROOF_TYPES:-plugin theme}; do
    for scenario in ${RAN_RELEASE_SWITCH_PROOF_CASES:-same newer active older disabled failed-download offer-removed compete conflict failed-install kill foreign-lock expired-lock}; do
        phases="seed run readback"
        case "$scenario" in
            conflict|failed-install|kill|foreign-lock|expired-lock) phases="$phases release retry retried" ;;
        esac
        for phase in $phases; do
            status=0
            RAN_RELEASE_SWITCH_PROOF_TYPE="$package_type" RAN_RELEASE_SWITCH_PROOF_CASE="$scenario" RAN_RELEASE_SWITCH_PROOF_PHASE="$phase" \
                "$PHP_BIN" -d memory_limit=512M "$WP_CLI_BIN" eval-file "$proof" --path="$RAN_RELEASE_SWITCH_PROOF_SITE" --user="$RAN_RELEASE_SWITCH_PROOF_USER" || status=$?
            if [[ "$phase" == run && "$scenario" =~ ^(kill|foreign-lock|expired-lock)$ ]]; then
                [[ "$status" == 137 ]] || { echo "Expected SIGKILL exit 137, got $status" >&2; exit 1; }
            elif [[ "$status" != 0 ]]; then
                exit "$status"
            fi
        done
    done
done
