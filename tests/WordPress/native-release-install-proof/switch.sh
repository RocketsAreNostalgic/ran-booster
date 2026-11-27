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
queue=()
for package_type in ${RAN_RELEASE_SWITCH_PROOF_TYPES:-plugin theme}; do
    for scenario in ${RAN_RELEASE_SWITCH_PROOF_CASES:-choice-current choice-latest choice-dedup choice-disappeared form-choice-current form-choice-latest same form-same prerelease-order form-prerelease-order prerelease-no-consent comparison-unavailable form-comparison-unavailable stale-confirmation form-stale-confirmation replaced-asset form-replaced-asset form-failed-download return-lock newer active older disabled failed-download incompatible-zip pre-install pre-install-throw stale form-stale missing fallback-repaired compete conflict failed-install kill foreign-lock expired-lock fresh-finished fresh-killed fresh-damaged fresh-deleted}; do
        queue+=("$package_type:$scenario")
    done
done

# One fixture is on the site at a time. To spare a WordPress start, a case's last process may seed the
# next one; "next" then still names it when that case begins, and its own seed phase is dropped.
next=''
for index in "${!queue[@]}"; do
    package_type="${queue[index]%%:*}"
    scenario="${queue[index]#*:}"
    phases="seed run readback"
    case "$scenario" in
        conflict|failed-install|kill|foreign-lock|expired-lock) phases="$phases release retry retried" ;;
        return-lock) phases="seed run branch-return" ;;
        fresh-*) phases="$phases release fresh" ;;
    esac
    [[ -z "$next" ]] || phases="${phases#seed }"
    for phase in $phases; do
        # Only these two leave no update-check deletion for request end, which would undo the next seed.
        next=''
        if [[ "$phase" == "${phases##* }" && "$phase" =~ ^(readback|retried)$ ]]; then
            next="${queue[index + 1]:-}"
        fi
        status=0
        RAN_RELEASE_SWITCH_PROOF_TYPE="$package_type" RAN_RELEASE_SWITCH_PROOF_CASE="$scenario" RAN_RELEASE_SWITCH_PROOF_PHASE="$phase" RAN_RELEASE_SWITCH_PROOF_SEED_NEXT="$next" \
            "$PHP_BIN" -d memory_limit=512M "$WP_CLI_BIN" eval-file "$proof" --path="$RAN_RELEASE_SWITCH_PROOF_SITE" --user="$RAN_RELEASE_SWITCH_PROOF_USER" || status=$?
        if [[ "$phase" == run && "$scenario" =~ ^(kill|foreign-lock|expired-lock|fresh-killed|fresh-damaged|fresh-deleted)$ ]]; then
            [[ "$status" == 137 ]] || { echo "Expected SIGKILL exit 137, got $status" >&2; exit 1; }
        elif [[ "$status" != 0 ]]; then
            exit "$status"
        fi
    done
done
