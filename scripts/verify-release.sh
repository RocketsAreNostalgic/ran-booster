#!/usr/bin/env bash

# Verify a RAN Booster release ZIP against an immutable Git commit and a clean
# install of its committed Composer lock plus runtime packaging policy.
#
# Usage: verify-release.sh [archive] [expected-version] [ref]
# Defaults: ref=HEAD; expected-version=the plugin Version at ref; archive=the
# corresponding versioned file under build/.

# Sourced by the builder, candidate validator, and installed smoke for shared
# release parsing and hashing. Verification runs only when invoked directly.
sha256_file() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum < "$1" | awk '{ print $1 }'
	elif command -v shasum >/dev/null 2>&1; then
		shasum -a 256 < "$1" | awk '{ print $1 }'
	else
		fail 'sha256sum or shasum is required.'
	fi
}

plugin_version_header='[[:space:]]*[*][[:space:]]*Version'

read_release_header() {
	LC_ALL=C awk -v "header=^$1:" '
		$0 ~ header {
			count++
			sub(header, "")
			sub(/^[[:space:]]+/, "")
			sub(/[[:space:]]+$/, "")
			value = $0
		}
		END {
			if (count != 1 || value !~ /^[^[:space:]]+$/) exit 1
			print value
		}
	'
}

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
generated_entries=(
	'languages'
	'ran-booster-packages.json'
	'ran-booster-release.json'
)
allowed_entries=( "${committed_entries[@]}" "${generated_entries[@]}" )

