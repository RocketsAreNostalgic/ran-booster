#!/usr/bin/env bash
# Prove, on the marked disposable site, that a plugin can supersede bundled
# GitHub through ProviderRegistry::supersede_github(), and that a second or a
# failing claimant leaves GitHub unavailable without the bundled fallback.
# Leaves both claimant plugins installed and inactive for the Bitbucket proof.
set -euo pipefail
fail() { printf 'github-supersede-installed: %s\n' "$*" >&2; exit 2; }
[[ "${RAN_BOOSTER_GITHUB_SUPERSEDE_DISPOSABLE:-}" == 1 ]] || fail 'Explicit disposable-site consent is required.'
wordpress="${RAN_BOOSTER_WORDPRESS_PATH:?Set the exact disposable site path.}"
php_bin="${PHP_BIN:?Set PHP.}"
wp_cli="${WP_CLI_BIN:?Set WP-CLI.}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
proof="$root/tests/WordPress/github-supersede-installed-smoke.php"
plugins="$wordpress/wp-content/plugins"
booster_vendor="$plugins/ran-booster/vendor/ran"
extension="$plugins/ran-booster-github-provider-extension"
claimant="$plugins/ran-booster-github-supersede-failing"
marker="$wordpress/.ran-booster-disposable-test-site"
for directory in "$wordpress" "$wordpress/wp-content" "$plugins" "$plugins/ran-booster"; do
	[[ -d "$directory" && ! -L "$directory" && "$(cd "$directory" && pwd -P)" == "$directory" ]] || fail 'Exact non-symlinked site roots are required.'
done
[[ -f "$marker" && ! -L "$marker" && "$(<"$marker")" == 'RAN Booster disposable test site' ]] || fail 'Exact disposable-site marker required.'
for target in "$extension" "$claimant"; do
	[[ ! -e "$target" && ! -L "$target" ]] || fail "Refusing an existing fixture target: $target"
done

# Materialize the external wrapper as the parity test does, with the released
# packages Booster's archive bundles and a small autoloader of its own.
mkdir "$extension"
cp -R "$root/tests/fixtures/ran-booster-github-provider-extension/." "$extension/"
mkdir -p "$extension/vendor/ran"
for package in booster-github-provider wp-release-updater updater-support; do
	[[ -d "$booster_vendor/$package" && ! -L "$booster_vendor/$package" ]] || fail "The installed archive lacks $package."
	cp -R "$booster_vendor/$package" "$extension/vendor/ran/$package"
done
cat > "$extension/vendor/autoload.php" <<'PHP'
<?php
declare(strict_types=1);

$prefixes = array(
	'RAN\\BoosterGitHubProvider\\V1\\' => __DIR__ . '/ran/booster-github-provider/src/',
	'RAN\\UpdaterSupport\\V1\\' => __DIR__ . '/ran/updater-support/src/',
);

spl_autoload_register(
	static function ( string $class ) use ( $prefixes ): void {
		foreach ( $prefixes as $prefix => $directory ) {
			if ( ! str_starts_with( $class, $prefix ) ) {
				continue;
			}
			$file = $directory . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
			return;
		}
	},
	true,
	true
);
PHP
mkdir "$claimant"
cp -R "$root/tests/fixtures/ran-booster-github-supersede-failing/." "$claimant/"

wp() { "$php_bin" "$wp_cli" "$@" --path="$wordpress"; }
# Phases without --user run as no one; the administrator phase names the installed admin.
check() { wp eval-file "$proof" "$1"; }

check bundled
wp plugin activate ran-booster-github-provider-extension
check superseded
check notice-anonymous
wp eval-file "$proof" notice-administrator --user=admin
wp plugin activate ran-booster-github-supersede-failing
check conflict
wp plugin deactivate ran-booster-github-provider-extension
check failed
wp plugin deactivate ran-booster-github-supersede-failing
check bundled
wp eval-file "$proof" notice-administrator --user=admin
echo 'Installed GitHub supersede, conflict, failure and supplier notice proof passed.'
