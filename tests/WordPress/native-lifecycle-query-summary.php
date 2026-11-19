<?php

// Summarises the opt-in native lifecycle query log written by native-lifecycle-bootstrap-probe.php (#1091).

declare(strict_types=1);

$ran_booster_directory = $argv[1] ?? '';
if ( ! is_dir( $ran_booster_directory ) || is_link( $ran_booster_directory ) ) {
	throw new InvalidArgumentException( 'Usage: php native-lifecycle-query-summary.php <query-log-directory>' );
}

$ran_booster_files = glob( $ran_booster_directory . '/*.json' );
if ( ! is_array( $ran_booster_files ) || array() === $ran_booster_files ) {
	throw new RuntimeException( 'The native lifecycle query log is empty.' );
}
$ran_booster_requests = array();
foreach ( $ran_booster_files as $ran_booster_file ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test-owned journal file, not a remote URL.
	$ran_booster_log = json_decode( (string) file_get_contents( $ran_booster_file ), true, 8, JSON_THROW_ON_ERROR );
	if ( ! is_array( $ran_booster_log ) || ! is_string( $ran_booster_log['script'] ?? null ) || ! is_int( $ran_booster_log['total'] ?? null ) ) {
		throw new RuntimeException( 'Invalid query log: ' . basename( $ran_booster_file ) );
	}
	$ran_booster_requests[] = $ran_booster_log;
}

/**
 * @param list<array<string, mixed>> $requests
 * @return array{requests: int, total: int, groups: array<string, array<string, int>>}
 */
function ran_booster_query_sum( array $requests ): array {
	$sum = array(
		'requests' => count( $requests ),
		'total'    => 0,
		'groups'   => array(),
	);
	foreach ( $requests as $request ) {
		$sum['total'] += (int) $request['total'];
		foreach ( array( 'by_keyword', 'by_statement', 'by_caller', 'by_owner', 'by_entry', 'by_frame' ) as $group ) {
			foreach ( (array) ( $request[ $group ] ?? array() ) as $key => $count ) {
				$sum['groups'][ $group ][ (string) $key ] = ( $sum['groups'][ $group ][ (string) $key ] ?? 0 ) + (int) $count;
			}
		}
	}
	return $sum;
}

$ran_booster_all       = ran_booster_query_sum( $ran_booster_requests );
$ran_booster_report    = sprintf( "Native lifecycle query log: %d queries in %d WP-CLI requests\n", $ran_booster_all['total'], $ran_booster_all['requests'] );
$ran_booster_by_script = array();
foreach ( $ran_booster_requests as $ran_booster_request ) {
	$ran_booster_by_script[ $ran_booster_request['script'] . ' scale ' . $ran_booster_request['scale'] ][] = $ran_booster_request;
}
ksort( $ran_booster_by_script, SORT_NATURAL );
foreach ( $ran_booster_by_script as $ran_booster_label => $ran_booster_group ) {
	$ran_booster_sum     = ran_booster_query_sum( $ran_booster_group );
	$ran_booster_report .= sprintf( "  %-52s %3d requests %8d queries %8.0f per request\n", $ran_booster_label, $ran_booster_sum['requests'], $ran_booster_sum['total'], $ran_booster_sum['total'] / $ran_booster_sum['requests'] );
}

foreach ( array( '1', '20' ) as $ran_booster_scale ) {
	$ran_booster_proof = array_values(
		array_filter(
			$ran_booster_requests,
			static fn ( array $request ): bool => 'native-lifecycle-installed-smoke.php' === $request['script'] && $ran_booster_scale === $request['scale']
		)
	);
	if ( array() === $ran_booster_proof ) {
		continue;
	}
	$ran_booster_sum     = ran_booster_query_sum( $ran_booster_proof );
	$ran_booster_report .= sprintf( "\nOne native update request at scale %s (mean of %d): %.0f queries\n", $ran_booster_scale, $ran_booster_sum['requests'], $ran_booster_sum['total'] / $ran_booster_sum['requests'] );
	foreach ( $ran_booster_sum['groups'] as $ran_booster_group => $ran_booster_counts ) {
		arsort( $ran_booster_counts );
		$ran_booster_report .= sprintf( "  %s\n", $ran_booster_group );
		foreach ( array_slice( $ran_booster_counts, 0, 'by_frame' === $ran_booster_group ? 25 : 12, true ) as $ran_booster_key => $ran_booster_count ) {
			$ran_booster_report .= sprintf( "    %8.0f  %s\n", $ran_booster_count / $ran_booster_sum['requests'], $ran_booster_key );
		}
	}
}
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain-text CLI report of test-owned query counts, never HTML.
echo $ran_booster_report;