is_allowed_entry() {
	local candidate=$1
	local allowed

	for allowed in "${allowed_entries[@]}"; do
		[[ "$candidate" == "$allowed" ]] && return 0
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

load_release_manifest() {
	local manifest entry existing tree_entry
	local -a entries=()
	manifest=$(git show "$commit:release-files.txt") \
		|| fail 'release ref is missing release-files.txt.'
	while IFS= read -r entry || [[ -n "$entry" ]]; do
		[[ -z "$entry" || "$entry" == \#* ]] && continue
		is_allowed_entry "$entry" \
			|| fail "unexpected Core runtime allowlist entry: $entry"

		for existing in "${entries[@]:-}"; do
			[[ "$entry" != "$existing" ]] \
				|| fail "duplicate allowlist entry: $entry"
		done

		if ! is_generated_entry "$entry"; then
			git cat-file -e "$commit:$entry" 2>/dev/null \
				|| fail "allowlist path is missing from release ref: $entry"
		fi
		entries+=( "$entry" )
	done <<< "$manifest"

	[[ ${#entries[@]} -eq ${#allowed_entries[@]} ]] \
		|| fail 'release-files.txt does not contain the complete Core runtime allowlist.'

	local tree
	tree=$(git ls-tree -r "$commit" -- "${committed_entries[@]}")
	while IFS= read -r tree_entry; do
		[[ "${tree_entry%% *}" != '120000' ]] \
			|| fail 'release files must not contain symbolic links.'
	done <<< "$tree"
}

load_release_metadata() {
	local stable_tag requires_wordpress requires_php tested_wordpress manifest_version compatibility_version
	plugin_version=$(git show "$commit:ran-booster.php" | read_release_header "$plugin_version_header") \
		|| fail 'ran-booster.php must contain exactly one plugin Version header with a single value.'
	stable_tag=$(git show "$commit:readme.txt" | read_release_header 'Stable tag') \
		|| fail 'readme.txt must contain exactly one Stable tag with a single value.'
	requires_wordpress=$(git show "$commit:ran-booster.php" | read_release_header '[[:space:]]*[*][[:space:]]*Requires at least') \
		|| fail 'ran-booster.php must contain exactly one Requires at least header with a single value.'
	requires_php=$(git show "$commit:ran-booster.php" | read_release_header '[[:space:]]*[*][[:space:]]*Requires PHP') \
		|| fail 'ran-booster.php must contain exactly one Requires PHP header with a single value.'
	tested_wordpress=$(git show "$commit:readme.txt" | read_release_header 'Tested up to') \
		|| fail 'readme.txt must contain exactly one Tested up to header with a single value.'
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
}

install_release_runtime() (
	cd "$1"
	export COMPOSER_HOME=$2
	# The runtime dependencies intentionally use exact version constraints.
	composer validate --strict --no-check-all --no-plugins --no-scripts
	composer install \
		--no-dev \
		--prefer-dist \
		--no-interaction \
		--no-progress \
		--no-scripts \
		--no-plugins \
		--no-autoloader
)

if [[ "${BASH_SOURCE[0]}" != "$0" ]]; then
	return 0
fi

set -euo pipefail

# Caller options can convert text on extraction or change ZIP listings.
unset UNZIP UNZIPOPT ZIPINFO ZIPINFOOPT

fail() {
	printf 'verify-release: %s
' "$*" >&2
	exit 1
}

script_dir=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
repo_root=$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null) \
	|| fail 'run this script from a Git checkout.'
cd "$repo_root"

for command_name in cmp composer diff git php tar unzip zipinfo; do
	command -v "$command_name" >/dev/null 2>&1 \
		|| fail "$command_name is required."
done

ref=${3:-HEAD}
[[ "$ref" != -* ]] || fail 'release ref must not begin with a hyphen.'
commit=$(git rev-parse --verify "${ref}^{commit}" 2>/dev/null) \
	|| fail "release ref does not identify a commit: $ref"

load_release_manifest
load_release_metadata

bootstrap_source=$(git show "$commit:ran-booster.php")
for required_api_marker in \
	"define( 'RAN_BOOSTER_PROVIDER_API_VERSION', 15 );" \
	"define( 'RAN_BOOSTER_ADDON_API_VERSION', 17 );" \
	"define( 'RAN_BOOSTER_ADMIN_INTERACTION_API_VERSION', 3 );" \
	"define( 'RAN_BOOSTER_PORTABILITY_API_VERSION', PortabilityFacade::API_VERSION );"; do
	grep -Fq "$required_api_marker" <<< "$bootstrap_source" \
		|| fail "release ref is missing the coordinated API marker: $required_api_marker"
done
portability_facade_source=$(git show "$commit:RAN/AddOn/Portability/PortabilityFacade.php")
grep -Fq 'public const API_VERSION = 4;' <<< "$portability_facade_source" \
	|| fail 'release ref is missing exact Portability API 4.'
for removed_api_marker in \
	RAN_BOOSTER_LOGGING_API_VERSION \
	RAN_BOOSTER_WEBHOOK_CLEANUP_API_VERSION \
	RAN_BOOSTER_DOCUMENTATION_API_VERSION \
	RAN_BOOSTER_PACKAGE_EXTENSION_API_VERSION \
	RAN_BOOSTER_PROVIDER_ADMIN_EXTENSION_API_VERSION \
	RAN_BOOSTER_SETTINGS_PAGE_API_VERSION \
	RAN_BOOSTER_PROSPECTIVE_RELEASE_API_VERSION \
	ran_booster_release_tracking_ready \
	ran_booster_prospective_release_ready; do
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
policy_file="$composer_dir/runtime-packaging-policy.json"
packaging_records="$tmp_dir/runtime-packaging.tsv"
mkdir -p "$composer_dir" "$composer_home"
for required_source in composer.json composer.lock .release-please-manifest.json runtime-packaging-policy.json; do
	git cat-file -e "$commit:$required_source" 2>/dev/null \
		|| fail "release ref is missing $required_source."
done
git show "$commit:composer.json" > "$composer_dir/composer.json"
git show "$commit:composer.lock" > "$composer_dir/composer.lock"
git show "$commit:runtime-packaging-policy.json" > "$policy_file"

if ! dependency_records=$(php "$repo_root/scripts/verify-runtime-dependencies.php" "$composer_dir/composer.lock" "$policy_file"); then
	fail 'composer.lock and runtime packaging policy do not describe one approved runtime dependency set.'
fi
if ! php "$repo_root/scripts/verify-runtime-dependencies.php" --packaging "$composer_dir/composer.lock" "$policy_file" > "$packaging_records"; then
	fail 'runtime packaging projection could not be produced.'
fi
[[ -s "$packaging_records" ]] || fail 'runtime packaging policy did not produce any package records.'

package_roots=()
package_surface_specs=()
package_installed=()
while IFS=$'\t' read -r package_name package_version package_reference package_repository package_root surface_specs build_role; do
	[[ -n "$package_name" && -n "$package_version" && -n "$package_reference" && -n "$package_repository" && -n "$package_root" && -n "$surface_specs" && -n "$build_role" ]] \
		|| fail 'runtime packaging projection contains an incomplete package record.'
	package_roots+=( "$package_root" )
	package_surface_specs+=( "$surface_specs" )
	package_installed+=( "$composer_dir/$package_root" )
done < "$packaging_records"

install_release_runtime "$composer_dir" "$composer_home"

if ! php "$repo_root/scripts/verify-runtime-dependencies.php" --verify-install "$composer_dir" "$composer_dir/composer.lock" "$policy_file"; then
	fail 'installed runtime package surfaces do not match the locked runtime packaging policy.'
fi

archive_paths="$tmp_dir/archive-paths.txt"
archive_files="$tmp_dir/archive-files.txt"
expected_files="$tmp_dir/expected-files.txt"
expected_paths="$tmp_dir/expected-paths.txt"
core_files="$tmp_dir/core-files.txt"
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
		AGENTS.md|AGENTS.md/*|.agents|.agents/*|.dex|.dex/*|ran-booster-workbench|ran-booster-workbench/*|.github|.github/*|tests|tests/*|scripts|scripts/*|build|build/*|node_modules|node_modules/*|README.md|CHANGELOG.md|release-files.txt|runtime-packaging-policy.json|release-please-config.json|.release-please-manifest.json|package.json|pnpm-lock.yaml|composer.json|composer.lock|.ran-booster|.ran-booster/*|ran-booster-store.json|*/ran-booster-store.json|ran-booster-store.json.lock|*/ran-booster-store.json.lock|secrets.json|*/secrets.json|secrets.json.lock|*/secrets.json.lock|boosterlog|*.log|*.zip|*.tar|*.tar.gz|*.tgz)
			fail "development, secret, log, or archive path is forbidden: $path"
			;;
		vendor|vendor/|vendor/ran|vendor/ran/)
			;;
		vendor/*)
			allowed_vendor=false
			for package_root in "${package_roots[@]}"; do
				if [[ "$relative" == "$package_root" || "$relative" == "$package_root/" || "$relative" == "$package_root/"* ]]; then
					allowed_vendor=true
					break
				fi
			done
			[[ "$allowed_vendor" == true ]] || fail "unexpected vendor path is forbidden: $path"
			;;
	esac
done < "$archive_paths"

if zipinfo -l "$archive" | awk '$1 ~ /^l/ { found = 1 } END { exit !found }'; then
	fail 'release archive must not contain symbolic links.'
fi

git ls-tree -r --name-only "$commit" -- "${committed_entries[@]}" > "$core_files"
sed 's#^#ran-booster/#' "$core_files" > "$expected_files"
append_runtime_files() {
	local installed_root=$1
	local package_root=$2
	local surface_specs_csv=$3
	local surface_spec surface_kind surface
	local -a surface_specs
	IFS=',' read -r -a surface_specs <<< "$surface_specs_csv"
	for surface_spec in "${surface_specs[@]}"; do
		surface_kind=${surface_spec%%:*}
		surface=${surface_spec#*:}
		case "$surface_kind" in
			file)
				printf '%s\n' "$installed_root/$surface"
				;;
			directory)
				find "$installed_root/$surface" -type f -print
				;;
			*)
				fail "runtime packaging projection contains an unsupported surface kind: $surface_kind"
				;;
		esac
	done | while IFS= read -r runtime_file; do
		printf '%s
' "ran-booster/$package_root/${runtime_file#"$installed_root/"}"
	done >> "$expected_files"
}
for index in "${!package_roots[@]}"; do
	append_runtime_files \
		"${package_installed[$index]}" \
		"${package_roots[$index]}" \
		"${package_surface_specs[$index]}"
done
printf '%s\n' 'ran-booster/languages/ran-booster.pot' 'ran-booster/ran-booster-packages.json' 'ran-booster/ran-booster-release.json' >> "$expected_files"
LC_ALL=C sort -o "$expected_files" "$expected_files"
grep -v '/$' "$archive_paths" | LC_ALL=C sort > "$archive_files"

diff -u "$expected_files" "$archive_files" >/dev/null \
	|| fail 'archive files do not exactly match committed Core plus the locked runtime dependency policy.'

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
	'ran-booster/languages/ran-booster.pot' \
	'ran-booster/license.txt' \
	'ran-booster/ran-booster-packages.json' \
	'ran-booster/ran-booster-release.json' \
	'ran-booster/ran-booster.php'; do
	grep -Fqx "$required_path" "$archive_files" \
		|| fail "required runtime file is missing: $required_path"
done
archive_plugin_version=$(unzip -p "$archive" ran-booster/ran-booster.php | read_release_header "$plugin_version_header") \
	|| fail 'archive plugin Version must have exactly one header with a single value.'
archive_stable_tag=$(unzip -p "$archive" ran-booster/readme.txt | read_release_header 'Stable tag') \
	|| fail 'archive Stable tag must have exactly one header with a single value.'
[[ "$archive_plugin_version" == "$plugin_version" ]] \
	|| fail 'archive plugin Version does not match the release ref.'
[[ "$archive_stable_tag" == "$plugin_version" ]] \
	|| fail 'archive Stable tag does not match the release ref.'

extract_dir="$tmp_dir/extracted"
mkdir -p "$extract_dir"
unzip -q "$archive" -d "$extract_dir"

while IFS= read -r core_file; do
	git cat-file blob "$commit:$core_file" | cmp -s "$extract_dir/ran-booster/$core_file" - \
		|| fail "archived Core file $core_file does not match the release ref."
done < "$core_files"

# Regenerate the POT from a separate export with the builder's line endings.
pot_source="$tmp_dir/pot-source"
mkdir -p "$pot_source"
git -c core.autocrlf=false -c core.eol=lf archive --format=tar "$commit" -- "${committed_entries[@]}" \
	| tar -xf - -C "$pot_source"
bash "$script_dir/make-pot.sh" --source "$pot_source" --output "$tmp_dir/expected.pot"
cmp -s "$tmp_dir/expected.pot" "$extract_dir/ran-booster/languages/ran-booster.pot" \
	|| fail 'archived translation template does not match one regenerated from the release ref.'

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

# The built-with record must list exactly the locked runtime packages and versions.
# shellcheck disable=SC2016
php -r '
	$document = json_decode( file_get_contents( $argv[1] ), true, 16, JSON_THROW_ON_ERROR );
	$packages = array();
	foreach ( file( $argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		$fields                = explode( "\t", $line );
		$packages[ $fields[0] ] = $fields[1];
	}
	ksort( $packages );
	$expected = array(
		"schema"         => "ran-booster-built-with",
		"schema_version" => 1,
		"packages"       => $packages,
	);
	if ( $document !== $expected ) {
		exit( 1 );
	}
' \
	"$extract_dir/ran-booster/ran-booster-packages.json" \
	"$packaging_records" \
	|| fail 'installed built-with package record does not match composer.lock.'

compare_runtime_package() {
	local installed_root=$1
	local archived_root=$2
	local surface_specs_csv=$3
	local surface_spec surface_kind entry package_file relative_package_file
	local -a surface_specs
	IFS=',' read -r -a surface_specs <<< "$surface_specs_csv"
	for surface_spec in "${surface_specs[@]}"; do
		surface_kind=${surface_spec%%:*}
		entry=${surface_spec#*:}
		case "$surface_kind" in
			file)
				cmp -s "$installed_root/$entry" "$archived_root/$entry" \
					|| fail "archived runtime dependency $entry does not match the committed Composer lock."
				;;
			directory)
				while IFS= read -r package_file || [[ -n "$package_file" ]]; do
					[[ -n "$package_file" ]] || continue
					relative_package_file=${package_file#"$installed_root/"}
					cmp -s "$package_file" "$archived_root/$relative_package_file" \
						|| fail "archived runtime dependency $relative_package_file does not match the committed Composer lock."
				done < <(find "$installed_root/$entry" -type f -print | LC_ALL=C sort)
				;;
			*)
				fail "runtime packaging projection contains an unsupported surface kind: $surface_kind"
				;;
		esac
	done
}
for index in "${!package_roots[@]}"; do
	compare_runtime_package \
		"${package_installed[$index]}" \
		"$extract_dir/ran-booster/${package_roots[$index]}" \
		"${package_surface_specs[$index]}"
done
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
printf 'Runtime dependencies\n%s\n' "$dependency_records"
printf 'PHP files linted %s\n' "$php_file_count"
