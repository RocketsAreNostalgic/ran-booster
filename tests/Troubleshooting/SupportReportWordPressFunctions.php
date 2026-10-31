<?php

declare(strict_types=1);

namespace RAN\Troubleshooting;

/**
 * @param array<string,string> $headers
 * @return array<string,string>
 */
// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- The WordPress header-reader signature is retained; this fixture supplies one reviewed header.
function get_file_data( string $file, array $headers ): array {
	$GLOBALS['ran_booster_support_header_reads'][] = $file;
	if ( isset( $GLOBALS['ran_booster_support_header_failure'] ) ) {
		throw new \RuntimeException( '/private/header-canary; Authorization: private-token-canary' );
	}
	return array( 'Version' => $GLOBALS['ran_booster_support_version'] ?? '1.0.0-beta.41' );
}
