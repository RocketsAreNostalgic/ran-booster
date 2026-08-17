#!/usr/bin/env bash
set -euo pipefail

fail() {
	printf 'immutable release assets: %s\n' "$*" >&2
	exit 1
}

[[ $# -eq 3 ]] || fail 'expected <verified-archive> <verified-checksum> <download-directory>.'
archive=$1
checksum=$2
download_directory=$3
archive_name=$(basename "$archive")
checksum_name=$(basename "$checksum")
[[ "$checksum_name" == "${archive_name}.sha256" ]] || fail 'checksum name does not match the archive.'
[[ -f "$archive" && -f "$checksum" ]] || fail 'verified Quality assets are unavailable.'
[[ -d "$download_directory" ]] || fail 'download directory is unavailable.'

release_json=$(cat)
jq -e \
	--arg archive "$archive_name" \
	--arg checksum "$checksum_name" \
	'([.assets[].name] | sort) == ([$archive, $checksum] | sort)' \
	<<< "$release_json" >/dev/null \
	|| fail 'immutable release does not contain exactly the expected archive and checksum.'

cmp -s "$archive" "$download_directory/$archive_name" \
	|| fail 'immutable release archive differs from the verified Quality archive.'
cmp -s "$checksum" "$download_directory/$checksum_name" \
	|| fail 'immutable release checksum differs from the verified Quality checksum.'

printf 'Verified immutable release asset bytes.\n'
