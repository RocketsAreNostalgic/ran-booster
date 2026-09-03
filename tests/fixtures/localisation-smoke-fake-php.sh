#!/usr/bin/env bash

set -euo pipefail

arguments="$*"
if [[ "${1:-}" == '-r' ]]; then
	echo '8.2'
	exit 0
fi
if [[ "$arguments" == *'plugin_file = WP_PLUGIN_DIR'* ]]; then
	printf '%s' "$RAN_BOOSTER_LOCALISATION_FAKE_RUNTIME"
	exit 0
fi
if [[ "$arguments" == *'core version'* ]]; then
	echo '7.0'
	exit 0
fi
if [[ "$arguments" == *'option get siteurl'* ]]; then
	echo 'http://localhost'
	exit 0
fi
if [[ "$arguments" == *'plugin is-active ran-booster'* ]]; then
	exit 0
fi
if [[ "$arguments" == *'option get WPLANG'* ]]; then
	exit 1
fi
if [[ "$arguments" == *'update_option( "WPLANG"'* ]]; then
	touch "$RAN_BOOSTER_LOCALISATION_FAKE_WPLANG_MUTATION"
fi
