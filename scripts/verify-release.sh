#!/usr/bin/env bash

# Verify a RAN Booster release ZIP against an immutable Git commit and a clean
# install of its committed Composer lock.
#
# Usage: verify-release.sh [archive] [expected-version] [ref]
# Defaults: ref=HEAD; expected-version=the plugin Version at ref; archive=the
# corresponding versioned file under build/.

set -euo pipefail

fail() {
	printf 'verify-release: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum "$1" | awk '{ print $1 }'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 "$1" | awk '{ print $1 }'
	else
		fail 'sha256sum or shasum is required.'
	fi
}

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null) \
	|| fail 'run this script from a Git checkout.'
cd "$repo_root"

manifest="$repo_root/release-files.txt"
[[ -f "$manifest" ]] || fail 'release-files.txt is missing.'
for command_name in cmp composer diff git php unzip zipinfo; do
	command -v "$command_name" >/dev/null 2>&1 \
		|| fail "$command_name is required."
done

ref=${3:-HEAD}
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
updater_version='v2.0.0-beta.3'
updater_commit='e1103e1e28e0bda4ea3ae8a3e8b88c9b6b39dd99'
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

bootstrap_source=$(git show "$commit:ran-booster.php")
for required_api_marker in \
	"define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 8 );" \
	"define( 'RAN_BOOSTER_ADDON_API_VERSION', 14 );" \
	"define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 2 );" \
	"define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );"; do
	grep -Fq "$required_api_marker" <<< "$bootstrap_source" \
		|| fail "release ref is missing the coordinated API marker: $required_api_marker"
done
portability_facade_source=$(git show "$commit:RAN/AddOn/Portability/PortabilityFacade.php")
grep -Fq 'public const API_VERSION = 2;' <<< "$portability_facade_source" \
	|| fail 'release ref is missing exact Portability API 2.'
for removed_api_marker in \
	RAN_BOOSTER_LOGGING_API_VERSION \
	RAN_BOOSTER_WEBHOOK_CLEANUP_API_VERSION \
	RAN_BOOSTER_DOCUMENTATION_API_VERSION \
	RAN_BOOSTER_PACKAGE_EXTENSION_API_VERSION \
	RAN_BOOSTER_PROVIDER_ADMIN_EXTENSION_API_VERSION \
	RAN_BOOSTER_SETTINGS_PAGE_API_VERSION; do
	if grep -Fq "$removed_api_marker" <<< "$bootstrap_source"; then
		fail "release ref retains a removed or unimplemented API marker: $removed_api_marker"
	fi
done

if git ls-tree -r --name-only "$commit" -- RAN/AddOn/Logging | grep -q .; then
	fail 'release ref retains the removed public add-on Logging API.'
fi

for removed_webhook_type in \
	RAN/AddOn/WebhookAssistance/ProvisioningCallbackResult.php \
	RAN/AddOn/WebhookAssistance/ProvisioningResult.php \
	RAN/AddOn/WebhookAssistance/WebhookCleanupFacade.php; do
	if git cat-file -e "$commit:$removed_webhook_type" 2>/dev/null; then
		fail "release ref retains removed webhook assistance type: $removed_webhook_type"
	fi
done

for required_webhook_type in \
	RAN/RepositoryProvider/RepositoryWebhookFitness.php \
	RAN/RepositoryProvider/RepositoryWebhookFitnessResult.php \
	RAN/RepositoryProvider/RepositoryWebhookManagement.php \
	RAN/RepositoryProvider/RepositoryWebhookOperationResult.php; do
	git cat-file -e "$commit:$required_webhook_type" 2>/dev/null \
		|| fail "release ref is missing fixed webhook operation type: $required_webhook_type"
done

webhook_facade_source=$(git show "$commit:RAN/AddOn/WebhookAssistance/WebhookAssistanceFacade.php")
for removed_webhook_method in withCredential provision releaseProfile; do
	if grep -Eq "function[[:space:]]+${removed_webhook_method}[[:space:]]*\\(" <<< "$webhook_facade_source"; then
		fail "release ref retains removed secret-bearing webhook method: $removed_webhook_method"
	fi
done

if grep -Eq 'function[[:space:]]+ran_booster[[:space:]]*\(' <<< "$bootstrap_source"; then
	fail 'release ref retains the removed global Core container accessor.'
