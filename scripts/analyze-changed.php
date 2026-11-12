<?php
/**
 * Quick local level-8 analysis of the development PHP changed from origin/main.
 *
 * Selects committed, staged, unstaged and untracked PHP under scripts/ and tests/
 * (deleted files excluded), then runs the same locked analyzer configurations as
 * scripts/analyze-development.php, using the same profile routing: WordPress,
 * fixture and Integration tests are analysed one file at a time with
 * phpstan-integration.neon; everything else is one phpstan-development.neon run.
 *
 * Limitation: this skips the full runner's batching partition, so a file whose
 * test classes collide with another file's declarations can differ from the full
 * run. It is a fast pre-push check only. `composer analyze` and CI stay
 * authoritative.
 */

declare(strict_types=1);

$ran_booster_changed_status = ( static function (): int {
	$root = dirname( __DIR__ );
	$base = 'origin/main';
	$git  = static function ( array $arguments ) use ( $root ): ?array {
		$output = array();
		$code   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Local developer tool reading git state with a fixed argument vector.
		exec( 'git -C ' . escapeshellarg( $root ) . ' -c core.quotepath=off ' . implode( ' ', array_map( 'escapeshellarg', $arguments ) ) . ' 2>/dev/null', $output, $code );
		return 0 === $code ? $output : null;
	};
	if ( null === $git( array( 'rev-parse', '--verify', '--quiet', $base ) ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Report CLI failure to standard error.
		fwrite( STDERR, "Cannot find {$base}; run git fetch origin main first.\n" );
		return 2;
	}
	// One name per line (unquoted), so spaces are safe; the pathspec keeps only maintained development PHP.
	$specs = array( '--', 'scripts/*.php', 'tests/*.php' );
	$names = array();
	foreach ( array(
		array( 'diff', '--name-only', '--diff-filter=d', $base ),
		array( 'ls-files', '--others', '--exclude-standard' ),
	) as $command ) {
		$list = $git( array_merge( $command, $specs ) );
		if ( null === $list ) {
			return 2;
		}
		foreach ( $list as $name ) {
			if ( '' !== $name && is_file( $root . '/' . $name ) ) {
				$names[ $name ] = true;
			}
		}
	}
	$names = array_keys( $names );
	sort( $names );
	if ( array() === $names ) {
		echo "No changed development PHP files.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CLI text.
		return 0;
	}
	$development = array();
	$groups      = array();
	foreach ( $names as $name ) {
		if ( preg_match( '~^tests/(?:WordPress|fixtures|Integration)/~', $name ) ) {
			$groups[] = array( 'phpstan-integration.neon', array( $name ) );
		} else {
			$development[] = $name;
		}
	}
	if ( array() !== $development ) {
		array_unshift( $groups, array( 'phpstan-development.neon', $development ) );
	}
	$status = 0;
	foreach ( $groups as list( $profile, $files ) ) {
		printf( "Analysing %d file(s) with %s:\n  %s\n", count( $files ), $profile, implode( "\n  ", $files ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI text, not HTML.
		$command = array_merge( array( PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--configuration=' . $profile, '--no-progress', '--memory-limit=-1' ), $files );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Local developer tool running the locked analyzer without a shell.
		$process = proc_open( $command, array( STDIN, STDOUT, STDERR ), $pipes, $root );
		if ( ! is_resource( $process ) ) {
			return 2;
		}
		if ( 0 !== proc_close( $process ) ) {
			$status = 1;
		}
	}
	return $status;
} )();
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The closure returns only an integer CLI status.
exit( $ran_booster_changed_status );
