#!/usr/bin/env bash

set -euo pipefail

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
artifact_directory="${repository_root}/build/test-artifacts/published-template-packs"
php_binary="${PHP_BIN:-php}"

download_and_verify() {
	local destination="$1"
	local expected_size="$2"
	local expected_sha256="$3"
	local url="$4"
	local partial="${destination}.part"
	local actual_size
	local actual_sha256

	if [[ -f "$destination" ]]; then
		actual_size="$(wc -c < "$destination" | tr -d '[:space:]')"
		actual_sha256="$("$php_binary" -r 'echo hash_file( "sha256", $argv[1] );' "$destination")"
		if [[ "$actual_size" == "$expected_size" && "$actual_sha256" == "$expected_sha256" ]]; then
			return
		fi
		rm -f "$destination"
	fi

	rm -f "$partial"
	curl --fail --location --retry 3 --retry-delay 1 --connect-timeout 20 --output "$partial" "$url"
	actual_size="$(wc -c < "$partial" | tr -d '[:space:]')"
	actual_sha256="$("$php_binary" -r 'echo hash_file( "sha256", $argv[1] );' "$partial")"
	[[ "$actual_size" == "$expected_size" ]]
	[[ "$actual_sha256" == "$expected_sha256" ]]
	mv "$partial" "$destination"
}

umask 077
mkdir -p "$artifact_directory"

api2_archive="${artifact_directory}/v0.2.1.zip"
api1_archive="${artifact_directory}/v0.2.0.zip"

download_and_verify \
	"$api2_archive" \
	13879 \
	7518b7c30b23fe95fb6c3c5211607657394ffcf440d258323d55c20b15bb5b14 \
	https://github.com/RocketsAreNostalgic/ran-booster-release-bootstrap-templates/releases/download/v0.2.1/ran-booster-release-bootstrap-templates.zip
download_and_verify \
	"$api1_archive" \
	8017 \
	2c223e14287a1fab28aa91e92d6a454b27e647cfb33f1bb3df965ed995cd89db \
	https://github.com/RocketsAreNostalgic/ran-booster-release-bootstrap-templates/releases/download/v0.2.0/ran-booster-release-bootstrap-templates.zip

export RAN_TEMPLATE_PACK_ZIP="$api2_archive"
export RAN_TEMPLATE_PACK_API1_ZIP="$api1_archive"

exec "$php_binary" "${repository_root}/vendor/bin/phpunit" \
	--configuration "${repository_root}/phpunit.published-template-pack.xml" \
	--fail-on-skipped \
	--fail-on-phpunit-deprecation