fi
booster_source=$(git show "$commit:RAN/Booster.php")
service_provider_source=$(git show "$commit:RAN/BoosterServiceProvider.php")
for removed_singleton_method in getInstance setInstance; do
	if grep -Eq "function[[:space:]]+${removed_singleton_method}[[:space:]]*\\(" <<< "$booster_source"; then
		fail "release ref retains the removed Core singleton method: $removed_singleton_method"
	fi
done
if grep -Eq -- "->bind\([[:space:]]*('RAN\\\\Booster'|Booster::class)" <<< "$service_provider_source"; then
	fail 'release ref retains the removed Core self-binding.'
fi
secrets_source=$(git show "$commit:RAN/Secrets/SecretsFile.php")
if grep -Eq 'function[[:space:]]+credentialMaterials[[:space:]]*\(' <<< "$secrets_source"; then
	fail 'release ref retains the removed bulk credential-plaintext enumerator.'
fi

expected_version=${2:-$plugin_version}
[[ "$expected_version" == "$plugin_version" ]] \
	|| fail "expected version ($expected_version) does not match release ref ($plugin_version)."

archive=${1:-"$repo_root/build/ran-booster-$expected_version.zip"}
[[ -f "$archive" ]] || fail "archive is missing: $archive"
archive=$(CDPATH='' cd -- "$(dirname -- "$archive")" && pwd)/$(basename -- "$archive")
archive_name=$(basename -- "$archive")
expected_name="ran-booster-$expected_version.zip"
[[ "$archive_name" == "$expected_name" ]] \
	|| fail "archive name must be $expected_name."

actual_hash=$(sha256_file "$archive")
archive_size=$(wc -c < "$archive" | tr -d '[:space:]')
[[ "$archive_size" =~ ^[1-9][0-9]*$ ]] || fail 'archive size is invalid.'

unzip -tqq "$archive" >/dev/null || fail 'ZIP integrity check failed.'

tmp_dir=$(mktemp -d "${TMPDIR:-/tmp}/ran-booster-verify.XXXXXX") \
	|| fail 'could not create a temporary verification directory.'
trap 'rm -rf "$tmp_dir"' EXIT HUP INT TERM

composer_dir="$tmp_dir/composer"
composer_home="$tmp_dir/composer-home"
mkdir -p "$composer_dir" "$composer_home"
for required_source in composer.json composer.lock .release-please-manifest.json; do
	git cat-file -e "$commit:$required_source" 2>/dev/null \
		|| fail "release ref is missing $required_source."
done
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
	|| fail 'the committed lock did not install the updater package.'

# shellcheck disable=SC2016
if ! package_lock_record=$(
	php -r '
		$lock = json_decode( file_get_contents( $argv[1] ), true, 512, JSON_THROW_ON_ERROR );
		$packages = $lock["packages"] ?? null;
		if ( ! is_array( $packages ) || 1 !== count( $packages ) || ! is_array( $packages[0] ) ) {
			exit( 1 );
		}
		$package = $packages[0];
		$name = $package["name"] ?? null;
		$version = $package["version"] ?? null;
		$source = $package["source"]["reference"] ?? null;
		$dist = $package["dist"]["reference"] ?? null;
		$contentHash = $lock["content-hash"] ?? null;
		if (
			"ran/wp-github-release-updater" !== $name
			|| $argv[2] !== $version
			|| ! is_string( $source )
			|| ! hash_equals( $argv[3], $source )
			|| $source !== $dist
			|| ! is_string( $contentHash )
			|| 1 !== preg_match( "/^[0-9a-f]{32}$/", $contentHash )
		) {
			exit( 1 );
		}
		echo implode( "\t", array( $name, $version, $source, $contentHash ) );
	' "$composer_dir/composer.lock" "$updater_version" "$updater_commit"
); then
	fail "composer.lock must contain only ran/wp-github-release-updater $updater_version at $updater_commit as a production package."
fi
IFS=$'\t' read -r package_name package_version package_commit lock_content_hash <<< "$package_lock_record"

for package_entry in LICENSE bootstrap.php runtime.php src; do
	[[ -e "$installed_package/$package_entry" ]] \
		|| fail "the locked updater package is missing $package_entry."
done
if find "$installed_package/LICENSE" "$installed_package/bootstrap.php" "$installed_package/runtime.php" "$installed_package/src" -type l -print -quit | grep -q .; then
	fail 'the updater runtime allowlist must not contain symbolic links.'
fi

archive_paths="$tmp_dir/archive-paths.txt"
archive_files="$tmp_dir/archive-files.txt"
expected_files="$tmp_dir/expected-files.txt"
expected_paths="$tmp_dir/expected-paths.txt"
entries_file="$tmp_dir/entries.txt"
contents_file="$tmp_dir/archive-contents.bin"

