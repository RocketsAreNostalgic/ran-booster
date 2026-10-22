<?php

declare(strict_types=1);

namespace RAN_Booster_GitHubReplacementFixture;

function get_option( string $name, mixed $default = false ): mixed { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Preserve the WordPress function parameter signature.
	return $GLOBALS['ran_booster_fixture_guard_options'][ $name ] ?? $default;
}
