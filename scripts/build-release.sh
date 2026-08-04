#!/usr/bin/env bash

# Build a deterministic, runtime-only RAN Booster release archive.
#
# Core files come from an immutable Git commit. The updater package comes from a
# clean production Composer install using that commit's composer.json and lock.
# ZIP entries are store-only, sorted, timestamp-normalized, and stripped of
# platform-specific metadata.
#
# Usage: build-release.sh [ref] [expected-version]
# Defaults: ref=HEAD; expected-version=the plugin Version at ref.

set -euo pipefail

# ZIP stores timezone-naive DOS timestamps, so fix the build zone for reproducibility.
export TZ=UTC

fail() {
	printf 'build-release: %s\n' "$*" >&2
	exit 1
}

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null) \
	|| fail 'run this script from a Git checkout.'
cd "$repo_root"

manifest="$repo_root/release-files.txt"
[[ -f "$manifest" ]] || fail 'release-files.txt is missing.'
for command_name in composer git php tar unzip zip zipinfo; do
	command -v "$command_name" >/dev/null 2>&1 \
		|| fail "$command_name is required."
done

ref=${1:-HEAD}
[[ "$ref" != -* ]] || fail 'release ref must not begin with a hyphen.'
commit=$(git rev-parse --verify "${ref}^{commit}" 2>/dev/null) \
	|| fail "release ref does not identify a commit: $ref"

committed_entries=(
	'NOTICE.md'
	'RAN'
	'assets'
	'autoload.php'
	'index.php'
	'license.txt'
	'ran-booster.php'
	'readme.txt'
	'uninstall.php'
	'views'
)
package_root='vendor/ran/wp-github-release-updater'
updater_version='v2.0.0-beta.4'
updater_commit='0688b189c24bb5458cf27a417a743cfb011f499a'
package_entries=(
	"$package_root/LICENSE"
	"$package_root/bootstrap.php"
	"$package_root/runtime.php"
	"$package_root/src"
)
generated_entries=(
	'ran-booster-release.json'
)
allowed_entries=( "${committed_entries[@]}" "${package_entries[@]}" "${generated_entries[@]}" )
entries=()

is_allowed_entry() {
	local candidate=$1
	local allowed

	for allowed in "${allowed_entries[@]}"; do
		[[ "$candidate" == "$allowed" ]] && return 0
	done

	return 1
}

is_package_entry() {
	local candidate=$1
	local package_entry

	for package_entry in "${package_entries[@]}"; do
		[[ "$candidate" == "$package_entry" ]] && return 0
	done

	return 1
}

is_generated_entry() {
	local candidate=$1
	local generated_entry

	for generated_entry in "${generated_entries[@]}"; do
		[[ "$candidate" == "$generated_entry" ]] && return 0
	done

	return 1
}

