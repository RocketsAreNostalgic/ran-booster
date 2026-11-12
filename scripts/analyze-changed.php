<?php
/**
 * Quick local level-8 analysis of the development PHP changed from origin/main.
 *
 * Selects committed, staged, unstaged and untracked PHP under scripts/ and tests/
 * (deleted files excluded; NUL-delimited git output, so any file name is safe),
 * then runs the locked analyzer with the same configurations as
 * scripts/analyze-development.php. Every changed file is analysed in its own
 * process, so files that declare incompatible symbols can never mask each other's
 * errors (the isolation the full runner derives from source declarations); the
 * routing between phpstan-development.neon and phpstan-integration.neon follows
 * the full runner. It is a fast pre-push check only: `composer analyze` and CI
 * stay authoritative.
 */

declare(strict_types=1);

$ran_booster_changed_status = ( static function (): int {
	$root = dirname( __DIR__ );
	$base = 'origin/main';
	$git  = static function ( array $arguments ) use ( $root ): ?string {
		$command = array_values( array_merge( array( 'git', '-C', $root ), $arguments ) );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Local developer tool reading git state with a fixed argument vector and no shell.
		$process = proc_open(
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'file', '/dev/null', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			return null;
		}
		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close a local process pipe.

		return 0 === proc_close( $process ) && is_string( $output ) ? $output : null;
	};
	if ( null === $git( array( 'rev-parse', '--verify', '--quiet', $base ) ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Report CLI failure to standard error.
		fwrite( STDERR, "Cannot find {$base}; run git fetch origin main first.\n" );
		return 2;
	}
	// NUL-delimited names (-z), so quotes, backslashes, spaces and newlines in a name are preserved; the pathspec keeps only maintained development PHP.
	$specs = array( '--', 'scripts/*.php', 'tests/*.php' );
	$names = array();
	foreach ( array(
		array( 'diff', '-z', '--name-only', '--diff-filter=d', $base ),
		array( 'ls-files', '-z', '--others', '--exclude-standard' ),
	) as $command ) {
		$list = $git( array_merge( $command, $specs ) );
		if ( null === $list ) {
			return 2;
		}
		foreach ( explode( "\0", $list ) as $name ) {
			if ( '' !== $name && is_file( $root . '/' . $name ) ) {
				$names[ $name ] = true;
			}
		}
	}
	// Kept in step with phpstan-development.neon excludePaths: analysing an excluded fixture explicitly is a false failure.
	$names = array_values( array_diff( array_keys( $names ), array( 'tests/fixtures/provider-api11-registration/workflow-provider.php', 'tests/fixtures/provider-api12-registration/repository-provider.php' ) ) );
	sort( $names );
	if ( array() === $names ) {
		echo "No changed development PHP files.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CLI text.
		return 0;
	}
	// One process per file: files that declare incompatible symbols must never be analysed together.
	$groups = array();
	foreach ( $names as $name ) {
		$groups[] = array( 1 === preg_match( '~^tests/(?:WordPress|fixtures|Integration)/~', $name ) ? 'phpstan-integration.neon' : 'phpstan-development.neon', array( $name ) );
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
