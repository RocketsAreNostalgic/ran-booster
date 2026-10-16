#!/usr/bin/env bash

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
temporary_dir="$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-runtime-contract.XXXXXXXX")"
trap 'rm -rf -- "$temporary_dir"' EXIT

# Exercise the actual assertion blocks without running disposable-site mutations.
cat > "$temporary_dir/php" <<'PROBE'
#!/usr/bin/env bash
if [[ "${1:-}" == '-r' ]]; then
	printf '%s\n' "$FAKE_PHP_VERSION"
	exit "${FAKE_PHP_STATUS:-0}"
fi
if [[ "${2:-}" == core && "${3:-}" == version ]]; then
	printf '%s\n' "$FAKE_WORDPRESS_VERSION"
	exit "${FAKE_WORDPRESS_STATUS:-0}"
fi
exit 99
PROBE
chmod +x "$temporary_dir/php"

assert_guard() {
	local expected_status="$1" status=0
	shift
	env -u RAN_BOOSTER_TEST_PHP_VERSION -u RAN_BOOSTER_TEST_WORDPRESS_VERSION \
		php_bin="$temporary_dir/php" wp_cli=wp wordpress="$temporary_dir" \
		FAKE_PHP_VERSION=8.2 FAKE_WORDPRESS_VERSION=7.0 \
		FAKE_PHP_STATUS=0 FAKE_WORDPRESS_STATUS=0 \
		"$@" bash -eu "$temporary_dir/guard" > "$temporary_dir/output" 2>&1 || status=$?
	if [[ "$status" != "$expected_status" ]]; then
		cat "$temporary_dir/output" >&2
		echo "Runtime guard $script: expected $expected_status, got $status for $*" >&2
		exit 1
	fi
}

for script in core-updater-proof core-package-executor-smoke native-updater-lock-race \
	managed-theme-registration-smoke release-capability-installed-smoke localisation-installed-smoke; do
	source_script="$root/tests/WordPress/$script.sh"
	sed -n '/^expected_php=/,/^fi$/p' "$source_script" > "$temporary_dir/guard"
	test -s "$temporary_dir/guard"
	assert_guard 0
	assert_guard 2 FAKE_PHP_VERSION=8.5
	assert_guard 0 RAN_BOOSTER_TEST_PHP_VERSION=8.5 FAKE_PHP_VERSION=8.5
	assert_guard 2 RAN_BOOSTER_TEST_PHP_VERSION=8.5
	assert_guard 0 RAN_BOOSTER_TEST_PHP_VERSION=8.6 FAKE_PHP_VERSION=8.6
	assert_guard 2 FAKE_PHP_STATUS=1
	for invalid in '' 8.5.1 '8.*' '8.5; exit 0'; do
		assert_guard 2 "RAN_BOOSTER_TEST_PHP_VERSION=$invalid" "FAKE_PHP_VERSION=$invalid"
	done

	sed -n '/^expected_wordpress=/,/^fi$/p' "$source_script" > "$temporary_dir/guard"
	case "$script" in
		core-package-executor-smoke|native-updater-lock-race)
			test ! -s "$temporary_dir/guard"
			continue
			;;
	esac
	test -s "$temporary_dir/guard"
	assert_guard 0
	assert_guard 0 FAKE_WORDPRESS_VERSION=7.0.7
	assert_guard 2 FAKE_WORDPRESS_VERSION=7.1.3
	assert_guard 0 RAN_BOOSTER_TEST_WORDPRESS_VERSION=7.1.3 FAKE_WORDPRESS_VERSION=7.1.3
	assert_guard 2 RAN_BOOSTER_TEST_WORDPRESS_VERSION=7.1.3 FAKE_WORDPRESS_VERSION=7.1.2
	assert_guard 2 RAN_BOOSTER_TEST_WORDPRESS_VERSION=7.0 FAKE_WORDPRESS_VERSION=7.0.7
	assert_guard 2 FAKE_WORDPRESS_STATUS=1
	for invalid in '' '7.1.*' '7.1.3; exit 0'; do
		assert_guard 2 "RAN_BOOSTER_TEST_WORDPRESS_VERSION=$invalid" "FAKE_WORDPRESS_VERSION=$invalid"
	done
done

echo 'Installed runtime expectation contracts passed for all six maintained harnesses.'
