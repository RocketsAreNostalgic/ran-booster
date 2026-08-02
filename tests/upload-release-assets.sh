#!/usr/bin/env bash
set -euo pipefail

project_root="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT HUP INT TERM
mkdir -p "$temporary/bin" "$temporary/state"

cat > "$temporary/bin/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-} ${2:-}" in
	'release view')
		if [[ -n "${FAKE_GH_NAMES:-}" ]]; then
			printf '%s\n' "$FAKE_GH_NAMES"
		elif [[ -f "$FAKE_GH_ASSET" ]]; then
			basename "$FAKE_GH_ASSET"
		fi
		exit 0
		;;
	'release upload')
		cp "$4" "$FAKE_GH_ASSET"
		printf 'upload\n' >> "$FAKE_GH_UPLOADS"
		;;
	'release download')
		destination=''
		while [[ $# -gt 0 ]]; do
			if [[ "$1" == '--dir' ]]; then
				destination=$2
				break
			fi
			shift
		done
		cp "$FAKE_GH_ASSET" "$destination/$(basename "$FAKE_GH_ASSET")"
		;;
	*)
		exit 2
		;;
esac
EOF
chmod +x "$temporary/bin/gh"

archive="$temporary/ran-booster-1.2.3.zip"
export FAKE_GH_ASSET="$temporary/state/$(basename "$archive")"
export FAKE_GH_UPLOADS="$temporary/state/uploads"
export GITHUB_REPOSITORY='RocketsAreNostalgic/ran-booster'
export PATH="$temporary/bin:$PATH"

printf 'first' > "$archive"
bash "$project_root/scripts/upload-release-assets.sh" v1.2.3 "$archive"
cmp "$archive" "$FAKE_GH_ASSET"
bash "$project_root/scripts/upload-release-assets.sh" v1.2.3 "$archive"
[[ $(wc -l < "$FAKE_GH_UPLOADS" | tr -d '[:space:]') == 1 ]]

export FAKE_GH_NAMES='another.zip'
if bash "$project_root/scripts/upload-release-assets.sh" v1.2.3 "$archive" 2>/dev/null; then
	printf 'release containing another ZIP was accepted.\n' >&2
	exit 1
fi
export FAKE_GH_NAMES=$'ran-booster-1.2.3.zip\nran-booster-1.2.3.zip'
if bash "$project_root/scripts/upload-release-assets.sh" v1.2.3 "$archive" 2>/dev/null; then
	printf 'duplicate release ZIP names were accepted.\n' >&2
	exit 1
fi
unset FAKE_GH_NAMES

printf 'conflict' > "$archive"
if bash "$project_root/scripts/upload-release-assets.sh" v1.2.3 "$archive" 2>/dev/null; then
	printf 'conflicting release ZIP was accepted.\n' >&2
	exit 1
fi
[[ $(cat "$FAKE_GH_ASSET") == 'first' ]]