unzip -Z1 "$archive" > "$archive_paths"
[[ -s "$archive_paths" ]] || fail 'archive is empty.'
grep -Fqx 'ran-booster/' "$archive_paths" \
	|| fail 'archive must contain exactly one ran-booster/ root.'

while IFS= read -r path || [[ -n "$path" ]]; do
	[[ "$path" == ran-booster/* ]] \
		|| fail "path is outside the ran-booster/ root: $path"
	[[ "$path" != *\\* && "$path" != *'//'* ]] \
		|| fail "unsafe archive path: $path"
	relative=${path#ran-booster/}
	[[ -z "$relative" || ( "$relative" != '..' && "$relative" != ../* && "$relative" != */../* && "$relative" != */.. ) ]] \
		|| fail "unsafe archive path: $path"

	case "$relative" in
		AGENTS.md|AGENTS.md/*|.agents|.agents/*|.dex|.dex/*|.ran-booster-workbench|.ran-booster-workbench/*|.github|.github/*|tests|tests/*|scripts|scripts/*|build|build/*|node_modules|node_modules/*|README.md|CHANGELOG.md|release-files.txt|release-please-config.json|.release-please-manifest.json|package.json|pnpm-lock.yaml|composer.json|composer.lock|.ran-booster|.ran-booster/*|secrets.json|*/secrets.json|secrets.json.lock|*/secrets.json.lock|boosterlog|*.log|*.zip|*.tar|*.tar.gz|*.tgz)
			fail "development, secret, log, or archive path is forbidden: $path"
			;;
		vendor|vendor/|vendor/ran|vendor/ran/)
			;;
		vendor/*)
			[[ "$relative" == "$package_root" || "$relative" == "$package_root/" || "$relative" == "$package_root/"* ]] \
				|| fail "unexpected vendor path is forbidden: $path"
			;;
	esac
done < "$archive_paths"

if zipinfo -l "$archive" | awk '$1 ~ /^l/ { found = 1 } END { exit !found }'; then
	fail 'release archive must not contain symbolic links.'
fi

while IFS= read -r entry || [[ -n "$entry" ]]; do
	[[ -z "$entry" || "$entry" == \#* ]] && continue
	[[ "$entry" =~ ^[A-Za-z0-9._/-]+$ ]] \
		|| fail "unsafe allowlist path: $entry"

	allowed=false
	for expected in "${allowed_entries[@]}"; do
		if [[ "$entry" == "$expected" ]]; then
			allowed=true
			break
		fi
	done
	[[ "$allowed" == true ]] \
		|| fail "unexpected runtime allowlist entry: $entry"
	grep -Fqx "$entry" "$entries_file" 2>/dev/null \
		&& fail "duplicate allowlist entry: $entry"
	printf '%s\n' "$entry" >> "$entries_file"
done < "$manifest"

[[ $(awk 'END { print NR }' "$entries_file") -eq ${#allowed_entries[@]} ]] \
	|| fail 'release-files.txt does not contain the complete runtime allowlist.'
for required in "${allowed_entries[@]}"; do
	grep -Fqx "$required" "$entries_file" \
		|| fail "required allowlist entry is missing: $required"
done

while IFS= read -r tree_entry; do
	mode=${tree_entry%% *}
	[[ "$mode" != '120000' ]] \
		|| fail 'release files must not contain symbolic links.'
done < <(git ls-tree -r "$commit" -- "${committed_entries[@]}")

git ls-tree -r --name-only "$commit" -- "${committed_entries[@]}" \
	| sed 's#^#ran-booster/#' \
	> "$expected_files"
for package_entry in LICENSE bootstrap.php runtime.php src; do
	if [[ -d "$installed_package/$package_entry" ]]; then
		find "$installed_package/$package_entry" -type f -print
	else
		printf '%s\n' "$installed_package/$package_entry"
	fi
done \
	| sed "s#^$installed_package/#ran-booster/$package_root/#" \
	>> "$expected_files"
printf '%s\n' 'ran-booster/ran-booster-release.json' >> "$expected_files"
LC_ALL=C sort -o "$expected_files" "$expected_files"
grep -v '/$' "$archive_paths" | LC_ALL=C sort > "$archive_files"

diff -u "$expected_files" "$archive_files" >/dev/null \
	|| fail 'archive files do not exactly match committed Core plus the locked updater runtime allowlist.'

printf '%s\n' 'ran-booster/' > "$expected_paths"
while IFS= read -r expected_file; do
	printf '%s\n' "$expected_file" >> "$expected_paths"
	parent=${expected_file%/*}
	while [[ "$parent" != 'ran-booster' ]]; do
		printf '%s/\n' "$parent" >> "$expected_paths"
		parent=${parent%/*}
	done
done < "$expected_files"
LC_ALL=C sort -u -o "$expected_paths" "$expected_paths"
LC_ALL=C sort -u -o "$archive_paths" "$archive_paths"
diff -u "$expected_paths" "$archive_paths" >/dev/null \
	|| fail 'archive contains an unexpected file or directory path.'

for required_path in \
	'ran-booster/NOTICE.md' \
	'ran-booster/license.txt' \
	'ran-booster/ran-booster-release.json' \
	'ran-booster/ran-booster.php' \
	"ran-booster/$package_root/LICENSE" \
	"ran-booster/$package_root/bootstrap.php" \
	"ran-booster/$package_root/runtime.php"; do
	grep -Fqx "$required_path" "$archive_files" \
		|| fail "required runtime file is missing: $required_path"
done

archive_plugin_version=$(
	unzip -p "$archive" ran-booster/ran-booster.php \
		| sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p'
)
archive_stable_tag=$(
	unzip -p "$archive" ran-booster/readme.txt \
		| sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\)[[:space:]]*$/\1/p'
)
[[ "$archive_plugin_version" == "$plugin_version" ]] \
	|| fail 'archive plugin Version does not match the release ref.'
[[ "$archive_stable_tag" == "$plugin_version" ]] \
	|| fail 'archive Stable tag does not match the release ref.'

extract_dir="$tmp_dir/extracted"
mkdir -p "$extract_dir"
unzip -q "$archive" -d "$extract_dir"

# The installed marker is a strict build-provenance signal, not an authenticity
# signature. Exact release identity and the locally calculated ZIP digest remain
# separate.
# shellcheck disable=SC2016
php -r '
	$document = json_decode( file_get_contents( $argv[1] ), true, 16, JSON_THROW_ON_ERROR );
	$expected = array(
		"schema"         => "ran-booster-core-release",
		"schema_version" => 1,
		"version"        => $argv[2],
		"commit"         => $argv[3],
	);
	if ( $document !== $expected ) {
		exit( 1 );
	}
' \
	"$extract_dir/ran-booster/ran-booster-release.json" \
	"$expected_version" \
	"$commit" \
	|| fail 'installed Core release marker does not match the release version and commit.'

archived_package="$extract_dir/ran-booster/$package_root"
for package_entry in LICENSE bootstrap.php runtime.php; do
	cmp -s "$installed_package/$package_entry" "$archived_package/$package_entry" \
		|| fail "archived updater $package_entry does not match the committed Composer lock."
done
while IFS= read -r package_file || [[ -n "$package_file" ]]; do
	[[ -n "$package_file" ]] || continue
	relative_package_file=${package_file#"$installed_package/"}
	cmp -s "$package_file" "$archived_package/$relative_package_file" \
		|| fail "archived updater $relative_package_file does not match the committed Composer lock."
done < <(find "$installed_package/src" -type f -print | LC_ALL=C sort)

php_file_count=0
while IFS= read -r php_file || [[ -n "$php_file" ]]; do
	[[ -z "$php_file" ]] && continue
	php -l "$php_file" >/dev/null \
		|| fail "PHP syntax check failed for ${php_file#"$extract_dir"/}."
	php_file_count=$((php_file_count + 1))
done < <(find "$extract_dir/ran-booster" -type f -name '*.php' -print | LC_ALL=C sort)
[[ $php_file_count -gt 0 ]] || fail 'archive does not contain any PHP files.'

unzip -p "$archive" > "$contents_file"
if LC_ALL=C grep -Eaq \
	'(github_pat_[A-Za-z0-9_]{20,}|gh[pousr]_[A-Za-z0-9]{20,}|ATATT[A-Za-z0-9_-]{20,}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|https?://[^/@[:space:]]+:[^/@[:space:]]+@)' \
	"$contents_file"; then
	fail 'archive contains credential-shaped material.'
fi

printf 'Verified %s\n' "$archive"
printf 'Version %s\n' "$plugin_version"
printf 'SHA-256 %s\n' "$actual_hash"
printf 'Updater %s %s %s\n' "$package_name" "$package_version" "$package_commit"
printf 'PHP files linted %s\n' "$php_file_count"