while IFS= read -r entry || [[ -n "$entry" ]]; do
	[[ -z "$entry" || "$entry" == \#* ]] && continue
	[[ "$entry" =~ ^[A-Za-z0-9._/-]+$ ]] \
		|| fail "unsafe allowlist path: $entry"
	[[ "$entry" != /* && "$entry" != ./* && "$entry" != *'//'* ]] \
		|| fail "unsafe allowlist path: $entry"
	[[ "$entry" != '..' && "$entry" != ../* && "$entry" != */../* && "$entry" != */.. ]] \
		|| fail "unsafe allowlist path: $entry"
	is_allowed_entry "$entry" \
		|| fail "unexpected runtime allowlist entry: $entry"

	for existing in "${entries[@]:-}"; do
		[[ "$entry" != "$existing" ]] \
			|| fail "duplicate allowlist entry: $entry"
	done

	if ! is_package_entry "$entry" && ! is_generated_entry "$entry"; then
		git cat-file -e "$commit:$entry" 2>/dev/null \
			|| fail "allowlist path is missing from release ref: $entry"
	fi
	entries+=( "$entry" )
done < "$manifest"

[[ ${#entries[@]} -eq ${#allowed_entries[@]} ]] \
	|| fail 'release-files.txt does not contain the complete runtime allowlist.'

for required in "${allowed_entries[@]}"; do
	found=false
	for entry in "${entries[@]}"; do
		if [[ "$entry" == "$required" ]]; then
			found=true
			break
		fi
	done
	[[ "$found" == true ]] || fail "required allowlist entry is missing: $required"
done

for required_source in composer.json composer.lock .release-please-manifest.json; do
	git cat-file -e "$commit:$required_source" 2>/dev/null \
		|| fail "release ref is missing $required_source."
done

while IFS= read -r tree_entry; do
	mode=${tree_entry%% *}
	[[ "$mode" != '120000' ]] \
		|| fail 'release files must not contain symbolic links.'
done < <(git ls-tree -r "$commit" -- "${committed_entries[@]}")

plugin_version=$(
	git show "$commit:ran-booster.php" \
		| sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p'
)
stable_tag=$(
	git show "$commit:readme.txt" \
		| sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p'
)
requires_wordpress=$(
	git show "$commit:ran-booster.php" \
		| sed -n 's/^[[:space:]]*\*[[:space:]]*Requires at least:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p'
)
requires_php=$(
	git show "$commit:ran-booster.php" \
		| sed -n 's/^[[:space:]]*\*[[:space:]]*Requires PHP:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p'
)
tested_wordpress=$(
	git show "$commit:readme.txt" \
		| sed -n 's/^Tested up to:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p'
)
# The single-quoted program is PHP and must not be expanded by the shell.
# shellcheck disable=SC2016
if ! manifest_version=$(
	git show "$commit:.release-please-manifest.json" \
		| php -r '
			$document = json_decode( stream_get_contents( STDIN ), true, 512, JSON_THROW_ON_ERROR );
			if ( ! is_array( $document ) || ! isset( $document["."] ) || ! is_string( $document["."] ) ) {
				exit( 1 );
			}
			echo $document["."];
		'
); then
	fail 'release ref must contain a valid root manifest version.'
fi

[[ -n "$plugin_version" && "$plugin_version" != *$'\n'* ]] \
	|| fail 'ran-booster.php must contain exactly one plugin Version header.'
[[ -n "$stable_tag" && "$stable_tag" != *$'\n'* ]] \
	|| fail 'readme.txt must contain exactly one Stable tag.'
[[ "$plugin_version" == "$stable_tag" ]] \
	|| fail "plugin Version ($plugin_version) does not match Stable tag ($stable_tag)."
[[ "$plugin_version" == "$manifest_version" ]] \
	|| fail "plugin Version ($plugin_version) does not match manifest version ($manifest_version)."
[[ "$plugin_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] \
	|| fail "unsupported release version: $plugin_version"
for compatibility_version in "$requires_wordpress" "$requires_php" "$tested_wordpress"; do
	[[ "$compatibility_version" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]] \
		|| fail "unsupported compatibility version: $compatibility_version"
done

expected_version=${2:-$plugin_version}
[[ "$expected_version" == "$plugin_version" ]] \
	|| fail "expected version ($expected_version) does not match release ref ($plugin_version)."

build_dir="$repo_root/build"
mkdir -p "$build_dir"
tmp_dir=$(mktemp -d "$build_dir/.release-build.XXXXXX") \
	|| fail 'could not create a temporary build directory.'
trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

composer_dir="$tmp_dir/composer"
composer_home="$tmp_dir/composer-home"
stage_dir="$tmp_dir/stage"
stage_root="$stage_dir/ran-booster"
mkdir -p "$composer_dir" "$composer_home" "$stage_root"
git show "$commit:composer.json" > "$composer_dir/composer.json"
git show "$commit:composer.lock" > "$composer_dir/composer.lock"

(
	cd "$composer_dir"
	COMPOSER_HOME="$composer_home" composer install \
		--no-dev \
		--prefer-dist \
		--no-interaction \
		--no-progress \
		--no-scripts \
		--no-plugins \
		--no-autoloader
)

installed_package="$composer_dir/$package_root"
[[ -d "$installed_package" ]] \
	|| fail 'the locked updater package was not installed.'

# Validate the locked package identity.
# shellcheck disable=SC2016
if ! php -r '
		$lock = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
		$packages = $lock["packages"] ?? null;
		if ( ! is_array( $packages ) || 1 !== count( $packages ) || ! is_array( $packages[0] ) ) {
			exit( 1 );
		}
		$package = $packages[0];
		$source = $package["source"]["reference"] ?? null;
		if (
			"ran/wp-github-release-updater" !== ( $package["name"] ?? null )
			|| $argv[2] !== ( $package["version"] ?? null )
			|| ! is_string( $source )
			|| ! hash_equals( $argv[3], $source )
			|| $source !== ( $package["dist"]["reference"] ?? null )
		) {
			exit( 1 );
		}
	' "$composer_dir/composer.lock" "$updater_version" "$updater_commit"; then
	fail "composer.lock must contain only ran/wp-github-release-updater $updater_version at $updater_commit as a production package."
fi

for package_entry in LICENSE bootstrap.php runtime.php src; do
	[[ -e "$installed_package/$package_entry" ]] \
		|| fail "the locked updater package is missing $package_entry."
done
if find "$installed_package/LICENSE" "$installed_package/bootstrap.php" "$installed_package/runtime.php" "$installed_package/src" -type l -print -quit | grep -q .; then
	fail 'the updater runtime allowlist must not contain symbolic links.'
fi

git archive \
	--format=tar \
	--prefix=ran-booster/ \
	"$commit" \
	-- "${committed_entries[@]}" \
	| tar -xf - -C "$stage_dir"

mkdir -p "$stage_root/$package_root"
cp "$installed_package/LICENSE" "$stage_root/$package_root/LICENSE"
cp "$installed_package/bootstrap.php" "$stage_root/$package_root/bootstrap.php"
cp "$installed_package/runtime.php" "$stage_root/$package_root/runtime.php"
cp -R "$installed_package/src" "$stage_root/$package_root/src"

# This positive provenance marker exists only inside an official staged archive.
# Source checkouts therefore fail closed unless an operator explicitly enables
# Core update discovery for a disposable update test.
# shellcheck disable=SC2016
php -r '
	$marker = array(
		"schema"         => "ran-booster-core-release",
		"schema_version" => 1,
		"version"        => $argv[1],
		"commit"         => $argv[2],
	);
	file_put_contents(
		$argv[3],
		json_encode( $marker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n"
	);
' "$expected_version" "$commit" "$stage_root/ran-booster-release.json"

if find "$stage_root" -type l -print -quit | grep -q .; then
	fail 'release files must not contain symbolic links.'
fi

commit_epoch=$(git show -s --format=%ct "$commit")
# Normalize generated package modes and every ZIP timestamp. The archive's
# committed Core modes still come from git archive.
# shellcheck disable=SC2016
php -r '
	$packageRoot = $argv[1];
	$archiveRoot = $argv[2];
	$epoch = (int) $argv[3];
	$marker = $argv[4];
	$packageIterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $packageRoot, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $packageIterator as $item ) {
		chmod( $item->getPathname(), $item->isDir() ? 0755 : 0644 );
	}
	chmod( $marker, 0644 );
	$archiveIterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $archiveRoot, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $archiveIterator as $item ) {
		touch( $item->getPathname(), $epoch );
	}
	touch( $archiveRoot, $epoch );
	' "$stage_root/$package_root" "$stage_root" "$commit_epoch" "$stage_root/ran-booster-release.json"

archive_name="ran-booster-$expected_version.zip"
tmp_archive="$tmp_dir/$archive_name"

(
	cd "$stage_dir"
	find ran-booster -print \
		| LC_ALL=C sort \
		| zip -q -0 -X "$tmp_archive" -@
)

archive_size=$(wc -c < "$tmp_archive" | tr -d '[:space:]')
[[ "$archive_size" =~ ^[1-9][0-9]*$ ]] || fail 'archive size is invalid.'

bash "$script_dir/verify-release.sh" "$tmp_archive" "$expected_version" "$commit"

mv -f "$tmp_archive" "$build_dir/$archive_name"

printf 'Built %s\n' "$build_dir/$archive_name"
